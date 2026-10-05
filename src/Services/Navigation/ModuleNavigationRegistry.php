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
        if ((bool) ($this->cacheConfig['enabled'] ?? true)) {
            return collect($this->store()->remember(
                $this->cacheKey(),
                (int) ($this->cacheConfig['ttl'] ?? 3600),
                fn (): array => $this->build()->map(static fn (NavigationItem $item): array => $item->toArray())->all(),
            ))->map(fn (mixed $item): NavigationItem => $this->hydrate((array) $item));
        }

        return $this->build();
    }

    /** @return Collection<int, NavigationItem> */
    protected function build(): Collection
    {
        return $this->frontend->all()->flatMap(function (FrontendModule $module): array {
            $items = $module->navigation['items'];

            return collect($items)
                ->map(fn (array $entry): ?NavigationItem => $this->make($module, $entry))
                ->filter()
                ->all();
        })->sortBy([
            ['order', 'asc'],
            ['label', 'asc'],
            ['module', 'asc'],
        ])->values();
    }

    /** @param array<string, mixed> $entry */
    protected function make(FrontendModule $module, array $entry, ?string $parent = null): ?NavigationItem
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
            $permission = $this->permissions->name($module->key, $permission);
        }

        $children = array_values(collect((array) ($entry['children'] ?? []))
            ->map(fn (mixed $child): ?NavigationItem => is_array($child) ? $this->make($module, $child, (string) $entry['key']) : null)
            ->filter()
            ->values()
            ->all());

        return new NavigationItem(
            $module->key,
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
            || ($item->permission !== null && ($user === null || ! $user->can($item->permission)))
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

    /** @param array<string, mixed> $item */
    protected function hydrate(array $item): NavigationItem
    {
        return new NavigationItem(
            (string) $item['module'],
            (string) $item['key'],
            (string) $item['kind'],
            (string) $item['label'],
            isset($item['route']) ? (string) $item['route'] : null,
            isset($item['href']) ? (string) $item['href'] : null,
            isset($item['group']) ? (string) $item['group'] : null,
            (int) ($item['order'] ?? 0),
            isset($item['permission']) ? (string) $item['permission'] : null,
            array_values(array_filter((array) ($item['panels'] ?? []), 'is_string')),
            (bool) ($item['active'] ?? false),
            isset($item['parent']) ? (string) $item['parent'] : null,
            array_values(collect((array) ($item['children'] ?? []))
                ->filter('is_array')
                ->map(fn (array $child): NavigationItem => $this->hydrate($child))
                ->values()
                ->all()),
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
        return (string) ($this->cacheConfig['key'] ?? 'bedrock.navigation');
    }

    protected function store(): Repository
    {
        $store = $this->cacheConfig['store'] ?? null;

        return is_string($store) && $store !== '' ? $this->cache->store($store) : $this->cache->store();
    }
}
