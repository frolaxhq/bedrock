<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleDiscoverer;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;

it('discovers modules with a valid manifest', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    expect($result)->toBeInstanceOf(ModuleDiscoveryResult::class);

    $keys = array_map(static fn ($module): string => $module->key, $result->modules);

    expect($keys)->toBe(['alpha', 'beta', 'deps']);
});

it('ignores directories without a manifest', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    $paths = array_map(static fn ($module): string => $module->path, $result->modules);

    expect($paths)->not->toContain($this->fixturePath('manifestless'));
});

it('reports a module directory whose key does not match its name', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    expect($result->hasFailures())->toBeTrue()
        ->and($result->failures)->toHaveKey('mismatch')
        ->and($result->failures['mismatch'])->toContain('does not match its directory name');
});

it('reports a module with an invalid manifest', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    expect($result->failures)->toHaveKey('broken');
});

it('provides the manifest of every discovered module', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    $alpha = $result->find('alpha');

    expect($alpha)->not->toBeNull()
        ->and($alpha?->manifest->version)->toBe('1.2.0');
});

it('returns modules ordered by key', function () {
    $result = app(ModuleDiscoverer::class)->discover();

    $keys = array_map(static fn ($module): string => $module->key, $result->modules);
    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe($sorted);
});

it('returns an empty result when the module directory does not exist', function () {
    $this->useModulesDirectory($this->fixturePath('does-not-exist'));

    $result = app(ModuleDiscoverer::class)->discover();

    expect($result->modules)->toBe([])
        ->and($result->failures)->toBe([]);
});
