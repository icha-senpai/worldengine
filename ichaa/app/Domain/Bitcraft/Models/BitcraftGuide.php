<?php

namespace App\Domain\Bitcraft\Models;

use App\Models\User;
use Database\Factories\BitcraftGuideFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(BitcraftGuideFactory::class)]
class BitcraftGuide extends Model
{
    /** @use HasFactory<BitcraftGuideFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'summary',
        'category',
        'content',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
