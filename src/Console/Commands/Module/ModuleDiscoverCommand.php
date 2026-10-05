<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

class ModuleDiscoverCommand extends ModuleCommand
{
    protected $signature = 'module:discover';

    protected $description = 'Discover modules on disk and register the ones that are new';

    public function handle(): int
    {
        $report = $this->manager()->syncReport();

        foreach ($report->discovered as $key) {
            $this->components->info(sprintf('Discovered [%s].', $key));
        }

        $this->components->info(sprintf('Registry contains [%d] module(s).', count($report->registered)));

        foreach ($report->failures as $name => $reason) {
            $this->components->warn(sprintf('[%s] %s', $name, $reason));
        }

        return $report->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
