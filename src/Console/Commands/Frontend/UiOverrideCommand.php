<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Frontend;

use Frolax\Bedrock\Services\Frontend\FrontendOverrideCatalog;
use Illuminate\Console\Command;
use Throwable;

class UiOverrideCommand extends Command
{
    protected $signature = 'ui:override {target? : Catalog path or component shorthand} {--list : List overrideable frontend modules}';

    protected $description = 'Seed a conventional host override for a Bedrock frontend module';

    public function handle(FrontendOverrideCatalog $catalog): int
    {
        if ((bool) $this->option('list')) {
            $this->line('Override target -> Host path');

            foreach ($catalog->all() as $id) {
                $extension = str_starts_with($id, 'hooks/') ? 'ts' : 'tsx';
                $this->line(sprintf('%s -> resources/js/%s.%s', $id, $id, $extension));
            }

            return self::SUCCESS;
        }

        $target = $this->argument('target');

        if (! is_string($target) || $target === '') {
            $this->components->error('Provide an override target or use --list.');

            return self::FAILURE;
        }

        try {
            $id = $catalog->seed($target);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Created frontend override [%s].', $id));

        return self::SUCCESS;
    }
}
