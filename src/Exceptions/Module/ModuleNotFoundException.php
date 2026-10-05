<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleNotFoundException extends BedrockException
{
    /**
     * Create an exception for a module that is not registered.
     */
    public static function for(string $key): self
    {
        return new self(sprintf(
            'The module [%s] is not registered. Run the module:discover command to find modules on disk.',
            $key,
        ));
    }
}
