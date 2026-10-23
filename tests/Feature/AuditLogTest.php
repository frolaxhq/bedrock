<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleAutoloader;
use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Events\Module\ModuleBootFailed;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\AuditEntry;
use Modules\AuditLog\AuditLogServiceProvider;
use Modules\AuditLog\AuditRecorder;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $path = dirname(__DIR__, 2).'/modules/audit-log';
    $manifest = $this->loader()->load($path.'/module.json');
    app(ModuleAutoloader::class)->register($manifest, $path);
    $this->seedModule('audit-log', ['manifest' => $manifest->toArray(), 'status' => ModuleStatus::Enabled, 'path' => $path]);
    app()->register(AuditLogServiceProvider::class);
    $migration = require $path.'/database/migrations/2026_10_08_000000_create_bedrock_audit_entries_table.php';
    $migration->up();
});

it('records module events and only the keys of changed settings', function (): void {
    $this->seedModule('billing', ['manifest' => [
        'key' => 'billing', 'name' => 'Billing', 'version' => '1.0.0', 'provider' => 'Some\\Provider',
        'settings' => ['api_key' => ['type' => 'secret']],
    ]]);
    event(new ModuleEnabled('billing', Manifest::from(['key' => 'billing', 'version' => '1.0.0'])));
    app(ModuleSettings::class)->set('billing', 'api_key', 'never-log-this-secret');

    expect(DB::table('bedrock_audit_entries')->pluck('event')->all())->toContain('module.enabled', 'settings.changed')
        ->and(json_encode(DB::table('bedrock_audit_entries')->get(), JSON_THROW_ON_ERROR))->not->toContain('never-log-this-secret');
});

it('records access operations and authenticated actors', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(AdministrationManager::class)->createRole('auditor');

    $entry = DB::table('bedrock_audit_entries')->where('event', 'role.created')->first();
    expect($entry)->not->toBeNull()->and($entry->actor_id)->toBe((string) $user->getKey());
});

it('records initial administrator role setup without reporting unchanged role setup', function (): void {
    $access = app(AdministrationManager::class);
    $access->ensureAdminRole();
    expect(DB::table('bedrock_audit_entries')->pluck('event')->all())
        ->toBe(['role.created', 'role.permissions_changed']);

    $access->ensureAdminRole();
    expect(DB::table('bedrock_audit_entries')->count())->toBe(2);
});

it('records boot failure identity without exception payloads or reasons', function (): void {
    event(new ModuleBootFailed('billing', Manifest::from(['key' => 'billing', 'version' => '1.0.0']),
        'Database password is a-secret-value', new RuntimeException('Raw request token is sensitive')));

    $entry = DB::table('bedrock_audit_entries')->where('event', 'module.boot_failed')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->module_key)->toBe('billing')
        ->and(json_decode($entry->metadata, true, 512, JSON_THROW_ON_ERROR))->toBe(['version' => '1.0.0']);
});

it('defers records until commit and drops records on rollback', function (): void {
    DB::beginTransaction();
    app(AuditRecorder::class)->record('invoice.approved', 'invoice', 'uuid-123');
    expect(DB::table('bedrock_audit_entries')->count())->toBe(0);
    DB::rollBack();
    expect(DB::table('bedrock_audit_entries')->count())->toBe(0);

    DB::transaction(fn () => app(AuditRecorder::class)->record('invoice.approved', 'invoice', 'uuid-123'));
    expect(DB::table('bedrock_audit_entries')->count())->toBe(1);
});

it('filters custom metadata and isolates audit storage failures', function (): void {
    app(AuditRecorder::class)->record('invoice.approved', 'invoice', '1', metadata: [
        'keys' => ['currency'], 'password' => 'secret', 'request_body' => ['token' => 'secret'],
    ]);
    expect(json_decode(DB::table('bedrock_audit_entries')->value('metadata'), true, 512, JSON_THROW_ON_ERROR))->toBe(['keys' => ['currency']]);

    Log::spy();
    Schema::drop('bedrock_audit_entries');
    $role = app(AdministrationManager::class)->createRole('storage-outage');
    expect($role->exists)->toBeTrue()
        ->and(DB::table('roles')->where('name', 'storage-outage')->exists())->toBeTrue();
    Log::shouldHaveReceived('error')->once();
});

it('records successful role and assignment changes but not denied access operations', function (): void {
    $access = app(AdministrationManager::class);
    $role = $access->createRole('auditor');
    $user = User::factory()->create();
    $access->grantAdministrator($user);
    $access->syncRolePermissions($role, ['bedrock.admin']);
    $access->syncUserRoles($user, ['bedrock-admin', 'auditor']);
    $count = DB::table('bedrock_audit_entries')->count();

    expect(fn () => $access->syncRolePermissions($role, ['unknown.permission']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $access->syncUserRoles($user, []))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('bedrock_audit_entries')->count())->toBe($count);

    $access->syncUserRoles($user, ['bedrock-admin']);
    $access->deleteRole($role);
    expect(DB::table('bedrock_audit_entries')->pluck('event')->all())
        ->toContain('role.created', 'role.permissions_changed', 'role.deleted', 'user.roles_changed', 'user.administrator_granted');
});

it('protects audit screens and removes module access when disabled', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    app(ModulePermissions::class)->sync('audit-log');
    $this->actingAs($user)->get('/bedrock/admin/audit-log')->assertForbidden();
    $user->givePermissionTo('audit-log.view');
    $this->actingAs($user)->get('/bedrock/admin/audit-log')->assertOk()->assertSee('Audit history');
    config()->set('bedrock.administration.enabled', false);
    $this->actingAs($user)->get('/bedrock/admin/audit-log')->assertNotFound();
    config()->set('bedrock.administration.enabled', true);
    $count = DB::table('bedrock_audit_entries')->count();

    app(ModuleManager::class)->disable('audit-log');
    $this->actingAs($user)->get('/bedrock/admin/audit-log')->assertNotFound();
    app(AuditRecorder::class)->record('invoice.approved', 'invoice', '1');
    expect(DB::table('bedrock_audit_entries')->count())->toBe($count);
});

it('supports UUID actors and prevents edits to recorded entries', function (): void {
    $user = new User;
    $user->setKeyType('string');
    $user->setAttribute('id', 'cf5e144a-cba0-422e-a8fe-f7a0f7c9b231');
    $this->actingAs($user);
    app(AuditRecorder::class)->record('invoice.approved', 'invoice', '123');

    expect(DB::table('bedrock_audit_entries')->value('actor_id'))->toBe('cf5e144a-cba0-422e-a8fe-f7a0f7c9b231');
    $entry = AuditEntry::query()->firstOrFail();
    expect(fn () => $entry->forceFill(['event' => 'rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
