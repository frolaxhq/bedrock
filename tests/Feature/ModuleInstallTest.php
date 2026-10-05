<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Exceptions\Module\ModuleAlreadyInstalledException;
use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Frolax\Bedrock\Services\Module\Install\ModuleInstaller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

it('copies a module from a source into the module root', function () {
    Event::fake([ModuleInstalled::class]);

    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');

    $registered = app(ModuleRepository::class)->find('alpha');

    expect($registered)->toBeInstanceOf(Module::class)
        ->and($registered?->status)->toBe(ModuleStatus::Installed)
        ->and($registered?->path)->toBe($root.'/alpha')
        ->and(is_file($root.'/alpha/module.json'))->toBeTrue()
        ->and(is_file($root.'/alpha/resources/views/index.blade.php'))->toBeTrue();

    Event::assertDispatched(ModuleInstalled::class, fn (ModuleInstalled $event): bool => $event->key === 'alpha');

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('leaves the source directory in place after installing', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');

    expect(is_file($module.'/module.json'))->toBeTrue()
        ->and(is_file($module.'/resources/views/index.blade.php'))->toBeTrue()
        ->and($module)->not->toBe($root.'/alpha');

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('refuses to install a module that already occupies the module root', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    $manager = app(ModuleManager::class);
    $manager->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');
    $manager->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');
})->throws(ModuleAlreadyInstalledException::class);

it('refuses to install from a directory that holds no module', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $source), 'alpha');
})->throws(InvalidModuleSourceException::class, 'is not a directory that contains a module manifest');

it('refuses to install a module under a key it does not declare', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'beta');
})->throws(InvalidModuleSourceException::class, 'holds the module [alpha], but [beta] was requested');

it('does not leave a partial module behind when the source is invalid', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    file_put_contents($module.'/module.json', '{"key": "alpha",');

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');
    } catch (Throwable) {
        // The install is expected to fail; the assertion is about the leftovers.
    }

    expect(is_dir($root.'/alpha'))->toBeFalse()
        ->and(app(ModuleRepository::class)->find('alpha'))->toBeNull();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('installs the module through the installer service', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    $installed = app(ModuleInstaller::class)->install(new LocalDirectorySource(app('files'), $module), 'alpha');

    expect($installed->key)->toBe('alpha')
        ->and($installed->path)->toBe($root.'/alpha')
        ->and($installed->manifest->name)->toBe('Alpha Module');

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('installs a module from a directory through the command', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha', '--from' => $module])
        ->expectsOutputToContain('Installed the module [alpha]')
        ->assertSuccessful();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Installed)
        ->and(is_file($root.'/alpha/module.json'))->toBeTrue();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('fails the install command when the source holds no module', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha', '--from' => $source])->assertFailed();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('fails the install command when the source key does not match', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'beta', '--from' => $module])
        ->expectsOutputToContain('holds the module [alpha], but [beta] was requested')
        ->assertFailed();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('fails the install command when the from option is empty', function () {
    $root = $this->tempDirectory('modules');

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha', '--from' => ''])
        ->expectsOutputToContain('The --from option needs a directory to install from.')
        ->assertFailed();

    File::deleteDirectory($root);
});

it('installs a copied module that is registered but not yet enabled', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Installed);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('installs available local dependencies before a copied module', function () {
    Event::fake([ModuleInstalled::class]);

    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');

    $this->moduleSource($root, 'foundation');
    $module = $this->moduleSource($source, 'feature');

    $manifest = json_decode(file_get_contents($module.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['requires'] = ['foundation' => '^1.0'];
    file_put_contents($module.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'feature');

    expect(app(ModuleRepository::class)->find('foundation')?->status)->toBe(ModuleStatus::Installed)
        ->and(app(ModuleRepository::class)->find('feature')?->status)->toBe(ModuleStatus::Installed)
        ->and(Event::dispatched(ModuleInstalled::class)
            ->map(static fn (array $events): string => $events[0]->key)
            ->all())->toBe(['foundation', 'feature']);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('keeps an incompatible local module installed for repair', function () {
    $root = $this->tempDirectory('modules');
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'dependent');

    $manifest = json_decode(file_get_contents($module.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['requires'] = ['missing' => '^2.0'];
    file_put_contents($module.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'dependent');
    } catch (InvalidModuleSourceException $exception) {
        expect($exception->getMessage())->toContain('Required modules are missing');
    }

    expect(app(ModuleRepository::class)->find('dependent')?->status)->toBe(ModuleStatus::Discovered)
        ->and(app(ModuleRepository::class)->find('dependent')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('dependent')?->installed_at)->toBeNull()
        ->and(is_file($root.'/dependent/module.json'))->toBeTrue();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('creates the module root when it does not exist yet', function () {
    $root = sys_get_temp_dir().'/bedrock-missing-'.uniqid().'/modules';
    $source = $this->tempDirectory('source');
    $module = $this->moduleSource($source, 'alpha');

    $this->useModulesDirectory($root);

    expect(is_dir($root))->toBeFalse();

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'alpha');

    expect(is_dir($root))->toBeTrue()
        ->and(is_file($root.'/alpha/module.json'))->toBeTrue();

    File::deleteDirectory(dirname($root));
    File::deleteDirectory($source);
});
