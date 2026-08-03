<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsInventoryStack extends Model
{
    protected $fillable = [
        'player_id',
        'item_key',
        'item_name',
        'rarity',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
