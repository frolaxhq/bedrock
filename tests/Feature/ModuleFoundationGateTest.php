<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Module\ModuleOperationLock;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Exceptions\Module\ComposerRequirementNotSatisfiedException;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Exceptions\Module\ModuleLockException;
use Frolax\Bedrock\Services\Module\CacheModuleOperationLock;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('autoloads module classes and loads helper files only during trusted installation', function () {
    $root = $this->tempDirectory('dynamic-root');
    $source = $this->tempDirectory('dynamic-source');
    $module = $this->moduleSource($source, 'dynamic');
    $manifestPath = $module.'/module.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['autoload']['files'] = ['src/helpers.php'];
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($module.'/src/helpers.php', <<<'PHP'
    <?php

    $GLOBALS['bedrock_dynamic_helper_loads'] = ($GLOBALS['bedrock_dynamic_helper_loads'] ?? 0) + 1;
    PHP);

    unset($GLOBALS['bedrock_dynamic_helper_loads']);
    $this->useModulesDirectory($root);

    app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'dynamic');

    expect(class_exists('Modules\\Dynamic\\DynamicServiceProvider'))->toBeTrue()
        ->and($GLOBALS['bedrock_dynamic_helper_loads'] ?? 0)->toBe(1)
        ->and(app(ModuleRepository::class)->find('dynamic')?->status)->toBe(ModuleStatus::Installed);

    app(ModuleManager::class)->enable('dynamic');

    expect($GLOBALS['bedrock_dynamic_helper_loads'])->toBe(1);

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('keeps copied files and a failed repair record when a Composer requirement is missing', function () {
    $root = $this->tempDirectory('composer-root');
    $source = $this->tempDirectory('composer-source');
    $module = $this->moduleSource($source, 'platform');
    $manifestPath = $module.'/module.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['composer'] = ['require' => ['ext-bedrock_missing' => '*']];
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'platform');
    } catch (ComposerRequirementNotSatisfiedException $exception) {
        expect($exception->getMessage())->toContain('ext-bedrock_missing');
    }

    $registered = app(ModuleRepository::class)->find('platform');

    expect(is_file($root.'/platform/module.json'))->toBeTrue()
        ->and($registered?->status)->toBe(ModuleStatus::Discovered)
        ->and($registered?->health)->toBe(ModuleHealth::Failed)
        ->and($registered?->failure_code)->toBe(ModuleFailureCode::PackageMissing)
        ->and($registered?->installed_at)->toBeNull();

    File::deleteDirectory($root);
    File::deleteDirectory($source);
});

it('rejects symbolic links in local module packages', function () {
    $root = $this->tempDirectory('link-root');
    $source = $this->tempDirectory('link-source');
    $module = $this->moduleSource($source, 'linked');
    symlink($module.'/module.json', $module.'/linked.json');
    $this->useModulesDirectory($root);

    try {
        app(ModuleManager::class)->installFrom(new LocalDirectorySource(app('files'), $module), 'linked');
    } finally {
        expect(is_dir($root.'/linked'))->toBeFalse();
        File::deleteDirectory($root);
        File::deleteDirectory($source);
    }
})->throws(InvalidModuleSourceException::class, 'symbolic link');

it('exposes scoped diagnostics through stable JSON output', function () {
    app(ModuleManager::class)->install('alpha');
    $report = app(ModuleDiagnostics::class)->diagnose('alpha');

    expect($report->ok)->toBeTrue()
        ->and(collect($report->checks)->pluck('code')->all())->toContain('provider.resolution');

    $exit = Artisan::call('module:doctor', ['key' => 'alpha', '--json' => true]);
    $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($json)->toHaveKeys(['ok', 'checks'])
        ->and($json['ok'])->toBeTrue()
        ->and($json['checks'][0])->toHaveKeys(['code', 'status', 'module', 'message']);
});

it('fails lifecycle writes cleanly when an atomic lock is contended', function () {
    app()->instance(ModuleOperationLock::class, new CacheModuleOperationLock(app('cache'), [
        'store' => 'array',
        'prefix' => 'modules.operations',
        'seconds' => 30,
        'wait' => 0,
    ]));
    app()->forgetInstance(ModuleManager::class);

    $held = Cache::store('array')->lock('modules.operations.alpha', 30);
    $held->get();
    $failure = null;

    try {
        app(ModuleManager::class)->install('alpha');
    } catch (ModuleLockException $exception) {
        $failure = $exception;
    } finally {
        $held->release();
    }

    expect($failure)->toBeInstanceOf(ModuleLockException::class)
        ->and($failure?->getMessage())->toContain('alpha')
        ->and(app(ModuleRepository::class)->find('alpha'))->toBeNull();
});
