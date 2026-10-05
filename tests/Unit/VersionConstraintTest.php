<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Frolax\Bedrock\Services\Core\BedrockVersion;
use Frolax\Bedrock\Services\Module\VersionConstraint;

it('accepts composer version constraint syntax', function (string $constraint) {
    expect(app(VersionConstraint::class)->isValid($constraint))->toBeTrue();
})->with([
    'caret' => '^1.2',
    'tilde' => '~1.2.3',
    'comparison range' => '>=1.0 <2.0',
    'wildcard' => '1.2.*',
    'compound alternative' => '^1.5 || ^2.0',
]);

it('rejects malformed composer constraints', function () {
    expect(app(VersionConstraint::class)->isValid('definitely not a constraint !'))->toBeFalse();
});

it('matches versions using composer semantics', function () {
    $constraints = app(VersionConstraint::class);

    expect($constraints->matches('1.8.0', '^1.5'))->toBeTrue()
        ->and($constraints->matches('2.0.0', '^1.5'))->toBeFalse()
        ->and($constraints->matches('2.1.0-beta.1', '^2.0@beta'))->toBeTrue();
});

it('uses the configured Bedrock version override', function () {
    config()->set('bedrock.core.version', '2.4.1');

    expect(app(BedrockVersion::class)->current())->toBe('2.4.1');
});

it('falls back to the Composer installed version', function () {
    config()->set('bedrock.core.version');

    expect(app(BedrockVersion::class)->current())
        ->toBe(InstalledVersions::getPrettyVersion('frolaxhq/bedrock'));
});
