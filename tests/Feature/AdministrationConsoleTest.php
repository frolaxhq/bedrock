<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Contracts\Settings\ModuleSettings;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Frolax\Bedrock\Jobs\InstallModule;
use Frolax\Bedrock\Models\ModuleInstallation;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Frolax\Bedrock\Services\Module\Operations\InstallationOperations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

it('keeps the administration console behind authentication and the configured permission', function (): void {
    Route::get('/login', fn () => 'login')->name('login');

    $this->get('/bedrock/admin/modules')->assertRedirect('/login');

    $user = User::factory()->create();
    $this->actingAs($user)->get('/bedrock/admin/modules')->assertForbidden();
    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/enable')->assertForbidden();

    app(AdministrationManager::class)->grantAdministrator($user);

    $this->actingAs($user)->get('/bedrock/admin/modules')->assertOk()->assertSee('Modules');
});

it('enforces explicit administration enablement even with cached routes', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    $router = app('router');
    $router->setCompiledRoutes($router->getRoutes()->compile());
    $this->actingAs($user)->get('/bedrock/admin/modules')->assertOk();

    config()->set('bedrock.administration.enabled', false);
    $this->actingAs($user)->get('/bedrock/admin/modules')->assertNotFound();
    $this->actingAs($user)->post('/bedrock/admin/roles', ['name' => 'disabled-console'])->assertNotFound();
});

it('shows registered module health and only performs lifecycle changes by POST', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    $this->seedModule('alpha', ['status' => ModuleStatus::Installed]);

    $this->actingAs($user)->get('/bedrock/admin/modules')
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('installed');

    $this->actingAs($user)->get('/bedrock/admin/modules/alpha')
        ->assertOk()
        ->assertSee('Health checks')
        ->assertSee('alpha');

    $this->actingAs($user)->get('/bedrock/admin/modules/alpha/enable')->assertMethodNotAllowed();
    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/enable')->assertRedirect('/bedrock/admin/modules/alpha');

    expect(app(ModuleRepository::class)->findOrFail('alpha')->status)->toBe(ModuleStatus::Enabled);

    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/disable')->assertRedirect('/bedrock/admin/modules/alpha');

    expect(app(ModuleRepository::class)->findOrFail('alpha')->status)->toBe(ModuleStatus::Disabled);
});

it('reports lifecycle failures without exposing them as server errors', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);

    $this->actingAs($user)->post('/bedrock/admin/modules/missing/enable')
        ->assertRedirect('/bedrock/admin/modules')
        ->assertSessionHasErrors('operation');

    $this->actingAs($user)->get('/bedrock/admin/modules/missing')->assertNotFound();
});

it('queues a discovered module and shows progress before registration', function (): void {
    Queue::fake();
    config()->set('queue.connections.installations', ['driver' => 'database']);
    config()->set('bedrock.modules.operations.connection', 'installations');
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);

    $this->actingAs($user)->get('/bedrock/admin/modules')->assertOk()->assertSee('Available on disk');
    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/install')
        ->assertRedirect('/bedrock/admin/modules/alpha');

    expect(app(ModuleRepository::class)->find('alpha'))->toBeNull();
    $installation = ModuleInstallation::query()->where('key', 'alpha')->sole();
    expect($installation->status)->toBe('queued');
    Queue::assertPushedOn('bedrock-install', InstallModule::class);
    $this->actingAs($user)->get('/bedrock/admin/modules/alpha')
        ->assertOk()->assertSee('queued')->assertSee($installation->id);
    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/install')
        ->assertRedirect('/bedrock/admin/modules')->assertSessionHasErrors('operation');

    app(InstallationOperations::class)->fail($installation->id);
    $this->actingAs($user)->get('/bedrock/admin/modules/alpha')
        ->assertOk()->assertSee('failed')->assertSee('not retried automatically');
    $this->actingAs($user)->get('/bedrock/admin/modules')->assertOk()->assertSee($installation->id);

    $next = app(InstallationOperations::class)->schedule('alpha');
    $this->actingAs($user)->get('/bedrock/admin/modules/alpha')
        ->assertOk()->assertSee($next->id)->assertDontSee($installation->id)->assertSee('queued');
});

it('reports an unavailable async queue without installing in the HTTP process', function (): void {
    config()->set('bedrock.modules.operations.connection', 'sync');
    config()->set('queue.connections.sync.driver', 'sync');
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);

    $this->actingAs($user)->post('/bedrock/admin/modules/alpha/install')
        ->assertRedirect('/bedrock/admin/modules')->assertSessionHasErrors('operation');

    expect(ModuleInstallation::query()->count())->toBe(0)
        ->and(app(ModuleRepository::class)->find('alpha'))->toBeNull();
});

it('manages roles using only active Bedrock permissions', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    $this->seedModule('billing', ['manifest' => [
        'key' => 'billing', 'name' => 'Billing', 'version' => '1.0.0',
        'provider' => 'Modules\\Billing\\BillingServiceProvider', 'permissions' => ['view'],
    ]]);
    app(ModulePermissions::class)->sync('billing');

    $this->actingAs($user)->get('/bedrock/admin/roles')->assertOk()->assertSee('billing.view');
    $this->actingAs($user)->post('/bedrock/admin/roles', ['name' => 'billing-operator'])
        ->assertRedirect('/bedrock/admin/roles');
    $role = app(AdministrationManager::class)->roles()->firstWhere('name', 'billing-operator');

    expect($role)->not->toBeNull();

    $this->actingAs($user)->post('/bedrock/admin/roles/'.$role->getKey().'/permissions', ['permissions' => ['billing.view']])
        ->assertRedirect('/bedrock/admin/roles');

    expect($role->fresh()->hasPermissionTo('billing.view'))->toBeTrue();

    $this->actingAs($user)->post('/bedrock/admin/roles/'.$role->getKey().'/permissions', ['permissions' => ['unmanaged.permission']])
        ->assertSessionHasErrors('operation');

    $adminRole = app(AdministrationManager::class)->roles()->firstWhere('name', 'bedrock-admin');
    $this->actingAs($user)->post('/bedrock/admin/roles/'.$adminRole->getKey().'/permissions', ['permissions' => []])
        ->assertSessionHasErrors('operation');
});

it('edits and resets declared module settings without exposing secret values', function (): void {
    $user = User::factory()->create();
    app(AdministrationManager::class)->grantAdministrator($user);
    $this->seedModule('billing', ['manifest' => [
        'key' => 'billing', 'name' => 'Billing', 'version' => '1.0.0',
        'provider' => 'Modules\\Billing\\BillingServiceProvider',
        'settings' => ['currency' => ['type' => 'text', 'default' => 'USD'], 'api_key' => ['type' => 'secret']],
    ]]);

    $this->actingAs($user)->get('/bedrock/admin/modules/billing/settings')->assertOk()->assertSee('currency');
    $this->actingAs($user)->post('/bedrock/admin/modules/billing/settings/currency', ['value' => 'EUR'])
        ->assertRedirect('/bedrock/admin/modules/billing/settings');
    $this->actingAs($user)->post('/bedrock/admin/modules/billing/settings/api_key', ['value' => 'secret-token'])
        ->assertRedirect('/bedrock/admin/modules/billing/settings');

    expect(app(ModuleSettings::class)->get('billing', 'currency'))->toBe('EUR')
        ->and(app(ModuleSettings::class)->get('billing', 'api_key'))->toBe('secret-token');

    $this->actingAs($user)->get('/bedrock/admin/modules/billing/settings')->assertOk()->assertDontSee('secret-token');
    $this->actingAs($user)->post('/bedrock/admin/modules/billing/settings/unknown', ['value' => 'x'])->assertNotFound();
    $this->actingAs($user)->post('/bedrock/admin/modules/billing/settings/currency/reset')
        ->assertRedirect('/bedrock/admin/modules/billing/settings');

    expect(app(ModuleSettings::class)->get('billing', 'currency'))->toBe('USD');
});

it('assigns roles to host users and preserves the last administrator', function (): void {
    $administrator = User::factory()->create();
    $operator = User::factory()->create();
    $administration = app(AdministrationManager::class);
    $administration->grantAdministrator($administrator);
    $administration->createRole('operator');

    $this->actingAs($administrator)->get('/bedrock/admin/users')->assertOk()->assertSee($operator->email);
    $this->actingAs($administrator)->post('/bedrock/admin/users/'.$operator->getKey().'/roles', ['roles' => ['operator']])
        ->assertRedirect('/bedrock/admin/users');

    expect($operator->fresh()->hasRole('operator'))->toBeTrue();

    $this->actingAs($administrator)->post('/bedrock/admin/users/'.$administrator->getKey().'/roles', ['roles' => []])
        ->assertSessionHasErrors('operation');

    expect($administrator->fresh()->hasRole('bedrock-admin'))->toBeTrue();
});
