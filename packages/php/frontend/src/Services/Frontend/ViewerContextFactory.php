<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the shared Bedrock viewer context for the current request.
 *
 * Bedrock never exposes the user model itself; the viewer is a narrow,
 * configurable projection of the authenticated user. Guests resolve to null
 * and panels fall back to the configured default.
 */
class ViewerContextFactory
{
    /**
     * @param  array<string, mixed>  $panels  The bedrock.frontend.panels config.
     * @param  array<string, mixed>  $viewer  The bedrock.frontend.viewer config.
     */
    public function __construct(
        protected ModulePermissions $permissions,
        protected array $panels = [],
        protected array $viewer = [],
    ) {}

    /**
     * The panel key for the given user, falling back to the configured default.
     */
    public function panel(?Authenticatable $user = null): string
    {
        $fallback = (string) ($this->panels['fallback'] ?? 'default');

        if (preg_match('/^[a-z][a-z0-9-]*$/', $fallback) !== 1) {
            $fallback = 'default';
        }

        if ($user === null) {
            return $fallback;
        }

        $candidate = data_get($user, (string) ($this->panels['user_attribute'] ?? 'type'));

        return is_string($candidate) && preg_match('/^[a-z][a-z0-9-]*$/', $candidate) === 1
            ? $candidate
            : $fallback;
    }

    /**
     * The full viewer payload, or null for guests.
     *
     * @return array<string, mixed>|null
     */
    public function make(?Authenticatable $user = null): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $this->identity($user, 'id'),
            'name' => (string) ($this->identity($user, 'name') ?? ''),
            'email' => $this->text($user, 'email'),
            'avatar' => $this->text($user, 'avatar'),
            'panel' => $this->panel($user),
            'permissions' => $this->permissions($user),
            'actions' => $this->actions(),
        ];
    }

    /**
     * The raw value of a configurable identity attribute.
     */
    protected function identity(Authenticatable $user, string $key): mixed
    {
        $attributes = (array) ($this->viewer['attributes'] ?? []);
        $source = $attributes[$key] ?? $key;

        if (! is_string($source) || trim($source) === '') {
            return null;
        }

        $value = data_get($user, $source);

        return is_scalar($value) ? $value : null;
    }

    /**
     * An optional string identity attribute such as email or avatar.
     */
    protected function text(Authenticatable $user, string $key): ?string
    {
        $value = $this->identity($user, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The granted permissions that are still active Bedrock catalog entries.
     *
     * @return list<string>
     */
    protected function permissions(Authenticatable $user): array
    {
        return array_values($this->permissions->activeNames()
            ->filter(static fn (string $ability): bool => Gate::forUser($user)->allows($ability))
            ->sort()
            ->values()
            ->all());
    }

    /**
     * The configured profile and logout action routes that actually exist.
     *
     * @return array<string, string>
     */
    protected function actions(): array
    {
        $actions = [];

        foreach ((array) ($this->viewer['actions'] ?? []) as $key => $route) {
            if (
                in_array($key, ['profile', 'logout'], true)
                && is_string($route)
                && trim($route) !== ''
                && Route::has($route)
            ) {
                $actions[$key] = route($route, [], false);
            }
        }

        return $actions;
    }
}
