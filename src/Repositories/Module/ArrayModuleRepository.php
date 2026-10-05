<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Repositories\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\ModuleNotFoundException;
use Frolax\Bedrock\Models\Module;
use Illuminate\Support\Collection;

/**
 * Keeps the module registry in memory.
 *
 * Used by tests and by environments that cannot reach a database.
 */
class ArrayModuleRepository implements ModuleRepository
{
    /**
     * Registered modules keyed by module key.
     *
     * @var array<string, Module>
     */
    protected array $modules = [];

    /**
     * Seed the repository with modules.
     *
     * @param  iterable<Module>  $modules
     */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $key = $module->key;

            $this->modules[$key] = $module;
        }
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function all(): Collection
    {
        $modules = $this->modules;

        ksort($modules);

        return new Collection(array_values($modules));
    }

    public function byStatus(ModuleStatus $status): Collection
    {
        return $this->all()
            ->filter(static fn (Module $module): bool => $module->status === $status);
    }

    public function find(string $key): ?Module
    {
        return $this->modules[$key] ?? null;
    }

    public function findOrFail(string $key): Module
    {
        return $this->find($key) ?? throw ModuleNotFoundException::for($key);
    }

    public function upsert(string $key, array $attributes): Module
    {
        $module = $this->modules[$key] ?? new Module(['key' => $key]);

        $module->forceFill($attributes);

        if ($module->getAttribute('key') === null || $module->getAttribute('key') === '') {
            $module->setAttribute('key', $key);
        }

        $this->modules[$key] = $module;

        return $module;
    }

    public function delete(string $key): bool
    {
        if (! isset($this->modules[$key])) {
            return false;
        }

        unset($this->modules[$key]);

        return true;
    }
}
