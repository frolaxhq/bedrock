<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleFailed;
use Frolax\Bedrock\Exceptions\Module\ModuleDatabaseException;
use Frolax\Bedrock\Services\Module\Install\DatabaseModuleRunner;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Frolax\Bedrock\Tests\Fixtures\Seeders\FailingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('runs the migrations and seeders a module declares', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'seededmod');

    $this->moduleMigration($module, 'module_items');
    $this->moduleManifest($module, 'seededmod', [
        'migrations' => true,
        'seeders' => true,
        'seeder' => 'Frolax\\Bedrock\\Tests\\Fixtures\\Seeders\\ItemsSeeder',
    ]);

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'seededmod');

    expect(Schema::hasTable('module_items'))->toBeTrue()
        ->and(DB::table('module_items')->count())->toBe(1)
        ->and(app(ModuleRepository::class)->find('seededmod')?->status)->toBe(ModuleStatus::Installed);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('does not run migrations a module does not declare', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'quietmod');

    $this->moduleMigration($module, 'quietmod_items');
    $this->moduleManifest($module, 'quietmod', ['migrations' => false, 'seeders' => false]);

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'quietmod');

    expect(Schema::hasTable('quietmod_items'))->toBeFalse()
        ->and(app(ModuleRepository::class)->find('quietmod')?->status)->toBe(ModuleStatus::Installed);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('runs no seeder when a module declares seeders without naming one', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'anonymousseeder');

    $this->moduleMigration($module, 'anonymousseeder_items');
    $this->moduleManifest($module, 'anonymousseeder', ['migrations' => true, 'seeders' => true]);

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'anonymousseeder');

    expect(Schema::hasTable('anonymousseeder_items'))->toBeTrue()
        ->and(DB::table('anonymousseeder_items')->count())->toBe(0);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('runs the database resources of a module directory it registers', function () {
    expect(Schema::hasTable('alpha_items'))->toBeFalse();

    $this->artisan('module:install', ['key' => 'alpha'])
        ->expectsOutputToContain('Registered the module [alpha]')
        ->assertSuccessful();

    expect(Schema::hasTable('alpha_items'))->toBeTrue()
        ->and(DB::table('alpha_items')->count())->toBe(1);
});

it('marks the module failed when a migration fails', function () {
    Event::fake([ModuleFailed::class]);

    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'brokenmigration');

    $this->failingModuleMigration($module, 'brokenmigration_items');
    $this->moduleManifest($module, 'brokenmigration', ['migrations' => true, 'seeders' => false]);

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'brokenmigration');
    } catch (ModuleDatabaseException) {
        // The assertion is about the state the failure leaves behind.
    }

    $registered = app(ModuleRepository::class)->find('brokenmigration');

    expect($registered?->status)->toBe(ModuleStatus::Discovered)
        ->and($registered?->health)->toBe(ModuleHealth::Failed)
        ->and($registered?->version)->toBe('1.0.0')
        ->and(is_dir($root.'/brokenmigration'))->toBeTrue();

    Event::assertDispatched(
        ModuleFailed::class,
        fn (ModuleFailed $event): bool => $event->key === 'brokenmigration'
            && $event->exception instanceof ModuleDatabaseException,
    );

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('fails the install command when a migration fails', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'brokenmigration');

    $this->failingModuleMigration($module, 'brokenmigration_items');
    $this->moduleManifest($module, 'brokenmigration', ['migrations' => true, 'seeders' => false]);

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'brokenmigration', '--from' => $module])
        ->expectsOutputToContain('The migrations for the module [brokenmigration]')
        ->assertFailed();

    expect(app(ModuleRepository::class)->find('brokenmigration')?->health)->toBe(ModuleHealth::Failed);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('marks the module failed when a seeder fails', function () {
    Event::fake([ModuleFailed::class]);

    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'brokenseeder');

    $this->moduleManifest($module, 'brokenseeder', [
        'migrations' => false,
        'seeders' => true,
        'seeder' => FailingSeeder::class,
    ]);

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'brokenseeder');
    } catch (ModuleDatabaseException) {
        // The assertion is about the state the failure leaves behind.
    }

    expect(app(ModuleRepository::class)->find('brokenseeder')?->health)->toBe(ModuleHealth::Failed);

    Event::assertDispatched(
        ModuleFailed::class,
        fn (ModuleFailed $event): bool => $event->key === 'brokenseeder'
            && $event->reason === sprintf('The seeder [%s] for the module [brokenseeder] failed to run.', FailingSeeder::class),
    );

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('installs a module that declares no database resources', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'nodb');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'nodb');

    expect(app(ModuleRepository::class)->find('nodb')?->status)->toBe(ModuleStatus::Installed);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('retains module database data when code is uninstalled', function () {
    $root = $this->tempDirectory('modules');
    $module = $this->moduleSource($root, 'retained');
    $this->moduleMigration($module, 'retained_items');
    $this->moduleManifest($module, 'retained', ['migrations' => true, 'seeders' => false]);
    $this->useModulesDirectory($root);

    app(ModuleManager::class)->install('retained');
    DB::table('retained_items')->insert(['name' => 'keep me']);
    app(ModuleManager::class)->uninstall('retained');

    expect(is_dir($root.'/retained'))->toBeFalse()
        ->and(Schema::hasTable('retained_items'))->toBeTrue()
        ->and(DB::table('retained_items')->value('name'))->toBe('keep me');

    File::deleteDirectory($root);
});

it('resolves the database runner from the container', function () {
    expect(app(DatabaseModuleRunner::class))->toBeInstanceOf(DatabaseModuleRunner::class);
});

it('keeps the database block of a manifest as it was written', function () {
    $database = [
        'migrations' => true,
        'seeders' => true,
        'seeder' => 'Modules\\Example\\Database\\Seeders\\ExampleSeeder',
        'unknown' => ['nested' => true],
    ];

    $root = $this->tempDirectory('manifests');
    $path = $this->moduleSource($root, 'shaped');

    $this->moduleManifest($path, 'shaped', $database);

    expect($this->loader()->load($path.'/module.json')->database)->toBe($database);

    File::deleteDirectory($root);
});
