<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Frontend
{
    public function __construct(protected FrontendRegistry $registry) {}

    /** @return Collection<int, FrontendModule> */
    public function all(): Collection
    {
        return $this->registry->all();
    }

    public function find(string $key): ?FrontendModule
    {
        return $this->registry->find($key);
    }

    public function isAvailable(string $key): bool
    {
        return $this->registry->isAvailable($key);
    }

    public function page(string $key, string $page): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $key) !== 1 || ! $this->validPage($page)) {
            throw new InvalidArgumentException('Frontend module page names must be safe relative identifiers.');
        }

        return 'module:'.$key.'/'.trim(str_replace('\\', '/', $page), '/');
    }

    protected function validPage(string $page): bool
    {
        $page = trim(str_replace('\\', '/', $page), '/');

        return $page !== ''
            && ! in_array('..', explode('/', $page), true)
            && preg_match('/^[A-Za-z0-9_\/-]+$/', $page) === 1;
    }
}
