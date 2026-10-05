<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleDisabled;
use Frolax\Bedrock\Events\Module\ModuleDiscovered;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Frolax\Bedrock\Events\Module\ModuleFailed;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Events\Module\ModuleRecovered;
use Frolax\Bedrock\Events\Module\ModuleUninstalled;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Exceptions\Module\CoreVersionNotSatisfiedException;
use Frolax\Bedrock\Exceptions\Module\DependencyNotSatisfiedException;
use Frolax\Bedrock\Exceptions\Module\InvalidLifecycleTransitionException;
use Frolax\Bedrock\Exceptions\Module\ModuleNotFoundException;
use Frolax\Bedrock\Services\Module\ModuleHealthManager;
use Frolax\Bedrock\Services\Module\StateReconciler;
use Frolax\Bedrock\Tests\Fixtures\Modules\Alpha\AlphaServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('registers modules discovered on disk as discovered', function () {
    Event::fake([ModuleDiscovered::class]);

    $report = app(ModuleManager::class)->syncReport();

    expect($report->registered)->toBe(['alpha', 'beta', 'deps'])
        ->and(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Discovered)
        ->and(app(ModuleRepository::class)->find('alpha')?->discovered_at)->not->toBeNull()
        ->and(app(ModuleRepository::class)->find('alpha')?->installed_at)->toBeNull()
        ->and(app(ModuleRepository::class)->find('alpha')?->version)->toBe('1.2.0');

    Event::assertDispatched(ModuleDiscovered::class, fn (ModuleDiscovered $event): bool => $event->key === 'alpha');
});

it('reports discovery failures while registering the valid modules', function () {
    $report = app(ModuleManager::class)->syncReport();

    expect($report->hasIssues())->toBeTrue()
        ->and($report->failures)->toHaveKeys(['broken', 'mismatch']);
});

it('does not register a module twice', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->all())->toHaveCount(3);
});

it('reports a version drift instead of silently rewriting the registry', function () {
    app(ModuleManager::class)->sync();

    app(ModuleRepository::class)->upsert('alpha', ['version' => '0.9.0']);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('alpha')?->version)->toBe('0.9.0')
        ->and(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed);
});

it('enables and disables a module', function () {
    Event::fake([ModuleEnabled::class, ModuleDisabled::class]);

    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');

    app(ModuleManager::class)->enable('alpha');
    $enabled = app(ModuleRepository::class)->find('alpha');

    expect($enabled?->status)->toBe(ModuleStatus::Enabled)
        ->and($enabled?->enabled_at)->not->toBeNull();

    app(ModuleManager::class)->disable('alpha');
    $disabled = app(ModuleRepository::class)->find('alpha');

    expect($disabled?->status)->toBe(ModuleStatus::Disabled)
        ->and($disabled?->disabled_at)->not->toBeNull();

    Event::assertDispatched(ModuleEnabled::class);
    Event::assertDispatched(ModuleDisabled::class);
});

it('enables a dependency before a module that requires it', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('beta');

    app(ModuleManager::class)->enable('alpha');
    app(ModuleManager::class)->enable('beta');

    expect(app(ModuleRepository::class)->find('beta')?->status)->toBe(ModuleStatus::Enabled);
});

it('installs locally available dependencies before the requested module', function () {
    Event::fake([ModuleInstalled::class]);

    app(ModuleManager::class)->install('beta');

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Installed)
        ->and(app(ModuleRepository::class)->find('beta')?->status)->toBe(ModuleStatus::Installed)
        ->and(Event::dispatched(ModuleInstalled::class)
            ->map(static fn (array $events): string => $events[0]->key)
            ->all())->toBe(['alpha', 'beta']);
});

it('rejects a circular dependency before installing the local chain', function () {
    $root = $this->tempDirectory('modules');

    $this->moduleSource($root, 'alpha');
    $this->moduleSource($root, 'beta');
    $this->moduleMigration($root.'/alpha', 'alpha_cycle_items');

    foreach (['alpha' => 'beta', 'beta' => 'alpha'] as $module => $dependency) {
        $path = $root.'/'.$module.'/module.json';
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['requires'] = [$dependency => '^1.0'];

        if ($module === 'alpha') {
            $manifest['database'] = ['migrations' => true, 'seeders' => false];
        }

        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->install('alpha');
    } finally {
        expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed)
            ->and(app(ModuleRepository::class)->find('beta'))->toBeNull()
            ->and(Schema::hasTable('alpha_cycle_items'))->toBeFalse();

        File::deleteDirectory($root);
    }
})->throws(CircularDependencyException::class, 'alpha -> beta -> alpha');

it('marks every member of a circular dependency failed during synchronization', function () {
    $root = $this->tempDirectory('modules');

    foreach (['alpha' => 'beta', 'beta' => 'alpha'] as $module => $dependency) {
        $path = $this->moduleSource($root, $module).'/module.json';
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['provider'] = AlphaServiceProvider::class;
        $manifest['requires'] = [$dependency => '^1.0'];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    $this->useModulesDirectory($root);

    $discovery = app(ModuleManager::class)->sync();
    $reasons = app(StateReconciler::class)->reconcile($discovery);

    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('beta')?->health)->toBe(ModuleHealth::Failed)
        ->and($reasons['alpha'])->toContain('alpha -> beta -> alpha')
        ->and($reasons['beta'])->toContain('alpha -> beta -> alpha');

    File::deleteDirectory($root);
});

it('refuses to enable a module when a dependency is not enabled', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('beta');

    app(ModuleManager::class)->enable('beta');
})->throws(DependencyNotSatisfiedException::class);

it('refuses to enable a module when a dependency is not registered', function () {
    app(ModuleManager::class)->sync();
    app(ModuleRepository::class)->upsert('deps', [
        'status' => ModuleStatus::Installed,
        'health' => ModuleHealth::Healthy,
        'failure_code' => null,
        'failure_reason' => null,
        'failed_at' => null,
        'installed_at' => now(),
    ]);

    app(ModuleManager::class)->enable('deps');
})->throws(DependencyNotSatisfiedException::class, 'requires [ghost]');

it('refuses to enable a module with an incompatible dependency version', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('beta');
    app(ModuleManager::class)->enable('alpha');
    app(ModuleRepository::class)->upsert('alpha', ['version' => '0.9.0']);

    app(ModuleManager::class)->enable('beta');
})->throws(DependencyNotSatisfiedException::class, 'installed version [0.9.0] does not satisfy [>=1.0.0]');

it('marks and recovers a module whose dependency version is incompatible', function () {
    app(ModuleManager::class)->sync();
    app(ModuleRepository::class)->upsert('alpha', ['version' => '0.9.0']);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('beta')?->health)->toBe(ModuleHealth::Failed);

    app(ModuleRepository::class)->upsert('alpha', ['version' => '1.2.0']);
    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('beta')?->health)->toBe(ModuleHealth::Healthy);
});

it('marks and recovers a module whose Bedrock core version is incompatible', function () {
    $path = $this->tempDirectory('core-version');

    $this->moduleSource($path, 'core-test');
    $this->moduleManifest($path.'/core-test', 'core-test', []);

    $manifest = json_decode(file_get_contents($path.'/core-test/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['provider'] = 'Frolax\\Bedrock\\Tests\\Fixtures\\Modules\\Alpha\\AlphaServiceProvider';
    $manifest['core'] = '^2.0';
    file_put_contents($path.'/core-test/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->useModulesDirectory($path);
    config()->set('bedrock.core.version', '1.5.0');

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('core-test')?->health)->toBe(ModuleHealth::Failed);

    config()->set('bedrock.core.version', '2.1.0');
    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('core-test')?->health)->toBe(ModuleHealth::Healthy);

    File::deleteDirectory($path);
});

it('refuses to enable a module whose Bedrock core version is incompatible', function () {
    $this->seedModule('core-test', [
        'manifest' => [
            ...$this->manifestArray('core-test'),
            'core' => '^2.0',
        ],
    ]);
    config()->set('bedrock.core.version', '1.5.0');

    app(ModuleManager::class)->enable('core-test');
})->throws(CoreVersionNotSatisfiedException::class, 'Bedrock [1.5.0] does not satisfy [^2.0]');

it('registers a module directory with the install command flow', function () {
    Event::fake([ModuleInstalled::class]);

    app(ModuleManager::class)->install('alpha');

    expect(app(ModuleRepository::class)->find('alpha'))->not->toBeNull();

    Event::assertDispatched(ModuleInstalled::class);
});

it('refuses to install a module that does not exist on disk', function () {
    app(ModuleManager::class)->install('missing');
})->throws(ModuleNotFoundException::class);

it('refuses to uninstall a module that has registered dependents', function () {
    Event::fake([ModuleUninstalled::class]);

    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->uninstall('alpha');
})->throws(InvalidLifecycleTransitionException::class, 'required by');

it('uninstalls a module and purges its directory', function () {
    $path = sys_get_temp_dir().'/bedrock-purge-'.uniqid();
    mkdir($path.'/purge-me', 0755, true);
    file_put_contents($path.'/purge-me/module.json', json_encode([
        'key' => 'purge-me',
        'name' => 'Purge Me',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]));

    $this->useModulesDirectory($path);

    app(ModuleManager::class)->sync();
    expect(app(ModuleRepository::class)->find('purge-me'))->not->toBeNull();

    app(ModuleManager::class)->uninstall('purge-me');

    expect(app(ModuleRepository::class)->find('purge-me'))->toBeNull()
        ->and(is_dir($path.'/purge-me'))->toBeFalse();

    @rmdir($path);
});

it('marks unreachable modules as failed during a sync', function () {
    $this->seedModule('ghost', [
        'path' => $this->fixturePath('ghost'),
        'status' => ModuleStatus::Installed,
    ]);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('ghost')?->health)->toBe(ModuleHealth::Failed);
});

it('marks a module failed when its version drifts from the manifest', function () {
    app(ModuleManager::class)->sync();
    app(ModuleRepository::class)->upsert('alpha', ['version' => '9.9.9']);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed)
        ->and(app(ModuleRepository::class)->find('alpha')?->failed_at)->not->toBeNull();
});

it('refreshes a stale provider snapshot from the manifest', function () {
    app(ModuleManager::class)->sync();
    app(ModuleRepository::class)->upsert('alpha', [
        'name' => 'Stale name',
        'provider' => 'Missing\\Provider',
        'path' => '/missing/stale/path',
        'dependencies' => ['stale' => '*'],
    ]);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Healthy)
        ->and(app(ModuleRepository::class)->find('alpha')?->name)->toBe('Alpha Module')
        ->and(app(ModuleRepository::class)->find('alpha')?->provider)->toBe(AlphaServiceProvider::class)
        ->and(app(ModuleRepository::class)->find('alpha')?->path)->toBe($this->fixturePath('alpha'))
        ->and(app(ModuleRepository::class)->find('alpha')?->dependencies)->toBe([]);
});

it('marks a module failed when a required module is missing', function () {
    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('deps')?->health)->toBe(ModuleHealth::Failed);
});

it('recovers a failed module that is healthy again', function () {
    Event::fake([ModuleFailed::class, ModuleRecovered::class]);
    app(ModuleManager::class)->sync();
    $module = app(ModuleRepository::class)->findOrFail('alpha');
    app(ModuleHealthManager::class)->fail($module, ModuleFailureCode::ProviderMissing, 'Temporary failure.');
    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed);

    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Discovered)
        ->and(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Healthy)
        ->and(app(ModuleRepository::class)->find('alpha')?->failed_at)->toBeNull();

    Event::assertDispatched(ModuleFailed::class, fn (ModuleFailed $event): bool => $event->code === ModuleFailureCode::ProviderMissing);
    Event::assertDispatched(ModuleRecovered::class, fn (ModuleRecovered $event): bool => $event->key === 'alpha');
});

it('restores the enabled status of a module that recovered', function () {
    app(ModuleManager::class)->sync();
    app(ModuleManager::class)->install('alpha');
    app(ModuleManager::class)->enable('alpha');

    $module = app(ModuleRepository::class)->findOrFail('alpha');
    app(ModuleHealthManager::class)->fail($module, ModuleFailureCode::BootstrapFailed, 'Temporary failure.');
    expect(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Failed);
    app(ModuleManager::class)->sync();

    expect(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Enabled)
        ->and(app(ModuleRepository::class)->find('alpha')?->health)->toBe(ModuleHealth::Healthy);
});

it('refreshes the registry cache on every transition', function () {
    app(ModuleManager::class)->sync();

    expect(app(ModuleManager::class)->sync())->not->toBeNull()
        ->and(Schema::hasTable('modules'))->toBeTrue();
});
