<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Navigation;

use Frolax\Bedrock\DataObjects\Navigation\NavigationItem;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface ModuleNavigation
{
    /** @return Collection<int, NavigationItem> */
    public function all(?Authenticatable $user = null): Collection;

    public function refresh(): void;
}
