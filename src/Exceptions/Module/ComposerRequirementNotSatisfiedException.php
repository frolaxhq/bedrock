<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ComposerRequirementNotSatisfiedException extends BedrockException
{
    public static function for(string $module, string $package, string $reason): self
    {
        $hint = preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $package) === 1
            ? sprintf(' Install a compatible host dependency with composer require %s, then retry.', $package)
            : ' Configure the required host PHP version or extension, then retry.';

        return new self(sprintf(
            'The module [%s] cannot satisfy Composer requirement [%s]: %s',
            $module,
            $package,
            $reason,
        ).$hint);
    }
}
