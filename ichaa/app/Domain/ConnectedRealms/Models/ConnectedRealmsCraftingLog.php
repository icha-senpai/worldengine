<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsCraftingLog extends Model
{
    protected $fillable = [
        'player_id',
        'recipe_key',
        'recipe_name',
        'skill',
        'items_consumed',
        'items_created',
        'experience_awarded',
        'gold_cost',
    ];

    protected $casts = [
        'items_consumed' => 'array',
        'items_created' => 'array',
        'experience_awarded' => 'integer',
        'gold_cost' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
