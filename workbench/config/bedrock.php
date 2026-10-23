<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Workbench Bedrock Configuration
|--------------------------------------------------------------------------
|
| The workbench application scans its own module directory so a module can be
| installed, enabled and served with `composer serve` during development.
|
*/

return [
    'administration' => [
        'enabled' => true,
    ],
    'modules' => [
        'path' => dirname(__DIR__).'/modules',
    ],
];
