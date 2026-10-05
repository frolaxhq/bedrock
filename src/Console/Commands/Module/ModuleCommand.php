<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Shared helpers for the module management commands.
 */
abstract class ModuleCommand extends Command
{
    /**
     * Resolve the module manager.
     */
    protected function manager(): ModuleManager
    {
        /** @var ModuleManager $manager */
        $manager = $this->laravel->make(ModuleManager::class);

        return $manager;
    }

    /**
     * Resolve the module registry.
     */
    protected function registry(): ModuleRegistry
    {
        /** @var ModuleRegistry $registry */
        $registry = $this->laravel->make(ModuleRegistry::class);

        return $registry;
    }

    /**
     * Report a failure and stop the command.
     */
    protected function failure(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
