<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers\Module;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Base service provider for packaged modules.
 *
 * Extending this class wires the conventional module layout: routes, migrations,
 * views, translations, configuration and publishable assets. Every path is
 * optional, so a module only pays for the resources it actually ships.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * The module key, used for namespaced resources.
     */
    protected string $moduleKey = '';

    /**
     * The absolute path to the module directory.
     */
    protected string $modulePath = '';

    /**
     * Register any module services.
     *
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        $this->registerModuleConfig();
        $this->registerModuleMigrations();
    }

    /**
     * Bootstrap any module services.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->bootModuleRoutes();
        $this->bootModuleViews();
        $this->bootModuleTranslations();
        $this->bootModulePublishes();
    }

    /**
     * The filesystem used to inspect module resources.
     *
     * @throws BindingResolutionException
     */
    protected function files(): Filesystem
    {
        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);

        return $files;
    }

    /**
     * Merge the module configuration file into the application config.
     *
     * @throws BindingResolutionException
     */
    protected function registerModuleConfig(): void
    {
        $config = $this->moduleConfigPath();

        if ($this->moduleKey !== '' && $config !== null && $this->files()->isFile($config)) {
            $this->mergeConfigFrom($config, $this->moduleKey);
        }
    }

    /**
     * Load the module migrations from the conventional directory.
     *
     * @throws BindingResolutionException
     */
    protected function registerModuleMigrations(): void
    {
        $migrations = $this->modulePath.'/database/migrations';

        if ($this->modulePath !== '' && $this->files()->isDirectory($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }

    /**
     * Load the module web and api routes when they exist.
     *
     * @throws BindingResolutionException
     */
    protected function bootModuleRoutes(): void
    {
        $web = $this->modulePath.'/routes/web.php';

        if ($this->modulePath !== '' && $this->files()->isFile($web)) {
            $this->loadRoutesFrom($web);
        }

        $api = $this->modulePath.'/routes/api.php';

        if ($this->modulePath !== '' && $this->files()->isFile($api)) {
            $this->loadRoutesFrom($api);
        }
    }

    /**
     * Load the module views under the module key namespace.
     *
     * @throws BindingResolutionException
     */
    protected function bootModuleViews(): void
    {
        $views = $this->modulePath.'/resources/views';

        if ($this->moduleKey !== '' && $this->modulePath !== '' && $this->files()->isDirectory($views)) {
            $this->loadViewsFrom($views, $this->moduleKey);
        }
    }

    /**
     * Load the module translations under the module key namespace.
     *
     * @throws BindingResolutionException
     */
    protected function bootModuleTranslations(): void
    {
        $lang = $this->modulePath.'/lang';

        if ($this->moduleKey !== '' && $this->modulePath !== '' && $this->files()->isDirectory($lang)) {
            $this->loadTranslationsFrom($lang, $this->moduleKey);
        }
    }

    /**
     * Register the module's publishable resources.
     */
    protected function bootModulePublishes(): void
    {
        if (! $this->app->runningInConsole() || $this->moduleKey === '' || $this->modulePath === '') {
            return;
        }

        $tags = ['modules', 'module-'.$this->moduleKey];

        $config = $this->moduleConfigPath();

        if ($config !== null) {
            $this->publishes([$config => config_path($this->moduleKey.'.php')], $tags);
        }

        $this->publishes([$this->modulePath.'/resources/views' => resource_path('views/vendor/'.$this->moduleKey)], $tags);
        $this->publishes([$this->modulePath.'/lang' => $this->app->langPath('vendor/'.$this->moduleKey)], $tags);
        $this->publishes([$this->modulePath.'/public' => public_path('vendor/'.$this->moduleKey)], $tags);

        $this->publishesMigrations([$this->modulePath.'/database/migrations' => database_path('migrations')], $tags);
    }

    /**
     * The conventional configuration file path for the module.
     */
    protected function moduleConfigPath(): ?string
    {
        if ($this->moduleKey === '' || $this->modulePath === '') {
            return null;
        }

        return $this->modulePath.'/config/'.$this->moduleKey.'.php';
    }
}
