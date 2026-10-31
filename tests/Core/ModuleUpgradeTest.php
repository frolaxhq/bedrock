<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleUpgraded;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->upgradeRoot = $this->tempDirectory('upgrade');
    File::copyDirectory($this->fixturePath('alpha'), $this->upgradeRoot.'/alpha');
    config()->set('bedrock.modules.path', $this->upgradeRoot);
    $manifest = $this->loader()->load($this->upgradeRoot.'/alpha/module.json');
    $this->seedModule('alpha', [
        'version' => '1.0.0', 'path' => $this->upgradeRoot.'/alpha',
        'provider' => $manifest->provider, 'manifest' => $manifest->toArray(),
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->upgradeRoot);
});

it('upgrades an inactive module and runs migrations without replaying installation seeders', function (): void {
    Event::fake([ModuleUpgraded::class]);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertSuccessful();

    $module = app(ModuleRepository::class)->findOrFail('alpha');
    expect($module->version)->toBe('1.2.0')
        ->and($module->status)->toBe(ModuleStatus::Installed)
        ->and($module->hasFailed())->toBeFalse()
        ->and(Schema::hasTable('alpha_items'))->toBeTrue();
    expect(DB::table('alpha_items')->count())->toBe(0);
    Event::assertDispatched(ModuleUpgraded::class, fn (ModuleUpgraded $event): bool => $event->previousVersion === '1.0.0');
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
});

it('rejects upgrades of enabled or merely discovered modules', function (ModuleStatus $status): void {
    app(ModuleRepository::class)->upsert('alpha', ['status' => $status]);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
})->with([ModuleStatus::Enabled, ModuleStatus::Discovered]);

it('rejects upgrades that break registered dependent constraints before migrations', function (): void {
    $this->seedModule('consumer', ['dependencies' => ['alpha' => '<1.1']]);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
});

it('keeps the previous version and records failure when upgrade migrations fail', function (): void {
    File::put($this->upgradeRoot.'/alpha/database/migrations/2026_12_01_000000_fail.php', '<?php throw new RuntimeException("upgrade failed");');
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    $module = app(ModuleRepository::class)->findOrFail('alpha');
    expect($module->version)->toBe('1.0.0')->and($module->hasFailed())->toBeTrue();
});

it('rejects downgrades and preserves disabled lifecycle state on a successful upgrade', function (): void {
    app(ModuleRepository::class)->upsert('alpha', ['version' => '2.0.0', 'status' => ModuleStatus::Disabled]);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(Schema::hasTable('alpha_items'))->toBeFalse();
    app(ModuleRepository::class)->upsert('alpha', ['version' => '1.0.0']);
    $this->artisan('module:upgrade', ['key' => 'alpha', '--offline' => true])->assertSuccessful();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->status)->toBe(ModuleStatus::Disabled);
});

it('rejects missing and incompatible dependencies before running migrations', function (bool $installed): void {
    $path = $this->upgradeRoot.'/alpha/module.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['requires'] = ['missing' => '^2.0'];
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($installed) {
        $this->seedModule('missing', ['version' => '1.0.0']);
    }
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
})->with([false, true]);

it('retries an explicitly repaired upgrade without rerunning completed migrations', function (): void {
    $failure = $this->upgradeRoot.'/alpha/database/migrations/2026_12_01_000000_fail.php';
    File::put($failure, '<?php throw new RuntimeException("upgrade failed");');
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    File::delete($failure);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertSuccessful();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.2.0')
        ->and(app(ModuleRepository::class)->findOrFail('alpha')->hasFailed())->toBeFalse()
        ->and(DB::table('alpha_items')->count())->toBe(0);
});

it('refuses to upgrade while an installation operation is active', function (): void {
    Queue::fake();
    config()->set('queue.connections.installations', ['driver' => 'database', 'retry_after' => 1500]);
    config()->set('bedrock.modules.operations.connection', 'installations');
    app(InstallationOperations::class)->schedule('alpha');
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
});

it('rejects enabled dependents even when their version constraints accept the upgrade', function (): void {
    $this->seedModule('consumer', ['status' => ModuleStatus::Enabled, 'dependencies' => ['alpha' => '^1.0']]);
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
});

it('rejects dependency cycles before changing database resources', function (): void {
    $path = $this->upgradeRoot.'/alpha/module.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['requires'] = ['alpha' => '^1.0'];
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
});

it('rejects unsupported frontend artifacts before upgrade migrations', function (): void {
    $path = $this->upgradeRoot.'/alpha/module.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['frontend'] = ['driver' => 'inertia-react', 'pages' => 'resources/js/pages', 'artifact' => 'dist/bedrock/frontend.json'];
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->artisan('module:upgrade', ['key' => 'alpha'])->assertFailed();
    expect(app(ModuleRepository::class)->findOrFail('alpha')->version)->toBe('1.0.0')
        ->and(Schema::hasTable('alpha_items'))->toBeFalse();
});
