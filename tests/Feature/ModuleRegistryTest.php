<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

it('returns only enabled modules', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);
    $this->seedModule('beta', ['status' => ModuleStatus::Disabled]);

    $enabled = app(ModuleRegistry::class)->enabled();

    expect($enabled->pluck('key')->all())->toBe(['alpha']);
});

it('reports whether a module is enabled', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);
    $this->seedModule('beta', ['status' => ModuleStatus::Disabled]);

    $registry = app(ModuleRegistry::class);

    expect($registry->isEnabled('alpha'))->toBeTrue()
        ->and($registry->isEnabled('beta'))->toBeFalse()
        ->and($registry->isEnabled('missing'))->toBeFalse();
});

it('caches the enabled module set', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);

    app(ModuleRegistry::class)->enabled();

    $snapshot = Cache::store('array')->get('modules.registry');

    expect(Cache::store('array')->has('modules.registry'))->toBeTrue()
        ->and($snapshot)->toBeArray()
        ->and($snapshot[0])->toBeArray()
        ->and($snapshot[0]['key'])->toBe('alpha');
});

it('invalidates the cache when the registry is refreshed', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);

    $registry = app(ModuleRegistry::class);
    $registry->enabled();

    $registry->refresh();

    expect(Cache::store('array')->has('modules.registry'))->toBeFalse();
});

it('serves the cached snapshot without touching the repository again', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);

    $registry = app(ModuleRegistry::class);
    expect($registry->enabled())->toHaveCount(1);

    app(ModuleRepository::class)->upsert('beta', [
        'name' => 'Beta',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'path' => module_path('beta'),
        'status' => ModuleStatus::Enabled,
    ]);

    expect($registry->enabled())->toHaveCount(1);

    $registry->refresh();

    expect($registry->enabled())->toHaveCount(2);
});

it('degrades to an empty set when the modules table does not exist', function () {
    Schema::drop('modules');

    $registry = app(ModuleRegistry::class);

    expect($registry->enabled())->toBeEmpty()
        ->and($registry->all())->toBeEmpty()
        ->and($registry->isEnabled('alpha'))->toBeFalse()
        ->and($registry->find('alpha'))->toBeNull();
});

it('degrades when the cache store is unavailable', function () {
    $this->seedModule('alpha', ['status' => ModuleStatus::Enabled]);

    app('config')->set('bedrock.modules.cache.store', 'unavailable');
    $this->forgetBedrockBindings();

    expect(app(ModuleRegistry::class)->enabled())->toBeEmpty();
});
