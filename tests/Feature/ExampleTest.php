<?php

declare(strict_types=1);

use Frolax\Bedrock\Bedrock;

it('resolves the singleton', function () {
    expect(app(Bedrock::class))->toBeInstanceOf(Bedrock::class);
});

it('returns the same instance from the container', function () {
    expect(app(Bedrock::class))->toBe(app(Bedrock::class));
});

it('merges the package config', function () {
    expect(config('bedrock.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('bedrock::messages.placeholder'))->toBe('Bedrock placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('bedrock::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('bedrock:placeholder')
        ->expectsOutputToContain('Bedrock placeholder command executed.')
        ->assertSuccessful();
});
