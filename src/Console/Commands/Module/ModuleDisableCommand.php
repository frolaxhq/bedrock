<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleDisableCommand extends ModuleCommand
{
    protected $signature = 'module:disable {key : The module key to disable}';

    protected $description = 'Disable a registered module without removing its files';

    public function handle(): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        try {
            $this->manager()->disable($key);
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info(sprintf('Disabled the module [%s].', $key));

        return self::SUCCESS;
    }
}
