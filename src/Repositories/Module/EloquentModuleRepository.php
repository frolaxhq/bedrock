<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Repositories\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\ModuleNotFoundException;
use Frolax\Bedrock\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Stores the module registry in the database.
 */
class EloquentModuleRepository implements ModuleRepository
{
    public function isAvailable(): bool
    {
        try {
            return Schema::hasTable((new Module)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    public function all(): Collection
    {
        if (! $this->isAvailable()) {
            return new Collection;
        }

        return Module::query()->orderBy('key')->get();
    }

    public function byStatus(ModuleStatus $status): Collection
    {
        if (! $this->isAvailable()) {
            return new Collection;
        }

        return Module::query()->where('status', $status->value)->orderBy('key')->get();
    }

    public function find(string $key): ?Module
    {
        if (! $this->isAvailable()) {
            return null;
        }

        return Module::query()->where('key', $key)->first();
    }

    public function findOrFail(string $key): Module
    {
        return $this->find($key) ?? throw ModuleNotFoundException::for($key);
    }

    public function upsert(string $key, array $attributes): Module
    {
        $module = $this->find($key) ?? new Module(['key' => $key]);

        $module->fill($attributes);
        $module->save();

        return $module->refresh();
    }

    public function delete(string $key): bool
    {
        $module = $this->find($key);

        return (bool) $module?->delete();
    }
}
