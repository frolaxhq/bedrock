<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Exceptions\Module\InstallationException;
use Frolax\Bedrock\Jobs\InstallModule;
use Frolax\Bedrock\Models\ModuleInstallation;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Frolax\Bedrock\Services\Module\Operations\InstallationProcess;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('queue.connections.installations', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 1500]);
    config()->set('bedrock.modules.operations.connection', 'installations');
    app()->singleton(InstallationOperations::class);
    Queue::fake();
});

it('durably queues installation options on a dedicated asynchronous queue', function (): void {
    $operation = app(InstallationOperations::class)->schedule('alpha', from: '/trusted/modules/alpha', offline: true);

    expect($operation->fresh()->status)->toBe('queued')
        ->and($operation->from)->toBe('/trusted/modules/alpha')
        ->and($operation->offline)->toBeTrue()
        ->and($operation->toArray())->not->toHaveKeys(['from', 'zip', 'active_key']);

    Queue::assertPushedOn('bedrock-install', InstallModule::class, function (InstallModule $job) use ($operation): bool {
        expect($job->operationId)->toBe($operation->id)
            ->and($job->connection)->toBe('installations')
            ->and($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(1230)
            ->and($job->failOnTimeout)->toBeTrue()
            ->and($job->afterCommit)->toBeTrue();

        return true;
    });
});

it('stores the real queue payload after commit without including source credentials', function (): void {
    Queue::getFacadeRoot()->except(InstallModule::class);
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    DB::transaction(function (): void {
        app(InstallationOperations::class)->schedule('alpha', from: '/private/source-with-credentials');
        expect(DB::table('jobs')->count())->toBe(0);
    });

    $queued = DB::table('jobs')->sole();
    $payload = json_decode($queued->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($queued->queue)->toBe('bedrock-install')
        ->and($payload['maxTries'])->toBe(1)
        ->and($payload['timeout'])->toBe(1230)
        ->and($queued->payload)->not->toContain('/private/source-with-credentials');
});

it('rejects synchronous and discard connections before recording an operation', function (string $driver): void {
    config()->set('queue.connections.installations.driver', $driver);

    expect(fn () => app(InstallationOperations::class)->schedule('alpha'))->toThrow(InstallationException::class);
    expect(ModuleInstallation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['sync', 'null', 'deferred', 'background']);

it('rejects queue visibility shorter than its worker timeout', function (): void {
    config()->set('queue.connections.installations.retry_after', 90);

    expect(fn () => app(InstallationOperations::class)->schedule('alpha'))
        ->toThrow(InstallationException::class, 'retry_after');
    expect(ModuleInstallation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('validates keys and mutually exclusive source options before queuing', function (string $key, ?string $from, ?string $zip): void {
    expect(fn () => app(InstallationOperations::class)->schedule($key, $from, $zip))->toThrow(InstallationException::class);
    Queue::assertNothingPushed();
})->with([
    ['../alpha', null, null],
    ['alpha', '/module', '/module.zip'],
    ['alpha', ' ', null],
    ['alpha', null, ''],
]);

it('guards duplicate scheduling with a durable unique active key', function (): void {
    $operations = app(InstallationOperations::class);
    $first = $operations->schedule('alpha');

    expect(fn () => $operations->schedule('alpha'))->toThrow(InstallationException::class, 'already queued');
    expect(ModuleInstallation::query()->count())->toBe(1);
    Queue::assertPushed(InstallModule::class, 1);

    $operations->fail($first->id);
    expect($operations->schedule('alpha')->id)->not->toBe($first->id);
});

it('requires a claimed matching operation and records child preparation before installation', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');

    expect(fn () => $operations->start($operation->id, 'alpha'))->toThrow(InstallationException::class);
    $operation->forceFill(['started_at' => now()])->save();
    expect(fn () => $operations->start($operation->id, 'beta'))->toThrow(InstallationException::class);
    $operations->start($operation->id, 'alpha');
    expect($operation->fresh()->status)->toBe('preparing');
    $operations->stage('installing');
    $operations->stage('installing');
    expect($operation->fresh()->status)->toBe('installing');
});

it('does not require an operation for ordinary installation stages', function (): void {
    app(InstallationOperations::class)->stage('installing');
    expect(ModuleInstallation::query()->count())->toBe(0);
});

it('resets operation context between artisan invocations', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operations->claim($operation->id);
    $operations->start($operation->id, 'alpha');
    $operations->reset();
    $operations->stage('installing');

    expect($operation->fresh()->status)->toBe('preparing');
});

it('tracks successful idempotent child commands and resets their shared context', function (): void {
    $this->mock(ModuleManager::class)->shouldReceive('install')->with('alpha')->once()->andReturn(false);
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operations->claim($operation->id);

    $this->artisan('module:install', ['key' => 'alpha', '--operation' => $operation->id])->assertSuccessful();
    expect($operation->fresh()->status)->toBe('installing')
        ->and($operations->complete($operation->id))->toBeTrue();
    // A leaked context would throw here because this operation is terminal.
    $operations->stage('installing');
    expect($operation->fresh()->status)->toBe('completed');
});

it('dispatches only after the operation transaction commits and skips rolled back work', function (): void {
    $operations = app(InstallationOperations::class);
    DB::beginTransaction();
    $operations->schedule('alpha');
    Queue::assertNothingPushed();
    DB::rollBack();
    Queue::assertNothingPushed();
    expect(ModuleInstallation::query()->count())->toBe(0);

    DB::transaction(function () use ($operations): void {
        $operations->schedule('alpha');
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(InstallModule::class, 1);
});

it('redacts queue dispatch errors that happen after transaction commit', function (): void {
    Queue::shouldReceive('connection')->with('installations')->andThrow(new RuntimeException('password=private-token'));
    $operations = app(InstallationOperations::class);

    expect(fn () => DB::transaction(fn () => $operations->schedule('alpha')))
        ->toThrow(InstallationException::class, 'could not be queued');
    expect(ModuleInstallation::query()->sole()->status)->toBe('failed');
});

it('redacts dispatch failures while keeping their durable failed record', function (): void {
    Queue::shouldReceive('connection')->with('installations')->andThrow(new RuntimeException('password=private-token'));

    expect(fn () => app(InstallationOperations::class)->schedule('alpha'))->toThrow(InstallationException::class, 'could not be queued');
    $operation = ModuleInstallation::query()->sole();
    expect($operation->status)->toBe('failed')
        ->and($operation->error)->not->toContain('private-token')
        ->and($operation->active_key)->toBeNull();
});

it('does not let late worker failure overwrite a completed operation', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operations->claim($operation->id);
    $operations->start($operation->id, 'alpha');
    $operations->stage('installing');
    $operations->complete($operation->id);
    (new InstallModule($operation->id))->failed(new RuntimeException('late failure'));

    expect($operation->fresh()->status)->toBe('completed')->and($operation->fresh()->error)->toBeNull();
});

it('launches a fresh artisan process with literal options and shared durable status', function (?string $sourceOption): void {
    $root = $this->tempDirectory('installation-child');
    $previousConnection = config('database.default');
    touch($root.'/operations.sqlite');
    File::copy(dirname(__DIR__).'/Fixtures/installation/artisan.php', $root.'/artisan');
    config()->set('database.connections.operation_child', ['driver' => 'sqlite', 'database' => $root.'/operations.sqlite']);
    config()->set('database.default', 'operation_child');

    try {
        (require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000003_create_module_installations_table.php')->up();
        Schema::create('child_observations', function (Blueprint $table): void {
            $table->text('arguments');
            $table->integer('process_id');
        });

        $source = $root.'/trusted source; literal.zip';
        $operations = app(InstallationOperations::class);
        $operation = $operations->schedule('alpha', from: $sourceOption === 'from' ? $source : null, zip: $sourceOption === 'zip' ? $source : null, offline: true);
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('basePath')->with('artisan')->andReturn($root.'/artisan');
        $application->shouldReceive('basePath')->withNoArgs()->andReturn($root);
        $process = new InstallationProcess($application, config());
        (new InstallModule($operation->id))->handle($operations, $process);

        expect($operation->fresh()->status)->toBe('completed')->and($operation->fresh()->error)->toBeNull();
        $observation = DB::table('child_observations')->sole();
        $arguments = json_decode($observation->arguments, true, flags: JSON_THROW_ON_ERROR);
        expect($observation->process_id)->not->toBe(getmypid())
            ->and($arguments)->toContain('module:install', 'alpha', '--operation='.$operation->id, '--offline');

        if ($sourceOption !== null) {
            expect($arguments)->toContain('--'.$sourceOption.'='.$source);
        }
    } finally {
        config()->set('database.default', $previousConnection);
        DB::purge('operation_child');
        File::deleteDirectory($root);
    }
})->with([null, 'from', 'zip']);

it('launches only one child per operation and completes its durable status', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha', zip: '/trusted/alpha.zip', offline: true);
    $this->mock(InstallationProcess::class)->shouldReceive('run')->once()->withArgs(function (ModuleInstallation $child) use ($operations, $operation): bool {
        expect($child->id)->toBe($operation->id)->and($child->started_at)->not->toBeNull();
        $operations->start($child->id, 'alpha');
        $operations->stage('installing');

        return true;
    })->andReturn(0);

    $job = new InstallModule($operation->id);
    $job->handle($operations, app(InstallationProcess::class));
    $job->handle($operations, app(InstallationProcess::class));

    expect($operation->fresh()->status)->toBe('completed')
        ->and($operation->fresh()->finished_at)->not->toBeNull()
        ->and($operation->fresh()->active_key)->toBeNull();
});

it('redacts child failures and never reruns migrations after failed delivery', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $this->mock(InstallationProcess::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('password=private-token /secret/host'));
    $job = new InstallModule($operation->id);
    $job->handle($operations, app(InstallationProcess::class));
    $job->handle($operations, app(InstallationProcess::class));

    expect($operation->fresh()->status)->toBe('failed')
        ->and($operation->fresh()->error)->not->toContain('private-token', '/secret/host')
        ->and($operation->fresh()->finished_at)->not->toBeNull();
});

it('marks nonzero exit codes and missing child stage tracking as failed', function (int $exit): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $this->mock(InstallationProcess::class)->shouldReceive('run')->once()->andReturn($exit);
    (new InstallModule($operation->id))->handle($operations, app(InstallationProcess::class));

    expect($operation->fresh()->status)->toBe('failed');
})->with([0, 1]);

it('records worker timeout failures without leaking the queue exception', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operations->claim($operation->id);
    $operations->start($operation->id, 'alpha');
    $operations->stage('installing');
    (new InstallModule($operation->id))->failed(new RuntimeException('password=private-token'));

    expect($operation->fresh()->status)->toBe('failed')
        ->and($operation->fresh()->error)->not->toContain('private-token')
        ->and($operations->claim($operation->id))->toBeNull();
});

it('recovers expired claimed operations without launching another child or replaying migrations', function (string $status): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operation->forceFill(['started_at' => now()->subHours(2), 'status' => $status])->save();
    $this->mock(InstallationProcess::class)->shouldNotReceive('run');

    $this->artisan('module:recover', ['operation' => $operation->id])->assertSuccessful();
    (new InstallModule($operation->id))->handle($operations, app(InstallationProcess::class));

    expect($operation->fresh()->status)->toBe('failed')
        ->and($operation->fresh()->active_key)->toBeNull()
        ->and($operation->fresh()->finished_at)->not->toBeNull();
    expect($operations->schedule('alpha')->id)->not->toBe($operation->id);
})->with(['queued', 'preparing', 'installing']);

it('recovers expired undelivered operations but leaves recent and terminal records alone', function (): void {
    $operations = app(InstallationOperations::class);
    $stale = $operations->schedule('alpha');
    $stale->forceFill(['created_at' => now()->subHours(2)])->save();
    $recent = $operations->schedule('beta');
    $terminal = $operations->schedule('gamma');
    $operations->fail($terminal->id);

    expect($operations->recover())->toBe(1)
        ->and($stale->fresh()->status)->toBe('failed')
        ->and($recent->fresh()->status)->toBe('queued')
        ->and($terminal->fresh()->status)->toBe('failed')
        ->and($operations->recover())->toBe(0);
});

it('does not expire a worker within its configured execution and lock windows', function (): void {
    config()->set('bedrock.modules.operations.stale_after', 1);
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operation->forceFill(['started_at' => now()->subMinutes(10), 'status' => 'installing'])->save();
    expect($operations->recover($operation->id))->toBe(0)
        ->and($operation->fresh()->active_key)->toBe('alpha');
});

it('prevents recovered operations from starting or completing after late worker delivery', function (): void {
    $operations = app(InstallationOperations::class);
    $operation = $operations->schedule('alpha');
    $operation->forceFill(['started_at' => now()->subHours(2)])->save();
    expect($operations->recover($operation->id))->toBe(1);
    expect(fn () => $operations->start($operation->id, 'alpha'))->toThrow(InstallationException::class)
        ->and($operations->complete($operation->id))->toBeFalse()
        ->and($operations->claim($operation->id))->toBeNull();
});
