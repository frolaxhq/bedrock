<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class InvalidModuleSourceException extends BedrockException
{
    /**
     * Create an exception for a path that does not hold a module.
     */
    public static function forPath(string $path): self
    {
        return new self(sprintf(
            'The module source [%s] is not a directory that contains a module manifest.',
            $path,
        ));
    }

    /**
     * Create an exception for a module whose key is not the one requested.
     */
    public static function keyMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'The module source holds the module [%s], but [%s] was requested.',
            $actual,
            $expected,
        ));
    }

    /**
     * Create an exception for a module that could not be copied into place.
     */
    public static function notCopied(string $source, string $target): self
    {
        return new self(sprintf(
            'The module source [%s] could not be copied to [%s].',
            $source,
            $target,
        ));
    }
}
