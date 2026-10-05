<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleUninstallCommand extends ModuleCommand
{
    protected $signature = 'module:uninstall {key : The module key to remove}';

    protected $description = 'Remove a module and its code while retaining database data and published resources';

    public function handle(): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        if (! $this->confirm(sprintf('Uninstall [%s] and delete its module directory?', $key))) {
            $this->components->warn('Uninstall cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->manager()->uninstall($key);
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info(sprintf('Uninstalled the module [%s] and deleted its directory.', $key));

        return self::SUCCESS;
    }
}
