<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Permissions\ModulePermissions;
use Frolax\Bedrock\Models\ModulePermission;
use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Spatie\Permission\Models\Permission;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->seedModule('billing', [
        'manifest' => [
            'key' => 'billing',
            'name' => 'Billing',
            'version' => '1.0.0',
            'provider' => 'Modules\\Billing\\BillingServiceProvider',
            'permissions' => [
                'view',
                ['key' => 'manage', 'label' => 'Manage billing', 'description' => 'Change billing configuration.'],
            ],
        ],
    ]);
});

it('synchronizes canonical module permissions with Spatie and the catalog', function (): void {
    $permissions = app(ModulePermissions::class);
    $permissions->sync('billing');

    expect($permissions->name('billing', 'view'))->toBe('billing.view')
        ->and(Permission::findByName('billing.view', 'web'))->not->toBeNull()
        ->and($permissions->active('billing')->pluck('name')->all())->toBe(['billing.manage', 'billing.view'])
        ->and(ModulePermission::query()->where('name', 'billing.manage')->value('label'))->toBe('Manage billing');
});

it('denies inactive module permissions while retaining their Spatie grants', function (): void {
    $permissions = app(ModulePermissions::class);
    $permissions->sync('billing');
    $user = User::factory()->create();
    $user->givePermissionTo('billing.view');

    expect($user->can('billing.view'))->toBeTrue();

    $permissions->deactivate('billing');

    expect($user->can('billing.view'))->toBeFalse()
        ->and($user->getDirectPermissions()->pluck('name')->all())->toContain('billing.view');
});

it('protects the administration role and keeps an administrator assigned', function (): void {
    $manager = app(AdministrationManager::class);
    $first = User::factory()->create();
    $manager->grantAdministrator($first);
    $role = $manager->roles()->firstWhere('name', 'bedrock-admin');

    expect($role)->not->toBeNull();
    expect(fn () => $manager->deleteRole($role))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->syncUserRoles($first, []))->toThrow(InvalidArgumentException::class);
});
