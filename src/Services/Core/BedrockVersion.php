<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Core;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use OutOfBoundsException;

/**
 * Resolves the Bedrock version used for module compatibility checks.
 */
class BedrockVersion
{
    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * The configured override or the version reported by Composer.
     */
    public function current(): ?string
    {
        $configured = $this->config->get('bedrock.core.version');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        try {
            return InstalledVersions::getPrettyVersion('frolaxhq/bedrock')
                ?? InstalledVersions::getVersion('frolaxhq/bedrock');
        } catch (OutOfBoundsException) {
            return null;
        }
    }
}
