<?php

declare(strict_types=1);

namespace Modules\AuditLog;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Events\Administration\AccessChanged;
use Frolax\Bedrock\Events\Module\ModuleBootFailed;
use Frolax\Bedrock\Events\Module\ModuleDisabled;
use Frolax\Bedrock\Events\Module\ModuleDiscovered;
use Frolax\Bedrock\Events\Module\ModuleEnabled;
use Frolax\Bedrock\Events\Module\ModuleFailed;
use Frolax\Bedrock\Events\Module\ModuleInstalled;
use Frolax\Bedrock\Events\Module\ModuleRecovered;
use Frolax\Bedrock\Events\Module\ModuleUninstalled;
use Frolax\Bedrock\Events\Settings\ModuleSettingsChanged;
use Frolax\Bedrock\Events\Settings\ModuleSettingsReset;
use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;
use Frolax\Bedrock\Services\Administration\AdminPageRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;

class AuditLogServiceProvider extends ModuleServiceProvider
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->moduleKey = 'audit-log';
        $registered = $app->make(ModuleRepository::class)->find('audit-log');
        $this->modulePath = $registered === null ? dirname(__DIR__) : (string) $registered->path;
    }

    public function register(): void
    {
        parent::register();
        $this->app->singleton(AuditRecorder::class);
    }

    public function boot(): void
    {
        parent::boot();

        foreach ([
            ModuleDiscovered::class => 'module.discovered', ModuleInstalled::class => 'module.installed',
            ModuleEnabled::class => 'module.enabled', ModuleDisabled::class => 'module.disabled',
            ModuleUninstalled::class => 'module.uninstalled', ModuleRecovered::class => 'module.recovered',
            ModuleFailed::class => 'module.failed', ModuleBootFailed::class => 'module.boot_failed',
        ] as $class => $action) {
            Event::listen($class, function (object $event) use ($action): void {
                /** @var ModuleDiscovered|ModuleInstalled|ModuleEnabled|ModuleDisabled|ModuleUninstalled|ModuleRecovered|ModuleFailed|ModuleBootFailed $event */
                $metadata = ['version' => $event->manifest->version];

                if ($event instanceof ModuleFailed) {
                    $metadata['failure_code'] = $event->code->value;
                }

                $this->app->make(AuditRecorder::class)->record($action, 'module', $event->key, $event->key, $metadata);
            });
        }

        Event::listen(ModuleSettingsChanged::class, function (ModuleSettingsChanged $event): void {
            $this->app->make(AuditRecorder::class)->record('settings.changed', 'module', $event->module, $event->module, ['keys' => $event->keys]);
        });
        Event::listen(ModuleSettingsReset::class, function (ModuleSettingsReset $event): void {
            $this->app->make(AuditRecorder::class)->record('settings.reset', 'module', $event->module, $event->module, ['keys' => $event->key === null ? [] : [$event->key]]);
        });
        Event::listen(AccessChanged::class, function (AccessChanged $event): void {
            $this->app->make(AuditRecorder::class)->record($event->action, $event->subjectType, $event->subjectId, metadata: $event->metadata);
        });

        if ($this->app->bound(AdminPageRegistry::class) && (bool) config('bedrock.administration.enabled', false)) {
            $this->app->make(AdminPageRegistry::class)->register('audit-log', 'Audit history', 'bedrock.admin.audit-log.index', 'audit-log.view', 'audit-log');
        }
    }
}
