<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Enums;

use BackedEnum;

interface HasColor
{
    public function getColor(): string|BackedEnum;
}
