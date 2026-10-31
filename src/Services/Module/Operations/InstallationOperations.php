<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Operations;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InstallationException;
use Frolax\Bedrock\Jobs\InstallModule;
use Frolax\Bedrock\Models\ModuleInstallation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/** Coordinates durable installation state across the request, worker, and child. */
class InstallationOperations
{
    protected ?string $operationId = null;

    public function __construct(
        protected Repository $config,
        protected Factory $queues,
    ) {}

    public function schedule(string $key, ?string $from = null, ?string $zip = null, bool $offline = false): ModuleInstallation
    {
        if (strlen($key) > 191 || preg_match(Manifest::KEY_PATTERN, $key) !== 1) {
            throw new InstallationException('A valid module key is required.');
        }

        if (($from !== null && $zip !== null) || ($from !== null && trim($from) === '') || ($zip !== null && trim($zip) === '')) {
            throw new InstallationException('Use one non-empty directory or ZIP source.');
        }

        $connection = $this->config->get('bedrock.modules.operations.connection') ?? $this->config->get('queue.default');
        $queue = $this->config->get('bedrock.modules.operations.queue', 'bedrock-install');

        if (! is_string($connection) || $connection === '' || ! is_string($queue) || trim($queue) === '') {
            throw new InstallationException('Configure an asynchronous installation connection and a dedicated queue.');
        }

        $driver = $this->config->get('queue.connections.'.$connection.'.driver');

        if (! is_string($driver) || in_array($driver, ['sync', 'null', 'deferred', 'background', 'failover'], true)) {
            throw new InstallationException('Module installation requires an asynchronous worker queue connection.');
        }

        $timeout = $this->config->get('bedrock.modules.operations.timeout', 1200);
        $jobTimeout = (is_int($timeout) && $timeout > 0 ? $timeout : 1200) + 30;
        $retryAfter = $this->config->get('queue.connections.'.$connection.'.retry_after');

        if (is_numeric($retryAfter) && (int) $retryAfter <= $jobTimeout) {
            throw new InstallationException('The installation queue retry_after must exceed the installation timeout plus 30 seconds.');
        }

        try {
            $operation = ModuleInstallation::query()->create([
                'key' => $key,
                'active_key' => $key,
                'status' => 'queued',
                'from' => $from,
                'zip' => $zip,
                'offline' => $offline,
                'connection' => $connection,
                'queue' => $queue,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InstallationException('An installation for this module is already queued or running.');
        } catch (Throwable) {
            throw new InstallationException('The installation could not be recorded. Check the installation database.');
        }

        $job = (new InstallModule($operation->id))
            ->onConnection($connection)->onQueue($queue)->afterCommit();
        $job->timeout = $jobTimeout;

        $dispatch = function () use ($operation, $connection, $queue, $job): void {
            try {
                $this->queues->connection($connection)->push($job, '', $queue);
            } catch (Throwable) {
                $this->fail($operation->id);

                throw new InstallationException('The installation could not be queued. Check the queue connection.');
            }
        };

        // Register our own callback so even deferred backend errors are redacted.
        $operation->getConnection()->afterCommit($dispatch);

        return $operation;
    }

    /** Expire abandoned operations without dispatching work or replaying migrations. */
    public function recover(?string $operationId = null): int
    {
        $seconds = max(
            1,
            (int) $this->config->get('bedrock.modules.operations.stale_after', 3600),
            (int) $this->config->get('bedrock.modules.operations.timeout', 1200) + 60,
            (int) $this->config->get('bedrock.modules.locks.seconds', 1500) + 60,
        );
        $cutoff = now()->subSeconds($seconds);

        return ModuleInstallation::query()
            ->when($operationId !== null, fn (Builder $query): Builder => $query->whereKey($operationId))
            ->whereNotNull('active_key')
            ->whereIn('status', ['queued', 'preparing', 'installing'])
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('started_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('started_at')->where('created_at', '<=', $cutoff);
                    });
            })->update([
                'status' => 'failed', 'active_key' => null, 'finished_at' => now(),
                'error' => 'Installation expired. Inspect running processes and database changes before explicitly installing again; migrations were not retried.',
            ]);
    }

    /** Atomically claim delivery without permitting migration replay. */
    public function claim(string $operationId): ?ModuleInstallation
    {
        $claimed = ModuleInstallation::query()->whereKey($operationId)
            ->where('status', 'queued')->whereNotNull('active_key')->whereNull('started_at')
            ->update(['started_at' => now()]);

        return $claimed === 1 ? ModuleInstallation::query()->find($operationId) : null;
    }

    /** Called once by the freshly booted module:install child command. */
    public function start(string $operationId, string $key): void
    {
        $started = ModuleInstallation::query()->whereKey($operationId)->where('key', $key)
            ->where('status', 'queued')->whereNotNull('started_at')
            ->update(['status' => 'preparing']);

        if ($started !== 1) {
            throw new InstallationException('This installation operation is unavailable or has already started.');
        }

        $this->operationId = $operationId;
    }

    /** Called immediately before database resources run, including dependencies. */
    public function stage(string $status): void
    {
        if ($this->operationId === null) {
            return;
        }

        if ($status !== 'installing') {
            throw new InstallationException('Invalid installation stage.');
        }

        $updated = ModuleInstallation::query()->whereKey($this->operationId)
            ->whereIn('status', ['preparing', 'installing'])->update(['status' => $status]);

        if ($updated !== 1) {
            throw new InstallationException('This installation operation is no longer active.');
        }
    }

    /** Clear child command context when Artisan is invoked again in the same app. */
    public function reset(): void
    {
        $this->operationId = null;
    }

    public function complete(string $operationId): bool
    {
        return ModuleInstallation::query()->whereKey($operationId)->where('status', 'installing')->update([
            'status' => 'completed', 'active_key' => null, 'finished_at' => now(),
        ]) === 1;
    }

    /** Persist only a fixed message; subprocess output and exceptions may contain credentials. */
    public function fail(string $operationId): void
    {
        ModuleInstallation::query()->whereKey($operationId)->whereIn('status', ['queued', 'preparing', 'installing'])->update([
            'status' => 'failed',
            'active_key' => null,
            'finished_at' => now(),
            'error' => 'Module installation failed. Inspect the host configuration before attempting another installation; migrations are not retried automatically.',
        ]);
    }
}
