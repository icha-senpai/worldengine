<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsJobCompletion extends Model
{
    protected $fillable = [
        'player_id',
        'job_key',
        'job_name',
        'category',
        'items_delivered',
        'rewards',
        'experience_awarded',
        'gold_awarded',
    ];

    protected $casts = [
        'items_delivered' => 'array',
        'rewards' => 'array',
        'experience_awarded' => 'integer',
        'gold_awarded' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
