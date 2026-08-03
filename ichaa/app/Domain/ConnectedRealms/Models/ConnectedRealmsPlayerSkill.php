<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsPlayerSkill extends Model
{
    protected $fillable = [
        'player_id',
        'skill',
        'level',
        'experience',
    ];

    protected $casts = [
        'level' => 'integer',
        'experience' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
