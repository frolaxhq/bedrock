<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Frontend\InvalidFrontendArtifactException;
use Frolax\Bedrock\Providers\AdministrationServiceProvider;
use Frolax\Bedrock\Providers\FrontendServiceProvider;
use Frolax\Bedrock\Services\Module\ModuleBootstrapper;
use Frolax\Bedrock\Services\Module\ModuleFeatureHandlers;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

it('boots core without optional providers or permission vendor tables', function (): void {
    expect(app()->getLoadedProviders())->not->toHaveKey(FrontendServiceProvider::class)
        ->not->toHaveKey(AdministrationServiceProvider::class)
        ->and(Schema::hasTable('permissions'))->toBeFalse()
        ->and(app(ModuleManager::class))->toBeInstanceOf(ModuleManager::class)
        ->and(app(ModuleFeatureHandlers::class))->toBeInstanceOf(ModuleFeatureHandlers::class);
});

it('rejects a frontend module when its optional handler is absent', function (): void {
    $manifest = Manifest::from(['key' => 'portal', 'frontend' => ['driver' => 'inertia-react']]);
    expect(fn () => app(ModuleFeatureHandlers::class)->ensureSupported($manifest))
        ->toThrow(InvalidFrontendArtifactException::class, 'bedrock-frontend');
});

it('blocks cached module routes after a module is disabled', function (): void {
    $manifest = $this->loader()->load($this->fixturePath('alpha').'/module.json');
    $this->seedModule('alpha', ['manifest' => $manifest->toArray(), 'provider' => $manifest->provider, 'status' => ModuleStatus::Enabled]);
    app(ModuleBootstrapper::class)->bootstrap();
    $router = app('router');
    $router->setCompiledRoutes($router->getRoutes()->compile());
    $this->get('/alpha/ping')->assertOk();

    app(ModuleManager::class)->disable('alpha');
    expect(Route::has('alpha.ping'))->toBeTrue();
    $this->get('/alpha/ping')->assertNotFound();
});

it('maintains its permission catalog without Spatie Permission installed', function (): void {
    $this->seedModule('billing', ['manifest' => [
        'key' => 'billing', 'name' => 'Billing', 'version' => '1.0.0',
        'provider' => 'Some\\Provider', 'permissions' => ['view'],
    ]]);
    app(ModulePermissions::class)->sync('billing');

    $user = new GenericUser(['id' => 'core-user']);
    Gate::define('billing.view', static fn (): bool => true);
    expect(app(ModulePermissions::class)->activeNames()->all())->toBe(['billing.view'])
        ->and(app(ModulePermissions::class)->can($user, 'billing', 'view'))->toBeTrue();

    app(ModulePermissions::class)->deactivate('billing');
    expect(app(ModulePermissions::class)->can($user, 'billing', 'view'))->toBeFalse();
});

it('generates backend modules by default and refuses frontend scaffolds without the package', function (): void {
    $root = $this->tempDirectory('core-scaffold');
    config()->set('bedrock.modules.path', $root);

    try {
        expect(Artisan::call('module:make', ['key' => 'plain']))->toBe(0)
            ->and(File::exists($root.'/plain/package.json'))->toBeFalse()
            ->and(Artisan::call('module:make', ['key' => 'portal', '--frontend' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('bedrock-frontend')
            ->and(File::exists($root.'/portal'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});
