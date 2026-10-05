<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Frolax\Bedrock\Models\Module;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Collection;

class ModuleFrontendRegistry implements FrontendRegistry
{
    /** @param array<string, mixed> $cacheConfig */
    public function __construct(
        protected ModuleRegistry $modules,
        protected FrontendAssetManager $assets,
        protected CacheFactory $cache,
        protected array $cacheConfig = [],
    ) {}

    public function all(): Collection
    {
        $store = $this->cache->store($this->store());
        $key = $this->key();
        $items = $store->remember($key, $this->ttl(), fn (): array => $this->resolve()->all());

        return collect($items)->map(static fn (array $item): FrontendModule => FrontendModule::from($item))->values();
    }

    public function find(string $key): ?FrontendModule
    {
        return $this->all()->first(static fn (FrontendModule $module): bool => $module->key === $key);
    }

    public function isAvailable(string $key): bool
    {
        return $this->find($key) !== null;
    }

    public function refresh(): void
    {
        $this->cache->store($this->store())->forget($this->key());
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function resolve(): Collection
    {
        return $this->modules->enabled()
            ->map(fn (Module $module): ?FrontendModule => $this->assets->descriptor($module->toManifest()))
            ->filter()
            ->map(fn (FrontendModule $module): array => $this->serialize($module))
            ->values();
    }

    /** @return array<string, mixed> */
    protected function serialize(FrontendModule $module): array
    {
        return $module->toArray();
    }

    protected function store(): ?string
    {
        $store = $this->cacheConfig['store'] ?? null;

        return is_string($store) && $store !== '' ? $store : null;
    }

    protected function key(): string
    {
        $key = $this->cacheConfig['key'] ?? 'bedrock.frontend.registry';

        return is_string($key) && $key !== '' ? $key : 'bedrock.frontend.registry';
    }

    protected function ttl(): int
    {
        $ttl = $this->cacheConfig['ttl'] ?? 3600;

        return is_int($ttl) && $ttl > 0 ? $ttl : 3600;
    }
}
