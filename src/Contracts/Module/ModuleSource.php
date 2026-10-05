<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

/**
 * A place a module can be installed from.
 *
 * A source resolves its input into a staged directory that holds exactly one
 * module, so every install path shares one validation and copy step. The
 * marketplace client is a future implementation.
 */
interface ModuleSource
{
    /**
     * Stage the module and return the absolute path to its directory.
     */
    public function stage(): string;
}
