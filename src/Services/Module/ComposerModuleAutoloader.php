<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Composer\Autoload\ClassLoader;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use WeakMap;

class ComposerModuleAutoloader implements ModuleAutoloader
{
    /** @var array<string, true> */
    protected array $loadedFiles = [];

    /** @var WeakMap<ClassLoader, ModuleClassMapLoader>|null */
    protected static ?WeakMap $compiledLoaders = null;

    public function register(Manifest $manifest, string $path, bool $loadFiles = false): void
    {
        $failures = $this->failures($manifest, $path);

        if ($failures !== []) {
            throw new InvalidModuleSourceException($failures[0]);
        }

        $runtime = app(PhpRuntime::class);
        $original = $path;
        $loading = $runtime->loading($manifest, $path);
        $path = $loading['root'];
        $loader = $this->loader();

        if ($path !== $original) {
            $maps = $loading['maps'];
            foreach ($maps['psr4'] as $prefix => $paths) {
                $loader->addPsr4($prefix, $paths);
            }

            if (self::$compiledLoaders !== null && isset(self::$compiledLoaders[$loader])) {
                $this->registerClassMap($maps['classmap'], $loader);
            } else {
                $loader->addClassMap($maps['classmap']);
            }

            if ($loadFiles) {
                foreach ($maps['files'] as $file) {
                    if (! isset($this->loadedFiles[$file])) {
                        require_once $file;
                        $this->loadedFiles[$file] = true;
                    }
                }
            }

            return;
        }

        foreach (($manifest->autoload['psr-4'] ?? []) as $prefix => $paths) {
            $paths = is_string($paths) ? [$paths] : $paths;
            $loader->addPsr4($prefix, array_values(array_map(
                static fn (string $autoloadPath): string => rtrim($path, '/\\').'/'.$autoloadPath,
                $paths,
            )));
        }

        if (! $loadFiles) {
            return;
        }

        foreach (($manifest->autoload['files'] ?? []) as $file) {
            $absolute = rtrim($path, '/\\').'/'.$file;
            $real = realpath($absolute) ?: $absolute;

            if (isset($this->loadedFiles[$real])) {
                continue;
            }

            require_once $absolute;
            $this->loadedFiles[$real] = true;
        }
    }

    /** @param array{root: string, maps: array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>, private_classmap?: array<string, string>, groups?: array<string, array<string, string>>}} $loading */
    public function registerLoading(array $loading): void
    {
        $loader = $this->loader();
        foreach ($loading['maps']['psr4'] as $prefix => $paths) {
            $loader->addPsr4($prefix, $paths);
        }

        if (isset($loading['maps']['groups'])) {
            $this->registerGroupedClassMap($loading['maps']['groups'], $loader);
        } elseif (isset($loading['maps']['private_classmap'])) {
            $this->registerCompiledClassMap($loading['maps']['private_classmap'], $loader);

            if ($loading['maps']['classmap'] !== []) {
                $this->registerCompiledClassMap($loading['maps']['classmap'], $loader);
            }
        } else {
            $this->registerClassMap($loading['maps']['classmap'], $loader);
        }
        foreach ($loading['maps']['files'] as $file) {
            if (! isset($this->loadedFiles[$file])) {
                require_once $file;
                $this->loadedFiles[$file] = true;
            }
        }
    }

    /** @param array<string, string> $classmap */
    protected function registerClassMap(array $classmap, ClassLoader $loader): void
    {
        $public = [];
        $private = [];
        foreach ($classmap as $class => $file) {
            if (str_starts_with($class, 'BedrockPrivate\\')) {
                $private[$class] = $file;
            } else {
                $public[$class] = $file;
            }
        }

        $this->registerCompiledClassMap($private, $loader);

        if ($public !== []) {
            $this->registerCompiledClassMap($public, $loader);
        }
    }

    /** @param array<string, string> $private */
    protected function registerCompiledClassMap(array $private, ClassLoader $loader): void
    {
        if ($private === []) {
            return;
        }
        $this->compiledLoader($loader)->addClassMap($private);
    }

    /** @param array<string, array<string, string>> $groups */
    protected function registerGroupedClassMap(array $groups, ClassLoader $loader): void
    {
        if ($groups !== []) {
            $this->compiledLoader($loader)->addGroupedClassMap($groups);
        }
    }

    protected function compiledLoader(ClassLoader $loader): ModuleClassMapLoader
    {
        if (self::$compiledLoaders === null) {
            /** @var WeakMap<ClassLoader, ModuleClassMapLoader> $loaders */
            $loaders = new WeakMap;
            self::$compiledLoaders = $loaders;
        }

        if (! isset(self::$compiledLoaders[$loader])) {
            $moduleLoader = new ModuleClassMapLoader;
            $moduleLoader->registerBefore($loader);
            self::$compiledLoaders[$loader] = $moduleLoader;
        }

        return self::$compiledLoaders[$loader];
    }

    public function failures(Manifest $manifest, string $path): array
    {
        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            return [sprintf('The module directory [%s] does not exist.', $path)];
        }

        $failures = [];

        foreach (($manifest->autoload['psr-4'] ?? []) as $prefix => $paths) {
            foreach (is_string($paths) ? [$paths] : $paths as $autoloadPath) {
                if (! $this->contained($root, $autoloadPath, true)) {
                    $failures[] = sprintf('Autoload prefix [%s] points to an unavailable or unsafe directory [%s].', $prefix, $autoloadPath);
                }
            }
        }

        foreach (($manifest->autoload['files'] ?? []) as $file) {
            if (! $this->contained($root, $file, false)) {
                $failures[] = sprintf('Autoload file [%s] is unavailable or outside the module directory.', $file);
            }
        }

        return $failures;
    }

    protected function contained(string $root, string $relative, bool $directory): bool
    {
        $resolved = realpath($root.'/'.$relative);

        if ($resolved === false || ($directory ? ! is_dir($resolved) : ! is_file($resolved))) {
            return false;
        }

        return $resolved === $root || str_starts_with($resolved, $root.DIRECTORY_SEPARATOR);
    }

    protected function loader(): ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $autoload) {
            if (is_array($autoload) && $autoload[0] instanceof ClassLoader && ! $autoload[0] instanceof ModuleClassMapLoader) {
                return $autoload[0];
            }
        }

        throw new InvalidModuleSourceException('Composer\'s active class loader could not be resolved.');
    }
}
