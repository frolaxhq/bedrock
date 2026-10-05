<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class CircularDependencyException extends BedrockException
{
    /**
     * @param  list<string>  $cycle
     */
    public function __construct(
        protected array $cycle,
    ) {
        parent::__construct(
            implode(' -> ', $cycle)
                |> (fn (string $message): string => sprintf('Circular module dependency detected: [%s].', $message)),
        );
    }

    /**
     * The dependency path, including the repeated closing module.
     *
     * @return list<string>
     */
    public function cycle(): array
    {
        return $this->cycle;
    }
}
