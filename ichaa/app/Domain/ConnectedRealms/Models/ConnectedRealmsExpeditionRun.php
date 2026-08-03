<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsExpeditionRun extends Model
{
    protected $fillable = [
        'player_id',
        'expedition_key',
        'expedition_name',
        'status',
        'supplies_consumed',
        'items_awarded',
        'experience_awarded',
        'gold_awarded',
        'resolved_at',
    ];

    protected $casts = [
        'supplies_consumed' => 'array',
        'items_awarded' => 'array',
        'experience_awarded' => 'integer',
        'gold_awarded' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
