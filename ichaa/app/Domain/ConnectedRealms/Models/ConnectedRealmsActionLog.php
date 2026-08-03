<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsActionLog extends Model
{
    protected $fillable = [
        'player_id',
        'action',
        'skill',
        'platform',
        'result_label',
        'tool_item_key',
        'tool_item_name',
        'event_key',
        'event_label',
        'items_awarded',
        'experience_awarded',
        'gold_awarded',
        'available_at',
    ];

    protected $casts = [
        'items_awarded' => 'array',
        'experience_awarded' => 'integer',
        'gold_awarded' => 'integer',
        'available_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
