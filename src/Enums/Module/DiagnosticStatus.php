<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Enums\Module;

enum DiagnosticStatus: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
}
