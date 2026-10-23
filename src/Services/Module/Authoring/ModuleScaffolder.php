<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Authoring;

use Frolax\Bedrock\Contracts\Module\ModuleFrontendScaffold;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Php\DependencyManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class ModuleScaffolder
{
    public function __construct(
        protected Filesystem $files,
        protected string $modulesPath,
        protected ?ModuleFrontendScaffold $frontend = null,
    ) {}

    public function create(string $key, ?string $name = null, bool $backendOnly = true): string
    {
        if (preg_match(Manifest::KEY_PATTERN, $key) !== 1) {
            throw new InvalidModuleSourceException(sprintf('The module key [%s] is invalid.', $key));
        }

        $class = Str::studly($key);
        $name ??= Str::headline($key).' Module';
        $target = rtrim($this->modulesPath, '/\\').'/'.$key;

        if ($this->files->exists($target)) {
            throw new InvalidModuleSourceException(sprintf('The module directory [%s] already exists.', $target));
        }

        $temporary = dirname($target).'/.'.$key.'.scaffold.'.bin2hex(random_bytes(8));

        try {
            foreach ($this->templates($key, $class, $name, $backendOnly) as $relative => $contents) {
                $path = $temporary.'/'.$relative;
                $this->files->ensureDirectoryExists(dirname($path));
                $this->files->put($path, $contents);
            }

            $this->files->ensureDirectoryExists(dirname($target));

            if (! @rename($temporary, $target)) {
                throw new InvalidModuleSourceException(sprintf('The module [%s] could not be created.', $key));
            }
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($temporary);

            throw $exception;
        }

        return $target;
    }

    /** @return array<string, string> */
    protected function templates(string $key, string $class, string $name, bool $backendOnly): array
    {
        $manifest = [
            'schema' => 1,
            'key' => $key,
            'name' => $name,
            'version' => '1.0.0',
            'description' => sprintf('The %s module.', $name),
            'provider' => 'Modules\\'.$class.'\\'.$class.'ServiceProvider',
            'autoload' => ['psr-4' => ['Modules\\'.$class.'\\' => 'src/']],
            'composer' => ['require' => ['php' => '^8.5', 'laravel/framework' => '^13.0']],
        ];

        $files = [
            'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            'src/'.$class.'ServiceProvider.php' => $this->stub('ModuleServiceProvider.php', compact('key', 'class')),
            'src/Http/Controllers/'.$class.'Controller.php' => $this->stub(
                'BackendController.php',
                compact('key', 'class'),
            ),
            'routes/web.php' => $this->stub('routes.php', compact('key', 'class')),
            'tests/Feature/'.$class.'ModuleTest.php' => $this->stub('ModuleTest.php', compact('key')),
            '.bedrockignore' => ".git\nnode_modules\n.DS_Store\n*.log\ndist/bedrock/client/.vite\ndist/bedrock/client/@mf-types*\ndist/bedrock/client/mf-stats.json\n",
        ];

        $composer = [
            'name' => 'bedrock-modules/'.$key,
            'type' => 'library',
            'require' => ['php' => '^8.5'],
            'autoload' => $manifest['autoload'],
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ];
        $lock = [
            '_readme' => ['This file locks this module’s private dependencies.'],
            'content-hash' => (new DependencyManifest)->contentHash($composer),
            'packages' => [], 'packages-dev' => [], 'aliases' => [],
            'minimum-stability' => 'stable', 'stability-flags' => new stdClass,
            'prefer-stable' => false, 'prefer-lowest' => false,
            'platform' => ['php' => '^8.5'], 'platform-dev' => new stdClass,
            'plugin-api-version' => '2.6.0',
        ];
        foreach (['composer.json' => $composer, 'composer.lock' => $lock] as $file => $data) {
            $files[$file] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        }
        $files['.gitignore'] = "/vendor/\n/dist/\n";
        $files['README.md'] = "# {$name}\n\nPrivate SDKs belong in this module's composer.json. Host integrations remain in module.json composer.require.\n\nRun composer update in this directory during authoring and commit composer.lock. The installer only runs locked installs.\n\nRun php artisan module:prepare {$key} and module:pack {$key} from the host. Test the scoped runtime before publishing.\n";

        if ($backendOnly) {
            return $files;
        }

        if ($this->frontend === null) {
            throw new InvalidModuleSourceException('Install frolaxhq/bedrock-frontend before generating a frontend module.');
        }

        return $this->frontend->extend($files, $key, $class, $name);
    }

    /** @param array<string, string> $replacements */
    protected function stub(string $name, array $replacements = []): string
    {
        $path = dirname(__DIR__, 4).'/resources/stubs/module/'.$name.'.stub';

        if (! $this->files->isFile($path)) {
            throw new InvalidModuleSourceException(sprintf('The module scaffold stub [%s] is missing.', $name));
        }

        $contents = $this->files->get($path);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }
}
