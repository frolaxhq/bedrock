<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Support\Module\ModulePath;
use Throwable;

class ModulePackCommand extends ModuleCommand
{
    protected $signature = 'module:pack
        {module : A module key or source directory}
        {--output=dist/modules : The archive output directory}
        {--force : Replace an existing archive}';

    protected $description = 'Build, validate, and package a module as a deterministic ZIP archive';

    public function handle(ModulePackager $packager): int
    {
        $argument = (string) $this->argument('module');
        $candidate = ModulePath::absolute($this->laravel, $argument);
        $source = is_dir($candidate) ? $candidate : ModulePath::modules($this->laravel).'/'.$argument;
        $output = ModulePath::absolute($this->laravel, (string) $this->option('output'));

        try {
            $archive = $packager->pack($source, $output, (bool) $this->option('force'));
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info(sprintf('Created [%s].', $archive));

        return self::SUCCESS;
    }
}
