<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Workbench Laravel Data Configuration
|--------------------------------------------------------------------------
|
| Testbench loads `workbench/config` as the application's config directory and
| does not fall back to its own skeleton, so a package config file has to be
| present here for a dependency that reads its config while a module manifest
| is hydrated. The defaults are taken from the package rather than copied, so
| they cannot drift.
|
| Structure caching is disabled: it is a production optimization that reaches
| for a cache store, and the workbench has no cache table migrated. Discovery
| and the module commands must not need one.
|
*/

$defaults = require dirname(__DIR__, 2).'/vendor/spatie/laravel-data/config/data.php';

return [
    ...$defaults,
    'structure_caching' => [
        ...$defaults['structure_caching'],
        'enabled' => false,
    ],
];
