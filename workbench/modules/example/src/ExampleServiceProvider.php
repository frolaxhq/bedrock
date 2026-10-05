<?php

declare(strict_types=1);

namespace Modules\Example;

use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

class ExampleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleKey = 'example';

    protected string $modulePath = __DIR__.'/..';
}
