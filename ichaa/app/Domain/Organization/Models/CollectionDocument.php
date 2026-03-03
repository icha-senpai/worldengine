<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Lore\Models\Document;

class CollectionDocument extends Model
{
    protected $table = 'collection_documents';

    protected $fillable = [
        'collection_id',
        'document_id',
        'role_in_collection',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // --- RELATIONSHIPS ---

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    // --- SCOPES ---

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->where('role_in_collection', $role);
    }
}
