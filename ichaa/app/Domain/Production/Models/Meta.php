<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\Models\GroupRelationship;

class Meta extends Model
{
    use SoftDeletes;

    protected $table = 'meta';

    protected $fillable = [
        'title',
        'category',
        'meta_note_type',
        'content',
        'sense_sight',
        'sense_sound',
        'sense_smell',
        'sense_taste',
        'sense_touch',
        'sense_magical',
        'emotional_register',
        'symbol_name',
        'symbol_origin_entity_id',
        'symbol_usage_context',
        'symbol_associated_entity_ids',
        'symbol_media_reference_id',
        'symbol_scope',
        'priority',
        'action_status',
        'resolved_at',
        'resolution_notes',
        'superseded_by_meta_id',
        'superseded_at',
        'supersession_reason',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'content'                      => 'array',
        'resolution_notes'             => 'array',
        'symbol_associated_entity_ids' => 'array',
        'resolved_at'                  => 'datetime',
        'superseded_at'                => 'datetime',
        'deleted_at'                   => 'datetime',
    ];

    const CATEGORIES = [
        'themes_and_motifs',
        'tensions_and_contradictions',
        'design_notes_and_author_intent',
        'secrets_and_hidden_truth',
        'moral_dilemmas',
        'sensory_palettes',
        'symbols_and_iconography',
    ];

    const NOTE_TYPES = [
        'passive',
        'active_task',
        'decision',
        'question',
        'reminder',
    ];

    const PRIORITIES = [
        'low',
        'medium',
        'high',
        'blocking',
    ];

    const ACTION_STATUSES = [
        'pending',
        'in_progress',
        'resolved',
        'deferred',
    ];

    const SYMBOL_SCOPES = [
        'in_world',
        'meta',
        'both',
    ];

    // --- RELATIONSHIPS ---

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'meta_entities',
            'meta_id',
            'entity_id'
        )->withPivot(['connection_notes'])
         ->withTimestamps();
    }

    public function groupRelationships(): BelongsToMany
    {
        return $this->belongsToMany(
            GroupRelationship::class,
            'meta_group_relationships',
            'meta_id',
            'group_relationship_id'
        )->withPivot(['connection_notes'])
         ->withTimestamps();
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'superseded_by_meta_id');
    }

    public function supersedes(): HasMany
    {
        return $this->hasMany(Meta::class, 'superseded_by_meta_id');
    }

    // --- SCOPES ---

    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeOfNoteType(Builder $query, string $type): Builder
    {
        return $query->where('meta_note_type', $type);
    }

    public function scopeActiveTasks(Builder $query): Builder
    {
        return $query->where('meta_note_type', 'active_task')
            ->whereNot('action_status', 'resolved');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_meta_id');
    }

    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_by_meta_id');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('priority', 'blocking');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by_meta_id !== null;
    }

    public function isActiveTask(): bool
    {
        return $this->meta_note_type === 'active_task' && !$this->isResolved();
    }

    public function isSensoryPalette(): bool
    {
        return $this->category === 'sensory_palettes';
    }

    public function isSymbol(): bool
    {
        return $this->category === 'symbols_and_iconography';
    }
}