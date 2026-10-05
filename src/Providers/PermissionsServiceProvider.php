<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Events\Module\ModuleUninstalled;
use Frolax\Bedrock\Permissions;
use Frolax\Bedrock\Services\Permissions\EloquentModulePermissions;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModulePermissions::class, function (Application $app): EloquentModulePermissions {
            return new EloquentModulePermissions(
                $app->make(ModuleRegistry::class),
                (array) $app->make('config')->get('bedrock.permissions', []),
            );
        });
        $this->app->singleton(Permissions::class);
    }

    public function boot(Gate $gate): void
    {
        $gate->before(function (mixed $user, string $ability): ?bool {
            /** @var ModulePermissions $permissions */
            $permissions = $this->app->make(ModulePermissions::class);

            return $permissions->isManaged($ability) && ! $permissions->isActive($ability) ? false : null;
        });

        Event::listen(ModuleInstalled::class, function (ModuleInstalled $event): void {
            $this->app->make(ModulePermissions::class)->sync($event->key);
        });
        Event::listen(ModuleUninstalled::class, function (ModuleUninstalled $event): void {
            $this->app->make(ModulePermissions::class)->deactivate($event->key);
        });
    }
}
