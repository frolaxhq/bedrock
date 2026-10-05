<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Enums;

enum Color: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}
