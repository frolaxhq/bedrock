<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

interface ModuleFrontendScaffold
{
    /** @param array<string, string> $files
     * @return array<string, string>
     */
    public function extend(array $files, string $key, string $class, string $name): array;
}
