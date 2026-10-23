<?php

declare(strict_types=1);

use CollisionSdkFixture\Client;
use Composer\Autoload\ClassLoader;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Php\DependencyManifest;
use Frolax\Bedrock\Services\Module\Php\PhpRuntime;
use Illuminate\Support\Facades\File;
use Psr\Container\ContainerInterface;

/** @return array{Manifest, string} */
function phpRuntimeSource(string $key, bool $composer = true): array
{
    $path = sys_get_temp_dir().'/bedrock-php-runtime-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($path.'/src');
    $path = realpath($path);
    $manifest = new Manifest(key: $key, name: $key, version: '1.0.0', provider: 'RuntimeFixture\\Provider', autoload: ['psr-4' => ['RuntimeFixture\\' => 'src/']]);
    File::put($path.'/module.json', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR));
    File::put($path.'/src/Provider.php', '<?php namespace RuntimeFixture; class Provider {}');

    if ($composer) {
        $data = ['name' => 'modules/'.$key, 'require' => new stdClass, 'autoload' => $manifest->autoload];
        File::put($path.'/composer.json', json_encode($data, JSON_THROW_ON_ERROR));
        File::put($path.'/composer.lock', json_encode([
            'content-hash' => app(DependencyManifest::class)->contentHash($data),
            'packages' => [], 'packages-dev' => [], 'aliases' => [], 'minimum-stability' => 'stable',
            'stability-flags' => new stdClass, 'prefer-stable' => false, 'prefer-lowest' => false,
            'platform' => new stdClass, 'platform-dev' => new stdClass, 'plugin-api-version' => '2.6.0',
        ], JSON_THROW_ON_ERROR));
    }

    return [$manifest, $path];
}

it('accepts lock-only validation but requires a runtime to activate composer modules', function () {
    [$manifest, $path] = phpRuntimeSource('lock-only');

    try {
        $runtime = app(PhpRuntime::class);
        $runtime->validate($manifest, $path);
        expect(fn () => $runtime->root($manifest, $path))->toThrow(InvalidModuleSourceException::class, 'prepared');
    } finally {
        File::deleteDirectory($path);
    }
});

it('prepares empty locks offline without composer and exports relocatable absolute maps', function () {
    [$manifest, $path] = phpRuntimeSource('empty-lock');
    $relocated = $path.'-moved';
    config()->set('bedrock.modules.php.composer', ['/does/not/exist']);

    try {
        $runtime = app(PhpRuntime::class);
        $runtime->prepare($manifest, $path, offline: true);
        expect($runtime->root($manifest, $path))->toBe($path.'/dist/php/module')
            ->and($runtime->maps($manifest, $path)['psr4']['RuntimeFixture\\'])->toBe([realpath($path.'/dist/php/module/src')])
            ->and(File::exists($path.'/dist/php/runtime.json'))->toBeTrue();
        rename($path, $relocated);
        $runtime->validate($manifest, $relocated, true);
        expect($runtime->maps($manifest, $relocated)['psr4']['RuntimeFixture\\'])->toBe([realpath($relocated.'/dist/php/module/src')]);
        File::put($relocated.'/src/Provider.php', '<?php namespace RuntimeFixture; class Changed {}');
        expect(fn () => $runtime->root($manifest, $relocated))->toThrow(InvalidModuleSourceException::class, 'source');
    } finally {
        File::deleteDirectory($path);
        File::deleteDirectory($relocated);
    }
});

it('rejects modified and additional runtime files without executing generated php', function () {
    [$manifest, $path] = phpRuntimeSource('tampering');
    $sentinel = $path.'/executed';

    try {
        $runtime = app(PhpRuntime::class);
        $runtime->prepare($manifest, $path, true);
        File::ensureDirectoryExists($path.'/dist/php/vendor/composer');
        File::put($path.'/dist/php/vendor/composer/installed.php', '<?php file_put_contents('.var_export($sentinel, true).', "executed");');
        expect(fn () => $runtime->validate($manifest, $path, true))->toThrow(InvalidModuleSourceException::class, 'inventory')
            ->and(File::exists($sentinel))->toBeFalse();
    } finally {
        File::deleteDirectory($path);
    }
});

it('keeps legacy module roots and maps without introducing a runtime', function () {
    [$manifest, $path] = phpRuntimeSource('legacy-runtime', false);

    try {
        $runtime = app(PhpRuntime::class);
        $runtime->prepare($manifest, $path, true);
        expect($runtime->root($manifest, $path))->toBe($path)
            ->and($runtime->maps($manifest, $path)['psr4']['RuntimeFixture\\'])->toBe([realpath($path.'/src')])
            ->and(File::exists($path.'/dist/php'))->toBeFalse();
    } finally {
        File::deleteDirectory($path);
    }
});

it('uses composer autoload declarations and module ignore patterns for an empty offline graph', function () {
    [$manifest, $path] = phpRuntimeSource('own-composer-autoload');

    try {
        File::ensureDirectoryExists($path.'/own');
        File::ensureDirectoryExists($path.'/mapped');
        File::put($path.'/own/Entry.php', '<?php namespace OwnComposerRuntime; class Entry {}');
        File::put($path.'/mapped/Thing.php', '<?php namespace MappedComposerRuntime; class Thing {}');
        File::put($path.'/functions.php', '<?php function runtimeEmptyGraphHelper() {}');
        File::put($path.'/.bedrockignore', "ignored/**\nsecret.php\n");
        File::ensureDirectoryExists($path.'/ignored');
        File::put($path.'/ignored/file.php', '<?php throw new Exception("excluded");');
        File::put($path.'/secret.php', '<?php throw new Exception("excluded");');
        $composer = json_decode(File::get($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['autoload'] = ['psr-4' => ['OwnComposerRuntime\\' => 'own/'], 'classmap' => ['mapped/'], 'files' => ['functions.php']];
        File::put($path.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $lock = json_decode(File::get($path.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $lock['content-hash'] = app(DependencyManifest::class)->contentHash($composer);
        File::put($path.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $runtime = app(PhpRuntime::class);
        $runtime->prepare($manifest, $path, true);
        $maps = $runtime->maps($manifest, $path);
        expect($maps['psr4']['OwnComposerRuntime\\'])->toBe([realpath($path.'/dist/php/module/own')])
            ->and($maps['classmap']['MappedComposerRuntime\\Thing'])->toBe(realpath($path.'/dist/php/module/mapped/Thing.php'))
            ->and($maps['files'])->toBe([realpath($path.'/dist/php/module/functions.php')])
            ->and(File::exists($path.'/dist/php/module/secret.php'))->toBeFalse()
            ->and(File::exists($path.'/dist/php/module/ignored/file.php'))->toBeFalse();
        File::deleteDirectory($path.'/ignored');
        File::delete($path.'/secret.php');
        $runtime->validate($manifest, $path, true);
    } finally {
        File::deleteDirectory($path);
    }
});

it('fails offline before downloading absent raw dependencies and cleans its build stage', function () {
    [$manifest, $path] = phpRuntimeSdk('missing-raw-sdk', 'MissingRawSdkModule', 'old');

    try {
        File::deleteDirectory($path.'/vendor');
        expect(fn () => app(PhpRuntime::class)->prepare($manifest, $path, true))
            ->toThrow(InvalidModuleSourceException::class, 'Offline PHP preparation')
            ->and(glob($path.'/dist/.php-stage-*'))->toBe([])
            ->and(File::exists($path.'/dist/php'))->toBeFalse();
    } finally {
        File::deleteDirectory($path);
    }
});

it('installs a locked dist archive with the real composer while ignoring scripts and inherited redirects', function () {
    if (! is_file(dirname(__DIR__, 2).'/resources/module-tools/vendor/autoload.php')) {
        $this->markTestSkipped('Install the pinned resources/module-tools Composer project to run the real install test.');
    }

    [$manifest, $path] = phpRuntimeSdk('locked-dist-sdk', 'LockedDistModule', 'old');
    $archivePath = $path.'-sdk.zip';
    $previous = getenv('COMPOSER');

    try {
        $archive = new ZipArchive;
        $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFile($path.'/vendor/fixture/collision-sdk/src/Client.php', 'sdk/src/Client.php');
        $archive->addFile($path.'/vendor/fixture/collision-sdk/helpers.php', 'sdk/helpers.php');
        $archive->close();
        $composer = json_decode(File::get($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['scripts'] = ['post-install-cmd' => '@php -r '.escapeshellarg('file_put_contents('.var_export($path.'/script-executed', true).', "unsafe");')];
        File::put($path.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $lock = json_decode(File::get($path.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $lock['packages'][0]['dist'] = ['type' => 'zip', 'url' => 'file://'.$archivePath, 'reference' => 'locked-fixture', 'shasum' => sha1_file($archivePath)];
        $lock['content-hash'] = app(DependencyManifest::class)->contentHash($composer);
        File::put($path.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        File::deleteDirectory($path.'/vendor');
        putenv('COMPOSER=/this-host-setting-must-not-be-used.json');
        app(PhpRuntime::class)->prepare($manifest, $path);
        $maps = app(PhpRuntime::class)->maps($manifest, $path);

        if (PHP_OS_FAMILY === 'Windows' && array_filter(array_keys($maps['classmap']), static fn (string $class): bool => str_starts_with($class, 'BedrockPrivate\\')) === []) {
            throw new RuntimeException(json_encode([
                'psr4' => array_keys($maps['psr4']),
                'classmap' => array_keys($maps['classmap']),
                'client' => File::exists($path.'/dist/php/vendor/fixture/collision-sdk/src/Client.php')
                    ? File::get($path.'/dist/php/vendor/fixture/collision-sdk/src/Client.php') : '<missing>',
            ], JSON_THROW_ON_ERROR));
        }

        expect(array_filter(array_keys($maps['classmap']), static fn (string $class): bool => str_starts_with($class, 'BedrockPrivate\\')))->not->toBeEmpty()
            ->and(File::exists($path.'/script-executed'))->toBeFalse()
            ->and(File::exists($path.'/vendor'))->toBeFalse();
    } finally {
        $previous === false ? putenv('COMPOSER') : putenv('COMPOSER='.$previous);
        File::deleteDirectory($path);
        File::delete($archivePath);
    }
});

/** @return array{Manifest, string} */
function phpRuntimeSdk(string $key, string $namespace, string $method): array
{
    [$unused, $path] = phpRuntimeSource($key);
    $manifest = new Manifest(key: $key, name: $key, version: '1.0.0', provider: $namespace.'\\Provider', autoload: ['psr-4' => [$namespace.'\\' => 'src/']]);
    $package = [
        'name' => 'fixture/collision-sdk', 'version' => $method === 'old' ? '1.0.0' : '2.0.0', 'type' => 'library',
        'autoload' => ['psr-4' => ['CollisionSdkFixture\\' => 'src/'], 'files' => ['helpers.php']],
    ];
    $composer = ['name' => 'modules/'.$key, 'require' => [$package['name'] => $package['version']], 'autoload' => $manifest->autoload];
    $lock = json_decode(File::get($path.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $lock['content-hash'] = app(DependencyManifest::class)->contentHash($composer);
    $lock['packages'] = [$package];
    File::put($path.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    File::put($path.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    File::put($path.'/module.json', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR));
    File::put($path.'/src/Provider.php', '<?php namespace '.$namespace.'; class Provider implements \\Psr\\Container\\ContainerInterface { public function has(string $id): bool { return true; } public function get(string $id): mixed { return (new \\CollisionSdkFixture\\Client)->'.$method.'().\\CollisionSdkFixture\\helper().\\CollisionSdkFixture\\LABEL; } }');
    File::ensureDirectoryExists($path.'/routes');
    File::put($path.'/routes/web.php', '<?php return (new \\CollisionSdkFixture\\Client)->'.$method.'();');
    File::ensureDirectoryExists($path.'/resources/views');
    File::put($path.'/resources/views/sdk.blade.php', '@php($sdk = new CollisionSdkFixture\\Client)');
    File::ensureDirectoryExists($path.'/vendor/fixture/collision-sdk/src');
    File::put($path.'/vendor/fixture/collision-sdk/src/Client.php', '<?php namespace CollisionSdkFixture; class Client extends \\ArrayObject { public function '.$method.'(): string { return "'.$method.'"; } }');
    File::put($path.'/vendor/fixture/collision-sdk/helpers.php', '<?php namespace CollisionSdkFixture; function helper(): string { return "-helper-"; } const LABEL = "'.$method.'";');
    File::ensureDirectoryExists($path.'/vendor/composer');
    File::put($path.'/vendor/composer/installed.json', json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR));
    $malicious = '<?php file_put_contents('.var_export($path.'/executed', true).', "executed"); throw new \\Exception("unsafe code ran");';
    File::put($path.'/vendor/composer/installed.php', $malicious);
    File::put($path.'/vendor/autoload.php', $malicious);
    File::put($path.'/scoper.inc.php', $malicious);

    return [$manifest, $path];
}

it('scopes two incompatible sdk versions alongside the host using the real isolated toolchain', function () {
    if (! is_file(dirname(__DIR__, 2).'/resources/module-tools/vendor/autoload.php')) {
        $this->markTestSkipped('Install the pinned resources/module-tools Composer project to run the real scoping test.');
    }

    [$first, $one] = phpRuntimeSdk('collision-one', 'CollisionModuleOne', 'old');
    [$second, $two] = phpRuntimeSdk('collision-two', 'CollisionModuleTwo', 'modern');
    $loader = new ClassLoader;

    try {
        File::put($one.'/host.php', '<?php namespace CollisionSdkFixture; class Client { public function host(): string { return "host"; } }');
        require $one.'/host.php';
        $runtime = app(PhpRuntime::class);

        foreach ([[$first, $one], [$second, $two]] as [$manifest, $path]) {
            $runtime->prepare($manifest, $path, true);
            $maps = $runtime->maps($manifest, $path);

            if (PHP_OS_FAMILY === 'Windows' && array_filter($maps['classmap'], static fn (string $file): bool => str_ends_with(str_replace('\\', '/', $file), '/Client.php')) === []) {
                throw new RuntimeException(json_encode([
                    'psr4' => array_keys($maps['psr4']),
                    'classmap' => array_keys($maps['classmap']),
                    'client' => File::exists($path.'/dist/php/vendor/fixture/collision-sdk/src/Client.php')
                        ? File::get($path.'/dist/php/vendor/fixture/collision-sdk/src/Client.php') : '<missing>',
                ], JSON_THROW_ON_ERROR));
            }

            expect(array_filter($maps['classmap'], static fn (string $file): bool => str_ends_with(str_replace('\\', '/', $file), '/Client.php')))->not->toBeEmpty();

            foreach ($maps['psr4'] as $prefix => $paths) {
                $loader->addPsr4($prefix, $paths);
            }

            $loader->addClassMap($maps['classmap']);
            $loader->register(true);

            foreach ($maps['files'] as $file) {
                require_once $file;
            }

            expect(File::exists($path.'/executed'))->toBeFalse()
                ->and(File::exists($path.'/dist/php/vendor/autoload.php'))->toBeFalse()
                ->and(File::exists($path.'/dist/php/resources/module-tools'))->toBeFalse();
        }

        $oneClass = $first->provider;
        $twoClass = $second->provider;
        expect((new $oneClass)->get('value'))->toBe('old-helper-old')
            ->and((new $twoClass)->get('value'))->toBe('modern-helper-modern')
            ->and(new $oneClass)->toBeInstanceOf(ContainerInterface::class)
            ->and((new Client)->host())->toBe('host')
            ->and(require $one.'/dist/php/module/routes/web.php')->toBe('old')
            ->and(require $two.'/dist/php/module/routes/web.php')->toBe('modern')
            ->and(File::get($one.'/dist/php/module/resources/views/sdk.blade.php'))->toContain('BedrockPrivate\\');
    } finally {
        $loader->unregister();
        File::deleteDirectory($one);
        File::deleteDirectory($two);
    }
});
