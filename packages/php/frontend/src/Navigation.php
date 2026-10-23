<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Contracts\Navigation\ModuleNavigation;
use Frolax\Bedrock\DataObjects\Navigation\NavigationItem;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class Navigation
{
    public function __construct(protected ModuleNavigation $navigation) {}

    /** @return Collection<int, NavigationItem> */
    public function all(?Authenticatable $user = null): Collection
    {
        return $this->navigation->all($user);
    }
}
