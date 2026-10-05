<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Permissions\PermissionDefinition;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class Permissions
{
    public function __construct(protected ModulePermissions $permissions) {}

    /** @return Collection<int, PermissionDefinition> */
    public function all(?string $module = null): Collection
    {
        return $this->permissions->all($module);
    }

    /** @return Collection<int, PermissionDefinition> */
    public function active(?string $module = null): Collection
    {
        return $this->permissions->active($module);
    }

    public function sync(?string $module = null): void
    {
        $this->permissions->sync($module);
    }

    public function name(string $module, string $key): string
    {
        return $this->permissions->name($module, $key);
    }

    public function can(Authenticatable $user, string $module, string $key): bool
    {
        return $this->permissions->can($user, $module, $key);
    }
}
