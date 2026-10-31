<?php

declare(strict_types=1);

return [
    'auth' => ['enabled' => false, 'registration' => false],
    'admin' => ['enabled' => false, 'path' => 'admin', 'navigation' => []],
    'brand' => ['name' => null, 'logo' => null],

    /*
    |--------------------------------------------------------------------------
    | Core
    |--------------------------------------------------------------------------
    |
    | A null version uses the package version reported by Composer. Set an
    | explicit semantic version when testing modules against a dev checkout.
    |
    */

    'core' => [
        'version' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Each Bedrock capability owns a section of this file. The module section
    | controls discovery, runtime booting, installation and registry caching.
    |
    */

    'modules' => [
        'boot' => ['compiled' => false],
        'path' => 'modules',
        'table' => 'modules',
        'manifest' => 'module.json',

        'bootstrap' => [
            'enabled' => true,
        ],

        'install' => [
            'max_archive_bytes' => 268435456,
            'max_entries' => 10000,
            'max_path_length' => 240,
        ],

        'php' => [
            'composer' => ['composer'],
            'timeout' => 300,
            'offline' => false,
            'toolchain_path' => 'storage/framework/bedrock/module-tools',
            'repositories' => [],
            'validation' => [
                'cache' => false,
                'release' => null,
                'store' => null,
                'ttl' => 300,
            ],
        ],

        'operations' => [
            'connection' => null,
            'queue' => 'bedrock-install',
            'timeout' => 1200,
            'stale_after' => 3600,
        ],

        'locks' => [
            'store' => null,
            'prefix' => 'modules.operations',
            'seconds' => 1500,
            'wait' => 5,
        ],

        'cache' => [
            'enabled' => true,
            'store' => null,
            'key' => 'modules.registry',
            'ttl' => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | Frontend modules publish immutable browser artifacts under public/ and
    | keep their server renderer inside storage. Development descriptors are
    | considered only in a local environment and when explicitly enabled.
    |
    */

    'frontend' => [
        'enabled' => true,
        'client' => [
            'path' => 'public/vendor/bedrock/modules',
            'url' => '/vendor/bedrock/modules',
        ],
        'server' => [
            'path' => 'storage/framework/bedrock/frontend',
        ],
        'development' => [
            'enabled' => false,
            'path' => 'storage/framework/bedrock/frontend-dev',
            'ttl' => 30,
        ],
        'panels' => [
            'user_attribute' => 'type',
            'fallback' => 'default',
        ],
        'viewer' => [
            'attributes' => [
                'id' => 'id',
                'name' => 'name',
                'email' => 'email',
                'avatar' => null,
            ],
            'actions' => [
                'profile' => 'profile.show',
                'logout' => 'logout',
            ],
        ],
        'cache' => [
            'store' => null,
            'key' => 'bedrock.frontend.registry',
            'ttl' => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    'settings' => [
        'table' => 'module_settings',
        'cache' => [
            'enabled' => true,
            'store' => null,
            'key' => 'bedrock.settings',
            'ttl' => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    'permissions' => [
        'catalog_table' => 'module_permissions',
        'guard' => 'web',
        'admin_permission' => 'bedrock.admin',
        'admin_role' => 'bedrock-admin',
        'user_provider' => 'users',
    ],

    'administration' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    'navigation' => [
        'cache' => [
            'enabled' => true,
            'store' => null,
            'key' => 'bedrock.navigation',
            'ttl' => 3600,
        ],
    ],

];
