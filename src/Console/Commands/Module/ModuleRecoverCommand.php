<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;

class ModuleRecoverCommand extends ModuleCommand
{
    protected $signature = 'module:recover {operation? : Expired installation UUID; omit to recover all expired operations}';

    protected $description = 'Mark expired installations failed without retrying their migrations';

    public function handle(InstallationOperations $operations): int
    {
        /** @var string|null $operation */
        $operation = $this->argument('operation');
        $count = $operations->recover($operation);
        $this->components->info(sprintf('Recovered %d expired installation operation(s). No migrations were retried.', $count));

        return self::SUCCESS;
    }
}
