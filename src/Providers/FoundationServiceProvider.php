<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Console\Commands\Administration\AdminCreateCommand;
use Frolax\Bedrock\Console\Commands\Installation\InstallCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FoundationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, AdminCreateCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(dirname(__DIR__, 2).'/routes/foundation.php');
        }
    }
}
