<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Providers\AdministrationServiceProvider;
use Frolax\Bedrock\Providers\CoreServiceProvider;
use Frolax\Bedrock\Providers\FoundationServiceProvider;
use Frolax\Bedrock\Providers\FrontendServiceProvider;
use Frolax\Bedrock\Providers\ModulesServiceProvider;
use Frolax\Bedrock\Providers\NavigationServiceProvider;
use Frolax\Bedrock\Providers\PermissionsServiceProvider;
use Frolax\Bedrock\Providers\SettingsServiceProvider;
use Frolax\Bedrock\Providers\SpatieAccessServiceProvider;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * The package entry point.
 *
 * Each first-class capability owns its bindings, resources and commands. This
 * provider only composes the shared core and the capabilities Bedrock ships.
 */
class BedrockServiceProvider extends ServiceProvider
{
    /**
     * The providers that make up the package.
     *
     * @var list<class-string<ServiceProvider>>
     */
    protected array $providers = [
        CoreServiceProvider::class,
        FoundationServiceProvider::class,
        ModulesServiceProvider::class,
        SettingsServiceProvider::class,
        PermissionsServiceProvider::class,
        PermissionServiceProvider::class,
        \Inertia\ServiceProvider::class,
        SpatieAccessServiceProvider::class,
        NavigationServiceProvider::class,
        FrontendServiceProvider::class,
        AdministrationServiceProvider::class,
    ];

    /**
     * Register the package providers.
     */
    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }
}
