<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Settings;

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\DataObjects\Settings\SettingDefinition;
use Frolax\Bedrock\Events\Settings\ModuleSettingsChanged;
use Frolax\Bedrock\Events\Settings\ModuleSettingsReset;
use Frolax\Bedrock\Models\ModuleSetting;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;

class EloquentModuleSettings implements ModuleSettings
{
    protected const array TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'secret'];

    /** @param array<string, mixed> $cacheConfig */
    public function __construct(
        protected ModuleRegistry $modules,
        protected Cache $cache,
        protected Encrypter $encrypter,
        protected array $cacheConfig = [],
    ) {}

    public function definitions(string $module): Collection
    {
        $registered = $this->modules->find($module);

        if ($registered === null) {
            throw new InvalidArgumentException(sprintf('The module [%s] is not registered.', $module));
        }

        /** @var array<string, mixed> $settings */
        $settings = $registered->toManifest()->settings;

        return collect($settings)
            ->map(fn (mixed $definition, mixed $key): SettingDefinition => $this->definition((string) $key, $definition))
            ->values();
    }

    public function all(string $module): array
    {
        $definitions = $this->definitions($module)->keyBy('key');
        $stored = $this->stored($module);
        $values = [];

        foreach ($definitions as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : $definition->default;
        }

        return $values;
    }

    public function get(string $module, string $key, mixed $default = null): mixed
    {
        $definition = $this->definitions($module)->firstWhere('key', $key);

        if (! $definition instanceof SettingDefinition) {
            return $default;
        }

        $values = $this->all($module);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function set(string $module, string $key, mixed $value): void
    {
        $this->update($module, [$key => $value]);
    }

    public function update(string $module, array $values): void
    {
        $definitions = $this->definitions($module)->keyBy('key');

        foreach (array_keys($values) as $key) {
            if (! $definitions->has($key)) {
                throw new InvalidArgumentException(sprintf('The setting [%s.%s] is not declared.', $module, $key));
            }
        }

        $rules = [];

        foreach ($values as $key => $value) {
            /** @var SettingDefinition $definition */
            $definition = $definitions->get($key);
            $rules[str_replace('.', '\\.', $key)] = $definition->rules;
            $this->validateOptions($definition, $value);
        }

        Validator::make($values, $rules)->validate();

        DB::transaction(function () use ($module, $values, $definitions): void {
            foreach ($values as $key => $value) {
                /** @var SettingDefinition $definition */
                $definition = $definitions->get($key);
                $serialized = $this->serialize($value);

                ModuleSetting::query()->updateOrCreate(
                    ['module_key' => $module, 'setting_key' => $key],
                    [
                        'value' => $definition->isSecret() ? $this->encrypter->encrypt($serialized) : $serialized,
                        'encrypted' => $definition->isSecret(),
                    ],
                );
            }
        });

        $this->forget($module);
        event(new ModuleSettingsChanged($module, array_keys($values)));
    }

    public function reset(string $module, ?string $key = null): void
    {
        if ($key !== null) {
            $this->definitionFor($module, $key);
            ModuleSetting::query()->where('module_key', $module)->where('setting_key', $key)->delete();
        } else {
            ModuleSetting::query()->where('module_key', $module)->delete();
        }

        $this->forget($module);
        event(new ModuleSettingsReset($module, $key));
    }

    /** @return array<string, mixed> */
    public function forInertia(string $module): array
    {
        $stored = $this->stored($module);

        return [
            'definitions' => $this->definitions($module)
                ->map(static fn (SettingDefinition $definition): array => $definition->forInertia(array_key_exists($definition->key, $stored)))
                ->all(),
            'values' => $this->definitions($module)
                ->reject(static fn (SettingDefinition $definition): bool => $definition->isSecret())
                ->mapWithKeys(fn (SettingDefinition $definition): array => [$definition->key => $stored[$definition->key] ?? $definition->default])
                ->all(),
        ];
    }

    protected function definitionFor(string $module, string $key): SettingDefinition
    {
        $definition = $this->definitions($module)->firstWhere('key', $key);

        if (! $definition instanceof SettingDefinition) {
            throw new InvalidArgumentException(sprintf('The setting [%s.%s] is not declared.', $module, $key));
        }

        return $definition;
    }

    protected function definition(string $key, mixed $definition): SettingDefinition
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]*$/', $key)) {
            throw new InvalidArgumentException(sprintf('The setting key [%s] is invalid.', $key));
        }

        if (! is_array($definition) || array_is_list($definition)) {
            $definition = ['default' => $definition, 'type' => $this->inferType($definition)];
        }

        $type = (string) ($definition['type'] ?? 'text');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('The setting [%s] has unsupported type [%s].', $key, $type));
        }

        $rules = array_values(array_filter((array) ($definition['rules'] ?? []), 'is_string'));
        $options = Arr::map((array) ($definition['options'] ?? []), static fn (mixed $label): string => (string) $label);

        if (in_array($type, ['select', 'multiselect'], true) && $options === []) {
            throw new InvalidArgumentException(sprintf('The setting [%s] requires options.', $key));
        }

        return new SettingDefinition(
            $key,
            $type,
            (string) ($definition['label'] ?? str($key)->replace(['_', '.', '-'], ' ')->headline()),
            $definition['default'] ?? null,
            isset($definition['description']) ? (string) $definition['description'] : null,
            $rules,
            $options,
        );
    }

    protected function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => 'text',
        };
    }

    protected function validateOptions(SettingDefinition $definition, mixed $value): void
    {
        if ($value === null || ! in_array($definition->type, ['select', 'multiselect'], true)) {
            return;
        }

        $values = $definition->type === 'multiselect' ? (is_array($value) ? $value : []) : [$value];

        foreach ($values as $option) {
            if (! array_key_exists((string) $option, $definition->options)) {
                throw new InvalidArgumentException(sprintf('The selected value for [%s] is not allowed.', $definition->key));
            }
        }
    }

    /** @return array<string, mixed> */
    protected function stored(string $module): array
    {
        $key = $this->cacheKey($module);

        if ((bool) ($this->cacheConfig['enabled'] ?? true)) {
            return $this->store()->remember($key, (int) ($this->cacheConfig['ttl'] ?? 3600), fn (): array => $this->loadStored($module));
        }

        return $this->loadStored($module);
    }

    /** @return array<string, mixed> */
    protected function loadStored(string $module): array
    {
        return ModuleSetting::query()->where('module_key', $module)->get()
            ->mapWithKeys(function (ModuleSetting $setting): array {
                $value = (string) $setting->value;

                if ($setting->encrypted) {
                    $value = $this->encrypter->decrypt($value);
                }

                return [$setting->setting_key => json_decode($value, true, 512, JSON_THROW_ON_ERROR)];
            })->all();
    }

    protected function forget(string $module): void
    {
        $this->store()->forget($this->cacheKey($module));
    }

    protected function cacheKey(string $module): string
    {
        return (string) ($this->cacheConfig['key'] ?? 'bedrock.settings').'.'.$module;
    }

    protected function store(): Repository
    {
        $store = $this->cacheConfig['store'] ?? null;

        return is_string($store) && $store !== '' ? $this->cache->store($store) : $this->cache->store();
    }

    protected function serialize(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The setting value cannot be serialized.', previous: $exception);
        }
    }
}
