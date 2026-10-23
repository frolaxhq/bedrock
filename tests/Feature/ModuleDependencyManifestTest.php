<?php

declare(strict_types=1);

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Php\DependencyManifest;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->dependencyPath = $this->tempDirectory('dependency-manifest');
    $this->dependencyManifest = new Manifest(key: 'reports', composer: ['require' => ['psr/log' => '^3.0']]);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->dependencyPath);
});

/** @param array<string, mixed> $composer
 * @param  list<array<string, mixed>>  $packages
 * @param  array<string, mixed>  $lock
 */
function writeModuleDependencies(string $path, array $composer = [], array $packages = [], array $lock = []): void
{
    $composer = array_replace(['name' => 'modules/reports', 'require' => ['php' => '^8.5']], $composer);
    file_put_contents($path.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    file_put_contents($path.'/composer.lock', json_encode(array_replace([
        'content-hash' => (new DependencyManifest)->contentHash($composer),
        'packages' => $packages,
        'packages-dev' => [],
        'platform' => ['php' => '^8.5'],
    ], $lock), JSON_THROW_ON_ERROR));
}

it('leaves legacy modules without their own composer metadata alone', function () {
    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))->toBeNull();
});

it('accepts a scaffold lock with no private packages', function () {
    writeModuleDependencies($this->dependencyPath, ['autoload' => ['psr-4' => ['Modules\\Reports\\' => 'src/']]]);

    $data = (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath);

    expect($data)->toHaveKeys(['composer', 'lock', 'packages', 'fingerprint'])
        ->and($data['packages'])->toBe([])
        ->and($data['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');
});

it('accepts private transitive libraries and ignores development packages', function () {
    $packages = [
        ['name' => 'acme/export', 'version' => '1.0.0', 'require' => ['acme/csv' => '^2.0', 'php' => '^8.5', 'ext-json' => '*']],
        ['name' => 'acme/csv', 'version' => 'v2.1.0', 'type' => 'library'],
    ];
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/export' => '^1.0']], $packages, [
        'packages-dev' => [['name' => 'dev/plugin', 'version' => '1.0.0', 'type' => 'composer-plugin']],
    ]);

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages);
});

it('accepts branch aliases for direct and transitive requirements without changing the package inventory', function (string $version, string $alias, string $constraint) {
    $packages = [
        ['name' => 'acme/sdk', 'version' => $version, 'extra' => ['branch-alias' => [$version => $alias]]],
        ['name' => 'acme/export', 'version' => '1.0.0', 'require' => ['acme/sdk' => $version]],
    ];
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => $constraint, 'acme/export' => '^1.0']], $packages);
    mkdir($this->dependencyPath.'/vendor/composer', 0755, true);
    file_put_contents($this->dependencyPath.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages);
})->with([
    'named branch' => ['dev-main', '1.0.x-dev', '^1.0@dev'],
    'feature branch' => ['dev-feature', '2.x-dev', '^2.0@dev'],
    'numeric branch narrowed' => ['1.x-dev', '1.2.x-dev', '~1.2.0@dev'],
]);

it('uses lock aliases to satisfy transitive constraints on inline aliased branches', function () {
    $packages = [
        ['name' => 'acme/sdk', 'version' => 'dev-bugfix'],
        ['name' => 'acme/export', 'version' => '1.0.0', 'require' => ['acme/sdk' => '^1.0']],
    ];
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => 'dev-bugfix as 1.0.0', 'acme/export' => '^1.0']], $packages, [
        'aliases' => [
            ['package' => 'acme/sdk', 'version' => 'dev-bugfix', 'alias' => '1.0.0', 'alias_normalized' => '1.0.0.0'],
            // Composer can retain aliases for development-only packages.
            ['package' => 'acme/dev-tool', 'version' => 'dev-main', 'alias' => '2.0.x-dev', 'alias_normalized' => '2.0.9999999.9999999-dev'],
        ],
    ]);

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages);
});

it('expands self version providers and replacements through branch and lock aliases', function (string $link, bool $inline) {
    $package = ['name' => 'acme/sdk', 'version' => 'dev-main', $link => ['acme/virtual-sdk' => 'self.version']];
    $lock = [];

    if ($inline) {
        $lock['aliases'] = [['package' => 'acme/sdk', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']];
    } else {
        $package['extra'] = ['branch-alias' => ['dev-main' => '1.0.x-dev']];
    }

    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/virtual-sdk' => '^1.0@dev']], [$package], $lock);

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe([$package]);
})->with(['provide', 'replace'])->with([true, false]);

it('does not treat invalid or unrelated branch aliases as satisfying dependencies', function (string $version, string $source, string $alias) {
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => '^1.0@dev']], [
        ['name' => 'acme/sdk', 'version' => $version, 'extra' => ['branch-alias' => [$source => $alias]]],
    ]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'acme/sdk');
})->with([
    'wrong source branch' => ['dev-main', 'dev-other', '1.0.x-dev'],
    'stable alias target' => ['dev-main', 'dev-main', '1.0.0'],
    'nonnumeric target' => ['dev-main', 'dev-main', 'dev-other-dev'],
    'different numeric branch' => ['2.x-dev', '2.x-dev', '1.0.x-dev'],
    'stable source version' => ['2.0.0', '2.0.0', '1.0.x-dev'],
]);

it('supports Composer default branch and legacy lock aliases', function (bool $legacy) {
    $package = ['name' => 'acme/sdk', 'version' => 'dev-master', 'default-branch' => true];
    $lock = [];
    $constraint = '9999999-dev';

    if ($legacy) {
        $constraint = '^1.0@dev';
        $lock['aliases'] = [['package' => 'acme/sdk', 'version' => '9999999-dev', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']];
    }

    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => $constraint]], [$package], $lock);

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe([$package]);
})->with([true, false]);

it('does not impose an additional stability restriction on locked production packages', function (string $version, string $constraint) {
    $packages = [['name' => 'acme/sdk', 'version' => $version]];
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => $constraint]], $packages);

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages);
})->with([
    ['dev-main', 'dev-main'], ['1.0.0-beta1', '^1.0@beta'], ['1.0.0-RC1', '^1.0@RC'],
]);

it('does not let development-only aliases satisfy missing production requirements', function () {
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => '^1.0']], [], [
        'packages-dev' => [['name' => 'acme/sdk', 'version' => 'dev-main']],
        'aliases' => [['package' => 'acme/sdk', 'version' => 'dev-main', 'alias' => '1.0.0', 'alias_normalized' => '1.0.0.0']],
    ]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'acme/sdk');
});

it('rejects malformed lock aliases as module source errors', function (mixed $aliases) {
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/sdk' => 'dev-main']], [['name' => 'acme/sdk', 'version' => 'dev-main']], ['aliases' => $aliases]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'alias');
})->with([
    'not a list' => ['invalid'],
    'invalid entry' => [[false]],
    'missing normalized version' => [[['package' => 'acme/sdk', 'version' => 'dev-main', 'alias' => '1.0.0']]],
    'invalid alias version' => [[['package' => 'acme/sdk', 'version' => 'dev-main', 'alias' => 'invalid', 'alias_normalized' => 'invalid']]],
    'inconsistent versions' => [[['package' => 'acme/sdk', 'version' => 'dev-main', 'alias' => '1.0.0', 'alias_normalized' => '2.0.0.0']]],
]);

it('requires a lock by default and supports inspection before it is generated', function () {
    file_put_contents($this->dependencyPath.'/composer.json', '{"require":{"php":"^8.5"}}');

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'composer.lock');

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath, false))
        ->toMatchArray(['composer' => ['require' => ['php' => '^8.5']], 'lock' => [], 'packages' => []]);
});

it('rejects stale locks even when lock enforcement is disabled', function () {
    writeModuleDependencies($this->dependencyPath, lock: ['content-hash' => str_repeat('0', 32)]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath, false))
        ->toThrow(InvalidModuleSourceException::class, 'content-hash');
});

it('matches Composer content hashes including nested key order and escaped slashes', function () {
    $composer = [
        'require' => ['zebra/lib' => '^2.0', 'alpha/lib' => '^1.0'],
        'name' => 'modules/reports',
        'config' => ['platform' => ['php' => '8.5.0'], 'vendor-dir' => 'vendor'],
        'extra' => ['url' => 'https://example.com/é', 'empty' => []],
        'scripts' => ['post-install-cmd' => 'never execute'],
    ];
    // Composer Locker::getContentHash sorts only the relevant top-level keys.
    $encoded = '{"config":{"platform":{"php":"8.5.0"}},"extra":{"url":"https:\\/\\/example.com\\/\\u00e9","empty":[]},"name":"modules\\/reports","require":{"zebra\\/lib":"^2.0","alpha\\/lib":"^1.0"}}';
    $service = new DependencyManifest;

    expect($service->contentHash($composer))->toBe(md5($encoded));
    $composer['scripts'] = [];
    $composer['autoload'] = ['files' => ['bootstrap.php']];
    expect($service->contentHash($composer))->toBe(md5($encoded));
    $composer['require'] = array_reverse($composer['require'], true);
    expect($service->contentHash($composer))->not->toBe(md5($encoded));
});

it('normalizes empty objects exactly like Composer JSON parsing before hashing', function () {
    $service = new DependencyManifest;

    expect($service->contentHash(['require' => new stdClass]))->toBe(md5('{"require":[]}'))
        ->and($service->contentHash(['config' => ['platform' => new stdClass]]))->toBe(md5('{"config":{"platform":[]}}'));
});

it('accepts scripts and reads vendor inventory without executing any PHP', function () {
    $packages = [['name' => 'acme/csv', 'version' => '1.0.0']];
    writeModuleDependencies($this->dependencyPath, [
        'scripts' => ['post-install-cmd' => '@php touch executed'],
        'autoload' => ['files' => ['execute.php']],
        'require' => ['acme/csv' => '^1.0'],
    ], $packages);
    mkdir($this->dependencyPath.'/vendor/composer', 0755, true);
    $trap = '<?php throw new RuntimeException("Module PHP was executed");';
    file_put_contents($this->dependencyPath.'/vendor/autoload.php', $trap);
    file_put_contents($this->dependencyPath.'/vendor/composer/installed.php', $trap);
    file_put_contents($this->dependencyPath.'/execute.php', $trap);
    file_put_contents($this->dependencyPath.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages)
        ->and(file_exists($this->dependencyPath.'/executed'))->toBeFalse();
});

it('rejects repositories and nonstandard vendor directories', function (array $composer) {
    writeModuleDependencies($this->dependencyPath, $composer);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class);
})->with([
    'path repository' => [['repositories' => [['type' => 'path', 'url' => '../shared']]]],
    'custom composer repository' => [['repositories' => [['type' => 'composer', 'url' => 'https://packages.example.com']]]],
    'custom vendor directory' => [['config' => ['vendor-dir' => 'lib']]],
    'escaping vendor directory' => [['config' => ['vendor-dir' => '../vendor']]],
]);

it('rejects shared packages anywhere in the private production inventory', function (string $name) {
    writeModuleDependencies($this->dependencyPath, packages: [['name' => $name, 'version' => '1.0.0']]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, $name);
})->with(['laravel/framework', 'illuminate/support', 'frolaxhq/bedrock', 'frolaxhq/bedrock-admin', 'psr/log']);

it('rejects production plugins and installers', function (array $package) {
    writeModuleDependencies($this->dependencyPath, packages: [array_replace(['name' => 'acme/install', 'version' => '1.0.0'], $package)]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class);
})->with([
    'plugin type' => [['type' => 'composer-plugin']],
    'legacy installer type' => [['type' => 'composer-installer']],
    'installer package' => [['name' => 'composer/installers']],
    'installer extender' => [['name' => 'oomphinc/composer-installers-extender']],
    'plugin API requirement' => [['require' => ['composer-plugin-api' => '^2.0']]],
]);

it('rejects local path and symlink sources in locked packages', function (string $kind, string $type) {
    writeModuleDependencies($this->dependencyPath, packages: [
        ['name' => 'acme/local', 'version' => '1.0.0', $kind => ['type' => $type, 'url' => '../local']],
    ]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, $type);
})->with(['source', 'dist'])->with(['path', 'symlink']);

it('rejects symlinked dependency metadata including dangling links', function (string $entry) {
    writeModuleDependencies($this->dependencyPath);
    $target = $this->dependencyPath.'/'.$entry;

    if (is_file($target)) {
        rename($target, $target.'.original');
        symlink($target.'.original', $target);
    } else {
        (new Filesystem)->ensureDirectoryExists(dirname($target));
        $source = $this->dependencyPath.'/missing';

        // Windows can reject dangling symlinks even when ordinary links work.
        if (PHP_OS_FAMILY === 'Windows') {
            if (str_ends_with($entry, '.json')) {
                file_put_contents($source, '{}');
            } else {
                (new Filesystem)->ensureDirectoryExists($source);
            }
        }

        symlink($source, $target);
    }

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class);
})->with(['composer.json', 'composer.lock', 'vendor', 'vendor/composer', 'vendor/composer/installed.json']);

it('rejects missing transitive packages and permits virtual private dependencies', function () {
    $packages = [
        ['name' => 'acme/export', 'version' => '1.0.0', 'require' => ['acme/virtual-csv' => '^2.0']],
        ['name' => 'acme/csv', 'version' => '2.1.0', 'provide' => ['acme/virtual-csv' => 'self.version']],
    ];
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/export' => '^1.0']], $packages);
    expect((new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath)['packages'])->toBe($packages);

    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/export' => '^1.0']], [$packages[0]]);
    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'acme/virtual-csv');
});

it('rejects shared replacements and providers', function (string $link) {
    writeModuleDependencies($this->dependencyPath, packages: [
        ['name' => 'acme/csv', 'version' => '1.0.0', $link => ['illuminate/support' => '*']],
    ]);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'illuminate/support');
})->with(['provide', 'replace']);

it('validates actual runtime platform requirements despite platform overrides', function (string $location, array $requirements) {
    $composer = ['config' => ['platform' => ['php' => '99.0.0', 'ext-bedrock_missing' => '1.0.0']]];
    $lock = ['platform-overrides' => ['php' => '99.0.0', 'ext-bedrock_missing' => '1.0.0']];
    $packages = [];

    if ($location === 'composer') {
        $composer['require'] = $requirements;
    } elseif ($location === 'lock') {
        $lock['platform'] = $requirements;
    } else {
        $packages[] = ['name' => 'acme/csv', 'version' => '1.0.0', 'require' => $requirements];
    }

    writeModuleDependencies($this->dependencyPath, $composer, $packages, $lock);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, array_key_first($requirements));
})->with(['composer', 'lock', 'package'])->with([
    'incompatible PHP' => [['php' => '^99.0']],
    'missing extension' => [['ext-bedrock_missing' => '*']],
    'incompatible extension' => [['ext-json' => '<1.0']],
    'malformed constraint' => [['php' => 'not a constraint']],
]);

it('rejects missing and incompatible private dependencies in a hash-fresh lock', function (array $packages) {
    writeModuleDependencies($this->dependencyPath, ['require' => ['acme/csv' => '^2.0']], $packages);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'acme/csv');
})->with([
    'missing' => [[]],
    'wrong version' => [[['name' => 'acme/csv', 'version' => '1.0.0']]],
]);

it('rejects malformed JSON and metadata using module source exceptions', function (string $file, string $contents) {
    writeModuleDependencies($this->dependencyPath);
    file_put_contents($this->dependencyPath.'/'.$file, $contents);

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class);
})->with([
    ['composer.json', '{'], ['composer.json', '[]'], ['composer.json', 'null'],
    ['composer.json', '{"require":false}'], ['composer.json', '{"config":"vendor"}'],
    ['composer.lock', '{'], ['composer.lock', '[]'], ['composer.lock', '{}'],
]);

it('validates raw vendor inventories against the complete production lock', function (array $installed, bool $modern) {
    $packages = [['name' => 'acme/csv', 'version' => '1.0.0', 'dist' => ['reference' => 'locked']]];
    writeModuleDependencies($this->dependencyPath, packages: $packages);
    $service = new DependencyManifest;
    $data = $service->inspect($this->dependencyManifest, $this->dependencyPath);
    mkdir($this->dependencyPath.'/vendor/composer', 0755, true);
    file_put_contents($this->dependencyPath.'/vendor/composer/installed.json', json_encode($modern ? ['packages' => $installed] : $installed, JSON_THROW_ON_ERROR));

    expect(fn () => $service->validateVendor($this->dependencyPath, $data))
        ->toThrow(InvalidModuleSourceException::class);
})->with([
    'missing' => [[]],
    'extra' => [[['name' => 'acme/csv', 'version' => '1.0.0', 'dist' => ['reference' => 'locked']], ['name' => 'acme/extra', 'version' => '1.0.0']]],
    'wrong version' => [[['name' => 'acme/csv', 'version' => '2.0.0']]],
    'wrong reference' => [[['name' => 'acme/csv', 'version' => '1.0.0', 'dist' => ['reference' => 'other']]]],
    'duplicate' => [[['name' => 'acme/csv', 'version' => '1.0.0'], ['name' => 'acme/csv', 'version' => '1.0.0']]],
])->with([true, false]);

it('rejects raw vendor directories without a JSON inventory', function () {
    writeModuleDependencies($this->dependencyPath);
    mkdir($this->dependencyPath.'/vendor');

    expect(fn () => (new DependencyManifest)->inspect($this->dependencyManifest, $this->dependencyPath))
        ->toThrow(InvalidModuleSourceException::class, 'installed.json');
});
