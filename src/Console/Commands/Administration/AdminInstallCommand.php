<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Administration;

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Frolax\Bedrock\Services\Frontend\FrontendHostInstaller;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionServiceProvider;
use Throwable;

class AdminInstallCommand extends Command
{
    protected $signature = 'bedrock:admin-install {--check : Check administration readiness without changing files} {--dry-run : Show required preparation without changing files}';

    protected $description = 'Inspect the local Bedrock administration prerequisites';

    public function handle(AdministrationManager $administration, FrontendHostInstaller $frontend): int
    {
        $inspection = $administration->inspect();

        foreach ($inspection['problems'] as $problem) {
            $this->components->error($problem);
        }

        try {
            $host = $frontend->inspect();

            foreach ([...$host['problems'], ...$host['changes']] as $problem) {
                $this->components->warn('Frontend integration: '.$problem);
            }
        } catch (Throwable $exception) {
            $this->components->warn('Frontend integration: '.$exception->getMessage());
        }

        if (! $inspection['ok']) {
            if ((bool) $this->option('check')) {
                return self::FAILURE;
            }

            if ((bool) $this->option('dry-run')) {
                $this->components->warn('Would publish Spatie permission configuration and migrations. No files were changed.');

                return self::FAILURE;
            }

            $this->call('vendor:publish', [
                '--provider' => PermissionServiceProvider::class,
                '--tag' => 'permission-config',
            ]);
            $this->call('vendor:publish', [
                '--provider' => PermissionServiceProvider::class,
                '--tag' => 'permission-migrations',
            ]);
            $this->components->warn('Spatie resources were prepared. Run migrations and add HasRoles to the configured user model before retrying.');

            return self::FAILURE;
        }

        if ((bool) $this->option('check') || (bool) $this->option('dry-run')) {
            $this->components->info('Bedrock administration prerequisites are ready.');

            return self::SUCCESS;
        }

        $administration->ensureAdminRole();
        $this->components->info('The protected Bedrock administrator role is ready.');

        return self::SUCCESS;
    }
}
