<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Permissions;

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Illuminate\Console\Command;

class PermissionsSyncCommand extends Command
{
    protected $signature = 'bedrock:permissions-sync {module? : One module key, or every registered module}';

    protected $description = 'Synchronize module permissions with the local permission catalog';

    public function handle(ModulePermissions $permissions): int
    {
        /** @var string|null $module */
        $module = $this->argument('module');
        $permissions->sync($module);
        $this->components->info($module === null ? 'Module permissions are synchronized.' : sprintf('Module permissions for [%s] are synchronized.', $module));

        return self::SUCCESS;
    }
}
