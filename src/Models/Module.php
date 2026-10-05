<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Models;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\ModuleFailureCode;
use Frolax\Bedrock\Enums\Module\ModuleHealth;
use Frolax\Bedrock\Enums\Module\ModuleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A module recorded in the Bedrock registry.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $version
 * @property string $provider
 * @property string $path
 * @property ModuleStatus $status
 * @property ModuleHealth $health
 * @property ModuleFailureCode|null $failure_code
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $manifest
 * @property array<string, string>|null $dependencies
 * @property Carbon|null $discovered_at
 * @property Carbon|null $installed_at
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $failed_at
 */
class Module extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'discovered',
        'health' => 'healthy',
    ];

    /**
     * The table associated with the model.
     */
    protected $table = 'modules';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'version',
        'provider',
        'path',
        'status',
        'health',
        'failure_code',
        'failure_reason',
        'manifest',
        'dependencies',
        'discovered_at',
        'installed_at',
        'enabled_at',
        'disabled_at',
        'failed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'dependencies' => 'array',
            'status' => ModuleStatus::class,
            'health' => ModuleHealth::class,
            'failure_code' => ModuleFailureCode::class,
            'discovered_at' => 'datetime',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the module is active in the application runtime.
     */
    public function isEnabled(): bool
    {
        return $this->status === ModuleStatus::Enabled && $this->health === ModuleHealth::Healthy;
    }

    /**
     * Determine whether the module needs attention.
     */
    public function hasFailed(): bool
    {
        return $this->health === ModuleHealth::Failed;
    }

    /**
     * The manifest snapshot stored with the registry row.
     */
    public function toManifest(): Manifest
    {
        /** @var array<string, mixed> $data */
        $data = is_array($this->manifest) ? $this->manifest : [];

        return Manifest::from($data);
    }

    public function getTable(): string
    {
        $table = config('bedrock.modules.table', parent::getTable());

        return is_string($table) && $table !== '' ? $table : parent::getTable();
    }
}
