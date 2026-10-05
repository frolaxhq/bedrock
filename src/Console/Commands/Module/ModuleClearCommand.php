<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

class ModuleClearCommand extends ModuleCommand
{
    protected $signature = 'module:clear';

    protected $description = 'Clear the cached registry of enabled modules';

    public function handle(): int
    {
        $this->registry()->refresh();

        $this->components->info('The module registry cache was cleared.');

        return self::SUCCESS;
    }
}
