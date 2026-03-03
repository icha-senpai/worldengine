<?php

namespace App\Domain\Lore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class SourceCanonReference extends Model
{
    use SoftDeletes;

    protected $table = 'source_canon_reference';

    protected $fillable = [
        'universe',
        'level',
        'parent_reference_id',
        'title',
        'content',

        // Universe level
        'universe_overview',
        'universe_priority',
        'universe_depth_rating',
        'overall_divergence_summary',
        'primary_elements_borrowed',
        'primary_divergences',
        'crossover_entry_point_id',

        // Category level
        'category_type',
        'category_overview',

        // Element level
        'element_type',
        'canonical_properties',
        'first_appearance',
        'source_material_references',
        'au_entity_id',

        // Canon dispute
        'canon_disputed',
        'dispute_description',
        'dispute_sources',
        'your_ruling',

        // Research
        'research_status',
        'research_notes',
        'last_researched_at',
        'research_confidence',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'content'                    => 'array', // Tiptap JSON
        'universe_overview'          => 'array', // Tiptap JSON
        'overall_divergence_summary' => 'array', // Tiptap JSON
        'primary_elements_borrowed'  => 'array',
        'primary_divergences'        => 'array',
        'category_overview'          => 'array', // Tiptap JSON
        'canonical_properties'       => 'array',
        'source_material_references' => 'array',
        'dispute_sources'            => 'array',
        'your_ruling'                => 'array', // Tiptap JSON
        'research_notes'             => 'array', // Tiptap JSON
        'canon_disputed'             => 'boolean',
        'last_researched_at'         => 'datetime',
        'deleted_at'                 => 'datetime',
    ];

    const LEVELS = [
        'universe',
        'category',
        'element',
    ];

    const UNIVERSE_PRIORITIES = [
        'peripheral',
        'moderate',
        'significant',
        'primary',
    ];

    const DEPTH_RATINGS = [
        'surface',
        'developing',
        'solid',
        'comprehensive',
    ];

    const CATEGORY_TYPES = [
        'magic_system',
        'political_structure',
        'history',
        'geography',
        'species',
        'artifacts',
        'characters',
        'cosmology',
        'technology',
        'culture',
    ];

    const ELEMENT_TYPES = [
        'character',
        'artifact',
        'location',
        'magic_ability',
        'political_entity',
        'species',
        'event',
        'concept',
    ];

    const RESEARCH_STATUSES = [
        'unstarted',
        'rough',
        'developing',
        'solid',
        'comprehensive',
    ];

    const RESEARCH_CONFIDENCES = [
        'rough',
        'developing',
        'solid',
        'verified',
    ];

    // --- RELATIONSHIPS ---

    // Self-referencing — universe → category → element
    // nullOnDelete prevents cascade deletion of entire research tree
    public function parentReference(): BelongsTo
    {
        return $this->belongsTo(SourceCanonReference::class, 'parent_reference_id');
    }

    public function childReferences(): HasMany
    {
        return $this->hasMany(SourceCanonReference::class, 'parent_reference_id');
    }

    // The AU entity that corresponds to this canonical element
    public function auEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'au_entity_id');
    }

    public function crossoverEntryPoint(): BelongsTo
    {
        return $this->belongsTo(CrossoverEntryPoint::class, 'crossover_entry_point_id');
    }

    public function canonReferenceEntities(): HasMany
    {
        return $this->hasMany(CanonReferenceEntity::class, 'canon_reference_id');
    }

    public function linkedEntities(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'canon_reference_entities',
            'canon_reference_id',
            'entity_id'
        )->withPivot(['divergence_level', 'relationship_type', 'divergence_notes'])
         ->withTimestamps();
    }

    // --- SCOPES ---

    public function scopeUniverseLevel(Builder $query): Builder
    {
        return $query->where('level', 'universe');
    }

    public function scopeCategoryLevel(Builder $query): Builder
    {
        return $query->where('level', 'category');
    }

    public function scopeElementLevel(Builder $query): Builder
    {
        return $query->where('level', 'element');
    }

    public function scopeForUniverse(Builder $query, string $universe): Builder
    {
        return $query->where('universe', $universe);
    }

    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('canon_disputed', true);
    }

    public function scopeUnresearched(Builder $query): Builder
    {
        return $query->where('research_status', 'unstarted');
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderByRaw("
            CASE universe_priority
                WHEN 'primary'     THEN 1
                WHEN 'significant' THEN 2
                WHEN 'moderate'    THEN 3
                WHEN 'peripheral'  THEN 4
                ELSE 5
            END
        ");
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isUniverseLevel(): bool
    {
        return $this->level === 'universe';
    }

    public function isCategoryLevel(): bool
    {
        return $this->level === 'category';
    }

    public function isElementLevel(): bool
    {
        return $this->level === 'element';
    }

    public function isDisputed(): bool
    {
        return (bool) $this->canon_disputed;
    }

    public function hasAuCounterpart(): bool
    {
        return $this->au_entity_id !== null;
    }

    public function isFullyResearched(): bool
    {
        return $this->research_status === 'comprehensive';
    }
}
