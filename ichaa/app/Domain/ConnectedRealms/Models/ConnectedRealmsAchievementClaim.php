<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsAchievementClaim extends Model
{
    protected $fillable = [
        'player_id',
        'achievement_key',
        'achievement_label',
        'category',
        'reward',
        'claimed_at',
    ];

    protected $casts = [
        'reward' => 'array',
        'claimed_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
