<?php

declare(strict_types=1);

use Frolax\Bedrock\Http\Controllers\Administration\ModuleController;
use Frolax\Bedrock\Http\Controllers\Administration\RoleController;
use Frolax\Bedrock\Http\Controllers\Administration\SettingController;
use Frolax\Bedrock\Http\Controllers\Administration\UserController;
use Frolax\Bedrock\Http\Middleware\RequireAdministrationEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireAdministrationEnabled::class,
    'web',
    'auth:'.config('bedrock.permissions.guard', 'web'),
    'can:'.config('bedrock.permissions.admin_permission', 'bedrock.admin'),
])->prefix('bedrock/admin')->name('bedrock.admin.')->group(function (): void {
    Route::get('/', fn () => redirect()->route('bedrock.admin.modules.index'))->name('index');
    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::get('/modules/{key}', [ModuleController::class, 'show'])->name('modules.show');
    Route::post('/modules/{key}/install', [ModuleController::class, 'install'])->name('modules.install');
    Route::post('/modules/{key}/enable', [ModuleController::class, 'enable'])->name('modules.enable');
    Route::post('/modules/{key}/disable', [ModuleController::class, 'disable'])->name('modules.disable');
    Route::get('/modules/{key}/settings', [SettingController::class, 'show'])->name('settings.show');
    Route::post('/modules/{key}/settings/{setting}', [SettingController::class, 'update'])->name('settings.update');
    Route::post('/modules/{key}/settings/{setting}/reset', [SettingController::class, 'reset'])->name('settings.reset');
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::post('/roles/{role}/permissions', [RoleController::class, 'permissions'])->name('roles.permissions');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/roles', [UserController::class, 'roles'])->name('users.roles');
});
