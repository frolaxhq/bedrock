<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests;

use Frolax\Bedrock\BedrockServiceProvider;
use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Frontend as FrontendGateway;
use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Module as ModuleGateway;
use Frolax\Bedrock\Navigation;
use Frolax\Bedrock\Permissions;
use Frolax\Bedrock\Repositories\Module\ArrayModuleRepository;
use Frolax\Bedrock\Settings;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Frolax\Bedrock\Testing\Module\ModuleManifestFactory;
use Frolax\Bedrock\Tests\Fixtures\Modules\Alpha\AlphaServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Workbench\App\Models\User;
use ZipArchive;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package providers.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BedrockServiceProvider::class,
            LaravelDataServiceProvider::class,
            PermissionServiceProvider::class,
        ];
    }

    /**
     * Prepare the package environment.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('bedrock.modules.path', __DIR__.'/Fixtures/modules');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission', require dirname(__DIR__).'/vendor/spatie/laravel-permission/config/permission.php');
        $app['config']->set('permission.testing', true);
    }

    /**
     * Migrate the package tables before each test.
     *
     * Testbench boots the application before this runs, so booting inside a test
     * exercises the registry explicitly rather than relying on the boot order.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/workbench/database/migrations');
        $this->artisan('migrate', ['--database' => 'testing'])->run();

        $migration = require dirname(__DIR__).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $migration->up();
    }

    /**
     * Register a module directly in the repository.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function seedModule(string $key, array $attributes = []): Module
    {
        $module = app(ModuleRepository::class)->upsert($key, array_merge([
            'name' => ucfirst($key),
            'version' => '1.0.0',
            'provider' => 'Some\\Provider',
            'path' => $this->fixturePath('alpha'),
            'status' => ModuleStatus::Installed,
            'manifest' => ['key' => $key],
            'dependencies' => [],
            'installed_at' => now(),
        ], $attributes));

        app(ModuleRegistry::class)->refresh();

        return $module;
    }

    /**
     * The manifest loader bound by the package.
     */
    protected function loader(): ModuleManifest
    {
        return app(ModuleManifest::class);
    }

    /**
     * Create a throwaway directory for a test.
     */
    protected function tempDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().'/bedrock-'.$prefix.'-'.uniqid();

        mkdir($path, 0755, true);

        return $path;
    }

    /**
     * Create a module directory holding a manifest and one nested file.
     */
    protected function moduleSource(string $root, string $key): string
    {
        $path = ModuleManifestFactory::for($key)->create($root);

        mkdir($path.'/resources/views', 0755, true);
        file_put_contents($path.'/resources/views/index.blade.php', 'module view');

        return $path;
    }

    /**
     * Write a ZIP archive and hand it to the callback to fill.
     *
     * @param  callable(ZipArchive): void  $fill
     */
    protected function zipArchive(string $path, callable $fill): string
    {
        $archive = new ZipArchive;

        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $fill($archive);

        $archive->close();

        return $path;
    }

    /**
     * The manifest a ZIP archive test ships.
     *
     * @return array<string, mixed>
     */
    protected function manifestArray(string $key): array
    {
        return [
            'schema' => 1,
            'key' => $key,
            'name' => ucfirst($key).' Module',
            'version' => '1.0.0',
            'provider' => AlphaServiceProvider::class,
        ];
    }

    /**
     * Write a migration into a module directory.
     */
    protected function moduleMigration(string $module, string $table): void
    {
        $path = $module.'/database/migrations';

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path.'/2026_01_01_000000_create_'.$table.'_table.php', <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->id();
                    \$table->string('name');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP);
    }

    /**
     * Write a migration that always fails into a module directory.
     */
    protected function failingModuleMigration(string $module, string $table): void
    {
        $path = $module.'/database/migrations';

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path.'/2026_01_01_000000_create_'.$table.'_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        return new class extends Migration
        {
            public function up(): void
            {
                DB::statement('this is not valid sql');
            }
        };
        PHP);
    }

    /**
     * Replace the manifest of a module directory.
     *
     * @param  array<string, mixed>  $database
     */
    protected function moduleManifest(string $module, string $key, array $database): void
    {
        file_put_contents($module.'/module.json', json_encode([
            ...$this->manifestArray($key),
            'database' => $database,
        ]));
    }

    /**
     * The number of staging directories left behind for a prefix.
     */
    protected function stagedDirectories(string $prefix): int
    {
        return count(glob(sys_get_temp_dir().'/bedrock-'.$prefix.'-*') ?: []);
    }

    /**
     * The absolute path to a module fixture directory.
     */
    protected function fixturePath(string $name = ''): string
    {
        $base = __DIR__.'/Fixtures/modules';

        return $name === '' ? $base : $base.'/'.$name;
    }

    /**
     * Point Bedrock at a module directory and rebuild the affected bindings.
     */
    protected function useModulesDirectory(string $path): void
    {
        config()->set('bedrock.modules.path', $path);

        $this->forgetBedrockBindings();
    }

    /**
     * Replace the registry repository with an in-memory one.
     *
     * @param  array<string, array<string, mixed>>  $modules
     */
    protected function useArrayRepository(array $modules = []): void
    {
        $this->app?->singleton(ModuleRepository::class, static function () use ($modules): ArrayModuleRepository {
            return new ArrayModuleRepository(array_map(
                static function (string $key, array $attributes): Module {
                    $module = new Module;
                    $module->forceFill(array_merge(['key' => $key], $attributes));

                    return $module;
                },
                array_keys($modules),
                array_values($modules),
            ));
        });

        $this->forgetBedrockBindings();
    }

    /**
     * Drop the resolved Bedrock services so configuration changes take effect.
     */
    protected function forgetBedrockBindings(): void
    {
        foreach ([
            ModuleDiscoverer::class,
            ModuleRegistry::class,
            ModuleManager::class,
            ModuleGateway::class,
            FrontendAssetManager::class,
            FrontendRegistry::class,
            FrontendGateway::class,
            ModuleSettings::class,
            Settings::class,
            ModulePermissions::class,
            Permissions::class,
            ModuleNavigation::class,
            Navigation::class,
        ] as $abstract) {
            $this->app?->forgetInstance($abstract);
        }
    }
}
