<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

class ModuleSyncCommand extends ModuleCommand
{
    protected $signature = 'module:sync';

    protected $description = 'Sync the module registry with the modules found on disk';

    public function handle(): int
    {
        $report = $this->manager()->syncReport();

        $this->components->info(sprintf('Registry contains [%d] module(s).', count($report->registered)));

        foreach ($report->failures as $name => $reason) {
            $this->components->warn(sprintf('[%s] %s', $name, $reason));
        }

        foreach ($report->broken as $key => $reason) {
            $this->components->warn(sprintf('[%s] %s', $key, $reason));
        }

        return $report->hasIssues() ? self::FAILURE : self::SUCCESS;
    }
}
