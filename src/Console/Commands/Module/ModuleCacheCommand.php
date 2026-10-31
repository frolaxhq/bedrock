<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Services\Module\CompiledModuleBoot;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Throwable;

class ModuleCacheCommand extends ModuleCommand
{
    protected $signature = 'module:cache {--warm : Fully verify and warm enabled module PHP loading descriptors}';

    protected $description = 'Rebuild the module registry and optionally warm immutable production runtimes';

    public function handle(PhpRuntime $runtime): int
    {
        if ($this->option('warm') && (! app()->isProduction()
            || ! config('bedrock.modules.php.validation.cache', false)
            || ! is_string(config('bedrock.modules.php.validation.release'))
            || trim(config('bedrock.modules.php.validation.release')) === '')) {
            return $this->failure('Warming requires production, modules.php.validation.cache=true and a unique modules.php.validation.release deployment ID.');
        }

        $this->registry()->refresh();
        $modules = $this->registry()->enabled();

        if ($this->option('warm')) {
            try {
                foreach ($modules as $module) {
                    $runtime->loading($module->toManifest(), (string) $module->path, true);
                }
                app(CompiledModuleBoot::class)->warm();
            } catch (Throwable $exception) {
                return $this->failure($exception->getMessage());
            }
            $this->components->info(sprintf('Fully verified and warmed %d enabled module runtimes.', $modules->count()));
        }

        $this->components->info('The module registry cache was rebuilt.');

        return self::SUCCESS;
    }
}
