<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $module_key
 * @property string $permission_key
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property bool $active
 */
class ModulePermission extends Model
{
    protected $table = 'module_permissions';

    protected $fillable = ['module_key', 'permission_key', 'name', 'label', 'description', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function getTable(): string
    {
        $table = config('bedrock.permissions.catalog_table', parent::getTable());

        return is_string($table) && $table !== '' ? $table : parent::getTable();
    }
}
