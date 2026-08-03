<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsEquipmentSlot extends Model
{
    protected $fillable = [
        'player_id',
        'tool_id',
        'slot',
        'item_key',
        'item_name',
        'rarity',
        'durability',
        'bonuses',
        'rarity_progress',
        'origin',
        'maker_name',
        'tier_level',
        'upgrade_count',
        'rarity_upgrade_attempts',
    ];

    protected $casts = [
        'bonuses' => 'array',
        'durability' => 'integer',
        'rarity_progress' => 'integer',
        'tier_level' => 'integer',
        'upgrade_count' => 'integer',
        'rarity_upgrade_attempts' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsTool::class, 'tool_id');
    }
}
