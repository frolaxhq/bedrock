<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleBootFailed;
use Frolax\Bedrock\Services\Module\ModuleBootstrapper;
use Frolax\Bedrock\Tests\Fixtures\Modules\Alpha\AlphaServiceProvider;
use Frolax\Bedrock\Tests\Fixtures\Modules\Ordering\BootstrapOrder;
use Frolax\Bedrock\Tests\Fixtures\Modules\Ordering\DependencyServiceProvider;
use Frolax\Bedrock\Tests\Fixtures\Modules\Ordering\FeatureServiceProvider;
use Illuminate\Support\Facades\Event;

it('boots the provider of an enabled module', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->app->register(AlphaServiceProvider::class);

    $this->get('/alpha/ping')->assertOk()->assertSee('alpha');
});

it('makes the module routes available after booting', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->app->register(AlphaServiceProvider::class);

    $this->get('/alpha/ping')->assertOk()->assertSee('alpha');
});

it('loads module views under the module key namespace', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->app->register(AlphaServiceProvider::class);

    expect(view()->exists('alpha::index'))->toBeTrue();
    expect(view('alpha::index')->render())->toContain('alpha-view');
});

it('loads module translations under the module key namespace', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->app->register(AlphaServiceProvider::class);

    expect(trans('alpha::messages.greeting'))->toBe('Hello from alpha.');
});

it('merges the module configuration', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $this->app->register(AlphaServiceProvider::class);

    expect(config('alpha.color'))->toBe('teal');
});

it('does not boot the provider of a disabled module', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');
    app(ModuleManager::class)->disable('alpha');

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Disabled);

    $this->get('/alpha/ping')->assertNotFound();
});

it('marks a module failed when its provider cannot be registered', function () {
    Event::fake([ModuleBootFailed::class]);

    $this->seedModule('broken-provider', [
        'provider' => 'Frolax\\Bedrock\\Tests\\Fixtures\\Modules\\Broken\\Missing',
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
    ]);

    app(ModuleBootstrapper::class)->bootstrap();

    expect(app(ModuleRepository::class)->find('broken-provider')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('broken-provider')?->status)->toBe(ModuleStatus::Enabled);

    Event::assertDispatched(ModuleBootFailed::class, fn (ModuleBootFailed $event): bool => $event->key === 'broken-provider');
});

it('failure from one module does not surface in the error details', function () {
    Event::fake([ModuleBootFailed::class]);

    $this->seedModule('broken-provider', [
        'provider' => 'Frolax\\Bedrock\\Tests\\Fixtures\\Modules\\Broken\\Missing',
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
    ]);
    $this->seedModule('alpha', [
        'provider' => AlphaServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
    ]);

    app(ModuleBootstrapper::class)->bootstrap();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Enabled);

    $this->get('/alpha/ping')->assertOk();
});

it('boots enabled modules in dependency order', function () {
    BootstrapOrder::$modules = [];

    $this->seedModule('aaa-feature', [
        'provider' => FeatureServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
        'manifest' => [
            'key' => 'aaa-feature',
            'name' => 'Feature',
            'version' => '1.0.0',
            'provider' => FeatureServiceProvider::class,
            'requires' => ['zzz-dependency' => '^1.0'],
        ],
    ]);
    $this->seedModule('zzz-dependency', [
        'provider' => DependencyServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
        'manifest' => [
            'key' => 'zzz-dependency',
            'name' => 'Dependency',
            'version' => '1.0.0',
            'provider' => DependencyServiceProvider::class,
        ],
    ]);

    app(ModuleBootstrapper::class)->bootstrap();

    expect(BootstrapOrder::$modules)->toBe(['dependency', 'feature']);
});

it('fails cyclic modules while still booting unrelated enabled modules', function () {
    Event::fake([ModuleBootFailed::class]);
    BootstrapOrder::$modules = [];

    $this->seedModule('alpha-cycle', [
        'provider' => FeatureServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
        'manifest' => [
            'key' => 'alpha-cycle',
            'name' => 'Alpha cycle',
            'version' => '1.0.0',
            'provider' => FeatureServiceProvider::class,
            'requires' => ['beta-cycle' => '^1.0'],
        ],
    ]);
    $this->seedModule('beta-cycle', [
        'provider' => DependencyServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
        'manifest' => [
            'key' => 'beta-cycle',
            'name' => 'Beta cycle',
            'version' => '1.0.0',
            'provider' => DependencyServiceProvider::class,
            'requires' => ['alpha-cycle' => '^1.0'],
        ],
    ]);
    $this->seedModule('independent', [
        'provider' => DependencyServiceProvider::class,
        'path' => $this->fixturePath('alpha'),
        'status' => ModuleStatus::Enabled,
        'manifest' => [
            'key' => 'independent',
            'name' => 'Independent',
            'version' => '1.0.0',
            'provider' => DependencyServiceProvider::class,
        ],
    ]);

    app(ModuleBootstrapper::class)->bootstrap();

    expect(app(ModuleRepository::class)->find('alpha-cycle')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('beta-cycle')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('independent')?->status)->toBe(ModuleStatus::Enabled)
        ->and(BootstrapOrder::$modules)->toBe(['dependency']);

    Event::assertNotDispatched(ModuleBootFailed::class);
});
