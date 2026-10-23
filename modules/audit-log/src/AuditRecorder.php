<?php

declare(strict_types=1);

namespace Modules\AuditLog;

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AuditRecorder
{
    public function __construct(protected ModuleRepository $modules) {}

    /** @param array<string, mixed> $metadata */
    public function record(string $event, string $subjectType, ?string $subjectId = null, ?string $module = null, array $metadata = []): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/', $event) || strlen($subjectType) > 128
            || strlen($subjectId ?? '') > 255 || strlen($module ?? '') > 255) {
            throw new InvalidArgumentException('Audit event or subject identity is invalid.');
        }

        try {
            if ($this->modules->find('audit-log')?->isEnabled() !== true) {
                return;
            }

            $actor = Auth::user();
            $entry = [
                'event' => $event,
                'occurred_at' => now(),
                'actor_type' => $actor === null ? null : $actor::class,
                'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'module_key' => $module,
                'metadata' => json_encode($this->metadata($metadata), JSON_THROW_ON_ERROR),
            ];

            DB::afterCommit(static function () use ($entry, $event): void {
                try {
                    DB::table('bedrock_audit_entries')->insert($entry);
                } catch (Throwable $exception) {
                    Log::error('Bedrock audit storage failed.', ['event' => $event, 'exception_type' => $exception::class]);
                }
            });
        } catch (Throwable $exception) {
            Log::error('Bedrock audit storage failed.', ['event' => $event, 'exception_type' => $exception::class]);
        }
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function metadata(array $metadata): array
    {
        $allowed = (array) config('audit-log.metadata_keys', []);
        $result = [];

        foreach ($metadata as $key => $value) {
            if (! in_array($key, $allowed, true)
                || preg_match('/password|secret|token|cookie|authorization|request|payload|exception/i', $key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
            } elseif (is_array($value) && array_is_list($value)) {
                $result[$key] = array_slice(array_filter($value, 'is_scalar'), 0, 100);
            }
        }

        return $result;
    }
}
