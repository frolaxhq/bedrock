<?php

declare(strict_types=1);

use Frolax\Bedrock\Http\Controllers\Administration\DashboardController;
use Frolax\Bedrock\Http\Controllers\Auth\AuthController;
use Frolax\Bedrock\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(HandleInertiaRequests::class)->group(function (): void {
    if (config('bedrock.auth.enabled')) {
        Route::middleware('guest:web')->group(function (): void {
            Route::get('/login', [AuthController::class, 'login'])->name('login');
            Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:30,1');
            Route::get('/register', [AuthController::class, 'registration'])->name('register');
            Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
            Route::get('/forgot-password', [AuthController::class, 'forgot'])->name('password.request');
            Route::post('/forgot-password', [AuthController::class, 'sendReset'])->middleware('throttle:6,1')->name('password.email');
            Route::get('/reset-password/{token}', [AuthController::class, 'reset'])->name('password.reset');
            Route::post('/reset-password', [AuthController::class, 'updatePasswordFromToken'])->middleware('throttle:6,1')->name('password.store');
        });
        Route::middleware('auth:web')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/verify-email', [AuthController::class, 'notice'])->name('verification.notice');
            Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
            Route::post('/email/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
            Route::get('/profile', [AuthController::class, 'profile'])->name('profile.show');
            Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
            Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
        });
    }

    if (config('bedrock.admin.enabled')) {
        Route::get('/'.trim((string) config('bedrock.admin.path', 'admin'), '/'), DashboardController::class)
            ->middleware(['auth:web', 'can:'.config('bedrock.permissions.admin_permission', 'bedrock.admin')])->name('bedrock.dashboard');
    }
});
