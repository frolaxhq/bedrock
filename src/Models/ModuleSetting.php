<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $module_key
 * @property string $setting_key
 * @property string|null $value
 * @property bool $encrypted
 */
class ModuleSetting extends Model
{
    protected $table = 'module_settings';

    protected $fillable = ['module_key', 'setting_key', 'value', 'encrypted'];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }

    public function getTable(): string
    {
        $table = config('bedrock.settings.table', parent::getTable());

        return is_string($table) && $table !== '' ? $table : parent::getTable();
    }
}
