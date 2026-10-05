<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

it('lists the registered modules', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');

    $this->artisan('module:list')
        ->expectsOutputToContain('alpha')
        ->assertSuccessful();
});

it('reports an empty registry', function () {
    $this->artisan('module:list')
        ->expectsOutputToContain('No modules are registered')
        ->assertSuccessful();
});

it('discovers modules from disk', function () {
    $path = sys_get_temp_dir().'/bedrock-cmd-'.uniqid();
    mkdir($path.'/valid', 0755, true);
    file_put_contents($path.'/valid/module.json', json_encode([
        'key' => 'valid',
        'name' => 'Valid',
        'version' => '1.0.0',
        'provider' => 'Some\Provider',
    ]));

    $this->useModulesDirectory($path);

    $this->artisan('module:discover')
        ->expectsOutputToContain('Discovered [valid]')
        ->assertSuccessful();

    expect(app(ModuleRepository::class)->find('valid'))->not->toBeNull();

    @unlink($path.'/valid/module.json');
    @rmdir($path.'/valid');
    @rmdir($path);
});

it('warns and fails when a discovered module is invalid', function () {
    $this->artisan('module:discover')
        ->expectsOutputToContain('broken')
        ->assertFailed();
});

it('syncs the registry', function () {
    $this->artisan('module:sync')->assertFailed();

    expect(app(ModuleRepository::class)->all())->toHaveCount(3);
});

it('enables a module', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');

    $this->artisan('module:enable', ['key' => 'alpha'])
        ->expectsOutputToContain('Enabled the module [alpha]')
        ->assertSuccessful();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Enabled);
});

it('fails to enable a module that is not registered', function () {
    $this->artisan('module:enable', ['key' => 'missing'])->assertFailed();
});

it('fails to enable a module with an unsatisfied dependency', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('beta');

    $this->artisan('module:enable', ['key' => 'beta'])->assertFailed();
});

it('reports an incompatible dependency without enabling the module', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('beta');
    app(ModuleManager::class)->enable('alpha');
    app(ModuleRepository::class)->upsert('alpha', ['version' => '0.9.0']);
    Event::fake([ModuleEnabled::class]);

    $this->artisan('module:enable', ['key' => 'beta'])
        ->expectsOutputToContain('installed version [0.9.0] does not satisfy [>=1.0.0]')
        ->assertFailed();

    expect(app(ModuleRepository::class)->find('beta')?->status)->not->toBe(ModuleStatus::Enabled);
    Event::assertNotDispatched(ModuleEnabled::class);
});

it('disables a module', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->artisan('module:disable', ['key' => 'alpha'])
        ->expectsOutputToContain('Disabled the module [alpha]')
        ->assertSuccessful();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Disabled);
});

it('uninstalls a module', function () {
    $path = $this->tempDirectory('uninstall-command');
    $this->moduleSource($path, 'standalone');
    $this->useModulesDirectory($path);
    app(ModuleManager::class)->install('standalone');

    $this->artisan('module:uninstall', ['key' => 'standalone'])
        ->expectsConfirmation('Uninstall [standalone] and delete its module directory?', 'yes')
        ->expectsOutputToContain('Uninstalled the module [standalone]')
        ->assertSuccessful();

    expect(app(ModuleRepository::class)->find('standalone'))->toBeNull()
        ->and(is_dir($path.'/standalone'))->toBeFalse();

    @rmdir($path);
});

it('asks before purging a module directory', function () {
    $path = sys_get_temp_dir().'/bedrock-cmd-purge-'.uniqid();
    mkdir($path.'/purge-me', 0755, true);
    file_put_contents($path.'/purge-me/module.json', json_encode([
        'key' => 'purge-me',
        'name' => 'Purge Me',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]));

    $this->useModulesDirectory($path);
    app(ModuleManager::class)->sync();

    $this->artisan('module:uninstall', ['key' => 'purge-me'])
        ->expectsConfirmation('Uninstall [purge-me] and delete its module directory?', 'no')
        ->assertSuccessful();

    expect(is_dir($path.'/purge-me'))->toBeTrue();

    @rmdir($path);
});

it('purges a module directory when confirmed', function () {
    $path = sys_get_temp_dir().'/bedrock-cmd-purge-'.uniqid();
    mkdir($path.'/purge-me', 0755, true);
    file_put_contents($path.'/purge-me/module.json', json_encode([
        'key' => 'purge-me',
        'name' => 'Purge Me',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]));

    $this->useModulesDirectory($path);
    app(ModuleManager::class)->sync();

    $this->artisan('module:uninstall', ['key' => 'purge-me'])
        ->expectsConfirmation('Uninstall [purge-me] and delete its module directory?', 'yes')
        ->assertSuccessful();

    expect(is_dir($path.'/purge-me'))->toBeFalse();

    @rmdir($path);
});

it('installs a module that exists on disk', function () {
    $this->artisan('module:install', ['key' => 'alpha'])
        ->expectsOutputToContain('Registered the module [alpha]')
        ->assertSuccessful();
});

it('fails to install a module that does not exist', function () {
    $this->artisan('module:install', ['key' => 'missing'])->assertFailed();
});

it('reports the complete circular dependency when installation is ordered', function () {
    $root = $this->tempDirectory('modules');

    foreach (['alpha' => 'beta', 'beta' => 'alpha'] as $module => $dependency) {
        $path = $this->moduleSource($root, $module).'/module.json';
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['requires'] = [$dependency => '^1.0'];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha'])
        ->expectsOutputToContain('alpha -> beta -> alpha')
        ->assertFailed();

    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed);

    File::deleteDirectory($root);
});

it('rebuilds the registry cache', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    Cache::store('array')->forget('modules.registry');

    $this->artisan('module:cache')
        ->expectsOutputToContain('The module registry cache was rebuilt.')
        ->assertSuccessful();

    expect(Cache::store('array')->has('modules.registry'))->toBeTrue();
});

it('clears the registry cache', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    Cache::store('array')->put('modules.registry', ['stale'], 60);

    $this->artisan('module:clear')
        ->expectsOutputToContain('The module registry cache was cleared.')
        ->assertSuccessful();

    expect(Cache::store('array')->has('modules.registry'))->toBeFalse();
});
