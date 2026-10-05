<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\Exceptions\BedrockException;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Illuminate\Filesystem\Filesystem;

/**
 * Discovers modules by scanning a directory for manifest files.
 */
class DirectoryModuleDiscoverer implements ModuleDiscoverer
{
    /**
     * Create a new discoverer.
     *
     * @param  list<string>  $roots  Absolute paths to scan for modules.
     */
    public function __construct(
        protected Filesystem $files,
        protected ModuleManifest $loader,
        protected string $manifestFile = 'module.json',
        protected array $roots = [],
    ) {}

    public function discover(): ModuleDiscoveryResult
    {
        /** @var array<string, DiscoveredModule> $modules */
        $modules = [];

        /** @var array<string, string> $failures */
        $failures = [];

        foreach ($this->roots as $root) {
            if (! $this->files->isDirectory($root)) {
                continue;
            }

            foreach ($this->files->directories($root) as $directory) {
                $name = $this->files->basename($directory);

                if (str_starts_with($name, '.')) {
                    continue;
                }

                $manifest = $directory.'/'.$this->manifestFile;

                if (! $this->files->isFile($manifest)) {
                    continue;
                }

                try {
                    $loaded = $this->loader->load($manifest);
                } catch (BedrockException $exception) {
                    $failures[$name] = $exception->getMessage();

                    continue;
                }

                if ($loaded->key !== $name) {
                    $failures[$name] = sprintf(
                        'The module manifest key [%s] does not match its directory name [%s].',
                        $loaded->key,
                        $name,
                    );

                    continue;
                }

                if (isset($modules[$loaded->key])) {
                    $failures[$loaded->key] = sprintf(
                        'The module key [%s] is used by more than one directory: [%s] and [%s].',
                        $loaded->key,
                        $modules[$loaded->key]->path,
                        $directory,
                    );

                    unset($modules[$loaded->key]);

                    continue;
                }

                if (isset($failures[$loaded->key])) {
                    continue;
                }

                $modules[$loaded->key] = new DiscoveredModule($loaded->key, $directory, $loaded);
            }
        }

        ksort($modules);

        return new ModuleDiscoveryResult(array_values($modules), $failures);
    }
}
