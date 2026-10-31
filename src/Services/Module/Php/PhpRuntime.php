<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Php;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

/** A data-only boundary between untrusted build artifacts and the active loader. */
class PhpRuntime
{
    public function __construct(
        protected DependencyManifest $dependencies,
        protected PhpRuntimeBuilder $builder,
    ) {}

    public function validate(Manifest $manifest, string $path, bool $requirePrepared = false): void
    {
        $data = $this->dependencies->inspect($manifest, $path);

        if ($data === null) {
            return;
        }

        $runtime = rtrim($path, '/\\').'/dist/php';

        if (file_exists($runtime) || is_link($runtime)) {
            $this->artifact($manifest, $path, $data, $runtime);
        } elseif ($requirePrepared) {
            throw new InvalidModuleSourceException('The module PHP runtime must be prepared before loading.');
        }
    }

    public function prepare(Manifest $manifest, string $path, bool $offline = false, bool $rebuild = false): void
    {
        // A sibling lock survives atomic publication without becoming part of the artifact.
        $lockPath = sys_get_temp_dir().'/bedrock-php-'.hash('sha256', realpath($path) ?: $path).'.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new InvalidModuleSourceException('Could not lock module PHP runtime preparation.');
        }

        try {
            $this->prepareLocked($manifest, $path, $offline || (bool) config('bedrock.modules.php.offline', false), $rebuild);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function prepareLocked(Manifest $manifest, string $path, bool $offline, bool $rebuild): void
    {
        $data = $this->dependencies->inspect($manifest, $path);

        if ($data === null) {
            return;
        }

        // Never silently replace a supplied but corrupt artifact.
        if (! $rebuild && (file_exists($path.'/dist/php') || is_link($path.'/dist/php'))) {
            $this->validate($manifest, $path, true);

            return;
        }

        $stage = $this->builder->build($manifest, $path, $data, $offline);
        $previous = null;

        try {
            $this->artifact($manifest, $path, $data, $stage);

            if ($rebuild && file_exists($path.'/dist/php')) {
                $destination = realpath($path.'/dist');

                if ($destination === false || is_link($path.'/dist') || is_link($path.'/dist/php') || ! is_dir($path.'/dist/php')) {
                    throw new InvalidModuleSourceException('The existing module PHP runtime directory is unsafe.');
                }
                $previous = $destination.'/.php-stage-'.bin2hex(random_bytes(12));

                if (! rename($path.'/dist/php', $previous)) {
                    throw new InvalidModuleSourceException('Could not preserve the previous module PHP runtime.');
                }
            }
            $this->builder->publish($path, $stage);
        } catch (Throwable $exception) {
            if ($previous !== null && ! file_exists($path.'/dist/php')) {
                rename($previous, $path.'/dist/php');
                $previous = null;
            }

            throw $exception;
        } finally {
            $this->builder->discard($stage);

            if ($previous !== null) {
                $this->builder->discard($previous);
            }
        }
    }

    /** @return array{root: string, maps: array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>}} */
    public function loading(Manifest $manifest, string $path, bool $force = false): array
    {
        $key = $this->loadingCacheKey($manifest, $path);
        $store = null;

        if ($key !== null) {
            try {
                $store = Cache::store(config('bedrock.modules.php.validation.store'));
                $cached = $force ? null : $store->get($key);

                if (is_array($cached) && ($cached['schema'] ?? null) === 1
                    && is_string($cached['loading']['root'] ?? null)
                    && is_array($cached['loading']['maps']['psr4'] ?? null)
                    && is_array($cached['loading']['maps']['classmap'] ?? null)
                    && is_array($cached['loading']['maps']['files'] ?? null)) {
                    return $cached['loading'];
                }
            } catch (Throwable) {
                $store = null;
            }
        }

        $this->validate($manifest, $path, true);
        $loading = ! is_file($path.'/composer.json')
            ? ['root' => $path, 'maps' => $this->builder->legacyMaps($manifest, $path)]
            : ['root' => rtrim($path, '/\\').'/dist/php/module', 'maps' => $this->readMaps($path.'/dist/php')];

        if ($store !== null) {
            try {
                $store->put($key, ['schema' => 1, 'loading' => $loading], max(1, (int) config('bedrock.modules.php.validation.ttl', 300)));
            } catch (Throwable) {
                // Loading remains valid when the optional cache cannot be written.
            }
        }

        return $loading;
    }

    protected function loadingCacheKey(Manifest $manifest, string $path): ?string
    {
        if (config('bedrock.modules.boot.strict_fallback', false)) {
            return null;
        }

        $release = config('bedrock.modules.php.validation.release');

        if (! app()->isProduction() || ! config('bedrock.modules.php.validation.cache', false)
            || ! is_string($release) || trim($release) === '' || ! is_file($path.'/composer.json')) {
            return null;
        }

        $files = [ModulePath::manifest(app()), 'composer.json', 'composer.lock', 'dist/php/runtime.json', 'dist/php/autoload.json'];
        $hashes = [];

        foreach ($files as $file) {
            $absolute = $path.'/'.$file;

            if (! is_file($absolute) || is_link($absolute)) {
                return null;
            }

            $hash = hash_file('sha256', $absolute);

            if ($hash === false) {
                return null;
            }

            $hashes[$file] = $hash;
        }

        if (is_link($path.'/dist') || is_link($path.'/dist/php') || is_link($path.'/vendor') || is_link($path.'/vendor/composer')) {
            return null;
        }

        $extensions = [];

        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = phpversion($extension);
        }

        ksort($extensions);

        return 'bedrock.php.loading.'.hash('sha256', json_encode([
            1, base_path(), realpath($path), realpath($path.'/dist/php'), PHP_VERSION, $extensions,
            $release, $manifest->toArray(), $hashes,
        ], JSON_THROW_ON_ERROR));
    }

    public function root(Manifest $manifest, string $path): string
    {
        $this->validate($manifest, $path, true);

        return is_file($path.'/composer.json') ? rtrim($path, '/\\').'/dist/php/module' : $path;
    }

    /** @return array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>} */
    public function maps(Manifest $manifest, string $path): array
    {
        $this->validate($manifest, $path, true);

        if (! is_file($path.'/composer.json')) {
            return $this->builder->legacyMaps($manifest, $path);
        }

        return $this->readMaps($path.'/dist/php');
    }

    /** @param array<string, mixed> $data */
    protected function artifact(Manifest $manifest, string $path, array $data, string $runtime): void
    {
        $real = realpath($runtime);
        $dist = realpath($path.'/dist');

        if ($real === false || $dist === false || is_link($path.'/dist') || is_link($runtime)
            || dirname($real) !== $dist || ! is_dir($real.'/module')) {
            throw new InvalidModuleSourceException('The module PHP runtime directory is unsafe.');
        }

        $metadata = $this->json($runtime.'/runtime.json');

        if (($metadata['schema'] ?? null) !== 1 || ($metadata['module'] ?? null) !== $manifest->key
            || ($metadata['fingerprint'] ?? null) !== $data['fingerprint']
            || ($metadata['dependencies'] ?? null) !== $this->builder->packageIdentity($data)
            || ($data['packages'] === []
                ? ($metadata['toolchain'] ?? null) !== null
                : ! is_string($metadata['toolchain'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $metadata['toolchain']) !== 1)) {
            throw new InvalidModuleSourceException('The module PHP runtime fingerprint does not match its Composer source/lock.');
        }

        $this->verifyInventory($path, $runtime, $metadata);

        $this->readMaps($runtime);
    }

    /** @param array<string, mixed> $metadata */
    protected function verifyInventory(string $path, string $runtime, array $metadata): void
    {
        if (($metadata['source'] ?? null) !== $this->builder->inventory($path, true)) {
            throw new InvalidModuleSourceException('The module PHP runtime source fingerprint is stale. Prepare it again from clean source.');
        }

        $inventory = $this->builder->inventory($runtime);
        unset($inventory['runtime.json']);

        if (($metadata['files'] ?? null) !== $inventory) {
            throw new InvalidModuleSourceException('The module PHP runtime inventory failed SHA256 verification.');
        }
    }

    /** @return array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>} */
    protected function readMaps(string $runtime): array
    {
        $maps = $this->json($runtime.'/autoload.json');

        if (! is_array($maps['psr4'] ?? null) || ! is_array($maps['classmap'] ?? null)
            || ! is_array($maps['files'] ?? null) || ! array_is_list($maps['files'])) {
            throw new InvalidModuleSourceException('The module PHP runtime autoload maps are invalid.');
        }

        $result = ['psr4' => [], 'classmap' => [], 'files' => []];

        foreach ($maps['psr4'] as $prefix => $paths) {
            if (! is_string($prefix) || $prefix === '' || ! str_ends_with($prefix, '\\') || ! is_array($paths) || ! array_is_list($paths)) {
                throw new InvalidModuleSourceException('The module PHP runtime PSR-4 map is invalid.');
            }

            $result['psr4'][$prefix] = array_map(fn (mixed $relative): string => $this->resolve($runtime, $relative, true), $paths);
        }

        foreach ($maps['classmap'] as $class => $relative) {
            if (! is_string($class) || $class === '' || str_starts_with($class, 'Composer\\')) {
                throw new InvalidModuleSourceException('The module PHP runtime class map is invalid.');
            }

            $result['classmap'][$class] = $this->resolve($runtime, $relative, false);
        }

        foreach ($maps['files'] as $relative) {
            $result['files'][] = $this->resolve($runtime, $relative, false);
        }

        return $result;
    }

    protected function resolve(string $runtime, mixed $relative, bool $directory): string
    {
        if (! is_string($relative) || ! preg_match('~^(?:module|vendor)/(?!.*(?:^|/)\.\.(?:/|$))[^\\\\\x00]+$~D', $relative)) {
            throw new InvalidModuleSourceException('The module PHP runtime autoload path is unsafe.');
        }

        $real = realpath($runtime.'/'.$relative);
        $root = realpath($runtime);

        if ($real === false || $root === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)
            || ($directory ? ! is_dir($real) : ! is_file($real))) {
            throw new InvalidModuleSourceException('The module PHP runtime autoload path is unavailable.');
        }

        return $real;
    }

    /** @return array<string, mixed> */
    protected function json(string $path): array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 16 * 1024 * 1024) {
            throw new InvalidModuleSourceException('The module PHP runtime metadata is missing or oversized.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidModuleSourceException('The module PHP runtime metadata is invalid JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new InvalidModuleSourceException('The module PHP runtime metadata must be an object.');
        }

        return $data;
    }
}
