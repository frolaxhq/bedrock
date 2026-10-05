<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Models\Module;
use Illuminate\Support\Collection;

class ModuleListCommand extends ModuleCommand
{
    protected $signature = 'module:list';

    protected $description = 'List every module in the registry';

    public function handle(): int
    {
        /** @var Collection<int, Module> $modules */
        $modules = $this->registry()->all();

        if ($modules->isEmpty()) {
            $this->components->info('No modules are registered. Run [php artisan module:discover].');

            return self::SUCCESS;
        }

        $rows = $modules->map(static fn (Module $module): array => [
            $module->key,
            $module->version,
            $module->status->getLabel(),
            $module->health->getLabel(),
            $module->failure_reason ?? '-',
            $module->path,
        ])->all();

        $this->table(['Key', 'Version', 'Status', 'Health', 'Failure', 'Path'], $rows);

        return self::SUCCESS;
    }
}
