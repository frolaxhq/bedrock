<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Administration;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\DataObjects\Settings\SettingDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingController
{
    public function __construct(
        protected ModuleRepository $modules,
        protected ModuleSettings $settings,
    ) {}

    public function show(string $key): View
    {
        $module = $this->modules->find($key);
        abort_if($module === null, 404);

        return view('bedrock::admin.settings.show', [
            'module' => $module,
            'definitions' => $this->settings->definitions($key),
            'publicSettings' => $this->settings->forInertia($key),
        ]);
    }

    public function update(Request $request, string $key, string $setting): RedirectResponse
    {
        $definition = $this->definition($key, $setting);

        if ($definition->type === 'boolean') {
            $value = $request->boolean('value');
        } elseif ($definition->type === 'multiselect') {
            $value = $request->validate(['value' => ['sometimes', 'array'], 'value.*' => ['string']])['value'] ?? [];
        } elseif ($definition->type === 'number') {
            $raw = $request->validate(['value' => ['required', 'numeric']])['value'];
            $value = str_contains((string) $raw, '.') ? (float) $raw : (int) $raw;
        } else {
            $value = $request->validate(['value' => [$definition->isSecret() ? 'required' : 'present', 'string']])['value'];
        }

        try {
            $this->settings->set($key, $setting, $value);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('bedrock.admin.settings.show', $key)->withErrors(['operation' => $exception->getMessage()]);
        }

        return redirect()->route('bedrock.admin.settings.show', $key)->with('status', sprintf('Setting [%s] saved.', $setting));
    }

    public function reset(string $key, string $setting): RedirectResponse
    {
        $this->definition($key, $setting);
        $this->settings->reset($key, $setting);

        return redirect()->route('bedrock.admin.settings.show', $key)->with('status', sprintf('Setting [%s] reset.', $setting));
    }

    protected function definition(string $key, string $setting): SettingDefinition
    {
        abort_if($this->modules->find($key) === null, 404);
        $definition = $this->settings->definitions($key)->firstWhere('key', $setting);
        abort_if(! $definition instanceof SettingDefinition, 404);

        return $definition;
    }
}
