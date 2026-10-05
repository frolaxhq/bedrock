<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\Exceptions\BedrockException;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Filesystem\Filesystem;

class ModuleInstallCommand extends ModuleCommand
{
    protected $signature = 'module:install
        {key : The module key}
        {--from= : A directory to install the module from}
        {--zip= : A ZIP archive to install the module from}';

    protected $description = 'Install a module from a directory, a ZIP archive, or an existing module directory';

    public function handle(Filesystem $files): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        /** @var string|null $from */
        $from = $this->option('from');

        /** @var string|null $zip */
        $zip = $this->option('zip');

        if ($from !== null && $zip !== null) {
            return $this->failure('Use either --from or --zip, not both.');
        }

        if ($from !== null && trim($from) === '') {
            return $this->failure('The --from option needs a directory to install from.');
        }

        if ($zip !== null && trim($zip) === '') {
            return $this->failure('The --zip option needs an archive to install from.');
        }

        try {
            $source = $this->source($files, $from, $zip);

            if ($source === null) {
                $this->manager()->install($key);
            } else {
                $this->manager()->installFrom($source, $key);
            }
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->components->info($source === null
            ? sprintf('Registered the module [%s].', $key)
            : sprintf('Installed the module [%s].', $key));

        return self::SUCCESS;
    }

    /**
     * Build the source the install reads from, when one was requested.
     */
    protected function source(Filesystem $files, ?string $from, ?string $zip): ?ModuleSource
    {
        $manifest = ModulePath::manifest($this->laravel);

        if ($zip !== null) {
            $config = $this->laravel->make('config');

            $limit = $config->get('bedrock.modules.install.max_archive_bytes', 268435456);
            $entries = $config->get('bedrock.modules.install.max_entries', 10000);
            $pathLength = $config->get('bedrock.modules.install.max_path_length', 240);

            return new ZipSource(
                $files,
                ModulePath::absolute($this->laravel, $zip),
                $manifest,
                is_int($limit) ? $limit : 268435456,
                is_int($entries) ? $entries : 10000,
                is_int($pathLength) ? $pathLength : 240,
            );
        }

        if ($from !== null) {
            return new LocalDirectorySource($files, ModulePath::absolute($this->laravel, $from), $manifest);
        }

        return null;
    }
}
