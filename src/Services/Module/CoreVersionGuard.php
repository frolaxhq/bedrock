<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\CoreVersionNotSatisfiedException;
use Frolax\Bedrock\Services\Core\BedrockVersion;

/**
 * Checks a module's optional Bedrock core version requirement.
 */
class CoreVersionGuard
{
    public function __construct(
        protected BedrockVersion $version,
        protected VersionConstraint $constraints,
    ) {}

    /**
     * Ensure the installed Bedrock version satisfies the module manifest.
     *
     * @throws CoreVersionNotSatisfiedException
     */
    public function ensureSatisfied(Manifest $manifest): void
    {
        $failure = $this->failure($manifest);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Explain why the core requirement is not satisfied, when applicable.
     */
    public function reason(Manifest $manifest): ?string
    {
        return $this->failure($manifest)?->getMessage();
    }

    /**
     * Build the compatibility failure for a manifest, when one exists.
     */
    protected function failure(Manifest $manifest): ?CoreVersionNotSatisfiedException
    {
        if ($manifest->core === null) {
            return null;
        }

        $version = $this->version->current();

        if ($version === null) {
            return CoreVersionNotSatisfiedException::unresolved($manifest->key, $manifest->core);
        }

        if (! $this->constraints->matches($version, $manifest->core)) {
            return CoreVersionNotSatisfiedException::incompatible($manifest->key, $version, $manifest->core);
        }

        return null;
    }
}
