<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsInventoryMigration extends Model
{
    protected $fillable = [
        'player_id',
        'old_stack_id',
        'item_key',
        'item_name',
        'rarity',
        'old_quantity',
        'new_item_key',
        'new_item_name',
        'new_quantity',
        'conversion_ratio',
        'rounding',
        'quantity_remainder',
        'gold_compensation',
        'value_before',
        'value_after',
        'value_delta',
        'action',
        'migration_version',
        'reason',
        'applied_at',
    ];

    protected $casts = [
        'old_quantity' => 'integer',
        'new_quantity' => 'integer',
        'conversion_ratio' => 'float',
        'quantity_remainder' => 'float',
        'gold_compensation' => 'integer',
        'value_before' => 'integer',
        'value_after' => 'integer',
        'value_delta' => 'integer',
        'applied_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsPlayer::class, 'player_id');
    }
}
