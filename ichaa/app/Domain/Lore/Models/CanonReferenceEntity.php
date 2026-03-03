<?php

namespace App\Domain\Lore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class CanonReferenceEntity extends Model
{
    protected $table = 'canon_reference_entities';

    protected $fillable = [
        'canon_reference_id',
        'entity_id',
        'divergence_level',
        'relationship_type',
        'divergence_notes',
    ];

    protected $casts = [
        'divergence_notes' => 'array', // Tiptap JSON
    ];

    const DIVERGENCE_LEVELS = [
        'minimal',
        'moderate',
        'significant',
        'complete_reimagining',
    ];

    const RELATIONSHIP_TYPES = [
        'au_version',       // Direct AU counterpart
        'inspired_by',      // Draws from but is not a direct version
        'references',       // Mentions or references the canonical element
    ];

    // --- RELATIONSHIPS ---

    public function canonReference(): BelongsTo
    {
        return $this->belongsTo(SourceCanonReference::class, 'canon_reference_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeDirectVersions(Builder $query): Builder
    {
        return $query->where('relationship_type', 'au_version');
    }

    public function scopeByDivergence(Builder $query, string $level): Builder
    {
        return $query->where('divergence_level', $level);
    }

    public function scopeCompleteReimagining(Builder $query): Builder
    {
        return $query->where('divergence_level', 'complete_reimagining');
    }
}
