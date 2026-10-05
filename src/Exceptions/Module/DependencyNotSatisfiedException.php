<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class DependencyNotSatisfiedException extends BedrockException
{
    /**
     * Create an exception for a dependency that is not registered.
     */
    public static function missing(string $module, string $dependency, string $constraint): self
    {
        return new self(sprintf(
            'The module [%s] requires [%s] %s, which is not registered.',
            $module,
            $dependency,
            $constraint,
        ));
    }

    /**
     * Create an exception for a dependency that is registered but not enabled.
     */
    public static function disabled(string $module, string $dependency, string $constraint): self
    {
        return new self(sprintf(
            'The module [%s] requires [%s] %s, which is registered but not enabled.',
            $module,
            $dependency,
            $constraint,
        ));
    }

    /**
     * Create an exception for an incompatible dependency version.
     */
    public static function incompatible(
        string $module,
        string $dependency,
        string $version,
        string $constraint,
    ): self {
        return new self(sprintf(
            'The module [%s] requires [%s] %s, but its installed version [%s] does not satisfy [%s].',
            $module,
            $dependency,
            $constraint,
            $version,
            $constraint,
        ));
    }
}
