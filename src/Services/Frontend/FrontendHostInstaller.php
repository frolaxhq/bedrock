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

        $vite = $this->path('vite.config.ts');

        if ($this->files->isFile($vite)
            && ! preg_match('/from\s+[\'\"]vite-plus[\'\"]/', $this->files->get($vite))
            && ! (str_contains($this->files->get($vite), 'defineHostConfig')
                && preg_match('/from\s+[\'\"]@frolax\/bedrock\/vite[\'\"]/', $this->files->get($vite)))) {
            $problems[] = 'Run vp migrate at the host application root before frontend:install.';
        }

        $devDependencies = (array) ($package['devDependencies'] ?? []);
        $dependencies = (array) ($package['dependencies'] ?? []);

        if (! isset($devDependencies['vite-plus']) && ! isset($dependencies['vite-plus'])) {
            $changes[] = 'Add vite-plus to package.json.';
        }

        $viteDependency = $devDependencies['vite'] ?? $dependencies['vite'] ?? null;

        if ($viteDependency === null) {
            $changes[] = 'Add the Vite+ core alias for host plugins.';
        } elseif (! $this->hasViteCoreAlias($viteDependency)) {
            $problems[] = 'Run vp migrate at the host application root before frontend:install.';
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
            && ! str_contains($this->files->get($this->path('vite.config.ts')), 'bedrockHost')
            && ! str_contains($this->files->get($this->path('vite.config.ts')), 'defineHostConfig')) {
            $changes[] = 'Register the Bedrock host plugin in vite.config.ts.';
        }

        if ($this->files->isFile($vite)
            && ! str_contains($this->files->get($vite), 'bedrockToolchain')
            && ! str_contains($this->files->get($vite), 'mergeBedrockToolchain')
            && ! str_contains($this->files->get($vite), 'defineHostConfig')) {
            $changes[] = 'Apply Bedrock Vite+ checks in vite.config.ts.';
        }

        if ($this->files->isFile($vite)
            && preg_match('/from\s+[\'\"]@frolax\/bedrock\/vite-plus[\'\"]/', $this->files->get($vite))) {
            $changes[] = 'Move the Bedrock host plugin to the Bedrock Vite entry point.';
        }

        foreach (['resources/js/app.tsx', 'resources/js/ssr.tsx'] as $relative) {
            $entry = $this->path($relative);

            if (! $this->files->isFile($entry)) {
                continue;
            }

            try {
                $this->patchedApp($entry);
            } catch (InvalidModuleSourceException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        if ($this->files->isFile($vite)
            && (preg_match('/from\s+[\'\"]vite-plus[\'\"]/', $this->files->get($vite))
                || str_contains($this->files->get($vite), 'defineHostConfig'))) {
            try {
                $this->patchedVite($vite);
            } catch (InvalidModuleSourceException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        return [
            'ok' => $problems === [],
            'installed' => $problems === [] && $changes === [],
            'changes' => $changes,
            'problems' => array_values(array_unique($problems)),
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

            const pages = import.meta.glob<{
                default: ComponentType<Record<string, unknown>>;
            }>('./pages/**/*.tsx');

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
            $writes[$pages] .= PHP_EOL;
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
            $writes[$types] = "declare module 'virtual:bedrock-overrides';\n";
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
        $dependencies['@frolax/bedrock'] = '^1.0.0';
        ksort($dependencies);
        $package['dependencies'] = $dependencies;
        $devDependencies = (array) ($package['devDependencies'] ?? []);

        if (! isset($dependencies['vite']) && ! isset($devDependencies['vite'])) {
            $devDependencies['vite'] = 'npm:@voidzero-dev/vite-plus-core@1.0.0';
        }

        if (! isset($dependencies['vite-plus']) && ! isset($devDependencies['vite-plus'])) {
            $devDependencies['vite-plus'] = '1.0.0';
        }
        ksort($devDependencies);
        $package['devDependencies'] = $devDependencies;

        return $package;
    }

    protected function hasViteCoreAlias(mixed $dependency): bool
    {
        if (! is_string($dependency)) {
            return false;
        }

        if (str_starts_with($dependency, 'npm:@voidzero-dev/vite-plus-core@')) {
            return true;
        }

        if ($dependency !== 'catalog:') {
            return false;
        }

        $workspace = $this->path('pnpm-workspace.yaml');

        if (! $this->files->isFile($workspace)) {
            return false;
        }

        $inCatalog = false;

        foreach (preg_split('/\R/', $this->files->get($workspace)) ?: [] as $line) {
            if (preg_match('/^[a-zA-Z][^:]*:/', $line)) {
                $inCatalog = preg_match('/^catalog:\s*(?:#.*)?$/', $line) === 1;

                continue;
            }

            if ($inCatalog && preg_match('/^ {2,4}vite:\s*[\'"]?(npm:@voidzero-dev\/vite-plus-core@[^\s\'"]+)[\'"]?\s*$/', $line)) {
                return true;
            }
        }

        return false;
    }

    protected function patchedApp(string $path): string
    {
        $contents = $this->files->get($path);

        if (str_contains($contents, 'resolveBedrockPage')) {
            return $contents;
        }

        $import = "import { resolveBedrockPage } from './bedrock-pages';\n";
        $contents = $import.$contents;
        $patched = preg_replace('/createInertiaApp\(\{\s*/', "createInertiaApp({\n    resolve: resolveBedrockPage,\n    ", $contents, 1, $count);

        if ($patched === null || $count !== 1) {
            throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Inertia entry [%s].', $path));
        }

        return $patched;
    }

    protected function patchedVite(string $path): string
    {
        $contents = $this->files->get($path);

        if (! preg_match('/from\s+[\'\"]vite-plus[\'\"]/', $contents)
            && ! (str_contains($contents, 'defineHostConfig')
                && preg_match('/from\s+[\'\"]@frolax\/bedrock\/vite[\'\"]/', $contents))) {
            throw new InvalidModuleSourceException(sprintf('Run vp migrate before modifying the Vite configuration [%s].', $path));
        }

        if (! str_contains($contents, 'bedrockHost') && ! str_contains($contents, 'defineHostConfig')) {
            $contents = "import { bedrockHost } from '@frolax/bedrock/vite';\n".$contents;
            $patterns = [
                '/plugins:\s*lazyPlugins\(\(\)\s*=>\s*\[\s*/',
                '/plugins:\s*\[\s*/',
            ];
            $patched = $contents;
            $count = 0;

            foreach ($patterns as $pattern) {
                $patched = preg_replace_callback(
                    $pattern,
                    static fn (array $matches): string => rtrim($matches[0])."\n        bedrockHost(),\n        ",
                    $contents,
                    1,
                    $count,
                ) ?? $contents;

                if ($count === 1) {
                    break;
                }
            }

            if ($count !== 1) {
                throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Vite configuration [%s].', $path));
            }

            $contents = $patched;
        }

        if (! str_contains($contents, 'bedrockToolchain')
            && ! str_contains($contents, 'mergeBedrockToolchain')
            && ! str_contains($contents, 'defineHostConfig')) {
            if (! preg_match('/export\s+default\s+defineConfig\(\s*\{[\s\S]*\}\s*\);\s*$/', $contents)) {
                throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Vite configuration [%s].', $path));
            }

            $contents = "import { mergeBedrockToolchain } from '@frolax/bedrock/vite';\n".$contents;
            $patched = preg_replace('/export\s+default\s+defineConfig\(\s*\{/', 'const hostConfig = defineConfig({', $contents, 1, $count);

            if ($patched === null || $count !== 1) {
                throw new InvalidModuleSourceException(sprintf('Refusing to modify the unfamiliar Vite configuration [%s].', $path));
            }

            $contents = rtrim($patched)."\n\nexport default mergeBedrockToolchain(hostConfig);\n";
        }

        $contents = str_replace(
            "import { mergeBedrockToolchain } from '@frolax/bedrock/vite';\nimport { bedrockHost } from '@frolax/bedrock/vite';",
            "import { bedrockHost, mergeBedrockToolchain } from '@frolax/bedrock/vite';",
            $contents,
        );

        return $contents;
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
