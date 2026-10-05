<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Spatie\LaravelData\Data;

/**
 * The outcome of scanning the module directory.
 */
class ModuleDiscoveryResult extends Data
{
    /**
     * Create a new discovery result.
     *
     * @param  list<DiscoveredModule>  $modules  Modules with a valid manifest, ordered by key.
     * @param  array<string, string>  $failures  Invalid module directories keyed by directory name.
     */
    public function __construct(
        public readonly array $modules = [],
        public readonly array $failures = [],
    ) {}

    /**
     * Determine whether any discovered module failed validation.
     */
    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * Find a discovered module by its key.
     */
    public function find(string $key): ?DiscoveredModule
    {
        foreach ($this->modules as $module) {
            if ($module->key === $key) {
                return $module;
            }
        }

        return null;
    }
}
