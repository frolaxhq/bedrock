<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Module;

/**
 * A module source that stages its module into a temporary directory.
 *
 * The installer calls `cleanup()` once it is done with the staged copy, so a
 * source that writes files can remove them whatever the outcome of the install.
 */
interface DisposableModuleSource
{
    /**
     * Remove anything the source staged.
     */
    public function cleanup(): void;
}
