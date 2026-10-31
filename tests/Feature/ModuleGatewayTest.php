<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRegistry;
use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\Facades\Module as ModuleFacade;
use Frolax\Bedrock\Models\Module as ModuleModel;
use Frolax\Bedrock\Module;
use Illuminate\Support\Collection;

it('delegates registry queries through the module gateway', function () {
    $model = new ModuleModel;
    $model->forceFill(['key' => 'alpha']);
    $all = new Collection([$model]);
    $enabled = new Collection([$model]);

    $registry = Mockery::mock(ModuleRegistry::class);
    $registry->shouldReceive('all')->once()->andReturn($all);
    $registry->shouldReceive('enabled')->once()->andReturn($enabled);
    $registry->shouldReceive('find')->once()->with('alpha')->andReturn($model);
    $registry->shouldReceive('isEnabled')->once()->with('alpha')->andReturnTrue();
    $registry->shouldReceive('refresh')->once();

    $gateway = new Module(app(), $registry, Mockery::mock(ModuleManager::class), Mockery::mock(ModuleDiagnostics::class));

    expect($gateway->all())->toBe($all)
        ->and($gateway->enabled())->toBe($enabled)
        ->and($gateway->find('alpha'))->toBe($model)
        ->and($gateway->isEnabled('alpha'))->toBeTrue();

    $gateway->refresh();
});

it('delegates lifecycle operations through the module gateway', function () {
    $source = Mockery::mock(ModuleSource::class);
    $result = new ModuleDiscoveryResult;

    $manager = Mockery::mock(ModuleManager::class);
    $manager->shouldReceive('sync')->once()->andReturn($result);
    $manager->shouldReceive('install')->once()->with('alpha')->andReturnTrue();
    $manager->shouldReceive('upgrade')->once()->with('alpha')->andReturnTrue();
    $manager->shouldReceive('installFrom')->once()->with($source, 'alpha')->andReturnTrue();
    $manager->shouldReceive('uninstall')->once()->with('alpha')->andReturnTrue();
    $manager->shouldReceive('enable')->once()->with('alpha')->andReturnTrue();
    $manager->shouldReceive('disable')->once()->with('alpha')->andReturnTrue();

    $gateway = new Module(app(), Mockery::mock(ModuleRegistry::class), $manager, Mockery::mock(ModuleDiagnostics::class));

    expect($gateway->sync())->toBe($result)
        ->and($gateway->install('alpha'))->toBeTrue()
        ->and($gateway->upgrade('alpha'))->toBeTrue()
        ->and($gateway->installFrom($source, 'alpha'))->toBeTrue()
        ->and($gateway->uninstall('alpha'))->toBeTrue()
        ->and($gateway->enable('alpha'))->toBeTrue()
        ->and($gateway->disable('alpha'))->toBeTrue();
});

it('exposes the configured path through the gateway', function () {
    expect(app(Module::class)->path())->toBe($this->fixturePath());
});

it('resolves the module facade to the gateway singleton', function () {
    expect(ModuleFacade::getFacadeRoot())->toBe(app(Module::class))
        ->and(ModuleFacade::path())->toBe($this->fixturePath());
});

it('does not retain the old module-specific Bedrock proxy', function () {
    expect(class_exists('Frolax\\Bedrock\\Bedrock'))->toBeFalse()
        ->and(class_exists('Frolax\\Bedrock\\Facades\\Bedrock'))->toBeFalse();
});
