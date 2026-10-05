<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\Models\Module;
use Illuminate\Support\Collection;

interface ModuleRegistry
{
    /**
     * Every registered module, ordered by key.
     *
     * @return Collection<int, Module>
     */
    public function all(): Collection;

    /**
     * Every registered module that is enabled.
     *
     * @return Collection<int, Module>
     */
    public function enabled(): Collection;

    /**
     * Find a registered module by key.
     */
    public function find(string $key): ?Module;

    /**
     * Determine whether the given module is enabled.
     */
    public function isEnabled(string $key): bool;

    /**
     * Reload the registry from its source of truth.
     */
    public function refresh(): void;
}
