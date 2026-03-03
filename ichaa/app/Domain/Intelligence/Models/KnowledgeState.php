<?php

namespace App\Domain\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Temporal\Models\Timeline;

class KnowledgeState extends Model
{
    use SoftDeletes;

    protected $table = 'knowledge_states';

    protected $fillable = [
        'knower_entity_id',

        // Exactly one subject field populated
        'subject_entity_id',
        'subject_relationship_id',
        'subject_group_relationship_id',
        'subject_event_id',
        'subject_secret_id',

        'knowledge_type',
        'knowledge_content',
        'accuracy',

        // Acquisition
        'acquired_at_era',
        'acquired_through',
        'acquired_from_entity_id',

        // Belief state — how the knower holds this knowledge
        'current_belief_state',

        // Whether the knower has acted on this knowledge
        'acted_on',
        'action_notes',

        // Supersession chain
        'valid_from_era',
        'valid_until_era',
        'is_current',
        'superseded_by_knowledge_id',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'knowledge_content' => 'array', // Tiptap JSON
        'action_notes'      => 'array', // Tiptap JSON
        'acted_on'          => 'boolean',
        'is_current'        => 'boolean',
        'deleted_at'        => 'datetime',
    ];

    const KNOWLEDGE_TYPES = [
        'true_nature',
        'secret',
        'public_fact',
        'rumor',
        'suspicion',
        'false_belief',
        'prophecy_fragment',
    ];

    const ACCURACY_LEVELS = [
        'true',
        'partial',
        'false',
        'distorted',
        'unknown_to_knower',
    ];

    // Belief state — how the knower relates to this knowledge psychologically
    // This is distinct from accuracy — a knower can believe something false
    // or doubt something true
    const BELIEF_STATES = [
        'believes',            // Accepts as true
        'suspects',            // Thinks it may be true
        'doubts',              // Unsure if true
        'disbelieves',         // Rejects as false
        'compartmentalizing',  // Knows it's true, cannot face it
    ];

    const ACQUISITION_METHODS = [
        'observation',
        'told_by',
        'legilimency',
        'prophecy',
        'deduction',
        'torture',
        'ritual',
        'accidental',
        'document',
        'dream',
        'other',
    ];

    // --- RELATIONSHIPS ---

    public function knower(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'knower_entity_id');
    }

    public function subjectEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'subject_entity_id');
    }

    public function subjectRelationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class, 'subject_relationship_id');
    }

    public function subjectGroupRelationship(): BelongsTo
    {
        return $this->belongsTo(GroupRelationship::class, 'subject_group_relationship_id');
    }

    public function subjectEvent(): BelongsTo
    {
        return $this->belongsTo(Timeline::class, 'subject_event_id');
    }

    public function subjectSecret(): BelongsTo
    {
        return $this->belongsTo(Secret::class, 'subject_secret_id');
    }

    public function acquiredFrom(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'acquired_from_entity_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(KnowledgeState::class, 'superseded_by_knowledge_id');
    }

    // --- SCOPES ---

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeForKnower(Builder $query, int $entityId): Builder
    {
        return $query->where('knower_entity_id', $entityId);
    }

    public function scopeAboutEntity(Builder $query, int $entityId): Builder
    {
        return $query->where('subject_entity_id', $entityId);
    }

    public function scopeAboutSecret(Builder $query, int $secretId): Builder
    {
        return $query->where('subject_secret_id', $secretId);
    }

    public function scopeAccurate(Builder $query): Builder
    {
        return $query->where('accuracy', 'true');
    }

    public function scopeInaccurate(Builder $query): Builder
    {
        return $query->whereIn('accuracy', ['false', 'distorted']);
    }

    public function scopeActedOn(Builder $query): Builder
    {
        return $query->where('acted_on', true);
    }

    public function scopeNotActedOn(Builder $query): Builder
    {
        return $query->where('acted_on', false);
    }

    // The latent tension map — entities who know something true
    // and have not yet acted on it
    public function scopeLatentTension(Builder $query): Builder
    {
        return $query->where('acted_on', false)
            ->where('accuracy', 'true')
            ->where('is_current', true);
    }

    public function scopeCompartmentalizing(Builder $query): Builder
    {
        return $query->where('current_belief_state', 'compartmentalizing');
    }

    public function scopeByBeliefState(Builder $query, string $state): Builder
    {
        return $query->where('current_belief_state', $state);
    }

    public function scopeAcquiredThrough(Builder $query, string $method): Builder
    {
        return $query->where('acquired_through', $method);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isCurrent(): bool
    {
        return (bool) $this->is_current;
    }

    public function isActedOn(): bool
    {
        return (bool) $this->acted_on;
    }

    public function isAccurate(): bool
    {
        return $this->accuracy === 'true';
    }

    public function isCompartmentalizing(): bool
    {
        return $this->current_belief_state === 'compartmentalizing';
    }

    // True = knower has accurate knowledge they haven't acted on
    // The core of the latent tension map
    public function isLatentTension(): bool
    {
        return $this->is_current
            && $this->accuracy === 'true'
            && !$this->acted_on;
    }

    // What type of subject this knowledge is about
    public function subjectType(): string
    {
        return match(true) {
            $this->subject_entity_id             !== null => 'entity',
            $this->subject_relationship_id       !== null => 'relationship',
            $this->subject_group_relationship_id !== null => 'group_relationship',
            $this->subject_event_id              !== null => 'event',
            $this->subject_secret_id             !== null => 'secret',
            default                                       => 'unknown',
        };
    }

    // The raw subject ID regardless of type
    public function subjectId(): ?int
    {
        return $this->subject_entity_id
            ?? $this->subject_relationship_id
            ?? $this->subject_group_relationship_id
            ?? $this->subject_event_id
            ?? $this->subject_secret_id;
    }
}
