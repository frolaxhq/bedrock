<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\DependencyNotSatisfiedException;

/**
 * Checks that a module's required modules are registered, enabled and version
 * compatible.
 */
class DependencyGuard
{
    public function __construct(
        protected ModuleRepository $repository,
        protected VersionConstraint $constraints,
    ) {}

    /**
     * Ensure every module required by the manifest is registered and enabled.
     *
     * @throws DependencyNotSatisfiedException
     */
    public function ensureSatisfied(Manifest $manifest): void
    {
        foreach ($manifest->requires as $dependency => $constraint) {
            $module = $this->repository->find($dependency);

            if ($module === null) {
                throw DependencyNotSatisfiedException::missing($manifest->key, $dependency, $constraint);
            }

            if ($module->status !== ModuleStatus::Enabled || $module->hasFailed()) {
                throw DependencyNotSatisfiedException::disabled($manifest->key, $dependency, $constraint);
            }

            if (! $this->constraints->matches((string) $module->version, $constraint)) {
                throw DependencyNotSatisfiedException::incompatible(
                    $manifest->key,
                    $dependency,
                    (string) $module->version,
                    $constraint,
                );
            }
        }
    }

    /**
     * The module keys required by the manifest that are not registered.
     *
     * @return list<string>
     */
    public function missing(Manifest $manifest): array
    {
        $missing = [];

        foreach ($manifest->requirements() as $dependency) {
            if ($this->repository->find($dependency) === null) {
                $missing[] = $dependency;
            }
        }

        return $missing;
    }

    /**
     * Dependency version failures keyed by module key.
     *
     * @return array<string, array{version: string, constraint: string}>
     */
    public function incompatible(Manifest $manifest): array
    {
        $incompatible = [];

        foreach ($manifest->requires as $dependency => $constraint) {
            $module = $this->repository->find($dependency);

            if ($module === null || $this->constraints->matches((string) $module->version, $constraint)) {
                continue;
            }

            $incompatible[$dependency] = [
                'version' => (string) $module->version,
                'constraint' => $constraint,
            ];
        }

        return $incompatible;
    }
}
