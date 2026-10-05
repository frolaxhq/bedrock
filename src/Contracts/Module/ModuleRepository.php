<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Models\Module;
use Illuminate\Support\Collection;

interface ModuleRepository
{
    /**
     * Determine whether the repository can be used in the current environment.
     */
    public function isAvailable(): bool;

    /**
     * Every registered module, ordered by key.
     *
     * @return Collection<int, Module>
     */
    public function all(): Collection;

    /**
     * Every registered module with the given status.
     *
     * @return Collection<int, Module>
     */
    public function byStatus(ModuleStatus $status): Collection;

    /**
     * Find a registered module by key.
     */
    public function find(string $key): ?Module;

    /**
     * Find the registered module with the given key or fail.
     */
    public function findOrFail(string $key): Module;

    /**
     * Persist attributes for a module key, creating the row when needed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(string $key, array $attributes): Module;

    /**
     * Remove the module with the given key.
     *
     * @return bool Whether a row was removed.
     */
    public function delete(string $key): bool;
}
