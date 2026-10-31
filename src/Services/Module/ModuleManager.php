<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Closure;
use Composer\Semver\Comparator;
use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleManager as ModuleManagerContract;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\Contracts\Module\ReverseDependencyResolver;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\DataObjects\Module\SyncReport;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleDisabled;
use Frolax\Bedrock\Events\Module\ModuleDiscovered;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Events\Module\ModuleRegistryChanged;
use Frolax\Bedrock\Events\Module\ModuleUninstalled;
use Frolax\Bedrock\Events\Module\ModuleUpgraded;
use Frolax\Bedrock\Exceptions\Frontend\InvalidFrontendArtifactException;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Exceptions\Module\InvalidLifecycleTransitionException;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Exceptions\Module\ModuleDatabaseException;
use Frolax\Bedrock\Exceptions\Module\ModuleLockException;
use Frolax\Bedrock\Exceptions\Module\ModuleNotFoundException;
use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Models\ModuleInstallation;
use Frolax\Bedrock\Services\Module\Install\DatabaseModuleRunner;
use Frolax\Bedrock\Services\Module\Install\ModuleInstaller;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class ModuleManager implements ModuleManagerContract
{
    public function __construct(
        protected Application $app,
        protected ModuleDiscoverer $discoverer,
        protected ModuleRepository $repository,
        protected ModuleRegistry $registry,
        protected DependencyGuard $guard,
        protected DependencyResolver $resolver,
        protected ReverseDependencyResolver $reverseDependencies,
        protected CoreVersionGuard $core,
        protected ComposerRequirementChecker $composer,
        protected ModuleAutoloader $autoloader,
        protected ModuleOperationLock $locks,
        protected ModuleHealthManager $health,
        protected StateReconciler $reconciler,
        protected ModuleInstaller $installer,
        protected DatabaseModuleRunner $database,
        protected Filesystem $files,
        protected ModuleFeatureHandlers $features,
        protected ModulePermissions $permissions,
    ) {}

    public function sync(): ModuleDiscoveryResult
    {
        return $this->withLocks(['__sync'], function (): ModuleDiscoveryResult {
            $discovery = $this->discoverer->discover();

            foreach ($discovery->modules as $module) {
                if ($this->repository->find($module->key) === null) {
                    $this->register($module);
                    event(new ModuleDiscovered($module->key, $module->manifest));
                }
            }

            $this->reconciler->reconcile($discovery);
            $this->registry->refresh();
            $this->permissions->sync();
            event(new ModuleRegistryChanged);

            return $discovery;
        });
    }

    public function syncReport(): SyncReport
    {
        $discovery = $this->sync();
        $all = $this->repository->all();
        $broken = [];

        foreach ($all as $module) {
            if ($module->hasFailed()) {
                $broken[(string) $module->key] = $module->failure_reason ?? 'The module is unhealthy.';
            }
        }

        return new SyncReport(
            array_map(static fn (DiscoveredModule $module): string => $module->key, $discovery->modules),
            array_values($all->pluck('key')->map(static fn (mixed $key): string => (string) $key)->all()),
            $discovery->failures,
            $broken,
        );
    }

    public function install(string $key): bool
    {
        $discovery = $this->discoverer->discover();
        $module = $discovery->find($key) ?? throw ModuleNotFoundException::for($key);

        try {
            $order = $this->installationOrder($discovery, $key);
        } catch (CircularDependencyException $exception) {
            $this->withLocks([$key], function () use ($module, $exception): void {
                $this->failDiscovered($module, ModuleFailureCode::DependencyCycle, $exception->getMessage(), $exception);
            }, $key);

            throw $exception;
        }

        $keys = array_map(static fn (DiscoveredModule $item): string => $item->key, $order);

        return $this->withLocks($keys, function () use ($order): bool {
            foreach ($order as $orderedModule) {
                $this->installDiscovered($orderedModule);
            }

            $this->registry->refresh();

            return true;
        }, $module->key);
    }

    public function upgrade(string $key): bool
    {
        $keys = array_values($this->repository->all()->pluck('key')->all());
        $keys[] = $key;
        $keys[] = '__sync';

        return $this->withLocks($keys, function () use ($key): bool {
            $registered = $this->repository->findOrFail($key);
            $previousVersion = (string) $registered->version;

            if (! in_array($registered->status, [ModuleStatus::Installed, ModuleStatus::Disabled], true)) {
                throw new InvalidLifecycleTransitionException('Disable the module and its enabled dependents before upgrading; discovered modules must be installed first.');
            }

            if (ModuleInstallation::query()->where('active_key', $key)->exists()) {
                throw new InvalidLifecycleTransitionException('An installation is queued or running for this module. Recover expired operations before upgrading.');
            }

            $path = $this->ownedPath($registered);
            $discovery = $this->discoverer->discover();
            $module = $discovery->find($key) ?? throw new InvalidModuleSourceException($discovery->failures[$key] ?? 'The deployed module manifest is missing.');

            if ($module->path !== $path && realpath($module->path) !== $path) {
                throw new InvalidModuleSourceException('The deployed module path does not match the registered module.');
            }

            if (! Comparator::greaterThan($module->manifest->version, (string) $registered->version)) {
                throw new InvalidLifecycleTransitionException('An upgrade requires a version newer than the registered version.');
            }

            $this->installationOrder($discovery, $key);
            $constraints = $this->app->make(VersionConstraint::class);

            foreach ($this->repository->all() as $dependent) {
                $constraint = $dependent->dependencies[$key] ?? null;

                if ($constraint !== null && $dependent->status === ModuleStatus::Enabled) {
                    throw new InvalidLifecycleTransitionException(sprintf('Disable dependent module [%s] before upgrading.', $dependent->key));
                }

                if (is_string($constraint) && ! $constraints->matches($module->manifest->version, $constraint)) {
                    throw new InvalidLifecycleTransitionException(sprintf('Module [%s] requires [%s %s]; upgrade refused.', $dependent->key, $key, $constraint));
                }
            }

            try {
                $this->core->ensureSatisfied($module->manifest);
                $this->composer->ensureSatisfied($module->manifest);

                if ($this->guard->missing($module->manifest) !== [] || $this->guard->incompatible($module->manifest) !== []) {
                    throw new InvalidModuleSourceException('Upgrade dependencies must already be installed at compatible versions.');
                }

                foreach ($module->manifest->requirements() as $dependency) {
                    $required = $this->repository->findOrFail($dependency);

                    if ($required->status === ModuleStatus::Discovered || $required->hasFailed()) {
                        throw new InvalidModuleSourceException('Upgrade dependencies must already be installed and healthy.');
                    }
                }

                $this->app->make(PhpRuntime::class)->prepare($module->manifest, $module->path, (bool) config('bedrock.modules.php.offline', false));
                $this->autoloader->register($module->manifest, $module->path, true);

                if (! class_exists($module->manifest->provider)) {
                    throw new InvalidModuleSourceException('The upgrade module provider does not exist.');
                }

                $this->features->ensureValid($module->manifest, $module->path);
                $this->features->publish($module->manifest, $module->path);
                $this->database->run($module, false);
                $updated = $this->repository->upsert($key, [
                    'name' => $module->manifest->name,
                    'version' => $module->manifest->version,
                    'provider' => $module->manifest->provider,
                    'path' => $module->path,
                    'manifest' => $module->manifest->toArray(),
                    'dependencies' => $module->manifest->requires,
                ]);
                $this->health->recover($updated);
                $this->registry->refresh();
                $this->permissions->sync($key);
            } catch (Throwable $exception) {
                $this->health->fail($registered, $exception instanceof ModuleDatabaseException ? ModuleFailureCode::DatabaseFailed : ModuleFailureCode::BootstrapFailed, $exception->getMessage(), $exception);
                $this->registry->refresh();

                throw $exception;
            }

            event(new ModuleRegistryChanged);
            event(new ModuleUpgraded($key, $module->manifest, $previousVersion));

            return true;
        }, $key);
    }

    public function installFrom(ModuleSource $source, string $key): bool
    {
        return $this->withLocks(['__source.'.$key], function () use ($source, $key): bool {
            $module = $this->installer->install($source, $key);

            try {
                return $this->install($key);
            } catch (Throwable $exception) {
                if ($this->repository->find($key) === null) {
                    $this->register($module);
                }

                throw $exception;
            }
        }, $key);
    }

    public function uninstall(string $key): bool
    {
        $dependents = $this->reverseDependencies->dependents($key);

        if ($dependents !== []) {
            throw InvalidLifecycleTransitionException::dependents($key, $dependents);
        }

        return $this->withLocks([$key], function () use ($key): bool {
            $module = $this->repository->findOrFail($key);
            $manifest = $module->toManifest();
            $path = $this->ownedPath($module);

            if ($module->status === ModuleStatus::Enabled) {
                $this->disableWithoutLock($module);
            }

            $quarantine = dirname($path).'/.'.$key.'.uninstall.'.bin2hex(random_bytes(8));

            if (! @rename($path, $quarantine)) {
                throw new InvalidModuleSourceException(sprintf('The module directory [%s] could not be quarantined.', $path));
            }

            try {
                $this->features->remove($manifest);

                if (! $this->files->deleteDirectory($quarantine)) {
                    throw new InvalidModuleSourceException(sprintf('The quarantined module directory [%s] could not be removed.', $quarantine));
                }

                $this->repository->delete($key);
                $this->registry->refresh();
                $this->permissions->deactivate($key);
            } catch (Throwable $exception) {
                if ($this->files->isDirectory($quarantine)) {
                    @rename($quarantine, $path);
                }

                throw $exception;
            }

            event(new ModuleUninstalled($key, $manifest));

            return true;
        }, $key);
    }

    public function enable(string $key): bool
    {
        return $this->withLocks([$key], function () use ($key): bool {
            $module = $this->repository->findOrFail($key);

            if ($module->status === ModuleStatus::Enabled && ! $module->hasFailed()) {
                return true;
            }

            if (! in_array($module->status, [ModuleStatus::Installed, ModuleStatus::Disabled], true)) {
                throw InvalidLifecycleTransitionException::enable($key, $module->status);
            }

            if ($module->hasFailed()) {
                throw new InvalidLifecycleTransitionException(sprintf('The module [%s] is unhealthy: %s', $key, $module->failure_reason));
            }

            $manifest = $module->toManifest();
            $this->core->ensureSatisfied($manifest);
            $this->composer->ensureSatisfied($manifest);
            $this->autoloader->register($manifest, (string) $module->path, true);
            $this->guard->ensureSatisfied($manifest);
            $this->features->ensureValid($manifest, (string) $module->path, true);

            $this->repository->upsert($key, [
                'status' => ModuleStatus::Enabled,
                'enabled_at' => $module->enabled_at ?? now(),
                'disabled_at' => null,
            ]);
            $this->registry->refresh();
            event(new ModuleEnabled($key, $manifest));

            return true;
        }, $key);
    }

    public function disable(string $key): bool
    {
        $dependents = $this->reverseDependencies->dependents($key, true);

        if ($dependents !== []) {
            throw InvalidLifecycleTransitionException::dependents($key, $dependents);
        }

        return $this->withLocks([$key], function () use ($key): bool {
            $module = $this->repository->findOrFail($key);

            if ($module->status === ModuleStatus::Disabled) {
                return true;
            }

            if ($module->status !== ModuleStatus::Enabled) {
                throw new InvalidLifecycleTransitionException(sprintf('The module [%s] cannot be disabled from status [%s].', $key, $module->status->value));
            }

            return $this->disableWithoutLock($module);
        }, $key);
    }

    protected function register(DiscoveredModule $module): Module
    {
        return $this->repository->upsert($module->key, [
            'name' => $module->manifest->name,
            'version' => $module->manifest->version,
            'provider' => $module->manifest->provider,
            'path' => $module->path,
            'status' => ModuleStatus::Discovered,
            'health' => ModuleHealth::Healthy,
            'manifest' => $module->manifest->toArray(),
            'dependencies' => $module->manifest->requires,
            'discovered_at' => now(),
        ]);
    }

    /** @return list<DiscoveredModule> */
    protected function installationOrder(ModuleDiscoveryResult $discovery, string $key): array
    {
        $manifests = array_map(static fn (DiscoveredModule $module): Manifest => $module->manifest, $discovery->modules);

        return array_values(array_filter(array_map(
            static fn (string $orderedKey): ?DiscoveredModule => $discovery->find($orderedKey),
            $this->resolver->order($manifests, $key),
        )));
    }

    protected function installDiscovered(DiscoveredModule $module): void
    {
        $registered = $this->repository->find($module->key) ?? $this->register($module);

        if (in_array($registered->status, [ModuleStatus::Installed, ModuleStatus::Enabled, ModuleStatus::Disabled], true)) {
            return;
        }

        $publishingFrontend = false;

        try {
            $this->core->ensureSatisfied($module->manifest);
            $this->composer->ensureSatisfied($module->manifest);
            $this->app->make(PhpRuntime::class)->prepare($module->manifest, $module->path, (bool) config('bedrock.modules.php.offline', false));
            $this->autoloader->register($module->manifest, $module->path, true);

            if (! class_exists($module->manifest->provider)) {
                throw new InvalidModuleSourceException(sprintf('The module provider [%s] does not exist.', $module->manifest->provider));
            }

            $missing = $this->guard->missing($module->manifest);

            if ($missing !== []) {
                throw new InvalidModuleSourceException(sprintf('Required modules are missing: [%s].', implode(', ', $missing)));
            }

            if ($this->guard->incompatible($module->manifest) !== []) {
                throw new InvalidModuleSourceException(sprintf('The module [%s] has incompatible module dependencies.', $module->key));
            }

            $this->features->ensureValid($module->manifest, $module->path);
            $publishingFrontend = true;
            $this->features->publish($module->manifest, $module->path);
            $publishingFrontend = false;
            $this->app->make(InstallationOperations::class)->stage('installing');
            $this->database->run($module);
        } catch (Throwable $exception) {
            $code = match (true) {
                $exception instanceof ModuleDatabaseException => ModuleFailureCode::DatabaseFailed,
                $publishingFrontend => ModuleFailureCode::FrontendPublishFailed,
                $exception instanceof InvalidFrontendArtifactException
                    && str_contains(strtolower($exception->getMessage()), 'does not exist') => ModuleFailureCode::FrontendArtifactMissing,
                $exception instanceof InvalidFrontendArtifactException => ModuleFailureCode::FrontendArtifactInvalid,
                default => $this->installationFailureCode($exception),
            };
            $this->failDiscovered($module, $code, $exception->getMessage(), $exception);

            throw $exception;
        }

        $updated = $this->repository->upsert($module->key, [
            'status' => ModuleStatus::Installed,
            'installed_at' => now(),
        ]);
        $this->health->recover($updated);
        event(new ModuleInstalled($module->key, $module->manifest));
    }

    protected function failDiscovered(DiscoveredModule $module, ModuleFailureCode $code, string $reason, ?Throwable $exception = null): void
    {
        $registered = $this->repository->find($module->key) ?? $this->register($module);
        $this->health->fail($registered, $code, $reason, $exception);
    }

    protected function installationFailureCode(Throwable $exception): ModuleFailureCode
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'composer requirement') && str_contains($message, 'not installed') => ModuleFailureCode::PackageMissing,
            str_contains($message, 'composer requirement') => ModuleFailureCode::PackageIncompatible,
            str_contains($message, 'bedrock') && str_contains($message, 'version') => ModuleFailureCode::CoreIncompatible,
            str_contains($message, 'provider') => ModuleFailureCode::ProviderMissing,
            str_contains($message, 'autoload') => ModuleFailureCode::AutoloadInvalid,
            default => ModuleFailureCode::DependencyIncompatible,
        };
    }

    protected function disableWithoutLock(Module $module): bool
    {
        $this->repository->upsert((string) $module->key, [
            'status' => ModuleStatus::Disabled,
            'disabled_at' => now(),
        ]);
        $this->registry->refresh();
        event(new ModuleDisabled((string) $module->key, $module->toManifest()));

        return true;
    }

    protected function ownedPath(Module $module): string
    {
        $root = realpath(ModulePath::modules($this->app));
        $path = realpath((string) $module->path);

        if ($root === false || $path === false || dirname($path) !== $root || basename($path) !== (string) $module->key) {
            throw new InvalidModuleSourceException(sprintf('Refusing to delete unowned module path [%s].', $module->path));
        }

        return $path;
    }

    /**
     * @template T
     *
     * @param  list<string>  $keys
     * @param  Closure(): T  $operation
     * @return T
     */
    protected function withLocks(array $keys, Closure $operation, ?string $failureKey = null): mixed
    {
        try {
            $result = $this->locks->synchronized($keys, function () use ($operation): mixed {
                app(CompiledModuleBoot::class)->invalidate();

                return $operation();
            });
            $compiled = app(CompiledModuleBoot::class);

            if ($compiled->enabled()) {
                $this->registry->refresh();

                try {
                    $compiled->warm();
                } catch (Throwable $exception) {
                    // The mutation succeeded; an unavailable artifact uses strict boot.
                    report($exception);
                }
            }

            return $result;
        } catch (ModuleLockException $exception) {
            $module = $failureKey === null ? null : $this->repository->find($failureKey);

            if ($module !== null) {
                $this->health->fail($module, ModuleFailureCode::LockUnavailable, $exception->getMessage(), $exception);
            }

            throw $exception;
        }
    }
}
