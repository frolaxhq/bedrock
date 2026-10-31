<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Events\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;

class ModuleUpgraded
{
    public function __construct(
        public readonly string $key,
        public readonly Manifest $manifest,
        public readonly string $previousVersion,
    ) {}
}
