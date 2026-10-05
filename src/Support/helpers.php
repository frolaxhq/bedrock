<?php

declare(strict_types=1);

use Frolax\Bedrock\Support\Module\ModulePath;
use JetBrains\PhpStorm\FileReference;

if (! function_exists('module_path')) {
    /**
     * Get the path to the module directory, or to a path inside it.
     *
     * The root is the one Bedrock resolves from `bedrock.modules.path`, so a
     * host application that moves its modules keeps getting the right answer.
     */
    function module_path(#[FileReference(basePath: 'modules')] string $path = ''): string
    {
        $root = ModulePath::modules(app());

        return $path === '' ? $root : $root.'/'.ltrim($path, '/');
    }
}
