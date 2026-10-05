<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class CoreVersionNotSatisfiedException extends BedrockException
{
    /**
     * Create an exception for a Bedrock version that cannot be determined.
     */
    public static function unresolved(string $module, string $constraint): self
    {
        return new self(sprintf(
            'The module [%s] requires Bedrock [%s], but the installed Bedrock version could not be determined.',
            $module,
            $constraint,
        ));
    }

    /**
     * Create an exception for an incompatible Bedrock version.
     */
    public static function incompatible(string $module, string $version, string $constraint): self
    {
        return new self(sprintf(
            'The module [%s] requires Bedrock [%s], but Bedrock [%s] does not satisfy [%s].',
            $module,
            $constraint,
            $version,
            $constraint,
        ));
    }
}
