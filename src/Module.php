<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\DataObjects\Module\DiagnosticReport;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\Models\Module as ModuleModel;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;

/**
 * The public gateway to Bedrock's module capability.
 */
class Module
{
    public function __construct(
        protected Application $app,
        protected ModuleRegistry $registry,
        protected ModuleManager $manager,
        protected ModuleDiagnostics $diagnostics,
    ) {}

    /**
     * Every registered module, ordered by key.
     *
     * @return Collection<int, ModuleModel>
     */
    public function all(): Collection
    {
        return $this->registry->all();
    }

    /**
     * Every enabled module, ordered by key.
     *
     * @return Collection<int, ModuleModel>
     */
    public function enabled(): Collection
    {
        return $this->registry->enabled();
    }

    /**
     * Find a registered module by key.
     */
    public function find(string $key): ?ModuleModel
    {
        return $this->registry->find($key);
    }

    /**
     * Determine whether a module is enabled.
     */
    public function isEnabled(string $key): bool
    {
        return $this->registry->isEnabled($key);
    }

    /**
     * Discover modules and reconcile the registry.
     */
    public function sync(): ModuleDiscoveryResult
    {
        return $this->manager->sync();
    }

    /**
     * Register a module that already exists in the module root.
     */
    public function install(string $key): bool
    {
        return $this->manager->install($key);
    }

    /**
     * Install a module from a source into the module root.
     */
    public function installFrom(ModuleSource $source, string $key): bool
    {
        return $this->manager->installFrom($source, $key);
    }

    /**
     * Remove a module from the registry and optionally delete its files.
     */
    public function uninstall(string $key): bool
    {
        return $this->manager->uninstall($key);
    }

    /** Diagnose the module foundation or one module. */
    public function diagnose(?string $key = null): DiagnosticReport
    {
        return $this->diagnostics->diagnose($key);
    }

    /**
     * Enable a registered module.
     */
    public function enable(string $key): bool
    {
        return $this->manager->enable($key);
    }

    /**
     * Disable a registered module.
     */
    public function disable(string $key): bool
    {
        return $this->manager->disable($key);
    }

    /**
     * Refresh the cached module registry.
     */
    public function refresh(): void
    {
        $this->registry->refresh();
    }

    /**
     * The absolute path to the module root.
     */
    public function path(): string
    {
        return ModulePath::modules($this->app);
    }
}
