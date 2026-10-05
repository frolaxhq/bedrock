<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions;

use RuntimeException;

/**
 * Base class for every exception thrown by Bedrock.
 */
class BedrockException extends RuntimeException
{
    /**
     * The path the exception relates to, when one is known.
     */
    protected ?string $contextPath = null;

    /**
     * Attach the path the exception relates to.
     */
    public function withPath(string $path): static
    {
        if ($this->contextPath === null) {
            $this->message = sprintf('[%s] %s', $path, $this->message);
            $this->contextPath = $path;
        }

        return $this;
    }

    /**
     * The path the exception relates to.
     */
    public function path(): ?string
    {
        return $this->contextPath;
    }
}
