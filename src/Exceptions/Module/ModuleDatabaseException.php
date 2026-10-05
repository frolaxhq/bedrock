<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;
use Throwable;

class ModuleDatabaseException extends BedrockException
{
    /**
     * Create an exception for a module whose migrations could not be run.
     */
    public static function migrationsFailed(string $key, string $path, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'The migrations for the module [%s] at [%s] failed to run.',
            $key,
            $path,
        ), 0, $previous);
    }

    /**
     * Create an exception for a module seeder that could not be run.
     */
    public static function seederFailed(string $key, string $class, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'The seeder [%s] for the module [%s] failed to run.',
            $class,
            $key,
        ), 0, $previous);
    }
}
