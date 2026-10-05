<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Spatie\LaravelData\Data;

/**
 * A module directory that was found on disk together with its valid manifest.
 */
class DiscoveredModule extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly Manifest $manifest,
    ) {}
}
