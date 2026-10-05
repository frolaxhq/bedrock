<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Administration;

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class AdministrationManager
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected ModulePermissions $permissions,
        protected array $config = [],
    ) {}

    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        return Role::query()->with('permissions')->where('guard_name', $this->guard())->orderBy('name')->get();
    }

    /** @return Collection<int, Permission> */
    public function grantablePermissions(): Collection
    {
        return Permission::query()->where('guard_name', $this->guard())
            ->whereIn('name', $this->permissions->active()->pluck('name')->push($this->adminPermission()))
            ->orderBy('name')->get();
    }

    public function createRole(string $name): Role
    {
        $name = trim($name);

        if ($name === '' || ! preg_match('/^[a-z][a-z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException('Role names must use lowercase letters, digits, dots, dashes, and underscores.');
        }

        Role::findOrCreate($name, $this->guard());

        return Role::query()->where('name', $name)->where('guard_name', $this->guard())->firstOrFail();
    }

    /** @param list<string> $permissions */
    public function syncRolePermissions(Role $role, array $permissions): void
    {
        $this->assertGuard($role);
        $allowed = $this->grantablePermissions()->pluck('name')->all();

        if (array_diff($permissions, $allowed) !== []) {
            throw new InvalidArgumentException('A role can only receive active Bedrock permissions.');
        }

        if ($role->name === $this->adminRole() && ! in_array($this->adminPermission(), $permissions, true)) {
            throw new InvalidArgumentException('The protected administrator role must retain the administration permission.');
        }

        $role->syncPermissions($permissions);
    }

    public function deleteRole(Role $role): void
    {
        $this->assertGuard($role);

        if ($role->name === $this->adminRole()) {
            throw new InvalidArgumentException('The protected administrator role cannot be deleted.');
        }

        $role->delete();
    }

    /** @param list<string> $roles */
    public function syncUserRoles(Model $user, array $roles): void
    {
        if (! method_exists($user, 'syncRoles')) {
            throw new InvalidArgumentException(sprintf('The configured user model [%s] must use Spatie\\Permission\\Traits\\HasRoles.', $user::class));
        }

        $allowed = $this->roles()->pluck('name')->all();

        if (array_diff($roles, $allowed) !== []) {
            throw new InvalidArgumentException('A user can only receive roles for the configured guard.');
        }

        $current = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [];

        if (in_array($this->adminRole(), $current, true) && ! in_array($this->adminRole(), $roles, true) && $this->adminCount() <= 1) {
            throw new InvalidArgumentException('At least one user must retain the protected administrator role.');
        }

        $user->syncRoles($roles);
    }

    public function grantAdministrator(Model $user): void
    {
        $role = $this->adminRoleModel();

        if (! method_exists($user, 'assignRole')) {
            throw new InvalidArgumentException(sprintf('The configured user model [%s] must use Spatie\\Permission\\Traits\\HasRoles.', $user::class));
        }

        $user->assignRole($role);
    }

    public function userModel(): string
    {
        $provider = (string) ($this->config['user_provider'] ?? 'users');
        $model = config('auth.providers.'.$provider.'.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException(sprintf('The auth provider [%s] does not declare an Eloquent user model.', $provider));
        }

        return $model;
    }

    /** @return array{ok: bool, problems: list<string>} */
    public function inspect(): array
    {
        $problems = [];

        foreach ((array) config('permission.table_names', []) as $table) {
            if (is_string($table) && ! Schema::hasTable($table)) {
                $problems[] = sprintf('The Spatie permission table [%s] has not been migrated.', $table);
            }
        }

        try {
            $model = $this->userModel();

            if (! in_array(HasRoles::class, class_uses_recursive($model), true)) {
                $problems[] = sprintf('The configured user model [%s] must use HasRoles.', $model);
            }
        } catch (InvalidArgumentException $exception) {
            $problems[] = $exception->getMessage();
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    public function ensureAdminRole(): Role
    {
        return $this->adminRoleModel();
    }

    protected function adminRoleModel(): Role
    {
        $permission = Permission::findOrCreate($this->adminPermission(), $this->guard());
        $role = Role::findOrCreate($this->adminRole(), $this->guard());
        $role->givePermissionTo($permission);

        return Role::query()->whereKey($role->getKey())->firstOrFail();
    }

    protected function adminCount(): int
    {
        $role = Role::findByName($this->adminRole(), $this->guard());

        return $role->users()->count();
    }

    protected function assertGuard(Role $role): void
    {
        if ($role->guard_name !== $this->guard()) {
            throw new InvalidArgumentException('The role does not belong to the configured permission guard.');
        }
    }

    protected function guard(): string
    {
        return (string) ($this->config['guard'] ?? 'web');
    }

    protected function adminPermission(): string
    {
        return (string) ($this->config['admin_permission'] ?? 'bedrock.admin');
    }

    protected function adminRole(): string
    {
        return (string) ($this->config['admin_role'] ?? 'bedrock-admin');
    }
}
