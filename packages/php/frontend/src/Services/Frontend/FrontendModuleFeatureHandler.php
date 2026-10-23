<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Module\ModuleFeatureHandler;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Module\DiagnosticCheck;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\DiagnosticStatus;
use Illuminate\Support\Facades\Route;

class FrontendModuleFeatureHandler implements ModuleFeatureHandler
{
    public function __construct(protected FrontendAssetManager $assets, protected ModulePermissions $permissions) {}

    public function failures(Manifest $manifest, string $path, bool $published = false): array
    {
        return $this->assets->failures($manifest, $path, $published);
    }

    public function publish(Manifest $manifest, string $path): void
    {
        $this->assets->publish($manifest, $path);
    }

    public function remove(string $key, string $version): void
    {
        $this->assets->remove($key, $version);
    }

    public function diagnostics(Manifest $manifest, string $path, bool $published = false): array
    {
        $failures = $this->failures($manifest, $path, $published);
        $checks = [new DiagnosticCheck(
            'frontend.artifact', $failures === [] ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
            $manifest->key, $failures[0] ?? 'Frontend artifacts are complete and pass integrity checks.',
        )];

        if ($failures !== []) {
            return $checks;
        }

        foreach ($this->items($this->assets->navigation($manifest, $path)['items']) as $item) {
            $route = $item['route'] ?? null;

            if (is_string($route) && $route !== '') {
                $checks[] = new DiagnosticCheck('navigation.route', Route::has($route) ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
                    $manifest->key, sprintf('Navigation route [%s] %s.', $route, Route::has($route) ? 'resolves' : 'does not exist'));
            }

            $permission = $item['permission'] ?? null;

            if (is_string($permission) && $permission !== '') {
                $name = str_contains($permission, '.') ? $permission : $this->permissions->name($manifest->key, $permission);
                $declared = collect($manifest->permissions)->contains(function (mixed $definition) use ($manifest, $name): bool {
                    $key = is_string($definition) ? $definition : $definition['key'] ?? null;

                    return is_string($key) && $name === $this->permissions->name($manifest->key, $key);
                });
                $checks[] = new DiagnosticCheck('navigation.permission', $declared ? DiagnosticStatus::Passed : DiagnosticStatus::Failed,
                    $manifest->key, sprintf('Navigation permission [%s] %s.', $name, $declared ? 'is declared' : 'is not declared by this module'));
            }
        }

        return $checks;
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    protected function items(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[] = $item;
            $children = $item['children'] ?? [];

            if (is_array($children)) {
                $result = [...$result, ...$this->items(array_values(array_filter($children, 'is_array')))];
            }
        }

        return $result;
    }
}
