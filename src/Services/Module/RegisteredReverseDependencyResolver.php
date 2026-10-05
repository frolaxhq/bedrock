<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Module\ReverseDependencyResolver;
use Frolax\Bedrock\Enums\Module\ModuleStatus;

class RegisteredReverseDependencyResolver implements ReverseDependencyResolver
{
    public function __construct(protected ModuleRepository $repository) {}

    public function dependents(string $key, bool $enabledOnly = false): array
    {
        $found = [];
        $visit = function (string $dependency) use (&$visit, &$found, $enabledOnly): void {
            foreach ($this->repository->all() as $module) {
                $moduleKey = (string) $module->key;

                if (isset($found[$moduleKey]) || ($enabledOnly && $module->status !== ModuleStatus::Enabled)) {
                    continue;
                }

                if (array_key_exists($dependency, $module->toManifest()->requires)) {
                    $found[$moduleKey] = true;
                    $visit($moduleKey);
                }
            }
        };
        $visit($key);

        return array_keys($found);
    }
}
