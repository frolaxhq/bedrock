<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Frontend;

use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;

interface FrontendAssetManager
{
    /** @return list<string> */
    public function failures(Manifest $manifest, string $modulePath, bool $published = false): array;

    public function ensureValid(Manifest $manifest, string $modulePath, bool $published = false): void;

    public function publish(Manifest $manifest, string $modulePath): void;

    public function remove(string $key, string $version): void;

    public function descriptor(Manifest $manifest): ?FrontendModule;

    /** @return array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>} */
    public function navigation(Manifest $manifest, string $modulePath): array;
}
