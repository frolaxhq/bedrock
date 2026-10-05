<?php

declare(strict_types=1);

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;
use Frolax\Bedrock\Services\Module\DependencyResolver;

function dependencyManifest(string $key, array $requires = []): Manifest
{
    return Manifest::from([
        'key' => $key,
        'name' => ucfirst($key),
        'version' => '1.0.0',
        'provider' => 'Modules\\'.ucfirst($key).'\\'.ucfirst($key).'ServiceProvider',
        'requires' => $requires,
    ]);
}

it('orders a dependency chain before the requested module', function () {
    $order = (new DependencyResolver)->order([
        dependencyManifest('checkout', ['payments' => '^1.0']),
        dependencyManifest('payments', ['accounts' => '^1.0']),
        dependencyManifest('accounts'),
    ], 'checkout');

    expect($order)->toBe(['accounts', 'payments', 'checkout']);
});

it('orders a shared dependency once', function () {
    $order = (new DependencyResolver)->order([
        dependencyManifest('shop', ['catalog' => '^1.0', 'checkout' => '^1.0']),
        dependencyManifest('catalog', ['foundation' => '^1.0']),
        dependencyManifest('checkout', ['foundation' => '^1.0']),
        dependencyManifest('foundation'),
    ], 'shop');

    expect($order)->toBe(['foundation', 'catalog', 'checkout', 'shop'])
        ->and(array_count_values($order)['foundation'])->toBe(1);
});

it('ignores dependencies that are not locally available', function () {
    $order = (new DependencyResolver)->order([
        dependencyManifest('checkout', ['marketplace-only' => '^1.0']),
    ], 'checkout');

    expect($order)->toBe(['checkout']);
});

it('only resolves the requested dependency closure', function () {
    $order = (new DependencyResolver)->order([
        dependencyManifest('checkout', ['payments' => '^1.0']),
        dependencyManifest('payments'),
        dependencyManifest('unrelated', ['missing' => '^1.0']),
    ], 'checkout');

    expect($order)->toBe(['payments', 'checkout']);
});

it('detects a circular dependency and reports its complete path', function () {
    (new DependencyResolver)->order([
        dependencyManifest('alpha', ['beta' => '^1.0']),
        dependencyManifest('beta', ['gamma' => '^1.0']),
        dependencyManifest('gamma', ['alpha' => '^1.0']),
    ], 'alpha');
})->throws(CircularDependencyException::class, 'alpha -> beta -> gamma -> alpha');

it('detects a module that depends on itself', function () {
    (new DependencyResolver)->order([
        dependencyManifest('alpha', ['alpha' => '^1.0']),
    ], 'alpha');
})->throws(CircularDependencyException::class, 'alpha -> alpha');
