<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Permissions;

use Frolax\Bedrock\DataObjects\Permissions\PermissionDefinition;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface ModulePermissions
{
    /** @return Collection<int, PermissionDefinition> */
    public function all(?string $module = null): Collection;

    /** @return Collection<int, PermissionDefinition> */
    public function active(?string $module = null): Collection;

    public function sync(?string $module = null): void;

    public function deactivate(string $module): void;

    public function name(string $module, string $key): string;

    public function can(Authenticatable $user, string $module, string $key): bool;

    public function isManaged(string $ability): bool;

    public function isActive(string $ability): bool;

    /** @return Collection<int, string> */
    public function activeNames(): Collection;
}
