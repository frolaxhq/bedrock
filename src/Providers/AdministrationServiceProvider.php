<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Console\Commands\Administration\AdminInstallCommand;
use Frolax\Bedrock\Console\Commands\Administration\AdminUserCommand;
use Frolax\Bedrock\Services\Administration\AdminPageRegistry;
use Illuminate\Support\ServiceProvider;

class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(dirname(__DIR__, 2).'/config/administration.php', 'bedrock.administration');
        $this->app->singleton(AdminPageRegistry::class);
    }

    public function boot(): void
    {
        if ((bool) config('bedrock.administration.enabled', false)) {
            $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/administration.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminInstallCommand::class,
                AdminUserCommand::class,
            ]);
        }
    }
}
