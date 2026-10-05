<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Events\Module\ModuleBootFailed;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Models\Module;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Registers the service provider of every enabled module.
 */
class ModuleBootstrapper
{
    public function __construct(
        protected Application $app,
        protected ModuleRegistry $registry,
        protected DependencyResolver $resolver,
        protected DependencyGuard $guard,
        protected CoreVersionGuard $core,
        protected ComposerRequirementChecker $composer,
        protected ModuleAutoloader $autoloader,
        protected ModuleHealthManager $health,
    ) {}

    /**
     * Boot the providers of the enabled modules.
     */
    public function bootstrap(): void
    {
        $enabled = $this->registry->enabled()->keyBy('key');

        try {
            $order = $this->resolver->order(
                $enabled->map(static fn (Module $module): Manifest => $module->toManifest()),
            );
        } catch (CircularDependencyException $exception) {
            foreach (array_unique($exception->cycle()) as $key) {
                $module = $enabled->get($key);

                if ($module instanceof Module) {
                    $this->fail($module, ModuleFailureCode::DependencyCycle, $exception->getMessage(), $exception);
                }
            }

            $this->bootstrap();

            return;
        }

        foreach ($order as $key) {
            $module = $enabled->get($key);

            if ($module instanceof Module) {
                $this->bootstrapModule($module);
            }
        }
    }

    /**
     * Register a single module provider, marking the module failed when it cannot boot.
     */
    protected function bootstrapModule(Module $module): void
    {
        $provider = $module->provider;

        try {
            $this->core->ensureSatisfied($module->toManifest());
            $this->composer->ensureSatisfied($module->toManifest());
            $this->autoloader->register($module->toManifest(), (string) $module->path, true);
            $this->guard->ensureSatisfied($module->toManifest());
        } catch (Throwable $exception) {
            $this->fail($module, ModuleFailureCode::BootstrapFailed, $exception->getMessage(), $exception);

            return;
        }

        if (! class_exists($provider)) {
            $this->fail($module, ModuleFailureCode::ProviderMissing, sprintf('The module provider [%s] does not exist.', $provider), null, true);

            return;
        }

        try {
            $this->app->register($provider);
        } catch (Throwable $exception) {
            $this->fail($module, ModuleFailureCode::BootstrapFailed, $exception->getMessage(), $exception, true);
        }
    }

    /**
     * Mark a module as failed and announce why.
     */
    protected function fail(
        Module $module,
        ModuleFailureCode $code,
        string $reason,
        ?Throwable $exception = null,
        bool $bootEvent = false,
    ): void {
        $this->health->fail($module, $code, $reason, $exception);

        if ($bootEvent) {
            event(new ModuleBootFailed($module->key, $module->toManifest(), $reason, $exception));
        }
    }
}
