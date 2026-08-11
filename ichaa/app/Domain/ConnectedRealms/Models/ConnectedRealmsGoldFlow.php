<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsGoldFlow extends Model
{
    public const DIRECTION_CREATED = 'created';

    public const DIRECTION_DESTROYED = 'destroyed';

    public const DIRECTION_TRANSFERRED = 'transferred';

    protected $fillable = [
        'player_id',
        'flow_key',
        'direction',
        'source_system',
        'gold',
        'subject_type',
        'subject_id',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'gold' => 'integer',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
