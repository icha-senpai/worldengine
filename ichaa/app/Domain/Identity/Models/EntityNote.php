<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class EntityNote extends Model
{
    use SoftDeletes;

    protected $table = 'entity_notes';

    protected $fillable = [
        'entity_id',
        'note_label',
        'content',
        'sort_order',
    ];

    protected $casts = [
        'content'    => 'array', // Tiptap JSON
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    // No visibility field — entity notes are always private by architecture

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeLabeled(Builder $query): Builder
    {
        return $query->whereNotNull('note_label');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }
}
