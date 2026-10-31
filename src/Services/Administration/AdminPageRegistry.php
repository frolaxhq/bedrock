<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Administration;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class AdminPageRegistry
{
    /** @var array<string, array{label: string, route: string, permission: string, module: string|null}> */
    protected array $pages = [];

    public function __construct(protected ModuleRepository $modules) {}

    public function register(string $key, string $label, string $route, string $permission, ?string $module = null): void
    {
        $this->pages[$key] = compact('label', 'route', 'permission', 'module');
    }

    /** @return list<array{label: string, route: string, permission: string, module: string|null}> */
    public function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter($this->pages, fn (array $page): bool => ($page['module'] === null || $this->modules->find($page['module'])?->isEnabled() === true)
            && Route::has($page['route']) && Gate::forUser($user)->allows($page['permission'])));
    }
}
