<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Composer\Autoload\ClassLoader;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;

class ComposerModuleAutoloader implements ModuleAutoloader
{
    /** @var array<string, true> */
    protected array $loadedFiles = [];

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
            $loader->addClassMap($maps['classmap']);

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
            if (is_array($autoload) && $autoload[0] instanceof ClassLoader) {
                return $autoload[0];
            }
        }

        throw new InvalidModuleSourceException('Composer\'s active class loader could not be resolved.');
    }
}
