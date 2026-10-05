<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Spatie\LaravelData\Data;

class DiagnosticReport extends Data
{
    /**
     * @param  list<DiagnosticCheck>  $checks
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $checks,
    ) {}
}
