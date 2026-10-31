<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Middleware;

use Closure;
use Frolax\Bedrock\DataObjects\Navigation\NavigationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'bedrock::app';

    public function handle(Request $request, Closure $next): Response
    {
        $shared = Inertia::getShared('__bedrock');

        try {
            return parent::handle($request, $next);
        } finally {
            // Never retain a request-specific wrapper in a reusable response factory.
            Inertia::share('__bedrock', $shared);
        }
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $shared = Inertia::getShared('__bedrock');

        return [...parent::share($request),
            'name' => config('bedrock.brand.name') ?: config('app.name', 'Bedrock'),
            'brandLogo' => config('bedrock.brand.logo'),
            'registration' => (bool) config('bedrock.auth.registration'),
            'status' => fn () => $request->session()->get('status'),
            '__bedrock' => function () use ($shared, $request): array {
                $data = is_callable($shared) ? $shared() : (array) $shared;
                $items = config('bedrock.admin.navigation', []);

                if (config('bedrock.admin.enabled')) {
                    array_unshift($items, ['label' => 'Dashboard', 'route' => 'bedrock.dashboard', 'permission' => config('bedrock.permissions.admin_permission')]);
                }
                $navigation = [];
                foreach ($items as $entry) {
                    if (! Route::has($entry['route']) || (isset($entry['permission']) && Gate::forUser($request->user('web'))->denies($entry['permission']))) {
                        continue;
                    }
                    $navigation[] = (new NavigationItem('@host', $entry['route'], 'link', $entry['label'], $entry['route'], route($entry['route'], [], false), active: $request->routeIs($entry['route'])))->toArray();
                }
                $data['navigation'] = [...$navigation, ...($data['navigation'] ?? [])];

                return $data;
            },
        ];
    }
}
