<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Frolax\Bedrock\Frontend */
class Frontend extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Frolax\Bedrock\Frontend::class;
    }
}
