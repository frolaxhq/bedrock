<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Modules\Ordering;

use Illuminate\Support\ServiceProvider;

class FeatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        BootstrapOrder::$modules[] = 'feature';
    }
}
