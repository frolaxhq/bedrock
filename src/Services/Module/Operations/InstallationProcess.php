<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Operations;

use Frolax\Bedrock\Models\ModuleInstallation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Process\Process;

class InstallationProcess
{
    public function __construct(
        protected Application $app,
        protected Repository $config,
    ) {}

    public function run(ModuleInstallation $operation): int
    {
        $binary = $this->config->get('bedrock.modules.php.binary', PHP_BINARY);
        $timeout = $this->config->get('bedrock.modules.operations.timeout', 1200);

        $command = [
            is_string($binary) && $binary !== '' ? $binary : PHP_BINARY,
            $this->app->basePath('artisan'),
            'module:install',
            $operation->key,
            '--operation='.$operation->id,
            '--no-interaction',
            '--no-ansi',
        ];

        if ($operation->from !== null) {
            $command[] = '--from='.$operation->from;
        }

        if ($operation->zip !== null) {
            $command[] = '--zip='.$operation->zip;
        }

        if ($operation->offline) {
            $command[] = '--offline';
        }

        $process = new Process($command, $this->app->basePath(), timeout: is_int($timeout) && $timeout > 0 ? $timeout : 1200);
        $process->disableOutput();

        return $process->run();
    }
}
