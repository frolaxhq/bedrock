<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Install;

use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;

/**
 * Installs a module from a directory on the local filesystem.
 *
 * The directory is staged as it stands; installing copies it rather than
 * moving it, so a module can be installed from a checkout without that
 * checkout disappearing.
 */
class LocalDirectorySource implements ModuleSource
{
    public function __construct(
        protected Filesystem $files,
        protected string $path,
        protected string $manifestFile = 'module.json',
    ) {}

    public function stage(): string
    {
        $path = rtrim($this->path, '/\\');

        if (! $this->files->isDirectory($path) || ! $this->files->isFile($path.'/'.$this->manifestFile)) {
            throw InvalidModuleSourceException::forPath($this->path);
        }

        return $path;
    }
}
