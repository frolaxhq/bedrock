<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Events\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Throwable;

class ModuleBootFailed
{
    public function __construct(
        public readonly string $key,
        public readonly Manifest $manifest,
        public readonly string $reason,
        public readonly ?Throwable $exception = null,
    ) {}
}
