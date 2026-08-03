<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsLeaderboardEntry extends Model
{
    protected $fillable = [
        'board_id',
        'player_id',
        'rank',
        'display_name',
        'species_label',
        'skill',
        'skill_label',
        'score',
        'score_label',
        'detail',
        'metrics',
        'recorded_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'score' => 'integer',
        'metrics' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsLeaderboardBoard::class, 'board_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
