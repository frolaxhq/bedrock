<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;

interface ModuleDiscoverer
{
    /**
     * Discover every module available on disk.
     */
    public function discover(): ModuleDiscoveryResult;
}
