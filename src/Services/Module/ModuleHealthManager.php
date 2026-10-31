<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Events\Module\ModuleFailed;
use Frolax\Bedrock\Events\Module\ModuleRecovered;
use Frolax\Bedrock\Models\Module;
use Throwable;

class ModuleHealthManager
{
    public function __construct(
        protected ModuleRepository $repository,
        protected ModuleRegistry $registry,
    ) {}

    public function fail(Module $module, ModuleFailureCode $code, string $reason, ?Throwable $exception = null): Module
    {
        $changed = $module->health !== ModuleHealth::Failed
            || $module->failure_code !== $code
            || $module->failure_reason !== $reason;

        app(CompiledModuleBoot::class)->invalidate();
        $updated = $this->repository->upsert((string) $module->key, [
            'health' => ModuleHealth::Failed,
            'failure_code' => $code,
            'failure_reason' => $reason,
            'failed_at' => $module->failed_at ?? now(),
        ]);
        $this->registry->refresh();

        if ($changed) {
            event(new ModuleFailed((string) $updated->key, $updated->toManifest(), $code, $reason, $exception));
        }

        return $updated;
    }

    public function recover(Module $module): Module
    {
        if ($module->health !== ModuleHealth::Failed) {
            return $module;
        }

        app(CompiledModuleBoot::class)->invalidate();
        $updated = $this->repository->upsert((string) $module->key, [
            'health' => ModuleHealth::Healthy,
            'failure_code' => null,
            'failure_reason' => null,
            'failed_at' => null,
        ]);
        $this->registry->refresh();
        event(new ModuleRecovered((string) $updated->key, $updated->toManifest()));

        return $updated;
    }
}
