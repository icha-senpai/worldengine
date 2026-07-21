<?php

namespace App\Domain\Bitcraft\Models;

use Illuminate\Database\Eloquent\Model;

class BitcraftWidgetProfile extends Model
{
    protected $fillable = [
        'widget',
        'source',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];
}
