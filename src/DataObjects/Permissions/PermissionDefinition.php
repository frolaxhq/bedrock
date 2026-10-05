<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Permissions;

use Spatie\LaravelData\Data;

class PermissionDefinition extends Data
{
    public function __construct(
        public readonly string $module,
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly bool $active = true,
    ) {}
}
