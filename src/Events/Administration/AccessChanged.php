<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Events\Administration;

class AccessChanged
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $action,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly array $metadata = [],
    ) {}
}
