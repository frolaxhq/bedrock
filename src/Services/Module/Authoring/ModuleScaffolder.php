<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Authoring;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class ModuleScaffolder
{
    public function __construct(
        protected Filesystem $files,
        protected string $modulesPath,
    ) {}

    public function create(string $key, ?string $name = null, bool $backendOnly = false): string
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

        if (! $backendOnly) {
            $manifest['frontend'] = [
                'driver' => 'inertia-react',
                'pages' => 'resources/js/pages',
                'artifact' => 'dist/bedrock/frontend.json',
                'requires' => [
                    'react' => '^19.0',
                    'react-dom' => '^19.0',
                    '@inertiajs/react' => '^3.0',
                    '@frolax/bedrock' => '^1.0',
                ],
            ];
        }

        $files = [
            'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            'src/'.$class.'ServiceProvider.php' => $this->stub('ModuleServiceProvider.php', compact('key', 'class')),
            'src/Http/Controllers/'.$class.'Controller.php' => $this->stub(
                $backendOnly ? 'BackendController.php' : 'FrontendController.php',
                compact('key', 'class'),
            ),
            'routes/web.php' => $this->stub('routes.php', compact('key', 'class')),
            'tests/Feature/'.$class.'ModuleTest.php' => $this->stub('ModuleTest.php', compact('key')),
            '.bedrockignore' => ".git\nnode_modules\n.DS_Store\n*.log\ndist/bedrock/client/.vite\ndist/bedrock/client/@mf-types*\ndist/bedrock/client/mf-stats.json\n",
        ];

        if ($backendOnly) {
            return $files;
        }

        return [...$files,
            'resources/js/pages.ts' => $this->stub('pages.ts', compact('key', 'name')),
            'resources/js/pages/Index.tsx' => $this->stub('Index.tsx', compact('name')),
            'resources/css/module.css' => $this->stub('module.css'),
            'vite.config.ts' => $this->stub('vite.config.ts', compact('key')),
            'tsconfig.json' => $this->stub('tsconfig.json'),
            'package.json' => $this->package($key),
        ];
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

    protected function package(string $key): string
    {
        return json_encode([
            'name' => '@modules/'.$key,
            'private' => true,
            'type' => 'module',
            'packageManager' => 'pnpm@10.19.0',
            'scripts' => [
                'dev' => 'vite',
                'build' => 'vite build && vite build --ssr resources/js/pages.ts && bedrock-artifact',
                'types' => 'tsc --noEmit',
            ],
            'dependencies' => [
                '@frolax/bedrock' => '^1.0.0',
                '@inertiajs/react' => '^3.0.0',
                '@tabler/icons-react' => '^3.48.0',
                'react' => '^19.0.0',
                'react-dom' => '^19.0.0',
            ],
            'devDependencies' => [
                '@module-federation/vite' => '1.23.0',
                '@tailwindcss/vite' => '^4.0.0',
                '@types/node' => '^24.0.0',
                '@types/react' => '^19.0.0',
                '@types/react-dom' => '^19.0.0',
                '@vitejs/plugin-react' => '^6.0.0',
                'tailwindcss' => '^4.0.0',
                'typescript' => '^5.9.0',
                'vite' => '^8.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
