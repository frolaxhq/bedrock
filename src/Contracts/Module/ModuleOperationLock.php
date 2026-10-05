<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Closure;

interface ModuleOperationLock
{
    /**
     * Execute an operation while holding deterministic locks for its graph keys.
     *
     * @template T
     *
     * @param  list<string>  $keys
     * @param  Closure(): T  $operation
     * @return T
     */
    public function synchronized(array $keys, Closure $operation): mixed;

    public function supported(): bool;
}
