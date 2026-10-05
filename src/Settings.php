<?php

declare(strict_types=1);

namespace Frolax\Bedrock;

use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\DataObjects\Settings\SettingDefinition;
use Illuminate\Support\Collection;

class Settings
{
    public function __construct(protected ModuleSettings $settings) {}

    /** @return Collection<int, SettingDefinition> */
    public function definitions(string $module): Collection
    {
        return $this->settings->definitions($module);
    }

    /** @return array<string, mixed> */
    public function all(string $module): array
    {
        return $this->settings->all($module);
    }

    public function get(string $module, string $key, mixed $default = null): mixed
    {
        return $this->settings->get($module, $key, $default);
    }

    public function set(string $module, string $key, mixed $value): void
    {
        $this->settings->set($module, $key, $value);
    }

    /** @param array<string, mixed> $values */
    public function update(string $module, array $values): void
    {
        $this->settings->update($module, $values);
    }

    public function reset(string $module, ?string $key = null): void
    {
        $this->settings->reset($module, $key);
    }
}
