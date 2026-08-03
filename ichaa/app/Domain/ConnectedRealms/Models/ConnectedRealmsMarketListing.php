<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsMarketListing extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SOLD = 'sold';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_ITEM = 'item';

    public const TYPE_TOOL = 'tool';

    protected $fillable = [
        'seller_player_id',
        'listing_type',
        'tool_id',
        'item_key',
        'item_name',
        'rarity',
        'quantity',
        'unit_price',
        'tool_snapshot',
        'status',
        'sold_at',
        'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'tool_snapshot' => 'array',
        'sold_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'seller_player_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsTool::class, 'tool_id');
    }
}
