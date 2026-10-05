<?php

declare(strict_types=1);

use Frolax\Bedrock\Providers\CoreServiceProvider;
use Frolax\Bedrock\Providers\ModulesServiceProvider;

it('provides the config a module manifest needs from the workbench directory', function () {
    $path = dirname(__DIR__, 2).'/workbench/config/data.php';

    expect(is_file($path))->toBeTrue();

    /** @var array<string, mixed> $config */
    $config = require $path;

    expect($config)->toHaveKey('validation_strategy')
        ->and($config)->toHaveKey('transformers')
        ->and($config['structure_caching']['enabled'])->toBeFalse();
});

it('loads the workbench config files Testbench would otherwise supply', function () {
    $config = dirname(__DIR__, 2).'/workbench/config';

    expect(is_file($config.'/app.php'))->toBeTrue()
        ->and(is_file($config.'/bedrock.php'))->toBeTrue()
        ->and(is_file($config.'/data.php'))->toBeTrue()
        ->and(is_file($config.'/view.php'))->toBeTrue();
});

it('keeps the package config readable after a second configuration load', function () {
    $repository = app('config');

    $repository->set('bedrock', ['modules' => ['path' => 'modules']]);

    app()->getProvider(CoreServiceProvider::class)->boot();

    expect($repository->get('bedrock.modules.manifest'))->toBe('module.json')
        ->and($repository->get('bedrock.modules.cache.key'))->toBe('modules.registry')
        ->and($repository->get('bedrock.modules.path'))->toBe('modules');
});

it('registers the module provider alongside the core provider', function () {
    expect(app()->getProvider(CoreServiceProvider::class))->not->toBeNull()
        ->and(app()->getProvider(ModulesServiceProvider::class))->not->toBeNull();
});
