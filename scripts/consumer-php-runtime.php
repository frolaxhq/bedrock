<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Models\ModuleInstallation;
use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Services\Module\Authoring\ModuleScaffolder;
use Frolax\Bedrock\Services\Module\Install\LocalDirectorySource;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Frolax\Bedrock\Services\Module\Php\DependencyManifest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

// Executed from a real consumer in separate processes, including cold HTTP startup.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$action = $argv[1] ?? '';

if (in_array($action, ['enabled', 'disabled', 'download-enabled'], true)) {
    $kernel = $app->make(HttpKernel::class);
    $route = $action === 'download-enabled' ? '/download-consumer' : '/independent-consumer';
    $response = $kernel->handle(Request::create($route));
    $expected = $action === 'disabled' ? 404 : 200;

    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException('Cold module HTTP startup returned '.$response->getStatusCode().' instead of '.$expected.'.');
    }

    if ($action === 'download-enabled' && ! str_contains($response->getContent(), 'BedrockPrivate')) {
        throw new RuntimeException('The downloaded SDK did not use its isolated namespace.');
    }
    $kernel->terminate(Request::create($route), $response);
    exit(0);
}

$app->make(Kernel::class)->bootstrap();
$manager = $app->make(ModuleManager::class);

if ($action === 'build') {
    $hashes = [];
    foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json'] as $file) {
        $hashes[$file] = hash_file('sha256', $app->basePath($file));
    }
    $module = (new ModuleScaffolder($app->make('files'), $app->storagePath('proof-source')))->create('independent-consumer');
    $archive = $app->make(ModulePackager::class)->pack($module, $app->storagePath('proof-artifacts'));
    config()->set('bedrock.modules.php.offline', true);
    config()->set('bedrock.modules.php.composer', ['/missing/composer']);
    $manager->installFrom(new ZipSource($app->make('files'), $archive), 'independent-consumer');
    $manager->enable('independent-consumer');
    foreach ($hashes as $file => $hash) {
        if (hash_file('sha256', $app->basePath($file)) !== $hash) {
            throw new RuntimeException('Module installation changed host dependency file '.$file.'.');
        }
    }
} elseif ($action === 'disable') {
    $manager->disable('independent-consumer');
} elseif ($action === 'cleanup') {
    $manager->uninstall('independent-consumer');

    if (is_dir($app->basePath('modules/independent-consumer'))) {
        throw new RuntimeException('Module-owned runtime remains after uninstall.');
    }
} elseif ($action === 'queue') {
    $source = (new ModuleScaffolder($app->make('files'), $app->storagePath('proof-source')))->create('queued-consumer');
    config()->set('bedrock.modules.operations.connection', 'database');
    config()->set('queue.connections.database.retry_after', 1500);
    $operation = $app->make(InstallationOperations::class)->schedule('queued-consumer', from: $source, offline: true);

    if ($operation->status !== 'queued') {
        throw new RuntimeException('The installation was not queued.');
    }
} elseif ($action === 'queue-verify') {
    $operation = ModuleInstallation::query()->where('key', 'queued-consumer')->latest()->firstOrFail();

    if ($operation->status !== 'completed') {
        throw new RuntimeException('The real installation worker did not complete: '.$operation->status.' '.$operation->error);
    }
    $manager->uninstall('queued-consumer');
} elseif ($action === 'download') {
    $source = (new ModuleScaffolder($app->make('files'), $app->storagePath('proof-source')))->create('download-consumer');
    $files = $app->make('files');
    $composer = json_decode($files->get($source.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer['require']['psr/log'] = '^3.0';
    $lock = json_decode($files->get($source.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $hostLock = json_decode($files->get($app->basePath('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $lock['packages'] = array_values(array_filter($hostLock['packages'], static fn (array $package): bool => $package['name'] === 'psr/log'));

    if (count($lock['packages']) !== 1) {
        throw new RuntimeException('The consumer must have psr/log available as a locked SDK fixture.');
    }
    $lock['content-hash'] = (new DependencyManifest)->contentHash($composer);
    $files->put($source.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $files->put($source.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $controller = $source.'/src/Http/Controllers/DownloadConsumerController.php';
    $files->put($controller, str_replace("['module' => 'download-consumer']", "['sdk' => (new \\Psr\\Log\\NullLogger)::class]", $files->get($controller)));
    $manager->installFrom(new LocalDirectorySource($files, $source), 'download-consumer');
    $manager->enable('download-consumer');
} elseif ($action === 'download-cleanup') {
    $manager->uninstall('download-consumer');
} else {
    throw new RuntimeException('Unknown consumer verification action.');
}
