<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\Services\Settings\EloquentModuleSettings;
use Frolax\Bedrock\Settings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleSettings::class, function (Application $app): EloquentModuleSettings {
            return new EloquentModuleSettings(
                $app->make(ModuleRegistry::class),
                $app->make('cache'),
                $app->make('encrypter'),
                (array) $app->make('config')->get('bedrock.settings.cache', []),
            );
        });
        $this->app->singleton(Settings::class);
    }
}
