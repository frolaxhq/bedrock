<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\DiagnosticCheck;
use Frolax\Bedrock\DataObjects\Module\Manifest;

interface ModuleFeatureHandler
{
    /** @return list<string> */
    public function failures(Manifest $manifest, string $path, bool $published = false): array;

    public function publish(Manifest $manifest, string $path): void;

    public function remove(string $key, string $version): void;

    /** @return list<DiagnosticCheck> */
    public function diagnostics(Manifest $manifest, string $path, bool $published = false): array;
}
