<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Jobs;

use Frolax\Bedrock\Exceptions\Module\InstallationException;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Frolax\Bedrock\Services\Module\Operations\InstallationProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class InstallModule implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1230;

    public bool $failOnTimeout = true;

    public function __construct(public string $operationId) {}

    public function handle(InstallationOperations $operations, InstallationProcess $process): void
    {
        $operation = $operations->claim($this->operationId);

        if ($operation === null) {
            return;
        }

        try {
            if ($process->run($operation) === 0 && $operations->complete($this->operationId)) {
                return;
            }
        } catch (Throwable) {
            // Never put child output, command arguments, or raw exceptions in failed job storage.
        }

        $operations->fail($this->operationId);
        $this->fail(new InstallationException('Module installation failed. Automatic retry is disabled.'));
    }

    public function failed(?Throwable $exception): void
    {
        app(InstallationOperations::class)->fail($this->operationId);
    }
}
