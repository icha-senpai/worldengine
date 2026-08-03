<?php

namespace App\Domain\ConnectedRealms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedRealmsLeaderboardSeason extends Model
{
    protected $fillable = [
        'key',
        'name',
        'active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function boards(): HasMany
    {
        return $this->hasMany(ConnectedRealmsLeaderboardBoard::class, 'season_id');
    }
}
