<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsTool extends Model
{
    public const STATUS_EQUIPPED = 'equipped';

    public const STATUS_INVENTORY = 'inventory';

    public const STATUS_LISTED = 'listed';

    protected $fillable = [
        'player_id',
        'slot',
        'skill',
        'item_key',
        'item_name',
        'rarity',
        'durability',
        'bonuses',
        'rarity_progress',
        'origin',
        'status',
        'maker_name',
        'tier_level',
        'upgrade_count',
        'tier_upgrade_count',
        'rarity_upgrade_attempts',
    ];

    protected $casts = [
        'bonuses' => 'array',
        'durability' => 'integer',
        'rarity_progress' => 'integer',
        'tier_level' => 'integer',
        'upgrade_count' => 'integer',
        'tier_upgrade_count' => 'integer',
        'rarity_upgrade_attempts' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
