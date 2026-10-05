<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ComposerRequirementNotSatisfiedException extends BedrockException
{
    public static function for(string $module, string $package, string $reason): self
    {
        return new self(sprintf(
            'The module [%s] cannot satisfy Composer requirement [%s]: %s',
            $module,
            $package,
            $reason,
        ));
    }
}
