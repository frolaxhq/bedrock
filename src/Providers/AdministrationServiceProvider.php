<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Console\Commands\Administration\AdminInstallCommand;
use Frolax\Bedrock\Console\Commands\Administration\AdminUserCommand;
use Frolax\Bedrock\Console\Commands\Permissions\PermissionsSyncCommand;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdministrationManager::class, function (Application $app): AdministrationManager {
            return new AdministrationManager(
                $app->make(ModulePermissions::class),
                (array) $app->make('config')->get('bedrock.permissions', []),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminInstallCommand::class,
                AdminUserCommand::class,
                PermissionsSyncCommand::class,
            ]);
        }
    }
}
