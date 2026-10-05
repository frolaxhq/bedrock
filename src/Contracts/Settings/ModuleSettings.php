<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Contracts\Settings;

use Frolax\Bedrock\DataObjects\Settings\SettingDefinition;
use Illuminate\Support\Collection;

interface ModuleSettings
{
    /** @return Collection<int, SettingDefinition> */
    public function definitions(string $module): Collection;

    /** @return array<string, mixed> */
    public function all(string $module): array;

    public function get(string $module, string $key, mixed $default = null): mixed;

    public function set(string $module, string $key, mixed $value): void;

    /** @param array<string, mixed> $values */
    public function update(string $module, array $values): void;

    public function reset(string $module, ?string $key = null): void;

    /** @return array<string, mixed> */
    public function forInertia(string $module): array;
}
