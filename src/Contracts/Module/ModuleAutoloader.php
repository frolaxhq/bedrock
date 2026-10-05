<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;

interface ModuleAutoloader
{
    /**
     * Register a module's PSR-4 mappings and optionally load its files.
     */
    public function register(Manifest $manifest, string $path, bool $loadFiles = false): void;

    /**
     * Validate that every declared autoload target exists inside the module.
     *
     * @return list<string>
     */
    public function failures(Manifest $manifest, string $path): array;
}
