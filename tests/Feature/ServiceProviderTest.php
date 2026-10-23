<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Module\ReverseDependencyResolver;
use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\Module;
use Frolax\Bedrock\Navigation;
use Frolax\Bedrock\Permissions;
use Frolax\Bedrock\Providers\AdministrationServiceProvider;
use Frolax\Bedrock\Providers\CoreServiceProvider;
use Frolax\Bedrock\Providers\FrontendServiceProvider;
use Frolax\Bedrock\Providers\ModulesServiceProvider;
use Frolax\Bedrock\Providers\NavigationServiceProvider;
use Frolax\Bedrock\Providers\PermissionsServiceProvider;
use Frolax\Bedrock\Providers\SettingsServiceProvider;
use Frolax\Bedrock\Services\Module\DependencyResolver;
use Frolax\Bedrock\Services\Module\Install\DatabaseModuleRunner;
use Frolax\Bedrock\Settings;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('resolves the module gateway as a singleton', function () {
    expect(app(Module::class))->toBeInstanceOf(Module::class)
        ->and(app(Module::class))->toBe(app(Module::class));
});

it('resolves application capability gateways and their lower-level contracts', function () {
    expect(app(Settings::class))->toBe(app(Settings::class))
        ->and(app(Permissions::class))->toBe(app(Permissions::class))
        ->and(app(Navigation::class))->toBe(app(Navigation::class))
        ->and(app(ModuleSettings::class))->toBeInstanceOf(ModuleSettings::class)
        ->and(app(ModulePermissions::class))->toBeInstanceOf(ModulePermissions::class)
        ->and(app(ModuleNavigation::class))->toBeInstanceOf(ModuleNavigation::class);
});

it('resolves the registry and manager contracts', function () {
    expect(app(ModuleRegistry::class))->toBeInstanceOf(ModuleRegistry::class)
        ->and(app(ModuleManager::class))->toBeInstanceOf(ModuleManager::class);
});

it('resolves the module path from configuration', function () {
    expect(app(Module::class)->path())->toBe($this->fixturePath())
        ->and(ModulePath::manifest(app()))->toBe('module.json');
});

it('falls back to the modules directory at the application root', function () {
    config()->set('bedrock.modules.path', 'modules');

    expect(app(Module::class)->path())->toBe(base_path('modules'));
});

it('honours an absolute module path', function () {
    config()->set('bedrock.modules.path', '/srv/modules');

    expect(app(Module::class)->path())->toBe('/srv/modules');
});

it('merges the capability configuration', function () {
    expect(config('bedrock.core.version'))->toBeNull()
        ->and(config('bedrock.modules.manifest'))->toBe('module.json')
        ->and(config('bedrock.modules.table'))->toBe('modules')
        ->and(config('bedrock.modules.bootstrap.enabled'))->toBeTrue()
        ->and(config('bedrock.modules.cache.enabled'))->toBeTrue()
        ->and(config('bedrock.modules.cache.store'))->toBeNull()
        ->and(config('bedrock.modules.cache.key'))->toBe('modules.registry')
        ->and(config('bedrock.modules.cache.ttl'))->toBe(3600)
        ->and(config('bedrock.frontend.enabled'))->toBeTrue()
        ->and(config('bedrock.frontend.client.path'))->toBe('public/vendor/bedrock/modules')
        ->and(config('bedrock.frontend.server.path'))->toBe('storage/framework/bedrock/frontend')
        ->and(config('bedrock.settings.table'))->toBe('module_settings')
        ->and(config('bedrock.permissions.catalog_table'))->toBe('module_permissions')
        ->and(config('bedrock.frontend.panels.user_attribute'))->toBe('type')
        ->and(config('bedrock.frontend.panels.fallback'))->toBe('default')
        ->and(config('bedrock.permissions.guard'))->toBe('web')
        ->and(config('bedrock.administration.enabled'))->toBeTrue();
});

it('publishes only the package configuration', function () {
    $paths = ServiceProvider::pathsToPublish(CoreServiceProvider::class);

    expect($paths)->toBe([
        dirname(__DIR__, 2).'/config/bedrock.php' => config_path('bedrock.php'),
    ]);
});

it('composes the core and module capability providers', function () {
    $providers = app()->getLoadedProviders();

    expect($providers)->toHaveKey(CoreServiceProvider::class)
        ->and($providers)->toHaveKey(ModulesServiceProvider::class)
        ->and($providers)->toHaveKey(FrontendServiceProvider::class)
        ->and($providers)->toHaveKey(SettingsServiceProvider::class)
        ->and($providers)->toHaveKey(PermissionsServiceProvider::class)
        ->and($providers)->toHaveKey(NavigationServiceProvider::class)
        ->and($providers)->toHaveKey(AdministrationServiceProvider::class);
});

it('resolves the module bindings from the capability provider', function () {
    expect(app(ModuleDiscoverer::class))->toBeInstanceOf(ModuleDiscoverer::class)
        ->and(app(ModuleAutoloader::class))->toBeInstanceOf(ModuleAutoloader::class)
        ->and(app(ComposerRequirementChecker::class))->toBeInstanceOf(ComposerRequirementChecker::class)
        ->and(app(ModuleDiagnostics::class))->toBeInstanceOf(ModuleDiagnostics::class)
        ->and(app(ModuleOperationLock::class))->toBeInstanceOf(ModuleOperationLock::class)
        ->and(app(ReverseDependencyResolver::class))->toBeInstanceOf(ReverseDependencyResolver::class)
        ->and(app(ModuleRepository::class))->toBeInstanceOf(ModuleRepository::class)
        ->and(app(DatabaseModuleRunner::class))->toBeInstanceOf(DatabaseModuleRunner::class)
        ->and(app(DependencyResolver::class))->toBe(app(DependencyResolver::class));
});

it('runs the modules migration exactly once', function () {
    $executed = DB::table('migrations')->pluck('migration');

    expect($executed->filter(fn (string $name): bool => str_contains($name, 'create_modules_table'))->count())->toBe(1)
        ->and(Schema::hasTable('modules'))->toBeTrue();
});

it('registers the module commands', function () {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('module:discover')
        ->and($commands)->toContain('module:list')
        ->and($commands)->toContain('module:sync')
        ->and($commands)->toContain('module:install')
        ->and($commands)->toContain('module:uninstall')
        ->and($commands)->toContain('module:enable')
        ->and($commands)->toContain('module:disable')
        ->and($commands)->toContain('module:doctor')
        ->and($commands)->toContain('module:make')
        ->and($commands)->toContain('module:validate')
        ->and($commands)->toContain('module:pack')
        ->and($commands)->toContain('frontend:install')
        ->and($commands)->not->toContain('bedrock:frontend-install')
        ->and($commands)->toContain('bedrock:admin-install')
        ->and($commands)->toContain('bedrock:admin-user')
        ->and($commands)->toContain('bedrock:permissions-sync')
        ->and($commands)->toContain('module:cache')
        ->and($commands)->toContain('module:clear');
});

it('creates the modules table through its migration', function () {
    expect(Schema::hasTable('modules'))->toBeTrue();

    $names = array_map(static fn (array $column): string => $column['name'], Schema::getColumns('modules'));

    expect($names)->toContain('key')->toContain('status')->toContain('health')
        ->toContain('failure_code')->toContain('failure_reason')->toContain('manifest')
        ->toContain('dependencies')->toContain('discovered_at')->toContain('installed_at')
        ->toContain('enabled_at')->toContain('disabled_at')->toContain('failed_at');
});

it('creates the capability-owned settings and permission catalog tables', function (): void {
    expect(Schema::hasTable('module_settings'))->toBeTrue()
        ->and(Schema::hasTable('module_permissions'))->toBeTrue();
});
