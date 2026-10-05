<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Frolax\Bedrock\Settings */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Frolax\Bedrock\Settings::class;
    }
}
