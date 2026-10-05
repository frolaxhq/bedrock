<?php

declare(strict_types=1);

it('resolves the configured module root', function () {
    expect(module_path())->toBe($this->fixturePath());
});

it('joins a path onto the module root', function () {
    expect(module_path('alpha'))->toBe($this->fixturePath('alpha'))
        ->and(module_path('alpha/resources/views'))->toBe($this->fixturePath('alpha').'/resources/views');
});

it('tolerates a leading slash on the joined path', function () {
    expect(module_path('/alpha'))->toBe($this->fixturePath('alpha'));
});

it('follows the module root when it is reconfigured', function () {
    config()->set('bedrock.modules.path', '/srv/modules');

    expect(module_path())->toBe('/srv/modules')
        ->and(module_path('billing'))->toBe('/srv/modules/billing');
});
