<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

class ModuleCacheCommand extends ModuleCommand
{
    protected $signature = 'module:cache';

    protected $description = 'Rebuild the cached registry of enabled modules';

    public function handle(): int
    {
        $this->registry()->refresh();
        $this->registry()->enabled();

        $this->components->info('The module registry cache was rebuilt.');

        return self::SUCCESS;
    }
}
