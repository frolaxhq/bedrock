<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleLockException extends BedrockException
{
    /** @param list<string> $keys */
    public static function unavailable(array $keys): self
    {
        return new self(sprintf('Could not acquire module operation locks for [%s].', implode(', ', $keys)));
    }
}
