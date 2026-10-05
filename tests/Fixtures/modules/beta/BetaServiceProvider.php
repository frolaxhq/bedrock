<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Modules\Beta;

use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

class BetaServiceProvider extends ModuleServiceProvider
{
    protected string $moduleKey = 'beta';

    protected string $modulePath = __DIR__;
}
