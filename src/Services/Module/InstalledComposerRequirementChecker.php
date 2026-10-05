<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Composer\InstalledVersions;
use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\ComposerRequirementNotSatisfiedException;
use OutOfBoundsException;

class InstalledComposerRequirementChecker implements ComposerRequirementChecker
{
    public function __construct(protected VersionConstraint $constraints) {}

    public function failures(Manifest $manifest): array
    {
        $failures = [];

        foreach (($manifest->composer['require'] ?? []) as $package => $constraint) {
            $version = $this->version($package);

            if ($version === null) {
                $failures[$package] = sprintf('package or extension is not installed (requires %s)', $constraint);

                continue;
            }

            if (! $this->constraints->matches($version, $constraint)) {
                $failures[$package] = sprintf('installed version [%s] does not satisfy [%s]', $version, $constraint);
            }
        }

        return $failures;
    }

    public function ensureSatisfied(Manifest $manifest): void
    {
        $failures = $this->failures($manifest);

        if ($failures === []) {
            return;
        }

        $package = array_key_first($failures);

        throw ComposerRequirementNotSatisfiedException::for($manifest->key, $package, $failures[$package]);
    }

    protected function version(string $package): ?string
    {
        if ($package === 'php') {
            return PHP_VERSION;
        }

        if (str_starts_with($package, 'ext-')) {
            $extension = substr($package, 4);

            if (! extension_loaded($extension)) {
                return null;
            }

            return phpversion($extension) ?: '0';
        }

        try {
            if (! InstalledVersions::isInstalled($package)) {
                return null;
            }

            return InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package);
        } catch (OutOfBoundsException) {
            return null;
        }
    }
}
