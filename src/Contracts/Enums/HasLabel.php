<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Enums;

interface HasLabel
{
    public function getLabel(): string;
}
