<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

interface ReverseDependencyResolver
{
    /**
     * Registered dependents in dependency order, nearest first.
     *
     * @return list<string>
     */
    public function dependents(string $key, bool $enabledOnly = false): array;
}
