<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Verified deployment artifacts; cache and release directories must be trusted. */
class CompiledModuleBoot
{
    /** @var array<string, mixed>|null */
    protected ?array $snapshot = null;

    public function enabled(): bool
    {
        return app()->isProduction() && config('bedrock.modules.boot.compiled', false)
            && is_string(config('bedrock.modules.php.validation.release'))
            && trim(config('bedrock.modules.php.validation.release')) !== '';
    }

    protected function key(): string
    {
        return 'bedrock.boot.'.hash('sha256', config('bedrock.modules.cache.key', 'modules.registry'));
    }

    protected function identity(): string
    {
        return hash('sha256', serialize([base_path(), PHP_VERSION, config('bedrock.modules.php.validation.release')]));
    }

    protected function store(): Repository
    {
        return Cache::store(config('bedrock.modules.cache.store'));
    }

    public function invalidate(): void
    {
        $this->snapshot = null;

        if (! $this->enabled()) {
            return;
        }

        // Propagate outages: a lifecycle write must not leave an accepted stale artifact.
        if (! $this->store()->forever($this->key(), (string) Str::uuid())) {
            throw new RuntimeException('Cannot invalidate the compiled module generation.');
        }
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $previous = $this->snapshot;
        $this->snapshot = null;

        if (! $this->enabled()) {
            return null;
        }

        try {
            $generation = $this->store()->get($this->key());

            if (! is_string($generation) || ! preg_match('/^[a-f0-9-]{36}$/', $generation)) {
                return null;
            }

            if ($previous !== null && ($previous['generation'] ?? null) === $generation
                && $previous['identity'] === $this->identity()) {
                return $this->snapshot = $previous;
            }
            $file = $this->path($generation);

            if (! is_file($file)) {
                return null;
            }
            $data = require $file;

            if (! is_array($data) || ! is_array($data['extensions'] ?? null) || ! is_array($data['modules'] ?? null)) {
                return null;
            }
            foreach ($data['modules'] as $entry) {
                if (! is_array($entry['attributes'] ?? null) || ! is_string($entry['attributes']['key'] ?? null)
                    || ! is_string($entry['attributes']['provider'] ?? null)
                    || ! is_string($entry['loading']['root'] ?? null)
                    || ! is_array($entry['loading']['maps']['psr4'] ?? null)
                    || ! is_array($entry['loading']['maps']['classmap'] ?? null)
                    || ! is_array($entry['loading']['maps']['files'] ?? null)
                    || (($data['schema'] ?? null) === 2 && ! is_array($entry['loading']['maps']['private_classmap'] ?? null))
                    || (($data['schema'] ?? null) === 3 && ! is_array($entry['loading']['maps']['groups'] ?? null))) {
                    return null;
                }
            }
            foreach ($data['modules'] as $entry) {
                foreach ($entry['loading']['maps']['groups'] ?? [] as $group => $map) {
                    if (! is_string($group) || ! is_array($map)) {
                        return null;
                    }
                }
            }
            foreach ($data['extensions'] as $extension => $version) {
                if (! extension_loaded($extension) || phpversion($extension) !== $version) {
                    return null;
                }
            }

            return $this->snapshot = in_array($data['schema'] ?? null, [1, 2, 3], true)
                && ($data['identity'] ?? null) === $this->identity() ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Only consulted after this request has accepted a verified snapshot. */
    public function resourceExists(string $provider, string $path): ?bool
    {
        $value = $this->snapshot['resources'][$provider][$path] ?? null;

        // read() validates the request snapshot; rejection and invalidation clear it.
        return is_bool($value) ? $value : null;
    }

    protected function path(string $generation): string
    {
        return app()->bootstrapPath('cache/bedrock-'.$this->identity().'-'.$generation.'.php');
    }

    public function warm(): void
    {
        if (! $this->enabled()) {
            return;
        }
        $store = $this->store();
        $generation = $store->get($this->key());

        if (! is_string($generation)) {
            $store->add($this->key(), (string) Str::uuid());
            $generation = $store->get($this->key());
        }

        if (! is_string($generation) || ! preg_match('/^[a-f0-9-]{36}$/', $generation)) {
            throw new RuntimeException('The module boot generation is unavailable.');
        }
        $modules = app(ModuleRegistry::class)->enabled()->keyBy('key');
        $manifests = $modules->map(static fn (Module $module): Manifest => $module->toManifest());
        $order = app(DependencyResolver::class)->order($manifests);
        $entries = [];
        $extensions = [];
        $resources = [];
        foreach ($order as $key) {
            $module = $modules->get($key);
            $manifest = $manifests->get($key);
            app(CoreVersionGuard::class)->ensureSatisfied($manifest);
            app(ComposerRequirementChecker::class)->ensureSatisfied($manifest);
            app(ModuleFeatureHandlers::class)->ensureSupported($manifest);
            app(DependencyGuard::class)->ensureSatisfied($manifest);
            $failures = app(ModuleAutoloader::class)->failures($manifest, (string) $module->path);

            if ($failures !== []) {
                throw new RuntimeException($failures[0]);
            }
            $requirements = $manifest->composer['require'] ?? [];
            $lockPath = $module->path.'/composer.lock';

            if (is_file($lockPath)) {
                $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
                $requirements += $lock['platform'] ?? [];
                foreach ($lock['packages'] ?? [] as $package) {
                    $requirements += $package['require'] ?? [];
                }
            }
            foreach (array_keys($requirements) as $package) {
                if (is_string($package) && str_starts_with($package, 'ext-')) {
                    $extensions[substr($package, 4)] = phpversion(substr($package, 4));
                }
            }
            $provider = $module->provider;

            if (class_exists($provider, false) && is_subclass_of($provider, ModuleServiceProvider::class)) {
                $reflection = new ReflectionClass($provider);

                if ($reflection->getMethod('files')->getDeclaringClass()->getName() === ModuleServiceProvider::class) {
                    $defaults = $reflection->getDefaultProperties();
                    $root = $defaults['modulePath'] ?? null;

                    if (is_string($root) && $root !== '') {
                        foreach (['/resources/views', '/lang'] as $suffix) {
                            $resources[$provider][$root.$suffix] = is_dir($root.$suffix);
                        }
                    }
                }
            }
            $loading = app(PhpRuntime::class)->loading($manifest, (string) $module->path, true);
            $groups = [];
            foreach ($loading['maps']['classmap'] as $class => $file) {
                $groups[ModuleClassMapLoader::group($class)][$class] = $file;
            }
            $loading['maps']['classmap'] = [];
            $loading['maps']['groups'] = $groups;
            $entries[] = ['attributes' => $module->getAttributes(), 'loading' => $loading];
        }
        $file = $this->path($generation);

        // Never replace a published filename: OPcache may have timestamps disabled.
        if (is_file($file)) {
            throw new RuntimeException('Clear the module registry before rebuilding a compiled artifact.');
        }
        $temporary = $file.'.'.Str::uuid().'.tmp';

        try {
            if (file_put_contents($temporary, '<?php return '.var_export(['schema' => 3, 'generation' => $generation, 'identity' => $this->identity(), 'modules' => $entries, 'extensions' => $extensions, 'resources' => $resources], true).';', LOCK_EX) === false) {
                throw new RuntimeException('Cannot write the compiled module artifact.');
            }

            if ($store->get($this->key()) !== $generation || ! link($temporary, $file)) {
                throw new RuntimeException('Module registry changed during compilation; retry warming.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
