<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Permissions;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Permissions\PermissionDefinition;
use Frolax\Bedrock\Events\Permissions\PermissionCatalogSynced;
use Frolax\Bedrock\Models\ModulePermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class EloquentModulePermissions implements ModulePermissions
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected ModuleRegistry $modules,
        protected array $config = [],
    ) {}

    public function all(?string $module = null): Collection
    {
        if (! $this->catalogAvailable()) {
            return new Collection;
        }

        return $this->catalog($module)->map(fn (ModulePermission $permission): PermissionDefinition => $this->definition($permission));
    }

    public function active(?string $module = null): Collection
    {
        if (! $this->catalogAvailable()) {
            return new Collection;
        }

        return $this->catalog($module)->where('active', true)
            ->map(fn (ModulePermission $permission): PermissionDefinition => $this->definition($permission))->values();
    }

    public function sync(?string $module = null): void
    {
        if (! $this->catalogAvailable()) {
            return;
        }

        $modules = $module === null ? $this->modules->all() : collect([$this->modules->find($module)]);

        foreach ($modules->filter() as $registered) {
            $this->syncModule((string) $registered->key, (array) $registered->toManifest()->permissions);
        }
        event(new PermissionCatalogSynced($module));
    }

    public function deactivate(string $module): void
    {
        if ($this->catalogAvailable()) {
            ModulePermission::query()->where('module_key', $module)->update(['active' => false]);
        }
    }

    public function name(string $module, string $key): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $module) !== 1 || preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
            throw new InvalidArgumentException('Module permission names must use lowercase letters, digits, dots, dashes, and underscores.');
        }

        return $module.'.'.$key;
    }

    public function can(Authenticatable $user, string $module, string $key): bool
    {
        return Gate::forUser($user)->allows($this->name($module, $key));
    }

    public function isManaged(string $ability): bool
    {
        return $this->catalogAvailable() && ModulePermission::query()->where('name', $ability)->exists();
    }

    public function isActive(string $ability): bool
    {
        return $this->catalogAvailable() && ModulePermission::query()->where('name', $ability)->where('active', true)->exists();
    }

    public function activeNames(): Collection
    {
        if (! $this->catalogAvailable()) {
            return new Collection;
        }

        return ModulePermission::query()->where('active', true)->pluck('name');
    }

    /** @param list<mixed> $permissions */
    protected function syncModule(string $module, array $permissions): void
    {
        $definitions = collect($permissions)->map(fn (mixed $permission): array => $this->manifestDefinition($module, $permission));
        $keys = $definitions->pluck('key')->all();

        $inactive = ModulePermission::query()->where('module_key', $module);

        if ($keys !== []) {
            $inactive->whereNotIn('permission_key', $keys);
        }

        $inactive->update(['active' => false]);

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            unset($definition['key']);
            ModulePermission::query()->updateOrCreate(
                ['module_key' => $module, 'permission_key' => $key],
                [...$definition, 'active' => true],
            );
        }
    }

    /** @return array{module_key: string, permission_key: string, key: string, name: string, label: string, description: string|null} */
    protected function manifestDefinition(string $module, mixed $permission): array
    {
        $definition = is_string($permission) ? ['key' => $permission] : $permission;

        if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
            throw new InvalidArgumentException(sprintf('The module [%s] declares an invalid permission.', $module));
        }

        $key = $definition['key'];

        return [
            'module_key' => $module,
            'permission_key' => $key,
            'key' => $key,
            'name' => $this->name($module, $key),
            'label' => (string) ($definition['label'] ?? str($key)->replace(['_', '.', '-'], ' ')->headline()),
            'description' => isset($definition['description']) ? (string) $definition['description'] : null,
        ];
    }

    protected function definition(ModulePermission $permission): PermissionDefinition
    {
        return new PermissionDefinition(
            (string) $permission->module_key,
            (string) $permission->permission_key,
            (string) $permission->name,
            (string) $permission->label,
            $permission->description === null ? null : (string) $permission->description,
            (bool) $permission->active,
        );
    }

    /** @return Collection<int, ModulePermission> */
    protected function catalog(?string $module): Collection
    {
        $query = ModulePermission::query();

        if ($module !== null) {
            $query->where('module_key', $module);
        }

        return $query->orderBy('module_key')->orderBy('permission_key')->get();
    }

    protected function catalogAvailable(): bool
    {
        try {
            return Schema::hasTable((new ModulePermission)->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
