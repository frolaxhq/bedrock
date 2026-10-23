<?php

declare(strict_types=1);

namespace Modules\AuditLog;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEntry extends Model
{
    protected $table = 'bedrock_audit_entries';

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit entries are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Audit entries are append-only.');
        });
    }
}
