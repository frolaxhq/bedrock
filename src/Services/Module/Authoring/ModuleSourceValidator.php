<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Authoring;

use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Install\ModulePackageValidator;
use Frolax\Bedrock\Services\Module\ModuleFeatureHandlers;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Frolax\Bedrock\Support\Module\ModuleManifest;

class ModuleSourceValidator
{
    public function __construct(
        protected ModulePackageValidator $packages,
        protected ModuleManifest $manifests,
        protected ModuleAutoloader $autoload,
        protected ModuleFeatureHandlers $frontend,
        protected string $manifestFile = 'module.json',
    ) {}

    public function validate(string $path, bool $validateFrontend = true): Manifest
    {
        $path = rtrim($path, '/\\');
        $this->packages->validate($path);
        $manifest = $this->manifests->load($path.'/'.$this->manifestFile);
        app(PhpRuntime::class)->validate($manifest, $path);
        $this->frontend->ensureSupported($manifest);
        $failures = $this->autoload->failures($manifest, $path);

        if ($validateFrontend) {
            $failures = [...$failures, ...$this->frontend->failures($manifest, $path)];
        }

        if ($failures !== []) {
            throw new InvalidModuleSourceException($failures[0]);
        }

        return $manifest;
    }

    public function manifest(string $path): Manifest
    {
        return $this->manifests->load(rtrim($path, '/\\').'/'.$this->manifestFile);
    }
}
