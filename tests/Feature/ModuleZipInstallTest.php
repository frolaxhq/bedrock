<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleArchiveException;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Illuminate\Support\Facades\File;

it('installs a module from an archive with a top level directory', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('alpha/resources/views/index.blade.php', 'module view');
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');

    expect(is_file($root.'/alpha/module.json'))->toBeTrue()
        ->and(is_file($root.'/alpha/resources/views/index.blade.php'))->toBeTrue()
        ->and(app(ModuleRepository::class)->find('alpha')?->status)->toBe(ModuleStatus::Installed);

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('installs a module from an archive that holds the module at its root', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('routes/web.php', '<?php');
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');

    expect(is_file($root.'/alpha/module.json'))->toBeTrue()
        ->and(is_file($root.'/alpha/routes/web.php'))->toBeTrue();

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('installs a module from an archive through the command', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
    });

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha', '--zip' => $archive])
        ->expectsOutputToContain('Installed the module [alpha]')
        ->assertSuccessful();

    expect(is_file($root.'/alpha/module.json'))->toBeTrue();

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('rejects an archive whose entry points outside of it', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/evil.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('alpha/../../escaped.txt', 'pwned');
    });

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
        $this->fail('The archive was expected to be rejected.');
    } catch (InvalidModuleArchiveException $exception) {
        expect($exception->getMessage())->toContain('points outside the archive');
    }

    expect(is_dir($root.'/alpha'))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('rejects an archive with an absolute entry name', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/evil.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('/etc/escaped.txt', 'pwned');
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'points outside the archive');

it('rejects an archive that contains a symbolic link', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/evil.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('alpha/passwd', '/etc/passwd');
        $zip->setExternalAttributesName('alpha/passwd', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'symbolic link');

it('rejects case-colliding archive entries', function () {
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/collision.zip', function (ZipArchive $zip): void {
        $zip->addFromString('module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('Assets/app.js', 'upper');
        $zip->addFromString('assets/app.js', 'lower');
    });

    (new ZipSource(app('files'), $archive))->stage();
})->throws(InvalidModuleArchiveException::class, 'not a regular file or directory');

it('enforces archive entry count limits', function () {
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/entries.zip', function (ZipArchive $zip): void {
        $zip->addFromString('module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('extra.txt', 'extra');
    });

    (new ZipSource(app('files'), $archive, 'module.json', 268_435_456, 1))->stage();
})->throws(InvalidModuleArchiveException::class, 'exceeds the limit of 1');

it('enforces archive path length limits', function () {
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/paths.zip', function (ZipArchive $zip): void {
        $zip->addFromString('module.json', json_encode($this->manifestArray('alpha')));
    });

    (new ZipSource(app('files'), $archive, 'module.json', 268_435_456, 10_000, 5))->stage();
})->throws(InvalidModuleArchiveException::class, 'path length limit of 5');

it('rejects a corrupt archive whose file does not match its checksum', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
        $zip->addFromString('alpha/payload.txt', str_repeat('A', 200));
        $zip->setCompressionName('alpha/payload.txt', ZipArchive::CM_STORE);
    });

    $raw = (string) file_get_contents($archive);
    $offset = strpos($raw, str_repeat('A', 200));

    expect($offset)->not->toBeFalse();

    $raw[(int) $offset + 20] = 'B';
    file_put_contents($archive, $raw);

    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
        $this->fail('The archive was expected to be rejected.');
    } catch (InvalidModuleArchiveException $exception) {
        expect($exception->getMessage())->toContain('does not match its recorded checksum');
    }

    expect(is_dir($root.'/alpha'))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('rejects a file that is not an archive', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $zips.'/not-a.zip';
    file_put_contents($archive, 'this is not a zip archive');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'not a ZIP archive');

it('rejects an archive that is missing', function () {
    $root = $this->tempDirectory('modules');

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $root.'/nope.zip'), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'does not exist');

it('rejects an archive without a module manifest', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/readme.txt', 'no manifest here');
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'does not contain a [module.json] file');

it('rejects an archive whose module is nested too deeply', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('build/alpha/module.json', json_encode($this->manifestArray('alpha')));
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'alpha');
})->throws(InvalidModuleArchiveException::class, 'does not contain a [module.json] file');

it('rejects an archive whose manifest key does not match the requested key', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
    });

    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'beta');
})->throws(InvalidModuleSourceException::class, 'holds the module [alpha], but [beta] was requested');

it('refuses to install an archive when both options are given', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');
    $archive = $this->zipArchive($zips.'/alpha.zip', function (ZipArchive $zip): void {
        $zip->addFromString('alpha/module.json', json_encode($this->manifestArray('alpha')));
    });

    $this->useModulesDirectory($root);

    $this->artisan('module:install', ['key' => 'alpha', '--from' => $zips, '--zip' => $archive])
        ->expectsOutputToContain('Use either --from or --zip, not both.')
        ->assertFailed();

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('removes the staging directory after a successful install', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');

    $this->useModulesDirectory($root);

    $before = $this->stagedDirectories('staging');

    $archive = $this->zipArchive($zips.'/staging.zip', function (ZipArchive $zip): void {
        $zip->addFromString('staging/module.json', json_encode($this->manifestArray('staging')));
    });

    app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'staging');

    expect($this->stagedDirectories('staging'))->toBe($before);

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});

it('removes the staging directory after a failed install', function () {
    $root = $this->tempDirectory('modules');
    $zips = $this->tempDirectory('zips');

    $this->useModulesDirectory($root);

    $before = $this->stagedDirectories('failing');

    $archive = $this->zipArchive($zips.'/failing.zip', function (ZipArchive $zip): void {
        $zip->addFromString('failing/module.json', json_encode($this->manifestArray('failing')));
        $zip->addFromString('failing/payload.txt', str_repeat('A', 200));
        $zip->setCompressionName('failing/payload.txt', ZipArchive::CM_STORE);
    });

    $raw = (string) file_get_contents($archive);
    $offset = (int) strpos($raw, str_repeat('A', 200));
    $raw[$offset + 20] = 'B';
    file_put_contents($archive, $raw);

    try {
        app(ModuleManager::class)->installFrom(new ZipSource(app('files'), $archive), 'failing');
    } catch (InvalidModuleArchiveException) {
        // The archive is expected to be rejected; the assertion is about leftovers.
    }

    expect($this->stagedDirectories('failing'))->toBe($before);

    File::deleteDirectory($root);
    File::deleteDirectory($zips);
});
