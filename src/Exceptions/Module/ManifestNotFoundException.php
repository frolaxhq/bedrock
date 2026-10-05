<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ManifestNotFoundException extends BedrockException
{
    /**
     * Create an exception for a missing manifest file.
     */
    public static function for(string $path): self
    {
        return new self(sprintf('No module manifest file was found at [%s].', $path));
    }
}
