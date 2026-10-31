<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\DataObjects\Module\SyncReport;

interface ModuleManager
{
    /**
     * Discover modules on disk and register the ones that are not registered yet.
     */
    public function sync(): ModuleDiscoveryResult;

    /**
     * Sync the registry and summarise the result for the console.
     */
    public function syncReport(): SyncReport;

    /**
     * Register a module directory that already exists on disk.
     */
    public function install(string $key): bool;

    /** Upgrade deployed code for an inactive installed module. */
    public function upgrade(string $key): bool;

    /**
     * Install a module from a source into the module root and register it.
     */
    public function installFrom(ModuleSource $source, string $key): bool;

    /**
     * Remove a module's code and registry record while retaining its data.
     */
    public function uninstall(string $key): bool;

    /**
     * Activate a registered module.
     */
    public function enable(string $key): bool;

    /**
     * Deactivate a registered module without removing its files or data.
     */
    public function disable(string $key): bool;
}
