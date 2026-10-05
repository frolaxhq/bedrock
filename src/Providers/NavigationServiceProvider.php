<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Navigation;
use Frolax\Bedrock\Services\Frontend\ViewerContextFactory;
use Frolax\Bedrock\Services\Navigation\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class NavigationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleNavigation::class, function (Application $app): ModuleNavigationRegistry {
            return new ModuleNavigationRegistry(
                $app->make(FrontendRegistry::class),
                $app->make(ModulePermissions::class),
                $app->make(ViewerContextFactory::class),
                $app->make('cache'),
                (array) $app->make('config')->get('bedrock.navigation.cache', []),
            );
        });
        $this->app->singleton(Navigation::class);
    }
}
