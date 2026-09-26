<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests;

use Frolax\Bedrock\BedrockServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BedrockServiceProvider::class,
        ];
    }
}
