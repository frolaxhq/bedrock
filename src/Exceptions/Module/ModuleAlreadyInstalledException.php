<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleAlreadyInstalledException extends BedrockException
{
    /**
     * Create an exception for a module that already occupies the module root.
     */
    public static function for(string $key, string $path): self
    {
        return new self(sprintf(
            'The module [%s] is already installed at [%s]. Uninstall it before installing it again.',
            $key,
            $path,
        ));
    }
}
