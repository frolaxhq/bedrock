<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Services\Module\Authoring\ModuleSourceValidator;
use Frolax\Bedrock\Support\Module\ModulePath;
use Throwable;

class ModuleValidateCommand extends ModuleCommand
{
    protected $signature = 'module:validate {source : A module directory} {--json : Emit stable JSON output}';

    protected $description = 'Validate a module source without executing its code';

    public function handle(ModuleSourceValidator $validator): int
    {
        $path = ModulePath::absolute($this->laravel, (string) $this->argument('source'));

        try {
            $manifest = $validator->validate($path);
            $result = ['ok' => true, 'module' => $manifest->key, 'version' => $manifest->version, 'message' => 'The module source is valid.'];
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'module' => null, 'version' => null, 'message' => $exception->getMessage()];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($result['ok']) {
            $this->components->info($result['message']);
        } else {
            $this->components->error($result['message']);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
