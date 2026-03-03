<?php

namespace App\Domain\Lore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class DocumentEntity extends Model
{
    protected $table = 'document_entities';

    protected $fillable = [
        'document_id',
        'entity_id',
        'relationship_type',
        'notes',
    ];

    protected $casts = [
        'notes' => 'array', // Tiptap JSON
    ];

    const RELATIONSHIP_TYPES = [
        'author',
        'true_author',
        'subject',
        'signatory',
        'witness',
        'recipient',
        'forger',
        'suppressor',
        'contradicts',
        'sealed_by',
        'referenced',
    ];

    // --- RELATIONSHIPS ---

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeOfRelationshipType(Builder $query, string $type): Builder
    {
        return $query->where('relationship_type', $type);
    }

    public function scopeAuthors(Builder $query): Builder
    {
        return $query->whereIn('relationship_type', ['author', 'true_author']);
    }

    public function scopeSubjects(Builder $query): Builder
    {
        return $query->where('relationship_type', 'subject');
    }
}
