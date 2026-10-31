<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Frontend;

use Frolax\Bedrock\Services\Frontend\FrontendHostInstaller;
use Illuminate\Console\Command;
use Throwable;

class FrontendInstallCommand extends Command
{
    protected $signature = 'frontend:install {--check : Check integration without changing files} {--dry-run : Show planned changes}';

    protected $description = 'Integrate Bedrock with a recognized Laravel React and Inertia host';

    public function handle(FrontendHostInstaller $installer): int
    {
        try {
            if ((bool) $this->option('check')) {
                $result = $installer->inspect();

                foreach ($result['problems'] as $problem) {
                    $this->components->error($problem);
                }

                foreach ($result['changes'] as $change) {
                    $this->components->warn($change);
                }

                if ($result['installed']) {
                    $this->components->info('Bedrock frontend integration is installed.');
                }

                return $result['installed'] ? self::SUCCESS : self::FAILURE;
            }

            $changes = $installer->install((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($changes === []) {
            $this->components->info('Bedrock frontend integration is already installed.');
        } else {
            $this->components->bulletList($changes);
            $this->components->info((bool) $this->option('dry-run') ? 'No files were changed.' : 'Run vp install to install the declared dependencies.');
        }

        return self::SUCCESS;
    }
}
