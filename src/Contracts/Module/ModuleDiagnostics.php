<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\DiagnosticReport;

interface ModuleDiagnostics
{
    public function diagnose(?string $key = null): DiagnosticReport;
}
