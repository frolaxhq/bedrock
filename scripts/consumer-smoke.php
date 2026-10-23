<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$repository = dirname(__DIR__);
$temporary = sys_get_temp_dir().'/bedrock-consumers-'.bin2hex(random_bytes(8));
$files = new Filesystem;
$files->makeDirectory($temporary);

/** @param list<string> $command */
function runConsumerCommand(array $command, string $directory): void
{
    $process = new Process($command, $directory, ['APP_ENV' => 'testing', 'COMPOSER_NO_INTERACTION' => '1']);
    $process->setTimeout(300);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getOutput().$process->getErrorOutput());
    }
}

try {
    runConsumerCommand(['composer', 'create-project', 'laravel/laravel', $temporary.'/template', '^13.0', '--no-install', '--no-scripts', '--prefer-dist'], $temporary);
    $profiles = [
        'core' => [],
        'access' => ['access-spatie'],
        'admin' => ['admin'],
        'frontend' => ['frontend'],
        'audit' => [],
        'full' => ['admin', 'frontend'],
    ];

    foreach ($profiles as $profile => $packages) {
        $directory = $temporary.'/'.$profile;
        $files->copyDirectory($temporary.'/template', $directory);
        $composer = json_decode($files->get($directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['repositories'] = [[
            'type' => 'path', 'url' => $repository, 'options' => ['versions' => ['frolaxhq/bedrock' => '1.0.0']],
        ], [
            'type' => 'path', 'url' => $repository.'/packages/php/*', 'options' => ['versions' => [
                'frolaxhq/bedrock-admin' => '1.0.0', 'frolaxhq/bedrock-access-spatie' => '1.0.0', 'frolaxhq/bedrock-frontend' => '1.0.0',
            ]],
        ]];
        $composer['require']['frolaxhq/bedrock'] = '^1.0';

        foreach ($packages as $package) {
            $composer['require']['frolaxhq/bedrock-'.$package] = '^1.0';
        }

        // The production dependency graph is the subject of this test.
        unset($composer['require-dev']);
        $files->put($directory.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        runConsumerCommand(['composer', 'update', '--no-dev', '--no-scripts', '--prefer-dist', '--no-progress'], $directory);
        $files->put($directory.'/database/database.sqlite', '');
        $files->put($directory.'/.env', "APP_ENV=testing\nAPP_KEY=base64:".base64_encode(str_repeat('a', 32))."\nDB_CONNECTION=sqlite\nDB_DATABASE=".$directory."/database/database.sqlite\nCACHE_STORE=array\nSESSION_DRIVER=array\n");
        $admin = in_array('admin', $packages, true);
        $config = ['core' => ['version' => '1.0.0'], 'administration' => ['enabled' => $admin]];
        $files->put($directory.'/config/bedrock.php', "<?php\nreturn ".var_export($config, true).";\n");
        runConsumerCommand([PHP_BINARY, 'artisan', 'package:discover'], $directory);

        if ($admin) {
            $user = $files->get($directory.'/app/Models/User.php');
            $user = str_replace('use HasFactory, Notifiable;', 'use HasFactory, Notifiable, \\Spatie\\Permission\\Traits\\HasRoles;', $user);
            $files->put($directory.'/app/Models/User.php', $user);
            runConsumerCommand([PHP_BINARY, 'artisan', 'vendor:publish', '--provider=Spatie\\Permission\\PermissionServiceProvider', '--tag=permission-migrations'], $directory);
        }

        runConsumerCommand([PHP_BINARY, 'artisan', 'migrate', '--force'], $directory);
        $audit = in_array($profile, ['audit', 'full'], true);

        if ($audit) {
            $files->ensureDirectoryExists($directory.'/modules');
            $files->copyDirectory($repository.'/modules/audit-log', $directory.'/modules/audit-log');
            runConsumerCommand([PHP_BINARY, 'artisan', 'module:install', 'audit-log'], $directory);
            runConsumerCommand([PHP_BINARY, 'artisan', 'module:enable', 'audit-log'], $directory);
            runConsumerCommand([PHP_BINARY, 'artisan', 'module:pack', 'audit-log', '--output=storage/module-artifacts'], $directory);
        }

        $probe = <<<'PHP'
        <?php
        require __DIR__.'/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        [$profile, $hasAdmin, $hasFrontend, $hasAccess, $hasAudit] = array_slice($argv, 1);
        foreach ([
            'frolaxhq/bedrock-admin' => $hasAdmin,
            'inertiajs/inertia-laravel' => $hasFrontend,
            'spatie/laravel-permission' => $hasAccess,
        ] as $package => $expected) {
            if (Composer\InstalledVersions::isInstalled($package) !== ($expected === '1')) {
                throw new RuntimeException('Unexpected dependency: '.$package);
            }
        }
        if (Illuminate\Support\Facades\Route::has('bedrock.admin.modules.index') !== ($hasAdmin === '1')) {
            throw new RuntimeException('Unexpected administration routes.');
        }
        if ($app->bound(Frolax\Bedrock\Contracts\Frontend\FrontendRegistry::class) !== ($hasFrontend === '1')) {
            throw new RuntimeException('Unexpected frontend integration.');
        }
        if (Illuminate\Support\Facades\Schema::hasTable('bedrock_audit_entries') !== ($hasAudit === '1')) {
            throw new RuntimeException('Unexpected audit table.');
        }
        if ($hasAdmin === '1') {
            $user = App\Models\User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => bcrypt('password')]);
            $administration = $app->make(Frolax\Bedrock\Services\Administration\AdministrationManager::class);
            $administration->grantAdministrator($user);
            if (!$user->can('bedrock.admin')) {
                throw new RuntimeException('Administration permission was not granted.');
            }
        }
        if ($hasAudit === '1') {
            $app->make(Modules\AuditLog\AuditRecorder::class)->record('consumer.verified', 'application', $profile);
            if (!Illuminate\Support\Facades\DB::table('bedrock_audit_entries')->where('event', 'consumer.verified')->exists()) {
                throw new RuntimeException('Audit capture failed.');
            }
        }
        if ($hasFrontend === '0' && is_dir(public_path('vendor/bedrock/modules'))) {
            throw new RuntimeException('Unexpected frontend artifacts.');
        }
        echo $profile." passed\n";
        PHP;
        $files->put($directory.'/probe.php', $probe);
        runConsumerCommand([PHP_BINARY, $directory.'/probe.php', $profile, $admin ? '1' : '0', in_array('frontend', $packages, true) ? '1' : '0',
            ($admin || in_array('access-spatie', $packages, true)) ? '1' : '0', $audit ? '1' : '0'], $directory);
        echo 'Consumer '.$profile." passed.\n";

        if ($profile === 'core') {
            foreach (['build', 'enabled', 'disable', 'disabled', 'cleanup'] as $action) {
                runConsumerCommand([PHP_BINARY, $repository.'/scripts/consumer-php-runtime.php', $action], $directory);
            }
            echo "Consumer isolated PHP runtime lifecycle passed.\n";
            runConsumerCommand([PHP_BINARY, $repository.'/scripts/consumer-php-runtime.php', 'queue'], $directory);
            runConsumerCommand([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=bedrock-install', '--once', '--tries=1', '--timeout=1260'], $directory);
            runConsumerCommand([PHP_BINARY, $repository.'/scripts/consumer-php-runtime.php', 'queue-verify'], $directory);
            echo "Consumer isolated installation worker passed.\n";
            foreach (['download', 'download-enabled', 'download-cleanup'] as $action) {
                runConsumerCommand([PHP_BINARY, $repository.'/scripts/consumer-php-runtime.php', $action], $directory);
            }
            echo "Consumer automatic locked SDK download and isolation passed.\n";
        }
    }
} finally {
    $files->deleteDirectory($temporary);
}
