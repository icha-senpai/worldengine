<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Lore\Models\Document;

class Collection extends Model
{
    use SoftDeletes;

    protected $table = 'collections';

    protected $fillable = [
        'name',
        'description',
        'collection_type',
        'collection_mode',
        'rules',
        'excluded_entity_ids',
        'parent_collection_id',
        'sort_order',
        'completion_state',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'description'         => 'array', // Tiptap JSON
        'rules'               => 'array', // JSONB rule conditions
        'excluded_entity_ids' => 'array',
        'sort_order'          => 'integer',
        'deleted_at'          => 'datetime',
    ];

    // Collection types
    const TYPES = [
        'arc',
        'chapter_group',
        'character_roster',
        'faction_roster',
        'location_cluster',
        'artifact_set',
        'event_sequence',
        'era_scope',
        'crossover_set',
        'power_system_group',
        'research_set',
        'canon_reference_set',
        'writing_batch',
        'reading_order',
        'thematic_group',
        'universe_scope',
        'custom',
        'smart',
    ];

    // Collection modes
    const MODES = [
        'manual',  // hand-picked entities only
        'smart',   // rule-based auto-population
        'hybrid',  // rules + manual overrides
    ];

    // Completion states
    const COMPLETION_STATES = [
        'not_started',
        'in_progress',
        'complete',
        'on_hold',
        'abandoned',
    ];

    // --- RELATIONSHIPS ---

    public function parentCollection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'parent_collection_id');
    }

    public function childCollections(): HasMany
    {
        return $this->hasMany(Collection::class, 'parent_collection_id')
            ->orderBy('sort_order');
    }

    public function entityEntries(): HasMany
    {
        return $this->hasMany(CollectionEntity::class, 'collection_id');
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'collection_entities',
            'collection_id',
            'entity_id'
        )->withPivot(['added_manually', 'added_by_rule', 'role_in_collection', 'sort_order', 'notes'])
         ->withTimestamps()
         ->orderByPivot('sort_order');
    }

    public function documentEntries(): HasMany
    {
        return $this->hasMany(CollectionDocument::class, 'collection_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            Document::class,
            'collection_documents',
            'collection_id',
            'document_id'
        )->withPivot(['role_in_collection', 'sort_order', 'notes'])
         ->withTimestamps()
         ->orderByPivot('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'collection_id');
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('collection_type', $type);
    }

    public function scopeSmart(Builder $query): Builder
    {
        return $query->whereIn('collection_mode', ['smart', 'hybrid']);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('collection_mode', 'manual');
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_collection_id');
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('completion_state', 'complete');
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('completion_state', 'in_progress');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // --- COMPUTED ---

    public function isSmart(): bool
    {
        return in_array($this->collection_mode, ['smart', 'hybrid'], true);
    }

    public function isManual(): bool
    {
        return $this->collection_mode === 'manual';
    }

    public function isNested(): bool
    {
        return $this->parent_collection_id !== null;
    }

    public function entityCount(): int
    {
        return $this->entities()->count();
    }

    public function isExcluded(int $entityId): bool
    {
        return in_array($entityId, $this->excluded_entity_ids ?? [], true);
    }
}
