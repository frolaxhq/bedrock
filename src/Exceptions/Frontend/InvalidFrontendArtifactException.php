<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Frontend;

use Frolax\Bedrock\Exceptions\BedrockException;

class InvalidFrontendArtifactException extends BedrockException
{
    public static function for(string $module, string $reason): self
    {
        return new self(sprintf('The frontend artifact for module [%s] is invalid: %s', $module, $reason));
    }
}
