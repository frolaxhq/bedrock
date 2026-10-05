<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Exceptions\BedrockException;
use Frolax\Bedrock\Services\Module\Authoring\ModuleScaffolder;

class ModuleMakeCommand extends ModuleCommand
{
    protected $signature = 'module:make
        {key : The lowercase module key}
        {--name= : The human-readable module name}
        {--backend-only : Generate no frontend source or build configuration}';

    protected $description = 'Create a new Bedrock module without registering or installing it';

    public function handle(ModuleScaffolder $scaffolder): int
    {
        try {
            $path = $scaffolder->create(
                (string) $this->argument('key'),
                is_string($this->option('name')) ? $this->option('name') : null,
                (bool) $this->option('backend-only'),
            );
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info(sprintf('Created the module at [%s].', $path));
        $this->components->bulletList(['Review the generated files.', 'Run module:validate before installation.']);

        return self::SUCCESS;
    }
}
