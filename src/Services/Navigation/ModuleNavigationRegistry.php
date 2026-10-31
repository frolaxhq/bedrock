<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Navigation;

use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Frolax\Bedrock\DataObjects\Navigation\NavigationItem;
use Frolax\Bedrock\Services\Frontend\ViewerContextFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Throwable;

class ModuleNavigationRegistry implements ModuleNavigation
{
    /** @param array<string, mixed> $cacheConfig */
    public function __construct(
        protected FrontendRegistry $frontend,
        protected ModulePermissions $permissions,
        protected ViewerContextFactory $viewer,
        protected Cache $cache,
        protected array $cacheConfig = [],
    ) {}

    public function all(?Authenticatable $user = null): Collection
    {
        $panel = $this->panel($user);

        return $this->items()
            ->map(fn (NavigationItem $item): ?NavigationItem => $this->authorize($item, $user, $panel))
            ->filter()
            ->values();
    }

    public function refresh(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /** @return Collection<int, NavigationItem> */
    protected function items(): Collection
    {
        $definitions = fn (): array => $this->frontend->all()->mapWithKeys(
            static fn (FrontendModule $module): array => [$module->key => $module->navigation['items']],
        )->all();

        $modules = (bool) ($this->cacheConfig['enabled'] ?? true)
            ? $this->store()->remember($this->cacheKey(), (int) ($this->cacheConfig['ttl'] ?? 3600), $definitions)
            : $definitions();

        // Cache definitions only: URLs, route defaults and active state belong to the request.
        return collect($modules)->flatMap(fn (array $items, string $module): array => collect($items)
            ->map(fn (array $entry): ?NavigationItem => $this->make($module, $entry))
            ->filter()->all())
            ->sortBy([['order', 'asc'], ['label', 'asc'], ['module', 'asc']])->values();
    }

    /** @param array<string, mixed> $entry */
    protected function make(string $module, array $entry, ?string $parent = null): ?NavigationItem
    {
        $kind = (string) ($entry['kind'] ?? '');

        if (! in_array($kind, ['link', 'action'], true)) {
            return null;
        }

        $route = isset($entry['route']) ? (string) $entry['route'] : null;
        $href = null;

        if ($kind === 'link') {
            if ($route === null || ! Route::has($route)) {
                return null;
            }

            try {
                $href = route($route, [], false);
            } catch (Throwable) {
                return null;
            }
        }

        $permission = isset($entry['permission']) ? (string) $entry['permission'] : null;

        if ($permission !== null) {
            $permission = $this->permissions->name($module, $permission);
        }

        $children = array_values(collect((array) ($entry['children'] ?? []))
            ->map(fn (mixed $child): ?NavigationItem => is_array($child) ? $this->make($module, $child, (string) $entry['key']) : null)
            ->filter()
            ->values()
            ->all());

        return new NavigationItem(
            $module,
            (string) $entry['key'],
            $kind,
            (string) $entry['label'],
            $route,
            $href,
            isset($entry['group']) ? (string) $entry['group'] : null,
            (int) ($entry['order'] ?? 0),
            $permission,
            array_values(array_filter((array) ($entry['panels'] ?? []), 'is_string')),
            $this->active($route, (array) ($entry['active'] ?? [])),
            $parent ?? (isset($entry['parent']) ? (string) $entry['parent'] : null),
            $children,
        );
    }

    protected function authorize(NavigationItem $item, ?Authenticatable $user, string $panel): ?NavigationItem
    {
        if (($item->panels !== [] && ! in_array($panel, $item->panels, true))
            || ($item->permission !== null && ($user === null || Gate::forUser($user)->denies($item->permission)))
        ) {
            return null;
        }

        $children = array_values(collect($item->children)
            ->map(fn (NavigationItem $child): ?NavigationItem => $this->authorize($child, $user, $panel))
            ->filter()
            ->values()
            ->all());

        return new NavigationItem(
            $item->module,
            $item->key,
            $item->kind,
            $item->label,
            $item->route,
            $item->href,
            $item->group,
            $item->order,
            $item->permission,
            $item->panels,
            $item->active,
            $item->parent,
            $children,
        );
    }

    protected function panel(?Authenticatable $user): string
    {
        return $this->viewer->panel($user);
    }

    /** @param array<int, mixed> $patterns */
    protected function active(?string $route, array $patterns): bool
    {
        $patterns = array_values(array_filter($patterns, 'is_string'));

        if ($patterns === [] && $route !== null) {
            $patterns = [$route];
        }

        return $patterns !== [] && request()->routeIs(...$patterns);
    }

    protected function cacheKey(): string
    {
        return (string) ($this->cacheConfig['key'] ?? 'bedrock.navigation').':definitions:v2';
    }

    protected function store(): Repository
    {
        $store = $this->cacheConfig['store'] ?? null;

        return is_string($store) && $store !== '' ? $this->cache->store($store) : $this->cache->store();
    }
}
