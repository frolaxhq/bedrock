<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\Contracts\Frontend\FrontendRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Frontend\InvalidFrontendArtifactException;
use Frolax\Bedrock\Facades\Frontend as FrontendFacade;
use Frolax\Bedrock\Frontend;
use Frolax\Bedrock\Navigation;
use Frolax\Bedrock\Providers\FrontendServiceProvider;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Frolax\Bedrock\Testing\Module\ModuleManifestFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Workbench\App\Models\User;

function frontendModuleSource(string $root, string $key = 'portal'): string
{
    $module = ModuleManifestFactory::for($key)->with([
        'permissions' => ['view'],
        'frontend' => [
            'driver' => 'inertia-react',
            'pages' => 'resources/js/pages',
            'artifact' => 'dist/bedrock/frontend.json',
            'requires' => [
                'react' => '^19.0',
                '@inertiajs/react' => '^3.0',
            ],
        ],
    ])->create($root);

    $artifactRoot = $module.'/dist/bedrock';
    File::ensureDirectoryExists($artifactRoot.'/client/assets');
    File::ensureDirectoryExists($artifactRoot.'/server');
    File::put($artifactRoot.'/client/mf-manifest.json', '{"name":"portal"}');
    File::put($artifactRoot.'/client/assets/module.css', '[data-bedrock-module]{display:block}');
    File::put($artifactRoot.'/server/entry.mjs', 'export const resolvePage = () => ({ default: () => null });');
    $files = [];

    foreach (['client/mf-manifest.json', 'client/assets/module.css', 'server/entry.mjs'] as $relative) {
        $files[$relative] = hash_file('sha256', $artifactRoot.'/'.$relative);
    }

    File::put($artifactRoot.'/frontend.json', json_encode([
        'schema' => 1,
        'module' => $key,
        'version' => '1.0.0',
        'client' => [
            'manifest' => 'client/mf-manifest.json',
            'css' => ['client/assets/module.css'],
        ],
        'server' => ['entry' => 'server/entry.mjs'],
        'navigation' => [
            'groups' => [
                ['key' => 'core', 'label' => 'Core', 'order' => 0],
            ],
            'items' => [[
                'key' => 'portal',
                'kind' => 'link',
                'label' => 'Portal',
                'route' => 'portal.index',
                'group' => 'core',
                'permission' => 'view',
                'panels' => [],
                'active' => ['portal.*'],
                'order' => 3,
                'children' => [],
            ]],
        ],
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $module;
}

it('composes and resolves the frontend capability as a singleton', function () {
    expect(app()->getLoadedProviders())->toHaveKey(FrontendServiceProvider::class)
        ->and(app(Frontend::class))->toBe(app(Frontend::class))
        ->and(FrontendFacade::getFacadeRoot())->toBe(app(Frontend::class))
        ->and(app(Frontend::class)->page('billing', 'Invoices/Index'))->toBe('module:billing/Invoices/Index');
});

it('shares a safe panel key for the authenticated viewer', function () {
    $shared = Inertia::getShared('__bedrock');

    expect(is_callable($shared) ? $shared()['viewer'] : null)->toBeNull();

    $admin = User::factory()->create(['type' => 'admin']);
    Route::get('/profile', static fn (): string => 'profile')->name('profile.show');
    Route::post('/logout', static fn (): string => 'logout')->name('logout');
    app('router')->getRoutes()->refreshNameLookups();
    request()->setUserResolver(static fn (): User => $admin);

    $viewer = is_callable($shared) ? $shared()['viewer'] : null;

    expect($viewer)->toMatchArray([
        'id' => $admin->getKey(),
        'name' => $admin->name,
        'email' => $admin->email,
        'avatar' => null,
        'panel' => 'admin',
        'permissions' => [],
        'actions' => ['profile' => '/profile', 'logout' => '/logout'],
    ])->and(array_keys($viewer))->toBe(['id', 'name', 'email', 'avatar', 'panel', 'permissions', 'actions']);

    config()->set('bedrock.frontend.panels.user_attribute', 'name');
    $support = User::factory()->create(['name' => 'support', 'type' => null]);
    request()->setUserResolver(static fn (): User => $support);

    expect(is_callable($shared) ? $shared()['viewer'] : null)->toMatchArray(['panel' => 'support']);

    config()->set('bedrock.frontend.panels.fallback', 'member');
    $invalid = User::factory()->create(['name' => 'Support Team', 'type' => null]);
    request()->setUserResolver(static fn (): User => $invalid);

    expect(is_callable($shared) ? $shared()['viewer'] : null)->toMatchArray(['panel' => 'member']);

    config()->set('bedrock.frontend.panels.fallback', '../unsafe');

    expect(is_callable($shared) ? $shared()['viewer'] : null)->toMatchArray(['panel' => 'default']);
});

it('rejects module navigation permissions that escape the module namespace', function () {
    $root = $this->tempDirectory('frontend-navigation-security');
    $source = frontendModuleSource($root, 'billing');
    $artifactPath = $source.'/dist/bedrock/frontend.json';
    $artifact = json_decode(File::get($artifactPath), true, 512, JSON_THROW_ON_ERROR);
    $artifact['navigation']['items'][0]['permission'] = 'bedrock.admin';
    File::put($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $manifest = app(ModuleManifest::class)->load($source.'/module.json');

    expect(fn () => app(FrontendAssetManager::class)->ensureValid($manifest, $source))
        ->toThrow(InvalidFrontendArtifactException::class, 'unsafe security metadata');

    File::deleteDirectory($root);
});

it('rejects unsafe frontend page identifiers', function (string $key, string $page) {
    app(Frontend::class)->page($key, $page);
})->with([
    ['Billing', 'Index'],
    ['billing', '../Index'],
    ['billing', 'Index.tsx'],
])->throws(InvalidArgumentException::class);

it('publishes, registers, shares, and removes versioned frontend artifacts', function () {
    $moduleRoot = $this->tempDirectory('frontend-modules');
    $sourceRoot = $this->tempDirectory('frontend-source');
    $clientRoot = $this->tempDirectory('frontend-client');
    $serverRoot = $this->tempDirectory('frontend-server');
    $source = frontendModuleSource($sourceRoot);
    config()->set('bedrock.frontend.client.path', $clientRoot);
    config()->set('bedrock.frontend.client.url', '/managed/modules');
    config()->set('bedrock.frontend.server.path', $serverRoot);
    $this->useModulesDirectory($moduleRoot);
    Route::get('/portal', static fn (): string => 'portal')->name('portal.index');
    app('router')->getRoutes()->refreshNameLookups();

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $source), 'portal');
    app(ModuleManager::class)->enable('portal');

    $frontend = app(Frontend::class);
    app(ModulePermissions::class)->sync('portal');
    $user = User::factory()->create();
    $user->givePermissionTo('portal.view');
    $descriptor = $frontend->find('portal');
    $shared = Inertia::getShared('__bedrock');
    $shared = is_callable($shared) ? $shared() : $shared;

    expect($descriptor)->not->toBeNull()
        ->and($frontend->isAvailable('portal'))->toBeTrue()
        ->and($frontend->all())->toHaveCount(1)
        ->and($descriptor?->client)->toBe('/managed/modules/portal/1.0.0/mf-manifest.json')
        ->and($descriptor?->server)->toBe($serverRoot.'/portal/1.0.0/entry.mjs')
        ->and($descriptor?->css)->toBe(['/managed/modules/portal/1.0.0/assets/module.css'])
        ->and($descriptor?->navigation['items'][0]['key'])->toBe('portal')
        ->and($shared['schema'])->toBe(1)
        ->and(Route::has('portal.index'))->toBeTrue()
        ->and($shared['modules']['portal']['requires']['react'])->toBe('^19.0')
        ->and($user->can('portal.view'))->toBeTrue()
        ->and(app(Navigation::class)->all())->toBeEmpty()
        ->and(app(Navigation::class)->all($user)->pluck('route')->all())->toBe(['portal.index'])
        ->and(app(Navigation::class)->all($user)->pluck('href')->all())->toBe(['/portal'])
        ->and(File::exists($clientRoot.'/portal/1.0.0/mf-manifest.json'))->toBeTrue()
        ->and(File::exists($clientRoot.'/portal/1.0.0/frontend.json'))->toBeFalse()
        ->and(File::exists($serverRoot.'/portal/1.0.0/frontend.json'))->toBeTrue()
        ->and(File::exists($serverRoot.'/portal/1.0.0/entry.mjs'))->toBeTrue();

    app(ModuleManager::class)->disable('portal');

    expect(app(FrontendRegistry::class)->isAvailable('portal'))->toBeFalse();

    app(ModuleManager::class)->uninstall('portal');

    expect(File::exists($clientRoot.'/portal/1.0.0'))->toBeFalse()
        ->and(File::exists($serverRoot.'/portal/1.0.0'))->toBeFalse();

    File::deleteDirectory($moduleRoot);
    File::deleteDirectory($sourceRoot);
    File::deleteDirectory($clientRoot);
    File::deleteDirectory($serverRoot);
});

it('marks frontend integrity drift unhealthy during reconciliation', function () {
    $moduleRoot = $this->tempDirectory('frontend-drift-modules');
    $sourceRoot = $this->tempDirectory('frontend-drift-source');
    $clientRoot = $this->tempDirectory('frontend-drift-client');
    $serverRoot = $this->tempDirectory('frontend-drift-server');
    $source = frontendModuleSource($sourceRoot, 'dashboard');
    config()->set('bedrock.frontend.client.path', $clientRoot);
    config()->set('bedrock.frontend.server.path', $serverRoot);
    $this->useModulesDirectory($moduleRoot);
    $manager = app(ModuleManager::class);
    $manager->installFrom(new LocalDirectorySource(app('files'), $source), 'dashboard');
    $manager->enable('dashboard');
    File::put($clientRoot.'/dashboard/1.0.0/mf-manifest.json', 'tampered');

    $manager->sync();
    $registered = app(ModuleRepository::class)->find('dashboard');

    expect($registered?->status)->toBe(ModuleStatus::Enabled)
        ->and($registered?->hasFailed())->toBeTrue()
        ->and($registered?->failure_code?->value)->toBe('frontend.artifact_invalid')
        ->and(app(Frontend::class)->isAvailable('dashboard'))->toBeFalse();

    File::deleteDirectory($moduleRoot);
    File::deleteDirectory($sourceRoot);
    File::deleteDirectory($clientRoot);
    File::deleteDirectory($serverRoot);
});
