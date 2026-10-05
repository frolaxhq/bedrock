<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class FrontendHostInstaller
{
    public function __construct(
        protected Filesystem $files,
        protected string $basePath,
    ) {}

    /** @return array{ok: bool, installed: bool, changes: list<string>, problems: list<string>} */
    public function inspect(): array
    {
        $problems = [];
        $changes = [];
        $package = $this->readPackage();

        if (! isset($package['dependencies']['@inertiajs/react']) && ! isset($package['devDependencies']['@inertiajs/react'])) {
            $problems[] = 'package.json must contain @inertiajs/react.';
        }

        foreach (['resources/js/app.tsx', 'vite.config.ts'] as $relative) {
            if (! $this->files->isFile($this->path($relative))) {
                $problems[] = sprintf('[%s] was not found.', $relative);
            }
        }

        if (! isset($package['dependencies']['@frolax/bedrock'])) {
            $changes[] = 'Add @frolax/bedrock to package.json.';
        }

        if (! $this->files->isFile($this->path('resources/js/bedrock-pages.ts'))) {
            $changes[] = 'Create resources/js/bedrock-pages.ts.';
        } else {
            $pages = $this->files->get($this->path('resources/js/bedrock-pages.ts'));

            if (! str_contains($pages, 'virtual:bedrock-overrides')) {
                $changes[] = 'Enable conventional Bedrock frontend overrides in resources/js/bedrock-pages.ts.';
            }

            if (str_contains($pages, '@frolax/bedrock/admin.css')) {
                $changes[] = 'Remove the obsolete standalone administration stylesheet import.';
            }

            if (str_contains($pages, 'bedrockPageOverrides')) {
                $changes[] = 'Remove the obsolete Bedrock administration page override registry.';
            }
        }

        if (! $this->files->isFile($this->path('resources/js/bedrock-vite.d.ts'))) {
            $changes[] = 'Create resources/js/bedrock-vite.d.ts.';
        } elseif (str_contains($this->files->get($this->path('resources/js/bedrock-vite.d.ts')), 'bedrockPageOverrides')) {
            $changes[] = 'Update the Bedrock virtual override declaration.';
        }

        if (! $this->files->isFile($this->path('resources/css/app.css'))) {
            $changes[] = 'Create resources/css/app.css with the Bedrock theme source.';
        } elseif (! str_contains($this->files->get($this->path('resources/css/app.css')), '@frolax/bedrock/theme.css')) {
            $changes[] = 'Import the Bedrock theme before host styles.';
        }

        if ($this->files->isFile($this->path('resources/js/app.tsx'))
            && ! str_contains($this->files->get($this->path('resources/js/app.tsx')), 'resolveBedrockPage')) {
            $changes[] = 'Compose the Inertia page resolver in resources/js/app.tsx.';
        }

        if ($this->files->isFile($this->path('vite.config.ts'))
            && ! str_contains($this->files->get($this->path('vite.config.ts')), 'bedrockHost')) {
            $changes[] = 'Register the Bedrock host plugin in vite.config.ts.';
        }

        return [
            'ok' => $problems === [],
            'installed' => $problems === [] && $changes === [],
            'changes' => $changes,
            'problems' => $problems,
        ];
    }

    /** @return list<string> */
    public function install(bool $dryRun = false): array
    {
        $inspection = $this->inspect();

        if (! $inspection['ok']) {
            throw new InvalidModuleSourceException(implode(' ', $inspection['problems']));
        }

        if ($inspection['installed'] || $dryRun) {
            return $inspection['changes'];
        }

        $writes = [];
        $package = $this->integratedPackage($this->readPackage());
        $writes[$this->path('package.json')] = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $pages = $this->path('resources/js/bedrock-pages.ts');

        if (! $this->files->isFile($pages)) {
            $writes[$pages] = <<<'TS'
            import 'virtual:bedrock-overrides';
            import { createBedrockResolver } from '@frolax/bedrock/react';
            import type { ComponentType } from 'react';

            const pages = import.meta.glob<{ default: ComponentType<Record<string, unknown>> }>('./pages/**/*.tsx');

            export const resolveBedrockPage = createBedrockResolver({
                local(name) {
                    const page = pages[`./pages/${name}.tsx`];

                    if (!page) {
                        throw new Error(`Page [${name}] was not found.`);
                    }

                    return page();
                },
            });
            TS;
        } else {
            $contents = $this->files->get($pages);

            $contents = preg_replace(
                '/^import\s+[\'\"]@frolax\/bedrock\/admin\.css[\'\"];\R/m',
                '',
                $contents,
            ) ?? $contents;
            $contents = str_replace(
                "import { bedrockPageOverrides } from 'virtual:bedrock-overrides';",
                "import 'virtual:bedrock-overrides';",
                $contents,
            );
            $contents = preg_replace('/^\s*pages:\s*bedrockPageOverrides,\R/m', '', $contents) ?? $contents;

            if (! str_contains($contents, 'virtual:bedrock-overrides')) {
                $contents = "import 'virtual:bedrock-overrides';\n".$contents;
            }

            $writes[$pages] = $contents;
        }

        $types = $this->path('resources/js/bedrock-vite.d.ts');

        if (! $this->files->isFile($types)
            || str_contains($this->files->get($types), 'bedrockPageOverrides')) {
            $writes[$types] = <<<'TS'
            declare module 'virtual:bedrock-overrides';
            TS;
        }

        $css = $this->path('resources/css/app.css');
        $cssContents = $this->files->isFile($css) ? $this->files->get($css) : '';

        if (! str_contains($cssContents, '@frolax/bedrock/theme.css')) {
            $cssContents = "@import '@frolax/bedrock/theme.css';\n".$cssContents;
        }

        if (! str_contains($cssContents, '@frolax/bedrock/dist')) {
            $cssContents = $this->appendAfterImports($cssContents, "@source '../../node_modules/@frolax/bedrock/dist';\n");
        }

        $writes[$css] = $cssContents;

        $app = $this->path('resources/js/app.tsx');
        $writes[$app] = $this->patchedApp($app);

        if ($this->files->isFile($this->path('resources/js/ssr.tsx'))) {
            $ssr = $this->path('resources/js/ssr.tsx');
            $writes[$ssr] = $this->patchedApp($ssr);
        }

        $vite = $this->path('vite.config.ts');
        $writes[$vite] = $this->patchedVite($vite);
        $this->writeAtomically($writes);

        return $inspection['changes'];
    }

    /** @return array<string, mixed> */
    protected function readPackage(): array
    {
        $path = $this->path('package.json');

        if (! $this->files->isFile($path)) {
            throw new InvalidModuleSourceException('package.json was not found.');
        }

        $package = json_decode($this->files->get($path), true);

        if (! is_array($package)) {
            throw new InvalidModuleSourceException('package.json is not valid JSON.');
        }

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    protected function integratedPackage(array $package): array
    {
        $dependencies = (array) ($package['dependencies'] ?? []);
        $dependencies['@base-ui/react'] = '^1.8.0';
        $dependencies['@frolax/bedrock'] = '^1.0.0';
        $dependencies['@tabler/icons-react'] = '^3.48.0';
        $dependencies['class-variance-authority'] = '^0.7.1';
        $dependencies['cn'] = '^0.4.0';
        $dependencies['next-themes'] = '^0.4.6';
        $dependencies['sonner'] = '^2.0.8';
        ksort($dependencies);
        $package['dependencies'] = $dependencies;
        $devDependencies = (array) ($package['devDependencies'] ?? []);
        $devDependencies['@module-federation/vite'] = '1.23.0';
        ksort($devDependencies);
        $package['devDependencies'] = $devDependencies;

        return $package;
    }

    protected function patchedApp(string $path): string
    {
        $contents = $this->files->get($path);

        if (str_contains($contents, 'resolveBedrockPage')) {
            return $contents;
        }

        $import = "import { resolveBedrockPage } from './bedrock-pages';\n";
        $contents = $import.$contents;
        $patched = preg_replace('/createInertiaApp\(\{\s*/', "createInertiaApp({\n    resolve: resolveBedrockPage,\n", $contents, 1, $count);

        if ($patched === null || $count !== 1) {
            throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Inertia entry [%s].', $path));
        }

        return $patched;
    }

    protected function patchedVite(string $path): string
    {
        $contents = $this->files->get($path);

        if (str_contains($contents, 'bedrockHost')) {
            return $contents;
        }

        $contents = "import { bedrockHost } from '@frolax/bedrock/vite';\n".$contents;
        $patterns = [
            '/plugins:\s*lazyPlugins\(\(\)\s*=>\s*\[\s*/',
            '/plugins:\s*\[\s*/',
        ];
        $patched = $contents;
        $count = 0;

        foreach ($patterns as $pattern) {
            $patched = preg_replace($pattern, '$0'."\n        bedrockHost(),", $contents, 1, $count) ?? $contents;

            if ($count === 1) {
                break;
            }
        }

        if ($count !== 1) {
            throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Vite configuration [%s].', $path));
        }

        return $patched;
    }

    protected function appendAfterImports(string $contents, string $addition): string
    {
        if (preg_match('/\A((?:@import[^\n]*\n)*)/', $contents, $matches) !== 1) {
            return $addition.$contents;
        }

        $offset = strlen($matches[1]);

        return substr($contents, 0, $offset).$addition.substr($contents, $offset);
    }

    /** @param array<string, string> $writes */
    protected function writeAtomically(array $writes): void
    {
        $token = bin2hex(random_bytes(8));
        $temporary = [];
        $backups = [];
        $replaced = [];

        try {
            foreach ($writes as $path => $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $temporary[$path] = $path.'.bedrock-'.$token.'.tmp';
                $this->files->put($temporary[$path], $contents);
            }

            foreach ($temporary as $path => $staged) {
                if ($this->files->exists($path)) {
                    $backups[$path] = $path.'.bedrock-'.$token.'.bak';

                    if (! @rename($path, $backups[$path])) {
                        throw new InvalidModuleSourceException(sprintf('Unable to stage the host file [%s].', $path));
                    }
                }

                if (! @rename($staged, $path)) {
                    throw new InvalidModuleSourceException(sprintf('Unable to install the host file [%s].', $path));
                }

                $replaced[] = $path;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($replaced) as $path) {
                $this->files->delete($path);

                if (isset($backups[$path])) {
                    @rename($backups[$path], $path);
                    unset($backups[$path]);
                }
            }

            foreach ($backups as $path => $backup) {
                if (! $this->files->exists($path)) {
                    @rename($backup, $path);
                }
            }

            throw $exception;
        } finally {
            foreach ($temporary as $staged) {
                $this->files->delete($staged);
            }

            foreach ($backups as $backup) {
                $this->files->delete($backup);
            }
        }
    }

    protected function path(string $relative): string
    {
        return rtrim($this->basePath, '/\\').'/'.$relative;
    }
}
