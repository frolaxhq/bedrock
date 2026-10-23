<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Install;

use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\Exceptions\Module\ModuleDatabaseException;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Runs the database resources a module declares in its manifest.
 *
 * Migrations run from the module's own directory through `migrate --path`, and
 * seeders run by class name through `db:seed`, so both happen as part of the
 * install instead of on the next request. The commands are called in this
 * process, which means a failure arrives as an exception rather than as an exit
 * code the caller has to remember to check.
 */
class DatabaseModuleRunner
{
    public function __construct(
        protected Kernel $kernel,
        protected Filesystem $files,
    ) {}

    /**
     * Run the migrations and seeders the module asks for.
     *
     * @throws ModuleDatabaseException
     */
    public function run(DiscoveredModule $module): void
    {
        $module = new DiscoveredModule($module->key, app(PhpRuntime::class)->root($module->manifest, $module->path), $module->manifest);

        if ($this->runsMigrations($module)) {
            $this->migrate($module);
        }

        foreach ($this->seeders($module) as $seeder) {
            $this->seed($module, $seeder);
        }
    }

    /**
     * Determine whether the module declares migrations that are present on disk.
     */
    protected function runsMigrations(DiscoveredModule $module): bool
    {
        if (! (bool) ($module->manifest->database['migrations'] ?? false)) {
            return false;
        }

        return $this->files->isDirectory($module->path.'/database/migrations');
    }

    /**
     * Run the module's migrations from its own directory.
     *
     * @throws ModuleDatabaseException
     */
    protected function migrate(DiscoveredModule $module): void
    {
        $path = $module->path.'/database/migrations';

        try {
            $exitCode = $this->kernel->call('migrate', [
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            throw ModuleDatabaseException::migrationsFailed($module->key, $path, $exception);
        }

        if ($exitCode !== 0) {
            throw ModuleDatabaseException::migrationsFailed($module->key, $path);
        }
    }

    /**
     * Run one of the module's seeders by class name.
     *
     * @throws ModuleDatabaseException
     */
    protected function seed(DiscoveredModule $module, string $class): void
    {
        try {
            $exitCode = $this->kernel->call('db:seed', [
                '--class' => $class,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            throw ModuleDatabaseException::seederFailed($module->key, $class, $exception);
        }

        if ($exitCode !== 0) {
            throw ModuleDatabaseException::seederFailed($module->key, $class);
        }
    }

    /**
     * The seeder classes the module asks to run.
     *
     * A module that declares seeders without naming one runs none: the class
     * name is the only handle `db:seed` can resolve, and guessing one would
     * seed whatever happened to share the name.
     *
     * @return list<string>
     */
    protected function seeders(DiscoveredModule $module): array
    {
        $database = $module->manifest->database;

        if (! (bool) ($database['seeders'] ?? false)) {
            return [];
        }

        $seeder = $database['seeder'] ?? null;

        return is_string($seeder) && trim($seeder) !== '' ? [trim($seeder)] : [];
    }
}
