<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsVendorSale extends Model
{
    protected $fillable = [
        'player_id',
        'vendor_key',
        'vendor_name',
        'item_key',
        'item_name',
        'rarity',
        'quantity',
        'unit_price',
        'total_price',
        'item_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'item_snapshot' => 'array',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
