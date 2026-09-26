<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Console\Commands\BedrockCommand;
use Illuminate\Support\ServiceProvider;

class BedrockServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bedrock.php', 'bedrock');

        $this->app->singleton(Bedrock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/bedrock.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bedrock');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'bedrock');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/bedrock.php' => config_path('bedrock.php'),
        ], ['bedrock', 'bedrock-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/bedrock'),
        ], ['bedrock', 'bedrock-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/bedrock'),
        ], ['bedrock', 'bedrock-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/bedrock'),
        ], ['bedrock', 'bedrock-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['bedrock', 'bedrock-migrations']);

        $this->commands([
            BedrockCommand::class,
        ]);
    }
}
