<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Services\Module\Authoring\ModuleScaffolder;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Frolax\Bedrock\Services\Module\ModuleBootstrapper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('creates locked standalone composer modules and prepares them without composer or internet', function () {
    $root = $this->tempDirectory('php-integration');
    config()->set('bedrock.modules.path', $root);
    $module = app(ModuleScaffolder::class)->create('independent');

    expect(File::exists($module.'/composer.json'))->toBeTrue()
        ->and(File::exists($module.'/composer.lock'))->toBeTrue()
        ->and(Artisan::call('module:prepare', ['module' => $module, '--offline' => true]))->toBe(0)
        ->and(File::exists($module.'/dist/php/runtime.json'))->toBeTrue();

    app(ModuleManager::class)->install('independent');
    app(ModuleManager::class)->enable('independent');

    expect(Artisan::call('module:doctor', ['key' => 'independent', '--json' => true]))->toBe(0);
    app(ModuleManager::class)->uninstall('independent');
    expect(File::exists($module))->toBeFalse();
    File::deleteDirectory($root);
});

it('packages locked source without raw vendor or a prepared runtime when requested', function () {
    $root = $this->tempDirectory('php-source-package');
    $module = (new ModuleScaffolder(app('files'), $root))->create('source-package');
    $archive = app(ModulePackager::class)->pack($module, $root.'/out', withoutPhpRuntime: true);
    $zip = new ZipArchive;
    $zip->open($archive);
    expect($zip->locateName('composer.lock'))->not->toBeFalse()
        ->and($zip->locateName('vendor/autoload.php'))->toBeFalse()
        ->and($zip->locateName('dist/php/runtime.json'))->toBeFalse();
    $zip->close();
    File::deleteDirectory($root);
});

it('rebuilds an authored runtime explicitly after changing its source', function () {
    $root = $this->tempDirectory('php-author-rebuild');
    $module = (new ModuleScaffolder(app('files'), $root))->create('author-rebuild');
    expect(Artisan::call('module:prepare', ['module' => $module, '--offline' => true]))->toBe(0);
    File::append($module.'/src/AuthorRebuildServiceProvider.php', "\n// New authoring revision.\n");
    expect(Artisan::call('module:prepare', ['module' => $module, '--offline' => true]))->toBe(1)
        ->and(Artisan::call('module:prepare', ['module' => $module, '--offline' => true, '--force' => true]))->toBe(0)
        ->and(File::get($module.'/dist/php/module/src/AuthorRebuildServiceProvider.php'))->toContain('New authoring revision');
    File::deleteDirectory($root);
});

it('installs a relocated prepared zip offline and serves its conventional route', function () {
    $source = $this->tempDirectory('php-zip-author');
    $target = $this->tempDirectory('php-zip-consumer');
    $module = (new ModuleScaffolder(app('files'), $source))->create('relocated-runtime');
    $archive = app(ModulePackager::class)->pack($module, $source.'/archives');
    config()->set('bedrock.modules.path', $target);
    config()->set('bedrock.modules.php.offline', true);
    config()->set('bedrock.modules.php.composer', ['/missing/composer']);

    $manager = app(ModuleManager::class);
    $manager->installFrom(new ZipSource(app('files'), $archive), 'relocated-runtime');
    $manager->enable('relocated-runtime');
    app(ModuleBootstrapper::class)->bootstrap();
    $this->get('/relocated-runtime')->assertSuccessful();
    $manager->disable('relocated-runtime');
    $this->get('/relocated-runtime')->assertNotFound();
    $manager->uninstall('relocated-runtime');
    expect(is_dir($target.'/relocated-runtime'))->toBeFalse();
    File::deleteDirectory($source);
    File::deleteDirectory($target);
});
