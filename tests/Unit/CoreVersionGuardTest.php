<?php

declare(strict_types=1);

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\CoreVersionNotSatisfiedException;
use Frolax\Bedrock\Services\Core\BedrockVersion;
use Frolax\Bedrock\Services\Module\CoreVersionGuard;
use Frolax\Bedrock\Services\Module\VersionConstraint;

it('does not require a Bedrock version when the manifest declares no core constraint', function () {
    $version = Mockery::mock(BedrockVersion::class);
    $version->shouldNotReceive('current');

    $guard = new CoreVersionGuard($version, app(VersionConstraint::class));
    $manifest = Manifest::from($this->manifestArray('alpha'));

    $guard->ensureSatisfied($manifest);

    expect($guard->reason($manifest))->toBeNull();
});

it('accepts a compatible Bedrock version', function () {
    $version = Mockery::mock(BedrockVersion::class);
    $version->shouldReceive('current')->twice()->andReturn('2.3.1');

    $guard = new CoreVersionGuard($version, app(VersionConstraint::class));
    $manifest = Manifest::from([
        ...$this->manifestArray('alpha'),
        'core' => '^2.0',
    ]);

    $guard->ensureSatisfied($manifest);

    expect($guard->reason($manifest))->toBeNull();
});

it('rejects a core requirement when the Bedrock version cannot be resolved', function () {
    $version = Mockery::mock(BedrockVersion::class);
    $version->shouldReceive('current')->once()->andReturnNull();

    $guard = new CoreVersionGuard($version, app(VersionConstraint::class));
    $manifest = Manifest::from([
        ...$this->manifestArray('alpha'),
        'core' => '^2.0',
    ]);

    $guard->ensureSatisfied($manifest);
})->throws(CoreVersionNotSatisfiedException::class, 'version could not be determined');
