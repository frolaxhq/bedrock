<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Module\ComposerRequirementChecker;
use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Exceptions\Module\DependencyNotSatisfiedException;
use Frolax\Bedrock\Models\Module;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class StateReconciler
{
    public function __construct(
        protected ModuleRepository $repository,
        protected DependencyGuard $guard,
        protected DependencyResolver $resolver,
        protected CoreVersionGuard $core,
        protected ComposerRequirementChecker $composer,
        protected ModuleAutoloader $autoloader,
        protected ModuleHealthManager $health,
        protected Filesystem $files,
        protected FrontendAssetManager $frontend,
    ) {}

    /** @return array<string, string> */
    public function reconcile(ModuleDiscoveryResult $discovery): array
    {
        $broken = [];
        $cycles = $this->cycles($discovery);

        foreach ($this->repository->all() as $module) {
            $failure = $this->failure($module, $discovery, $cycles);

            if ($failure !== null) {
                [$code, $reason] = $failure;
                $broken[(string) $module->key] = $reason;
                $this->health->fail($module, $code, $reason);

                continue;
            }

            $discovered = $discovery->find((string) $module->key);

            if ($discovered !== null) {
                $module = $this->repository->upsert((string) $module->key, [
                    'name' => $discovered->manifest->name,
                    'provider' => $discovered->manifest->provider,
                    'path' => $discovered->path,
                    'manifest' => $discovered->manifest->toArray(),
                    'dependencies' => $discovered->manifest->requires,
                ]);
            }

            $this->health->recover($module);
        }

        return $broken;
    }

    /**
     * @param  array<string, string>  $cycles
     * @return array{ModuleFailureCode, string}|null
     */
    protected function failure(Module $module, ModuleDiscoveryResult $discovery, array $cycles): ?array
    {
        $key = (string) $module->key;
        $discovered = $discovery->find($key);

        if ($discovered === null) {
            if (! $this->files->isDirectory((string) $module->path)) {
                return [ModuleFailureCode::DirectoryMissing, 'The module directory no longer exists.'];
            }

            return [ModuleFailureCode::ManifestInvalid, $discovery->failures[$key] ?? 'The module manifest is missing or invalid.'];
        }

        if ($discovered->manifest->version !== (string) $module->version) {
            return [ModuleFailureCode::VersionDrift, sprintf(
                'The registered version [%s] does not match the manifest version [%s].',
                $module->version,
                $discovered->manifest->version,
            )];
        }

        $autoloadFailures = $this->autoloader->failures($discovered->manifest, $discovered->path);

        if ($autoloadFailures !== []) {
            return [ModuleFailureCode::AutoloadInvalid, $autoloadFailures[0]];
        }

        try {
            $this->autoloader->register($discovered->manifest, $discovered->path);
        } catch (Throwable $exception) {
            return [ModuleFailureCode::AutoloadInvalid, $exception->getMessage()];
        }

        if (! class_exists($discovered->manifest->provider)) {
            return [ModuleFailureCode::ProviderMissing, sprintf('The module provider [%s] does not exist.', $discovered->manifest->provider)];
        }

        $frontend = $this->frontend->failures(
            $discovered->manifest,
            $discovered->path,
            $module->status !== ModuleStatus::Discovered,
        );

        if ($frontend !== []) {
            $code = str_contains(strtolower($frontend[0]), 'does not exist') || str_contains(strtolower($frontend[0]), 'missing')
                ? ModuleFailureCode::FrontendArtifactMissing
                : ModuleFailureCode::FrontendArtifactInvalid;

            return [$code, $frontend[0]];
        }

        if (isset($cycles[$key])) {
            return [ModuleFailureCode::DependencyCycle, $cycles[$key]];
        }

        if (($reason = $this->core->reason($discovered->manifest)) !== null) {
            return [ModuleFailureCode::CoreIncompatible, $reason];
        }

        $composer = $this->composer->failures($discovered->manifest);

        if ($composer !== []) {
            $package = array_key_first($composer);
            $code = str_contains($composer[$package], 'not installed')
                ? ModuleFailureCode::PackageMissing
                : ModuleFailureCode::PackageIncompatible;

            return [$code, sprintf('Composer requirement [%s] failed: %s.', $package, $composer[$package])];
        }

        $missing = $this->guard->missing($discovered->manifest);

        if ($missing !== []) {
            return [ModuleFailureCode::DependencyMissing, sprintf('Required modules are missing: [%s].', implode(', ', $missing))];
        }

        $incompatible = $this->guard->incompatible($discovered->manifest);

        if ($incompatible !== []) {
            $dependency = array_key_first($incompatible);
            $details = $incompatible[$dependency];

            return [ModuleFailureCode::DependencyIncompatible, DependencyNotSatisfiedException::incompatible(
                $discovered->manifest->key,
                $dependency,
                $details['version'],
                $details['constraint'],
            )->getMessage()];
        }

        if ($module->status === ModuleStatus::Enabled) {
            foreach ($discovered->manifest->requirements() as $dependency) {
                $required = $this->repository->find($dependency);

                if ($required !== null && ! $required->isEnabled()) {
                    return [ModuleFailureCode::DependencyDisabled, sprintf('Required module [%s] is disabled or unhealthy.', $dependency)];
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected function cycles(ModuleDiscoveryResult $discovery): array
    {
        $manifests = array_map(static fn (DiscoveredModule $module): Manifest => $module->manifest, $discovery->modules);
        $cycles = [];

        foreach ($discovery->modules as $module) {
            try {
                $this->resolver->order($manifests, $module->key);
            } catch (CircularDependencyException $exception) {
                foreach (array_unique($exception->cycle()) as $key) {
                    $cycles[$key] ??= $exception->getMessage();
                }
            }
        }

        return $cycles;
    }
}
