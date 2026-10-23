<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Frontend;

use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Illuminate\Support\Collection;

interface FrontendRegistry
{
    /** @return Collection<int, FrontendModule> */
    public function all(): Collection;

    public function find(string $key): ?FrontendModule;

    public function isAvailable(string $key): bool;

    public function refresh(): void;
}
