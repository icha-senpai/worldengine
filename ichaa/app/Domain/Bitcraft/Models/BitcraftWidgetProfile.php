<?php

namespace App\Domain\Bitcraft\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BitcraftWidgetProfile extends Model
{
    protected $fillable = [
        'user_id',
        'widget',
        'source',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
