<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $key
 * @property string $status
 * @property string|null $active_key
 * @property string|null $from
 * @property string|null $zip
 * @property bool $offline
 * @property string $connection
 * @property string $queue
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class ModuleInstallation extends Model
{
    use HasUuids;

    protected $table = 'module_installations';

    /** @var list<string> */
    protected $fillable = [
        'key', 'status', 'active_key', 'from', 'zip', 'offline',
        'connection', 'queue', 'error', 'started_at', 'finished_at',
    ];

    /** @var list<string> */
    protected $hidden = ['from', 'zip', 'active_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'offline' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'preparing', 'installing'], true);
    }
}
