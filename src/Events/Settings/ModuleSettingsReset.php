<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Events\Settings;

class ModuleSettingsReset
{
    public function __construct(
        public readonly string $module,
        public readonly ?string $key = null,
    ) {}
}
