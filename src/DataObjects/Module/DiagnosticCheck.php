<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Frolax\Bedrock\Enums\Module\DiagnosticStatus;
use Spatie\LaravelData\Data;

class DiagnosticCheck extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly DiagnosticStatus $status,
        public readonly ?string $module,
        public readonly string $message,
    ) {}
}
