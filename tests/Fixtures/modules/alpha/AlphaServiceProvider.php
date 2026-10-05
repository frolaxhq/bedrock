<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Modules\Alpha;

use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

class AlphaServiceProvider extends ModuleServiceProvider
{
    protected string $moduleKey = 'alpha';

    protected string $modulePath = __DIR__;
}
