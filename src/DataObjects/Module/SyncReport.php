<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Spatie\LaravelData\Data;

/**
 * The outcome of syncing the registry with the modules found on disk.
 */
class SyncReport extends Data
{
    /**
     * Create a new sync report.
     *
     * @param  list<string>  $discovered  Keys registered for the first time.
     * @param  list<string>  $registered  Every key in the registry after the sync.
     * @param  array<string, string>  $failures  Discovery failures keyed by directory name.
     * @param  array<string, string>  $broken  Modules in a failed state keyed by module key.
     */
    public function __construct(
        public readonly array $discovered = [],
        public readonly array $registered = [],
        public readonly array $failures = [],
        public readonly array $broken = [],
    ) {}

    /**
     * Determine whether the sync reported any problem worth surfacing.
     */
    public function hasIssues(): bool
    {
        return $this->failures !== [] || $this->broken !== [];
    }
}
