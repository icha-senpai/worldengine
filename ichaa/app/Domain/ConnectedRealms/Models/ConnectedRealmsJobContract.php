<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsJobContract extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'player_id',
        'job_key',
        'job_name',
        'category',
        'skill',
        'objective_type',
        'objective',
        'required_quantity',
        'progress_quantity',
        'status',
        'accepted_at',
        'completed_at',
    ];

    protected $casts = [
        'objective' => 'array',
        'required_quantity' => 'integer',
        'progress_quantity' => 'integer',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
