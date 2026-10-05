<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Validates and evaluates versions using Composer's constraint language.
 */
class VersionConstraint
{
    public function __construct(
        protected VersionParser $parser,
    ) {}

    /**
     * Determine whether a constraint can be parsed by Composer.
     */
    public function isValid(string $constraint): bool
    {
        if (trim($constraint) === '') {
            return false;
        }

        try {
            $this->parser->parseConstraints($constraint);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Determine whether a version satisfies a Composer constraint.
     */
    public function matches(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
