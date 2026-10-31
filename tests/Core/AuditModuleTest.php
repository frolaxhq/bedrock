<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleManager;
use Frolax\Bedrock\Services\Administration\AdminPageRegistry;
use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Frolax\Bedrock\Services\Module\ModuleBootstrapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\AuditRecorder;

it('installs a validated audit-log ZIP on core alone and preserves its records on uninstall', function (): void {
    $root = $this->tempDirectory('core-audit');
    config()->set('bedrock.modules.path', $root);
    config()->set('bedrock.core.version', '1.0.0');

    try {
        $archive = app(ModulePackager::class)->pack(dirname(__DIR__, 2).'/modules/audit-log', $root.'/artifacts');
        $source = new ZipSource(app('files'), $archive);
        app(ModuleManager::class)->installFrom($source, 'audit-log');
        expect(Schema::hasTable('bedrock_audit_entries'))->toBeTrue()
            ->and(app()->bound(AdminPageRegistry::class))->toBeTrue();

        app(ModuleManager::class)->enable('audit-log');
        app(ModuleBootstrapper::class)->bootstrap();
        app(AuditRecorder::class)->record('invoice.approved', 'invoice', '4b6927bc-4bed-4039-b5f1-41ca41908603');
        $count = DB::table('bedrock_audit_entries')->count();
        expect($count)->toBe(1);

        app(ModuleManager::class)->disable('audit-log');
        app(ModuleManager::class)->uninstall('audit-log');
        expect(DB::table('bedrock_audit_entries')->count())->toBe($count)
            ->and(File::exists($root.'/audit-log'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});
