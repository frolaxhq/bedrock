<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Console\Commands\Module\ModuleCacheCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleClearCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleDisableCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleDiscoverCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleDoctorCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleEnableCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleInstallCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleListCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleMakeCommand;
use Frolax\Bedrock\Console\Commands\Module\ModulePackCommand;
use Frolax\Bedrock\Console\Commands\Module\ModulePrepareCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleRecoverCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleSyncCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleUninstallCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleUpgradeCommand;
use Frolax\Bedrock\Console\Commands\Module\ModuleValidateCommand;
use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleFrontendScaffold;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Module\ReverseDependencyResolver;
use Frolax\Bedrock\Module;
use Frolax\Bedrock\Repositories\Module\EloquentModuleRepository;
use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Services\Module\Authoring\ModuleScaffolder;
use Frolax\Bedrock\Services\Module\Authoring\ModuleSourceValidator;
use Frolax\Bedrock\Services\Module\CachedModuleRegistry;
use Frolax\Bedrock\Services\Module\CacheModuleOperationLock;
use Frolax\Bedrock\Services\Module\CompiledModuleBoot;
use Frolax\Bedrock\Services\Module\ComposerModuleAutoloader;
use Frolax\Bedrock\Services\Module\DependencyResolver;
use Frolax\Bedrock\Services\Module\DirectoryModuleDiscoverer;
use Frolax\Bedrock\Services\Module\Install\DatabaseModuleRunner;
use Frolax\Bedrock\Services\Module\Install\ModuleInstaller;
use Frolax\Bedrock\Services\Module\Install\ModulePackageValidator;
use Frolax\Bedrock\Services\Module\InstalledComposerRequirementChecker;
use Frolax\Bedrock\Services\Module\ModuleBootstrapper;
use Frolax\Bedrock\Services\Module\ModuleDoctor;
use Frolax\Bedrock\Services\Module\ModuleFeatureHandlers;
use Frolax\Bedrock\Services\Module\ModuleHealthManager;
use Frolax\Bedrock\Services\Module\ModuleManager as ModuleManagerImplementation;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Frolax\Bedrock\Services\Module\RegisteredReverseDependencyResolver;
use Frolax\Bedrock\Services\Module\VersionConstraint;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers and boots Bedrock's module capability.
 */
class ModulesServiceProvider extends ServiceProvider
{
    /**
     * The commands owned by the module capability.
     *
     * @var list<class-string>
     */
    protected array $commands = [
        ModuleRecoverCommand::class,
        ModuleUpgradeCommand::class,
        ModuleCacheCommand::class,
        ModuleClearCommand::class,
        ModuleDisableCommand::class,
        ModuleDoctorCommand::class,
        ModuleDiscoverCommand::class,
        ModuleEnableCommand::class,
        ModuleInstallCommand::class,
        ModuleListCommand::class,
        ModuleMakeCommand::class,
        ModulePackCommand::class,
        ModulePrepareCommand::class,
        ModuleSyncCommand::class,
        ModuleUninstallCommand::class,
        ModuleValidateCommand::class,
    ];

    /**
     * Register module services and the public capability gateway.
     */
    public function register(): void
    {
        $this->app->scoped(CompiledModuleBoot::class);
        $this->app->singleton(ModuleRepository::class, EloquentModuleRepository::class);
        $this->app->singleton(VersionConstraint::class);
        $this->app->singleton(DependencyResolver::class);
        $this->app->singleton(ModuleManifest::class);
        $this->app->singleton(ModuleAutoloader::class, ComposerModuleAutoloader::class);
        $this->app->singleton(ComposerRequirementChecker::class, InstalledComposerRequirementChecker::class);
        $this->app->singleton(ReverseDependencyResolver::class, RegisteredReverseDependencyResolver::class);
        $this->app->singleton(ModuleDiagnostics::class, ModuleDoctor::class);
        $this->app->singleton(ModuleHealthManager::class);
        $this->app->singleton(ModuleFeatureHandlers::class);
        $this->app->singleton(InstallationOperations::class);

        $this->app->singleton(ModuleOperationLock::class, function (Application $app): CacheModuleOperationLock {
            return new CacheModuleOperationLock(
                $app->make('cache'),
                (array) $app->make('config')->get('bedrock.modules.locks', []),
            );
        });

        $this->app->singleton(ModuleDiscoverer::class, function (Application $app): DirectoryModuleDiscoverer {
            return new DirectoryModuleDiscoverer(
                $app->make('files'),
                $app->make(ModuleManifest::class),
                ModulePath::manifest($app),
                [ModulePath::modules($app)],
            );
        });

        $this->app->singleton(ModuleRegistry::class, function (Application $app): CachedModuleRegistry {
            return new CachedModuleRegistry(
                $app->make(ModuleRepository::class),
                $app->make('cache'),
                (array) $app->make('config')->get('bedrock.modules.cache', []),
            );
        });

        $this->app->singleton(ModuleManager::class, ModuleManagerImplementation::class);

        $this->app->singleton(ModulePackageValidator::class, function (Application $app): ModulePackageValidator {
            return new ModulePackageValidator((array) $app->make('config')->get('bedrock.modules.install', []));
        });

        $this->app->bind(ModuleInstaller::class, function (Application $app): ModuleInstaller {
            return new ModuleInstaller(
                $app->make('files'),
                $app->make(ModuleManifest::class),
                $app->make(ModuleAutoloader::class),
                $app->make(ModulePackageValidator::class),
                ModulePath::manifest($app),
                ModulePath::modules($app),
            );
        });

        $this->app->bind(ModuleScaffolder::class, function (Application $app): ModuleScaffolder {
            return new ModuleScaffolder($app->make('files'), ModulePath::modules($app),
                $app->bound(ModuleFrontendScaffold::class) ? $app->make(ModuleFrontendScaffold::class) : null);
        });

        $this->app->bind(ModuleSourceValidator::class, function (Application $app): ModuleSourceValidator {
            return new ModuleSourceValidator(
                $app->make(ModulePackageValidator::class),
                $app->make(ModuleManifest::class),
                $app->make(ModuleAutoloader::class),
                $app->make(ModuleFeatureHandlers::class),
                ModulePath::manifest($app),
            );
        });

        $this->app->bind(ModulePackager::class, function (Application $app): ModulePackager {
            return new ModulePackager(
                $app->make('files'),
                $app->make(ModuleSourceValidator::class),
                (array) $app->make('config')->get('bedrock.modules.install', []),
                ModulePath::manifest($app),
            );
        });

        $this->app->bind(DatabaseModuleRunner::class, function (Application $app): DatabaseModuleRunner {
            return new DatabaseModuleRunner(
                $app->make(Kernel::class),
                $app->make('files'),
            );
        });

        $this->app->singleton(Module::class);
    }

    /**
     * Register module resources and boot enabled modules.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
            $release = $this->app->make('config')->get('bedrock.modules.php.validation.release');
            $warm = $this->app->isProduction()
                && (bool) $this->app->make('config')->get('bedrock.modules.php.validation.cache', false)
                && is_string($release) && trim($release) !== '';
            $this->optimizes(
                optimize: $warm ? 'module:cache --warm' : 'module:cache',
                clear: 'module:clear',
                key: 'bedrock-modules',
            );
        }

        if (! (bool) $this->app->make('config')->get('bedrock.modules.bootstrap.enabled', true)) {
            return;
        }

        $this->app->make(ModuleBootstrapper::class)->bootstrap();
    }
}
