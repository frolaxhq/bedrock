<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Console\Commands\Frontend\FrontendInstallCommand;
use Frolax\Bedrock\Console\Commands\Frontend\UiOverrideCommand;
use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleFrontendScaffold;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Frolax\Bedrock\DataObjects\Navigation\NavigationItem;
use Frolax\Bedrock\Events\Module\ModuleDisabled;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Frolax\Bedrock\Events\Module\ModuleFailed;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Events\Module\ModuleRecovered;
use Frolax\Bedrock\Events\Module\ModuleRegistryChanged;
use Frolax\Bedrock\Events\Module\ModuleUninstalled;
use Frolax\Bedrock\Frontend;
use Frolax\Bedrock\Services\Frontend\FrontendArtifactManager;
use Frolax\Bedrock\Services\Frontend\FrontendHostInstaller;
use Frolax\Bedrock\Services\Frontend\FrontendModuleFeatureHandler;
use Frolax\Bedrock\Services\Frontend\FrontendModuleScaffold;
use Frolax\Bedrock\Services\Frontend\FrontendOverrideCatalog;
use Frolax\Bedrock\Services\Frontend\ModuleFrontendRegistry;
use Frolax\Bedrock\Services\Frontend\ViewerContextFactory;
use Frolax\Bedrock\Services\Module\ModuleFeatureHandlers;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FrontendAssetManager::class, FrontendArtifactManager::class);
        $this->callAfterResolving(ModuleFeatureHandlers::class, static function (ModuleFeatureHandlers $handlers): void {
            $handlers->register('inertia-react', FrontendModuleFeatureHandler::class);
        });
        $this->callAfterResolving('events', function (Dispatcher $events): void {
            foreach ([ModuleRegistryChanged::class, ModuleInstalled::class, ModuleEnabled::class, ModuleDisabled::class,
                ModuleUninstalled::class, ModuleFailed::class, ModuleRecovered::class] as $event) {
                $events->listen($event, function (): void {
                    $this->app->make(FrontendRegistry::class)->refresh();
                    $this->app->make(ModuleNavigation::class)->refresh();
                });
            }
        });
        $this->app->bind(ModuleFrontendScaffold::class,
            FrontendModuleScaffold::class);
        $this->app->bind(ViewerContextFactory::class, function (Application $app): ViewerContextFactory {
            $config = $app->make('config');

            return new ViewerContextFactory(
                $app->make(ModulePermissions::class),
                (array) $config->get('bedrock.frontend.panels', []),
                (array) $config->get('bedrock.frontend.viewer', []),
            );
        });
        $this->app->singleton(FrontendRegistry::class, function (Application $app): ModuleFrontendRegistry {
            return new ModuleFrontendRegistry(
                $app->make(ModuleRegistry::class),
                $app->make(FrontendAssetManager::class),
                $app->make('cache'),
                (array) $app->make('config')->get('bedrock.frontend.cache', []),
            );
        });
        $this->app->singleton(Frontend::class);
        $this->app->bind(FrontendHostInstaller::class, fn (Application $app): FrontendHostInstaller => new FrontendHostInstaller(
            $app->make('files'),
            $app->basePath(),
        ));
        $this->app->bind(FrontendOverrideCatalog::class, fn (Application $app): FrontendOverrideCatalog => new FrontendOverrideCatalog(
            $app->make('files'),
            dirname(__DIR__, 2),
            $app->basePath(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FrontendInstallCommand::class, UiOverrideCommand::class]);
        }

        if (! (bool) $this->app->make('config')->get('bedrock.frontend.enabled', true)) {
            return;
        }

        Inertia::share('__bedrock', function (): array {
            /** @var FrontendRegistry $registry */
            $registry = $this->app->make(FrontendRegistry::class);
            $user = $this->app->make('request')->user();

            return [
                'schema' => 1,
                'viewer' => $this->app->make(ViewerContextFactory::class)->make($user),
                'modules' => $registry->all()
                    ->mapWithKeys(static fn (FrontendModule $module): array => [$module->key => $module->forInertia()])
                    ->all(),
                'navigation' => $this->app->make(ModuleNavigation::class)
                    ->all($user)
                    ->map(static fn (NavigationItem $item): array => $item->toArray())
                    ->all(),
            ];
        });
    }
}
