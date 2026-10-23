<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Frolax\Bedrock\Support\Module\ModulePath;
use Throwable;

class ModulePrepareCommand extends ModuleCommand
{
    protected $signature = 'module:prepare {module : A module key or directory} {--offline : Never download dependencies or tooling} {--force : Atomically rebuild a generated runtime after source changes}';

    protected $description = 'Prepare a module’s locked and isolated PHP runtime';

    public function handle(PhpRuntime $runtime, ModuleManifest $manifests): int
    {
        $argument = (string) $this->argument('module');
        $candidate = ModulePath::absolute($this->laravel, $argument);
        $source = is_dir($candidate) ? $candidate : ModulePath::modules($this->laravel).'/'.$argument;

        try {
            $manifest = $manifests->load($source.'/'.ModulePath::manifest($this->laravel));
            $runtime->prepare($manifest, $source, (bool) $this->option('offline') || (bool) config('bedrock.modules.php.offline', false), (bool) $this->option('force'));
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info('The module PHP runtime is ready.');

        return self::SUCCESS;
    }
}
