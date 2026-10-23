<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Events\Permissions\PermissionCatalogSynced;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;

class SpatieAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdministrationManager::class, fn (Application $app): AdministrationManager => new AdministrationManager(
            $app->make(ModulePermissions::class),
            (array) $app->make('config')->get('bedrock.permissions', []),
        ));
    }

    public function boot(): void
    {
        Event::listen(PermissionCatalogSynced::class, function (PermissionCatalogSynced $event): void {
            if (! Schema::hasTable((string) config('permission.table_names.permissions', 'permissions'))) {
                return;
            }

            foreach ($this->app->make(ModulePermissions::class)->active($event->module) as $permission) {
                Permission::findOrCreate($permission->name, (string) config('bedrock.permissions.guard', 'web'));
            }
        });
    }
}
