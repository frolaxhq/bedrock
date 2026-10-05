<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Closure;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Exceptions\Module\ModuleLockException;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

class CacheModuleOperationLock implements ModuleOperationLock
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected Factory $cache,
        protected array $config = [],
    ) {}

    public function synchronized(array $keys, Closure $operation): mixed
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $this->withLock($keys, 0, $operation);
    }

    public function supported(): bool
    {
        return $this->store()->getStore() instanceof LockProvider;
    }

    /**
     * @param  list<string>  $keys
     */
    protected function withLock(array $keys, int $offset, Closure $operation): mixed
    {
        if (! isset($keys[$offset])) {
            return $operation();
        }

        $provider = $this->store()->getStore();

        if (! $provider instanceof LockProvider) {
            throw ModuleLockException::unavailable($keys);
        }

        $lock = $provider->lock($this->name($keys[$offset]), $this->seconds());

        try {
            return $lock->block($this->wait(), fn (): mixed => $this->withLock($keys, $offset + 1, $operation));
        } catch (LockTimeoutException) {
            throw ModuleLockException::unavailable($keys);
        }
    }

    protected function store(): Repository
    {
        $store = $this->config['store'] ?? null;

        return $this->cache->store(is_string($store) ? $store : null);
    }

    protected function name(string $key): string
    {
        $prefix = $this->config['prefix'] ?? 'modules.operations';

        return sprintf('%s.%s', is_string($prefix) ? $prefix : 'modules.operations', $key);
    }

    protected function seconds(): int
    {
        return max(1, (int) ($this->config['seconds'] ?? 30));
    }

    protected function wait(): int
    {
        return max(0, (int) ($this->config['wait'] ?? 5));
    }
}
