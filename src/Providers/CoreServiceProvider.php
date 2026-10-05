<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Providers;

use Frolax\Bedrock\Services\Core\BedrockVersion;
use Illuminate\Support\ServiceProvider;

/**
 * Registers configuration shared by Bedrock capabilities.
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom($this->packagePath('config/bedrock.php'), 'bedrock');
        $this->app->singleton(BedrockVersion::class);
    }

    /**
     * Register the package configuration for publishing.
     */
    public function boot(): void
    {
        // Testbench can rebuild its configuration repository after providers
        // register, so merge again during boot to keep the defaults available.
        $this->replaceConfigRecursivelyFrom($this->packagePath('config/bedrock.php'), 'bedrock');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->packagePath('config/bedrock.php') => config_path('bedrock.php'),
        ], ['bedrock', 'bedrock-config']);
    }

    /**
     * The absolute path to a file inside the package.
     */
    protected function packagePath(string $path = ''): string
    {
        return rtrim(dirname(__DIR__, 2), '/\\').($path === '' ? '' : '/'.$path);
    }
}
