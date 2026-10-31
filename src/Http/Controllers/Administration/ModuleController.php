<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Administration;

use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\Exceptions\BedrockException;
use Frolax\Bedrock\Models\ModuleInstallation;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ModuleController
{
    public function __construct(
        protected ModuleRepository $modules,
        protected ModuleDiscoverer $discoverer,
        protected ModuleDiagnostics $diagnostics,
        protected ModuleManager $manager,
        protected InstallationOperations $installations,
    ) {}

    public function index(): View
    {
        $registered = $this->modules->all();
        $discovered = $this->discoverer->discover();
        $unregistered = collect($discovered->modules)
            ->reject(fn (DiscoveredModule $module): bool => $registered->contains('key', $module->key))
            ->values();

        return view('bedrock::admin.modules.index', [
            'modules' => $registered,
            'unregistered' => $unregistered,
            'discoveryFailures' => $discovered->failures,
            'installations' => ModuleInstallation::query()->latest()->orderByDesc('id')->limit(50)->get(),
            'activeInstallations' => ModuleInstallation::query()->whereNotNull('active_key')->pluck('key'),
        ]);
    }

    public function show(string $key): View
    {
        $module = $this->modules->find($key);
        $installation = ModuleInstallation::query()->where('key', $key)->latest()->orderByDesc('id')->first();

        abort_if($module === null && $installation === null, 404);

        if ($module === null) {
            return view('bedrock::admin.modules.installation', ['installation' => $installation]);
        }

        return view('bedrock::admin.modules.show', [
            'module' => $module,
            'report' => $this->diagnostics->diagnose($key),
            'installation' => $installation,
        ]);
    }

    public function install(string $key): RedirectResponse
    {
        try {
            $operation = $this->installations->schedule($key);
        } catch (BedrockException $exception) {
            return redirect()->route('bedrock.admin.modules.index')->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.modules.show', $key)
            ->with('status', sprintf('Installation queued for module [%s]. Operation: %s.', $key, $operation->id));
    }

    public function enable(string $key): RedirectResponse
    {
        return $this->operate($key, fn (): bool => $this->manager->enable($key));
    }

    public function disable(string $key): RedirectResponse
    {
        return $this->operate($key, fn (): bool => $this->manager->disable($key));
    }

    /** @param  callable(): bool  $action */
    protected function operate(string $key, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (BedrockException $exception) {
            return redirect()->route('bedrock.admin.modules.index')->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.modules.show', $key)->with('status', sprintf('Module [%s] updated.', $key));
    }
}
