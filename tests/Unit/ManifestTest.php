<?php

declare(strict_types=1);

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidManifestException;
use Frolax\Bedrock\Exceptions\Module\ManifestNotFoundException;

it('loads a valid manifest', function () {
    $manifest = $this->loader()->load($this->fixturePath('alpha/module.json'));

    expect($manifest)->toBeInstanceOf(Manifest::class)
        ->and($manifest->key)->toBe('alpha')
        ->and($manifest->name)->toBe('Alpha Module')
        ->and($manifest->version)->toBe('1.2.0')
        ->and($manifest->provider)->toBe('Frolax\\Bedrock\\Tests\\Fixtures\\Modules\\Alpha\\AlphaServiceProvider')
        ->and($manifest->description)->toBe('A valid fixture module.')
        ->and($manifest->permissions)->toBe(['view'])
        ->and($manifest->settings)->toBe(['greeting' => 'default'])
        ->and($manifest->requires)->toBe([]);
});

it('carries the dependencies of a module', function () {
    $manifest = $this->loader()->load($this->fixturePath('beta/module.json'));

    expect($manifest->requires)->toBe(['alpha' => '>=1.0.0'])
        ->and($manifest->requirements())->toBe(['alpha'])
        ->and($manifest->constraintFor('alpha'))->toBe('>=1.0.0')
        ->and($manifest->constraintFor('missing'))->toBeNull();
});

it('round trips through an array', function () {
    $manifest = $this->loader()->load($this->fixturePath('alpha/module.json'));

    expect(Manifest::from($manifest->toArray())->toArray())->toBe($manifest->toArray());
});

it('throws when the manifest file is missing', function () {
    $this->loader()->load($this->fixturePath('manifestless/module.json'));
})->throws(ManifestNotFoundException::class);

it('throws when the manifest is not valid json', function () {
    $this->loader()->load($this->fixturePath('broken/module.json'));
})->throws(InvalidManifestException::class, 'not valid JSON');

it('rejects a manifest without required fields', function () {
    $path = sys_get_temp_dir().'/bedrock-manifest-'.uniqid().'.json';
    file_put_contents($path, json_encode(['name' => 'No key']));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'missing the required [key] field');

it('rejects a module key that does not follow the convention', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'Invalid Key',
        'name' => 'Invalid',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'is invalid');

it('rejects a version that is not semantic', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Invalid',
        'version' => 'v1',
        'provider' => 'Some\\Provider',
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'is not a semantic version');

it('rejects an invalid dependency constraint', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Deps',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'requires' => ['beta' => ''],
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'dependency [beta] is invalid');

it('accepts composer constraints for core and module dependencies', function (string $constraint) {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Compatible',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'core' => $constraint,
        'requires' => ['beta' => $constraint],
    ]));

    try {
        $manifest = $this->loader()->load($path);
    } finally {
        @unlink($path);
    }

    expect($manifest->core)->toBe($constraint)
        ->and($manifest->requires)->toBe(['beta' => $constraint]);
})->with(['^1.2', '~1.2.3', '>=1.0 <2.0', '1.2.*', '^1.5 || ^2.0']);

it('rejects a malformed dependency constraint', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Invalid dependency',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'requires' => ['beta' => 'definitely not a constraint !'],
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'dependency [beta]');

it('rejects a malformed core constraint', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Invalid core',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'core' => 'definitely not a constraint !',
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'core constraint');

it('ignores manifest keys it does not understand', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        'key' => 'alpha',
        'name' => 'Forward compatible',
        'version' => '1.0.0',
        'provider' => 'Some\\Provider',
        'future_feature' => ['unknown' => true],
    ]));

    try {
        $manifest = $this->loader()->load($path);
    } finally {
        @unlink($path);
    }

    expect($manifest->key)->toBe('alpha')
        ->and($manifest->toArray())->not->toHaveKey('future_feature');
});

it('rejects an unsupported manifest schema', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'schema' => 2,
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'schema [2] is not supported');

it('loads validated autoload and Composer requirements', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'autoload' => [
            'psr-4' => ['Modules\\Alpha\\' => 'src/'],
            'files' => ['src/helpers.php'],
        ],
        'composer' => [
            'require' => [
                'php' => '^8.5',
                'laravel/framework' => '^13.0',
                'ext-json' => '*',
            ],
        ],
    ]));

    try {
        $manifest = $this->loader()->load($path);
    } finally {
        @unlink($path);
    }

    expect($manifest->autoload['psr-4'])->toBe(['Modules\\Alpha\\' => 'src/'])
        ->and($manifest->autoload['files'])->toBe(['src/helpers.php'])
        ->and($manifest->composer['require'])->toHaveKey('php', '^8.5');
});

it('rejects unsafe module autoload paths', function (string $path) {
    $manifest = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($manifest, json_encode([
        ...$this->manifestArray('alpha'),
        'autoload' => ['psr-4' => ['Modules\\Alpha\\' => $path]],
    ]));

    try {
        $this->loader()->load($manifest);
    } finally {
        @unlink($manifest);
    }
})->with(['../outside', '/absolute', 'C:\\outside'])
    ->throws(InvalidManifestException::class, 'autoload declaration');

it('rejects malformed known manifest fields instead of silently normalizing them', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'requires' => 'beta',
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'field [requires] must be a JSON object');

it('rejects malformed Composer package requirements', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'composer' => ['require' => ['vendor/package' => 'not a constraint !']],
    ]));

    try {
        $this->loader()->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidManifestException::class, 'Composer requirement [vendor/package] is invalid');

it('validates known database frontend and asset declarations', function (array $attributes, string $field) {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        ...$attributes,
    ], JSON_THROW_ON_ERROR));

    try {
        expect(fn () => $this->loader()->load($path))
            ->toThrow(InvalidManifestException::class, $field);
    } finally {
        @unlink($path);
    }
})->with([
    'seeder class' => [['database' => ['seeder' => 'not-a-class']], 'database.seeder'],
    'navigation entry' => [['frontend' => ['navigation' => [['label' => 'Missing route']]]], 'frontend.navigation'],
    'asset path' => [['assets' => ['js' => ['../escape.js']]], 'assets.js'],
]);

it('loads the inertia react frontend contract', function () {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'frontend' => [
            'driver' => 'inertia-react',
            'pages' => 'resources/js/pages',
            'artifact' => 'dist/bedrock/frontend.json',
            'requires' => [
                'react' => '^19.0',
                '@inertiajs/react' => '^3.0',
                '@frolax/bedrock' => '^1.0',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $manifest = $this->loader()->load($path);
    } finally {
        @unlink($path);
    }

    expect($manifest->frontend['driver'])->toBe('inertia-react')
        ->and($manifest->frontend['artifact'])->toBe('dist/bedrock/frontend.json')
        ->and($manifest->frontend['requires']['@frolax/bedrock'])->toBe('^1.0');
});

it('rejects malformed frontend contracts', function (array $frontend, string $field) {
    $path = tempnam(sys_get_temp_dir(), 'bedrock').'.json';
    file_put_contents($path, json_encode([
        ...$this->manifestArray('alpha'),
        'frontend' => $frontend,
    ], JSON_THROW_ON_ERROR));

    try {
        expect(fn () => $this->loader()->load($path))
            ->toThrow(InvalidManifestException::class, $field);
    } finally {
        @unlink($path);
    }
})->with([
    'unsupported driver' => [['driver' => 'vue', 'pages' => 'resources/js', 'artifact' => 'dist/frontend.json'], 'frontend.driver'],
    'missing pages' => [['driver' => 'inertia-react', 'artifact' => 'dist/frontend.json'], 'frontend.pages'],
    'unsafe artifact' => [['driver' => 'inertia-react', 'pages' => 'resources/js', 'artifact' => '../frontend.json'], 'frontend.artifact'],
    'invalid requirement' => [[
        'driver' => 'inertia-react',
        'pages' => 'resources/js',
        'artifact' => 'dist/frontend.json',
        'requires' => ['react' => 'not a constraint !'],
    ], 'frontend.requires'],
]);
