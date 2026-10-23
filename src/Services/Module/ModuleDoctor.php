<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\DataObjects\Module\DiagnosticCheck;
use Frolax\Bedrock\DataObjects\Module\DiagnosticReport;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\DiagnosticStatus;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Frolax\Bedrock\Support\Module\ModulePath;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class ModuleDoctor implements ModuleDiagnostics
{
    public function __construct(
        protected Application $app,
        protected ModuleRepository $repository,
        protected ModuleDiscoverer $discoverer,
        protected ModuleOperationLock $locks,
        protected ModuleAutoloader $autoloader,
        protected ComposerRequirementChecker $composer,
        protected CoreVersionGuard $core,
        protected DependencyGuard $dependencies,
        protected DependencyResolver $resolver,
        protected Filesystem $files,
        protected ModuleFeatureHandlers $features,
    ) {}

    public function diagnose(?string $key = null): DiagnosticReport
    {
        $checks = [];
        $checks[] = $this->check(
            'database.registry',
            $this->repository->isAvailable() ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
            null,
            $this->repository->isAvailable() ? 'The module registry is available.' : 'The module registry table is unavailable.',
        );

        $root = ModulePath::modules($this->app);
        $rootReady = $this->files->isDirectory($root) && is_readable($root) && is_writable($root);
        $checks[] = $this->check(
            'path.root',
            $rootReady ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
            null,
            $rootReady ? sprintf('The module root [%s] is readable and writable.', $root) : sprintf('The module root [%s] must be readable and writable.', $root),
        );
        $checks[] = $this->check(
            'lock.support',
            $this->locks->supported() ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
            null,
            $this->locks->supported() ? 'The configured cache store supports atomic locks.' : 'The configured cache store does not support atomic locks.',
        );

        $discovery = $this->discoverer->discover();
        $cycles = $this->cycles($discovery->modules);

        foreach ($discovery->failures as $module => $reason) {
            if ($key === null || $key === $module) {
                $checks[] = $this->check('manifest.invalid', DiagnosticStatus::Failed, $module, $reason);
            }
        }

        foreach ($discovery->modules as $discovered) {
            if ($key !== null && $key !== $discovered->key) {
                continue;
            }

            if (is_file($discovered->path.'/composer.json')) {
                try {
                    $this->app->make(PhpRuntime::class)->validate($discovered->manifest, $discovered->path, true);
                    $checks[] = $this->check('php.runtime', DiagnosticStatus::Passed, $discovered->key, 'The isolated PHP runtime and locked dependencies are ready.');
                } catch (Throwable $exception) {
                    $checks[] = $this->check('php.runtime', DiagnosticStatus::Failed, $discovered->key, $exception->getMessage());
                    $command = $this->app->make('config')->get('bedrock.modules.php.composer', ['composer']);
                    $executable = is_array($command) ? ($command[0] ?? '') : '';
                    $found = is_string($executable) && $executable !== '' && ((new ExecutableFinder)->find($executable) !== null || is_file($executable));
                    $checks[] = $this->check('php.composer', $found ? DiagnosticStatus::Passed : DiagnosticStatus::Warning, $discovered->key,
                        $found ? 'The configured Composer executable is available for preparation.' : 'Configure bedrock.modules.php.composer or supply a ready-built runtime ZIP.');
                    $connection = config('bedrock.modules.operations.connection') ?? config('queue.default');
                    $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
                    $async = is_string($driver) && ! in_array($driver, ['sync', 'null', 'deferred', 'background', 'failover'], true);
                    $checks[] = $this->check('php.installation_queue', $async ? DiagnosticStatus::Passed : DiagnosticStatus::Warning, $discovered->key,
                        $async ? 'The installation queue is asynchronous; ensure its dedicated worker is running.' : 'Admin installation needs an asynchronous connection and a bedrock-install worker.');
                }
            }

            $failures = $this->autoloader->failures($discovered->manifest, $discovered->path);
            $checks[] = $this->check(
                'autoload.paths',
                $failures === [] ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
                $discovered->key,
                $failures[0] ?? 'All declared autoload paths are safe and available.',
            );

            if ($failures === []) {
                try {
                    $this->autoloader->register($discovered->manifest, $discovered->path);
                    $providerExists = class_exists($discovered->manifest->provider);
                    $checks[] = $this->check(
                        'provider.resolution',
                        $providerExists ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
                        $discovered->key,
                        $providerExists
                            ? sprintf('Provider [%s] resolves.', $discovered->manifest->provider)
                            : sprintf('Provider [%s] does not resolve.', $discovered->manifest->provider),
                    );
                } catch (Throwable $exception) {
                    $checks[] = $this->check('provider.resolution', DiagnosticStatus::Failed, $discovered->key, $exception->getMessage());
                }
            }

            $registered = $this->repository->find($discovered->key);
            $checks = [...$checks, ...$this->features->diagnostics(
                $discovered->manifest,
                $discovered->path,
                $registered !== null && $registered->status !== ModuleStatus::Discovered,
            )];

            $core = $this->core->reason($discovered->manifest);
            $checks[] = $this->check(
                'core.compatibility',
                $core === null ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
                $discovered->key,
                $core ?? 'The Bedrock core requirement is satisfied.',
            );

            foreach ($this->composer->failures($discovered->manifest) as $package => $reason) {
                $checks[] = $this->check('package.requirement', DiagnosticStatus::Failed, $discovered->key, sprintf('[%s] %s', $package, $reason));
            }

            foreach ($this->dependencies->missing($discovered->manifest) as $dependency) {
                $checks[] = $this->check('dependency.missing', DiagnosticStatus::Failed, $discovered->key, sprintf('Required module [%s] is not registered.', $dependency));
            }

            foreach ($this->dependencies->incompatible($discovered->manifest) as $dependency => $details) {
                $checks[] = $this->check('dependency.incompatible', DiagnosticStatus::Failed, $discovered->key, sprintf(
                    'Required module [%s] version [%s] does not satisfy [%s].',
                    $dependency,
                    $details['version'],
                    $details['constraint'],
                ));
            }

            if (isset($cycles[$discovered->key])) {
                $checks[] = $this->check('dependency.cycle', DiagnosticStatus::Failed, $discovered->key, $cycles[$discovered->key]);
            }

            if ($registered === null) {
                $checks[] = $this->check('registry.unregistered', DiagnosticStatus::Warning, $discovered->key, 'The module is valid on disk but is not registered.');
            } elseif ((string) $registered->version !== $discovered->manifest->version) {
                $checks[] = $this->check('registry.version_drift', DiagnosticStatus::Failed, $discovered->key, sprintf(
                    'Registry version [%s] differs from manifest version [%s].',
                    $registered->version,
                    $discovered->manifest->version,
                ));
            }
        }

        foreach ($this->repository->all() as $module) {
            if (($key === null || $key === $module->key) && $module->hasFailed()) {
                $checks[] = $this->check(
                    $module->failure_code->value,
                    DiagnosticStatus::Failed,
                    (string) $module->key,
                    $module->failure_reason ?? 'The module is unhealthy.',
                );
            }
        }

        if ($key !== null && $discovery->find($key) === null && $this->repository->find($key) === null) {
            $checks[] = $this->check('module.missing', DiagnosticStatus::Failed, $key, sprintf('The module [%s] was not found.', $key));
        }

        $ok = ! collect($checks)->contains(static fn (DiagnosticCheck $check): bool => $check->status === DiagnosticStatus::Failed);

        return new DiagnosticReport($ok, $checks);
    }

    protected function check(string $code, DiagnosticStatus $status, ?string $module, string $message): DiagnosticCheck
    {
        return new DiagnosticCheck($code, $status, $module, $message);
    }

    /**
     * @param  list<DiscoveredModule>  $modules
     * @return array<string, string>
     */
    protected function cycles(array $modules): array
    {
        $manifests = array_map(static fn (DiscoveredModule $module): Manifest => $module->manifest, $modules);
        $cycles = [];

        foreach ($modules as $module) {
            try {
                $this->resolver->order($manifests, $module->key);
            } catch (CircularDependencyException $exception) {
                foreach (array_unique($exception->cycle()) as $key) {
                    $cycles[$key] = $exception->getMessage();
                }
            }
        }

        return $cycles;
    }
}
