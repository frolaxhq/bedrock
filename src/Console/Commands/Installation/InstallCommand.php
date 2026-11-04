<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Installation;

use Frolax\Bedrock\Http\Controllers\Administration\DashboardController;
use Frolax\Bedrock\Http\Controllers\Auth\AuthController;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Frolax\Bedrock\Services\Installation\FoundationInstaller;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'bedrock:install {--check : Inspect setup without changes} {--dry-run : Preview files without changes} {--skip-frontend : Prepare files but defer npm install and build} {--skip-admin : Defer administrator creation}';

    protected $description = 'Prepare Bedrock authentication, administration and frontend for a fresh Laravel application';

    public function handle(Filesystem $files): int
    {
        try {
            $installer = new FoundationInstaller($files, base_path());
            $inspection = $installer->inspect();
            foreach ($inspection['problems'] as $problem) {
                $this->components->error($problem);
            }
            foreach ($inspection['changes'] as $change) {
                $this->line('Prepare: '.$change);
            }

            $reservedPaths = ['login', 'logout', 'register', 'forgot-password', 'reset-password', 'reset-password/{token}',
                'verify-email', 'verify-email/{id}/{hash}', 'email/verification-notification', 'profile', 'password',
                trim((string) config('bedrock.admin.path', 'admin'), '/')];
            $reservedNames = ['login', 'logout', 'register', 'password.request', 'password.email', 'password.reset', 'password.store',
                'password.update', 'profile.show', 'profile.update', 'verification.notice', 'verification.verify', 'verification.send', 'bedrock.dashboard'];
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (! in_array($route->uri(), $reservedPaths, true) && ! in_array($route->getName(), $reservedNames, true)) {
                    continue;
                }
                $controller = explode('@', $route->getActionName())[0];

                if (! in_array($controller, [AuthController::class, DashboardController::class], true)) {
                    $this->components->error('Route conflict: '.$route->uri().'. Integrate Bedrock with the existing authentication manually.');

                    return self::FAILURE;
                }
            }

            if (config('auth.guards.web.provider') !== 'users' || config('auth.providers.users.model') !== 'App\\Models\\User') {
                $this->components->error('Automatic setup requires the standard web guard and App\\Models\\User. Use manual integration for custom authentication.');

                return self::FAILURE;
            }

            $broker = config('auth.defaults.passwords');

            if (config('auth.passwords.'.$broker.'.provider') !== 'users' || config('bedrock.permissions.guard') !== 'web') {
                $this->components->error('Automatic setup requires the users password broker provider and web permission guard.');

                return self::FAILURE;
            }

            if ($inspection['problems'] !== []) {
                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            if ($this->option('check')) {
                $access = app(AdministrationManager::class);
                $ready = $access->inspect();
                foreach ($ready['problems'] as $problem) {
                    $this->components->error($problem);
                }
                $manifest = public_path('build/manifest.json');
                $built = $files->isFile($manifest) && isset(json_decode($files->get($manifest), true)['resources/js/bedrock.tsx']);
                $complete = $inspection['changes'] === [] && $ready['ok'] && $built && config('bedrock.auth.enabled') && config('bedrock.admin.enabled');

                if (! $complete) {
                    $this->components->warn('Complete the pending files, migrations, frontend build and enabled auth/admin configuration.');
                }

                return $complete ? self::SUCCESS : self::FAILURE;
            }
            $installer->install();
            // New processes load the updated User class and configuration.
            foreach ([[PHP_BINARY, 'artisan', 'config:clear'], [PHP_BINARY, 'artisan', 'route:clear'], [PHP_BINARY, 'artisan', 'migrate', '--force']] as $command) {
                if (! $this->runProcess($command)) {
                    return self::FAILURE;
                }
            }

            if (! $this->option('skip-frontend')) {
                foreach ([['npm', 'install'], ['npm', 'run', 'bedrock:build']] as $command) {
                    if (! $this->runProcess($command)) {
                        $this->components->warn('Files are prepared. Install Node 26.13+ and npm, then run npm install && npm run bedrock:build.');

                        return self::FAILURE;
                    }
                }
            } else {
                $this->components->warn('Frontend pending: npm install && npm run bedrock:build');
            }

            if (! $this->option('skip-admin') && $this->input->isInteractive()) {
                if (! $this->runProcess([PHP_BINARY, 'artisan', 'bedrock:admin-create'], true)) {
                    return self::FAILURE;
                }
            } else {
                $this->components->warn('Administrator pending: php artisan bedrock:admin-create');
            }
            $this->components->info('Bedrock files and database are prepared. After pending steps, open /login.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param list<string> $command */
    protected function runProcess(array $command, bool $interactive = false): bool
    {
        $process = new Process($command, base_path());
        $process->setTimeout(600);

        if ($interactive && Process::isTtySupported()) {
            $process->setTty(true);
        } elseif ($interactive) {
            $process->setInput(STDIN);
        }
        $process->run(fn (string $type, string $output) => $this->output->write($output));

        return $process->isSuccessful();
    }
}
