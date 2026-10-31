<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Contracts\Module\ModuleFrontendScaffold;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;

class FrontendModuleScaffold implements ModuleFrontendScaffold
{
    public function __construct(protected Filesystem $files) {}

    public function extend(array $files, string $key, string $class, string $name): array
    {
        $manifest = json_decode($files['module.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['composer']['require']['frolaxhq/bedrock'] = '^1.0';
        $manifest['frontend'] = [
            'driver' => 'inertia-react',
            'pages' => 'resources/js/pages',
            'artifact' => 'dist/bedrock/frontend.json',
            'requires' => [
                'react' => '^19.0', 'react-dom' => '^19.0',
                '@inertiajs/react' => '^3.0', '@frolax/bedrock' => '^1.0',
            ],
        ];

        return [...$files,
            'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            'src/Http/Controllers/'.$class.'Controller.php' => $this->stub('FrontendController.php', compact('key', 'class')),
            '.gitignore' => "/vendor/\n/node_modules/\n/dist/\n",
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
        $path = dirname(__DIR__, 3).'/resources/stubs/module/'.$name.'.stub';

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
            'scripts' => [
                'dev' => 'vp dev',
                'build' => 'vp build && vp build --ssr resources/js/pages.ts && bedrock-artifact',
                'check' => 'vp check',
            ],
            'dependencies' => [
                '@frolax/bedrock' => '^1.0.0',
                '@inertiajs/react' => '^3.8.0',
                '@tabler/icons-react' => '^3.49.0',
                'react' => '^19.3.0',
                'react-dom' => '^19.3.0',
            ],
            'devDependencies' => [
                '@types/node' => '^24.19.1',
                '@types/react' => '^19.3.0',
                '@types/react-dom' => '^19.3.0',
                'tailwindcss' => '^4.3.3',
                'typescript' => '^5.9.3',
                'vite' => 'npm:@voidzero-dev/vite-plus-core@1.0.0',
                'vite-plus' => '1.0.0',
            ],
            'engines' => ['node' => '^24.11.0'],
            'packageManager' => 'pnpm@10.34.6',
            'pnpm' => [
                'overrides' => [
                    'vite@*' => 'npm:@voidzero-dev/vite-plus-core@1.0.0',
                    'vitest@*' => '5.0.1',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
