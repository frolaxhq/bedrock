<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Models\Module;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads the module registry from the repository and caches the enabled set for boot.
 *
 * Reading the registry happens on every request, including the very first one in
 * a fresh application where neither the modules table nor the cache backing store
 * exist yet. Every read therefore degrades to an empty set instead of failing the
 * whole boot.
 */
class CachedModuleRegistry implements ModuleRegistry
{
    /**
     * Create a new registry.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected ModuleRepository $repository,
        protected CacheFactory $cache,
        protected array $config = [],
    ) {}

    public function all(): Collection
    {
        try {
            return $this->repository->all();
        } catch (Throwable) {
            return new Collection;
        }
    }

    public function enabled(): Collection
    {
        try {
            if (! $this->repository->isAvailable()) {
                return new Collection;
            }

            if (! $this->cachingEnabled()) {
                return $this->loadEnabled();
            }

            $snapshot = $this->store()->remember(
                $this->cacheKey(),
                $this->cacheTtl(),
                fn (): array => $this->loadEnabledSnapshot(),
            );

            return new Collection(array_map(
                static fn (array $attributes): Module => (new Module)->newFromBuilder($attributes),
                $snapshot,
            ));
        } catch (Throwable) {
            return new Collection;
        }
    }

    public function find(string $key): ?Module
    {
        try {
            return $this->repository->find($key);
        } catch (Throwable) {
            return null;
        }
    }

    public function isEnabled(string $key): bool
    {
        $module = $this->find($key);

        return $module !== null
            && $module->status === ModuleStatus::Enabled
            && $module->health === ModuleHealth::Healthy;
    }

    public function refresh(): void
    {
        app(CompiledModuleBoot::class)->invalidate();

        try {
            $this->store()->forget($this->cacheKey());
        } catch (Throwable) {
            // The cache store is unavailable; there is nothing to invalidate.
        }
    }

    /**
     * Read the enabled modules from the repository.
     *
     * @return Collection<int, Module>
     */
    protected function loadEnabled(): Collection
    {
        if (! $this->repository->isAvailable()) {
            return new Collection;
        }

        return $this->repository->byStatus(ModuleStatus::Enabled)
            ->filter(static fn (Module $module): bool => $module->health === ModuleHealth::Healthy)
            ->values();
    }

    /**
     * Build a cache-safe scalar snapshot instead of serializing Eloquent objects.
     *
     * Laravel cache stores may deliberately disallow object unserialization. Raw
     * model attributes keep the boot path query-free and can be hydrated safely.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadEnabledSnapshot(): array
    {
        return array_values($this->loadEnabled()
            ->map(static fn (Module $module): array => $module->getAttributes())
            ->values()
            ->all());
    }

    /**
     * Determine whether the enabled module set should be cached.
     */
    protected function cachingEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * The cache store used for the registry snapshot.
     */
    protected function store(): CacheRepository
    {
        $store = $this->config['store'] ?? null;

        return $this->cache->store(is_string($store) ? $store : null);
    }

    /**
     * The cache key used for the registry snapshot.
     */
    protected function cacheKey(): string
    {
        $key = $this->config['key'] ?? 'modules.registry';

        return is_string($key) && $key !== '' ? $key : 'modules.registry';
    }

    /**
     * The cache lifetime, in seconds.
     */
    protected function cacheTtl(): int
    {
        $ttl = $this->config['ttl'] ?? 3600;

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }
}
