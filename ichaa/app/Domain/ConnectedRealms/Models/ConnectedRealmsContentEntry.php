<?php

namespace App\Domain\ConnectedRealms\Models;

use App\Domain\ConnectedRealms\Services\ConnectedRealmsContentService;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedRealmsContentEntry extends Model
{
    protected $fillable = [
        'surface',
        'entry_key',
        'label',
        'category',
        'required_level',
        'rarity',
        'enabled',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'required_level' => 'integer',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $entry): mixed => ConnectedRealmsContentService::forgetSurface($entry->surface));
        static::deleted(fn (self $entry): mixed => ConnectedRealmsContentService::forgetSurface($entry->surface));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
