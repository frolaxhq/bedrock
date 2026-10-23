<?php

declare(strict_types=1);

use Frolax\Bedrock\Http\Middleware\RequireAdministrationEnabled;
use Frolax\Bedrock\Services\Administration\AdminPageRegistry;
use Illuminate\Support\Facades\Route;
use Modules\AuditLog\Http\AuditController;

if (app()->bound(AdminPageRegistry::class) && (bool) config('bedrock.administration.enabled', false)) {
    Route::get('/bedrock/admin/audit-log', [AuditController::class, 'index'])
        ->middleware([RequireAdministrationEnabled::class, 'web', 'auth:'.config('bedrock.permissions.guard', 'web'),
            'can:'.config('bedrock.permissions.admin_permission', 'bedrock.admin'), 'can:audit-log.view'])
        ->name('bedrock.admin.audit-log.index');
}
