<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsMarketTransaction extends Model
{
    protected $fillable = [
        'listing_id',
        'seller_player_id',
        'buyer_player_id',
        'listing_type',
        'tool_id',
        'item_key',
        'item_name',
        'rarity',
        'quantity',
        'unit_price',
        'total_price',
        'tool_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'tool_snapshot' => 'array',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsMarketListing::class, 'listing_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'seller_player_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'buyer_player_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsTool::class, 'tool_id');
    }
}
