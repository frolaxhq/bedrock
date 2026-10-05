<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleEnableCommand extends ModuleCommand
{
    protected $signature = 'module:enable {key : The module key to enable}';

    protected $description = 'Enable a registered module';

    public function handle(): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        try {
            $this->manager()->enable($key);
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info(sprintf('Enabled the module [%s].', $key));

        return self::SUCCESS;
    }
}
