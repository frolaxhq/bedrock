<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Contracts\Module\ModuleDiagnostics;
use Frolax\Bedrock\DataObjects\Module\DiagnosticCheck;
use JsonException;

class ModuleDoctorCommand extends ModuleCommand
{
    protected $signature = 'module:doctor {key? : Diagnose one module} {--json : Emit stable machine-readable JSON}';

    protected $description = 'Diagnose the module foundation and registered modules';

    /** @throws JsonException */
    public function handle(ModuleDiagnostics $diagnostics): int
    {
        $argument = $this->argument('key');
        $key = is_string($argument) ? $argument : null;
        $report = $diagnostics->diagnose($key);

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Code', 'Status', 'Module', 'Message'], array_map(
                static fn (DiagnosticCheck $check): array => [
                    $check->code,
                    $check->status->value,
                    $check->module ?? '-',
                    $check->message,
                ],
                $report->checks,
            ));
        }

        return $report->ok ? self::SUCCESS : self::FAILURE;
    }
}
