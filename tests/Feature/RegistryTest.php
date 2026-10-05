<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\ModuleNotFoundException;
use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Repositories\Module\EloquentModuleRepository;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates the modules table before the test', function () {
    expect(Schema::hasTable('modules'))->toBeTrue();
});

it('persists and reads a module', function () {
    $this->seedModule('alpha');

    $found = app(ModuleRepository::class)->find('alpha');

    expect($found)->not->toBeNull()
        ->and($found?->key)->toBe('alpha')
        ->and($found?->status)->toBe(ModuleStatus::Installed)
        ->and($found?->manifest)->toBe(['key' => 'alpha'])
        ->and($found?->installed_at)->not->toBeNull();
});

it('casts the status to an enum', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Enabled);
});

it('enforces unique module keys at the database level', function () {
    $this->seedModule('alpha');

    Module::query()->insert([
        'key' => 'alpha',
        'name' => 'Duplicate',
        'version' => '2.0.0',
        'provider' => 'Some\\Provider',
        'path' => module_path('alpha'),
        'status' => ModuleStatus::Installed->value,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('filters modules by status', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);
    $this->seedModule('beta', ['status' => ModuleStatus::Disabled]);

    $enabled = app(ModuleRepository::class)->byStatus(ModuleStatus::Enabled);

    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()?->key)->toBe('alpha');
});

it('deletes a module from the registry', function () {
    $this->seedModule('alpha');

    $deleted = app(ModuleRepository::class)->delete('alpha');

    expect($deleted)->toBeTrue()
        ->and(app(ModuleRepository::class)->find('alpha'))->toBeNull();
});

it('returns the module or fails', function () {
    app(ModuleRepository::class)->findOrFail('missing');
})->throws(ModuleNotFoundException::class);

it('is backed by eloquent in a configured environment', function () {
    expect(app(ModuleRepository::class))->toBeInstanceOf(EloquentModuleRepository::class)
        ->and(app(ModuleRepository::class)->isAvailable())->toBeTrue();
});

it('replaces the repository with an in-memory one', function () {
    $this->useArrayRepository([
        'alpha' => [
            'name' => 'Alpha',
            'version' => '1.0.0',
            'provider' => 'Some\\Provider',
            'path' => '/tmp/alpha',
            'status' => ModuleStatus::Enabled,
        ],
    ]);

    expect(app(ModuleRepository::class))->not->toBeInstanceOf(EloquentModuleRepository::class)
        ->and(app(ModuleRepository::class)->all())->toHaveCount(1);
});

it('uses the configured registry table in the migration and model', function () {
    config()->set('bedrock.modules.table', 'custom_modules');

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_create_modules_table.php';
    $migration->up();

    try {
        $this->seedModule('custom');

        expect(Schema::hasTable('custom_modules'))->toBeTrue()
            ->and(Module::query()->where('key', 'custom')->exists())->toBeTrue();
    } finally {
        $migration->down();
        config()->set('bedrock.modules.table', 'modules');
    }
});
