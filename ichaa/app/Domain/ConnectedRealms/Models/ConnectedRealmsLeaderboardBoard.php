<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedRealmsLeaderboardBoard extends Model
{
    protected $fillable = [
        'season_id',
        'key',
        'group_key',
        'group_label',
        'label',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(ConnectedRealmsLeaderboardSeason::class, 'season_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ConnectedRealmsLeaderboardEntry::class, 'board_id');
    }
}
