<?php

declare(strict_types=1);

use Frolax\Bedrock\DataObjects\Module\DiscoveredModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\DataObjects\Module\SyncReport;
use Frolax\Bedrock\Support\Module\ModuleManifest;
use Frolax\Bedrock\Testing\Module\ModuleManifestFactory;

it('builds a manifest from an array', function () {
    $manifest = Manifest::from([
        'key' => 'alpha',
        'name' => 'Alpha',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'requires' => ['beta' => '^1.0'],
    ]);

    expect($manifest->key)->toBe('alpha')
        ->and($manifest->schema)->toBe(Manifest::SCHEMA_VERSION)
        ->and($manifest->requires)->toBe(['beta' => '^1.0'])
        ->and($manifest->description)->toBeNull();
});

it('serializes a manifest to an array and json', function () {
    $manifest = Manifest::from([
        'key' => 'alpha',
        'name' => 'Alpha',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]);

    expect($manifest->toArray())->toHaveKeys(['key', 'name', 'version', 'provider', 'schema'])
        ->and(json_decode(json_encode($manifest), true))->toBe($manifest->toArray());
});

it('finds a discovered module by key', function () {
    $manifest = Manifest::from([
        'key' => 'alpha',
        'name' => 'Alpha',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]);

    $result = new ModuleDiscoveryResult([
        new DiscoveredModule('alpha', '/tmp/alpha', $manifest),
    ]);

    expect($result->find('alpha'))->toBeInstanceOf(DiscoveredModule::class)
        ->and($result->find('beta'))->toBeNull()
        ->and($result->hasFailures())->toBeFalse();
});

it('reports discovery failures', function () {
    $result = new ModuleDiscoveryResult([], ['broken' => 'Invalid manifest.']);

    expect($result->hasFailures())->toBeTrue()
        ->and($result->failures)->toBe(['broken' => 'Invalid manifest.']);
});

it('reports whether a sync has issues', function () {
    expect((new SyncReport([], ['alpha'], [], []))->hasIssues())->toBeFalse()
        ->and((new SyncReport([], [], ['broken' => 'x'], []))->hasIssues())->toBeTrue()
        ->and((new SyncReport([], [], [], ['alpha' => 'y']))->hasIssues())->toBeTrue();
});

it('creates a module directory from the manifest factory', function () {
    $base = sys_get_temp_dir().'/bedrock-factory-'.uniqid();

    $path = ModuleManifestFactory::for('demo')
        ->with(['description' => 'A demo module.'])
        ->create($base);

    $manifest = app(ModuleManifest::class)->load($path.'/module.json');

    expect($manifest->key)->toBe('demo')
        ->and($manifest->description)->toBe('A demo module.')
        ->and($manifest->version)->toBe('1.0.0');

    @unlink($path.'/module.json');
    @rmdir($path);
    @rmdir($base);
});
