<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Install;

use Frolax\Bedrock\Contracts\Module\DisposableModuleSource;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Exceptions\Module\ModuleAlreadyInstalledException;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Illuminate\Filesystem\Filesystem;

/**
 * Copies a module from a source into the module root.
 *
 * The manifest is read from the staged copy before anything is written, so an
 * invalid or wrongly addressed source never reaches the module root. A copy
 * that fails halfway is removed rather than left behind as a partial module.
 */
class ModuleInstaller
{
    public function __construct(
        protected Filesystem $files,
        protected ModuleManifest $loader,
        protected ModuleAutoloader $autoloader,
        protected ModulePackageValidator $validator,
        protected string $manifestFile = 'module.json',
        protected string $modulesPath = '',
    ) {}

    /**
     * Install the module held by a source under the requested key.
     *
     * @throws InvalidModuleSourceException
     * @throws ModuleAlreadyInstalledException
     */
    public function install(ModuleSource $source, string $key): DiscoveredModule
    {
        try {
            return $this->doInstall($source, $key);
        } finally {
            if ($source instanceof DisposableModuleSource) {
                $source->cleanup();
            }
        }
    }

    /**
     * Perform the install so the caller can clean up whatever happens.
     *
     * @throws InvalidModuleSourceException
     * @throws ModuleAlreadyInstalledException
     */
    protected function doInstall(ModuleSource $source, string $key): DiscoveredModule
    {
        $staged = $source->stage();

        $this->validator->validate($staged);

        $manifest = $this->loader->load($staged.'/'.$this->manifestFile);

        if ($manifest->key !== $key) {
            throw InvalidModuleSourceException::keyMismatch($key, $manifest->key);
        }

        $autoloadFailures = $this->autoloader->failures($manifest, $staged);

        if ($autoloadFailures !== []) {
            throw new InvalidModuleSourceException($autoloadFailures[0]);
        }

        $target = rtrim($this->modulesPath, '/\\').'/'.$key;

        if ($this->files->isDirectory($target)) {
            throw ModuleAlreadyInstalledException::for($key, $target);
        }

        $this->files->ensureDirectoryExists($this->modulesPath);
        $temporary = rtrim($this->modulesPath, '/\\').'/.'.$key.'.incoming.'.bin2hex(random_bytes(8));

        if (! $this->files->copyDirectory($staged, $temporary)) {
            $this->files->deleteDirectory($temporary);

            throw InvalidModuleSourceException::notCopied($staged, $target);
        }

        if (! @rename($temporary, $target)) {
            $this->files->deleteDirectory($temporary);

            throw InvalidModuleSourceException::notCopied($staged, $target);
        }

        return new DiscoveredModule($key, $target, $manifest);
    }
}
