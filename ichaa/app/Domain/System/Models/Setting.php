<?php

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    public $timestamps = false;

    protected $fillable = [
        'entity_type_templates',
        'notification_preferences',
    ];

    protected $casts = [
        'entity_type_templates' => 'array',
        'notification_preferences' => 'array',
    ];

    public static function singleton(): self
    {
        return static::query()->findOrFail(1);
    }

    public function notificationFlag(string $key, bool $default = false): bool
    {
        return (bool) data_get($this->notification_preferences ?? [], $key, $default);
    }
}
