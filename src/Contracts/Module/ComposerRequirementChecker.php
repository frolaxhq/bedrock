<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;

interface ComposerRequirementChecker
{
    /**
     * Return requirement failures keyed by package name.
     *
     * @return array<string, string>
     */
    public function failures(Manifest $manifest): array;

    /**
     * Stop when any declared host Composer requirement is unsatisfied.
     */
    public function ensureSatisfied(Manifest $manifest): void;
}
