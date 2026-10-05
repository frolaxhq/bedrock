<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\BedrockException;

class InvalidLifecycleTransitionException extends BedrockException
{
    public static function enable(string $key, ModuleStatus $status): self
    {
        return new self(sprintf('The module [%s] cannot be enabled from status [%s].', $key, $status->value));
    }

    /** @param list<string> $dependents */
    public static function dependents(string $key, array $dependents): self
    {
        return new self(sprintf(
            'The module [%s] is required by enabled modules: [%s].',
            $key,
            implode(', ', $dependents),
        ));
    }
}
