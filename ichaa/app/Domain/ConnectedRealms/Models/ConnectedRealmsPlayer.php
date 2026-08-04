<?php

namespace App\Domain\ConnectedRealms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedRealmsPlayer extends Model
{
    protected $fillable = [
        'user_id',
        'display_name',
        'title',
        'species',
        'pronouns',
        'home_region',
        'appearance',
        'reward_loadout',
        'gold',
        'last_action_at',
        'next_action_at',
    ];

    protected $casts = [
        'appearance' => 'array',
        'reward_loadout' => 'array',
        'gold' => 'integer',
        'last_action_at' => 'datetime',
        'next_action_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(ConnectedRealmsPlayerSkill::class, 'player_id');
    }

    public function inventoryStacks(): HasMany
    {
        return $this->hasMany(ConnectedRealmsInventoryStack::class, 'player_id');
    }

    public function equipmentSlots(): HasMany
    {
        return $this->hasMany(ConnectedRealmsEquipmentSlot::class, 'player_id');
    }

    public function tools(): HasMany
    {
        return $this->hasMany(ConnectedRealmsTool::class, 'player_id');
    }

    public function craftingLogs(): HasMany
    {
        return $this->hasMany(ConnectedRealmsCraftingLog::class, 'player_id');
    }

    public function jobCompletions(): HasMany
    {
        return $this->hasMany(ConnectedRealmsJobCompletion::class, 'player_id');
    }

    public function marketListings(): HasMany
    {
        return $this->hasMany(ConnectedRealmsMarketListing::class, 'seller_player_id');
    }

    public function marketPurchases(): HasMany
    {
        return $this->hasMany(ConnectedRealmsMarketTransaction::class, 'buyer_player_id');
    }

    public function vendorSales(): HasMany
    {
        return $this->hasMany(ConnectedRealmsVendorSale::class, 'player_id');
    }

    public function achievementClaims(): HasMany
    {
        return $this->hasMany(ConnectedRealmsAchievementClaim::class, 'player_id');
    }

    public function expeditionRuns(): HasMany
    {
        return $this->hasMany(ConnectedRealmsExpeditionRun::class, 'player_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ConnectedRealmsActionLog::class, 'player_id');
    }
}
