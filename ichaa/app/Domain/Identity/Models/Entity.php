<?php

namespace App\Domain\Identity\Models;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Events\EntityCreated;
use App\Domain\Identity\Events\EntityPublished;
use App\Domain\Identity\Events\EntityStatusChanged;
use App\Domain\Identity\Events\PowerTierChanged;
use App\Domain\Identity\Events\TrueNatureChanged;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\PowerTier;
use App\Domain\Identity\ValueObjects\SourceUniverse;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Production\Models\WritingPipeline;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\Timeline;
use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Entity extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'entities';

    protected $fillable = [
        'name',
        'public_title',
        'entity_type',
        'entity_sub_type',
        'summary',
        'public_summary',
        'source_universes',
        'origin_type',
        'canon_deviation',
        'origin_notes',

        // Power tiers
        'power_tier_ceiling',
        'power_tier_operating',
        'power_tier_influence',

        // Status
        'status',
        'type_status',
        'visibility',
        'content_classification',
        'published_at',

        // Perception layer
        'public_persona',
        'true_nature',
        'persona_divergence',
        'known_by',

        // Government control
        'control_state',
        'controlling_entity_id',

        // Spatial (location entities)
        'space_type',
        'coordinates',
        'planet_id',
        'plane_id',
        'entry_conditions',
        'existence_conditions',
        'manifestation_points',
        'movement_pattern',
        'position_history',
        'galactic_region_id',

        // Constructed intelligence
        'iteration_number',
        'previous_iterations_count',
        'source_entity_id',

        // Flexible type-specific data
        'attributes',

        // Completion indicators
        'has_attributes',
        'has_relationships',
        'has_timeline_entries',
        'has_documents',
        'has_state_snapshots',
        'has_aliases',
        'has_media',
        'completion_score',
    ];

    protected $casts = [
        // JSONB arrays and objects
        'source_universes' => 'array',
        'public_persona' => 'array',
        'true_nature' => 'array',
        'known_by' => 'array',
        'coordinates' => 'array',
        'entry_conditions' => 'array',
        'existence_conditions' => 'array',
        'manifestation_points' => 'array',
        'position_history' => 'array',
        'attributes' => 'array',

        // Booleans
        'has_attributes' => 'boolean',
        'has_relationships' => 'boolean',
        'has_timeline_entries' => 'boolean',
        'has_documents' => 'boolean',
        'has_state_snapshots' => 'boolean',
        'has_aliases' => 'boolean',
        'has_media' => 'boolean',

        // Integers
        'completion_score' => 'integer',
        'iteration_number' => 'integer',
        'previous_iterations_count' => 'integer',

        // Timestamps
        'published_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // --- DOMAIN EVENT DISPATCH ---
    // Fires domain events when significant fields change
    // Listeners handle side effects — canon state snapshots,
    // completion score recalculation, etc.

    protected $dispatchesEvents = [
        'created' => EntityCreated::class,
    ];

    protected static function newFactory(): EntityFactory
    {
        return EntityFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (Entity $entity) {
            // Power tier ceiling changed — fire event for canon state snapshot
            if ($entity->isDirty('power_tier_ceiling')) {
                event(new PowerTierChanged($entity, 'ceiling', $entity->getOriginal('power_tier_ceiling'), $entity->power_tier_ceiling));
            }

            // Power tier operating changed — fire event for canon state snapshot
            if ($entity->isDirty('power_tier_operating')) {
                event(new PowerTierChanged($entity, 'operating', $entity->getOriginal('power_tier_operating'), $entity->power_tier_operating));
            }

            // True nature changed — fire event for canon state snapshot
            if ($entity->isDirty('true_nature')) {
                event(new TrueNatureChanged($entity, $entity->getOriginal('true_nature'), $entity->true_nature));
            }

            // Status changed — fire event for canon state snapshot
            if ($entity->isDirty('status')) {
                event(new EntityStatusChanged($entity, $entity->getOriginal('status'), $entity->status));
            }

            // Published — fire published event
            if ($entity->isDirty('published_at') && $entity->published_at !== null) {
                event(new EntityPublished($entity));
            }
        });
    }

    // --- VALUE OBJECT ACCESSORS ---
    // Return typed value objects instead of raw strings
    // Usage: $entity->entityType()->isSpatial()

    public function entityType(): EntityType
    {
        return EntityType::from($this->entity_type);
    }

    public function powerTierCeiling(): ?PowerTier
    {
        return $this->power_tier_ceiling
            ? PowerTier::ceiling($this->power_tier_ceiling)
            : null;
    }

    public function powerTierOperating(): ?PowerTier
    {
        return $this->power_tier_operating
            ? PowerTier::operating($this->power_tier_operating)
            : null;
    }

    public function powerTierInfluence(): ?PowerTier
    {
        return $this->power_tier_influence
            ? PowerTier::influence($this->power_tier_influence)
            : null;
    }

    public function visibilityLevel(): VisibilityLevel
    {
        return VisibilityLevel::from($this->visibility);
    }

    public function contentClassification(): ContentClassification
    {
        return ContentClassification::from($this->content_classification);
    }

    // Returns typed SourceUniverse array from source_universes JSONB
    public function sourceUniverses(): array
    {
        return SourceUniverse::fromArray($this->source_universes ?? []);
    }

    // --- COMPUTED PROPERTIES ---

    public function hasUnrealizedPotential(): bool
    {
        if (! $this->power_tier_ceiling || ! $this->power_tier_operating) {
            return false;
        }

        return PowerTier::hasUnrealizedPotential(
            $this->powerTierCeiling(),
            $this->powerTierOperating()
        );
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null
            && $this->visibility === VisibilityLevel::PUBLIC_KNOWLEDGE;
    }

    public function isConstructedIntelligence(): bool
    {
        return $this->entity_type === EntityType::CONSTRUCTED_INTEL;
    }

    public function isSpatial(): bool
    {
        return $this->entityType()->isSpatial();
    }

    public function isPowered(): bool
    {
        return $this->entityType()->isPowered();
    }

    // --- RELATIONSHIPS ---

    // Identity domain
    public function aliases(): HasMany
    {
        return $this->hasMany(EntityAlias::class, 'entity_id');
    }

    public function activeAliases(): HasMany
    {
        return $this->hasMany(EntityAlias::class, 'entity_id')
            ->where('is_active', true);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EntityNote::class, 'entity_id')
            ->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(EntityQuestion::class, 'entity_id');
    }

    public function blockingQuestions(): HasMany
    {
        return $this->hasMany(EntityQuestion::class, 'entity_id')
            ->where('priority', 'blocking')
            ->whereNotIn('status', ['resolved', 'deferred']);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'entity_id');
    }

    public function primaryMedia(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'entity_id')
            ->where('is_primary', true);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VersionAndCanonState::class, 'entity_id')
            ->orderBy('version_number');
    }

    public function currentVersion(): HasMany
    {
        return $this->hasMany(VersionAndCanonState::class, 'entity_id')
            ->where('is_current', true);
    }

    public function versionZero(): HasMany
    {
        return $this->hasMany(VersionAndCanonState::class, 'entity_id')
            ->where('is_version_zero', true);
    }

    // Constructed intelligence — iterations of this construct
    public function iterations(): HasMany
    {
        return $this->hasMany(VersionAndCanonState::class, 'source_entity_id')
            ->orderBy('iteration_number');
    }

    // The controlling entity for puppet/captured entities
    public function controllingEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'controlling_entity_id');
    }

    // Entities this entity controls
    public function controlledEntities(): HasMany
    {
        return $this->hasMany(Entity::class, 'controlling_entity_id');
    }

    // Connections domain
    public function relationshipsFrom(): HasMany
    {
        return $this->hasMany(Relationship::class, 'from_entity_id');
    }

    public function relationshipsTo(): HasMany
    {
        return $this->hasMany(Relationship::class, 'to_entity_id');
    }

    // All relationships regardless of direction
    public function allRelationships(): Collection
    {
        return $this->relationshipsFrom->merge($this->relationshipsTo);
    }

    public function factionMemberships(): HasMany
    {
        return $this->hasMany(FactionMembership::class, 'member_entity_id');
    }

    public function activeFactionMemberships(): HasMany
    {
        return $this->hasMany(FactionMembership::class, 'member_entity_id')
            ->where('membership_status', 'active');
    }

    // Factions this entity leads or controls
    public function controlledFactions(): HasMany
    {
        return $this->hasMany(FactionMembership::class, 'faction_entity_id');
    }

    // Group relationships this entity participates in
    public function groupRelationships(): BelongsToMany
    {
        return $this->belongsToMany(
            GroupRelationship::class,
            'group_relationship_entities',
            'entity_id',
            'group_relationship_id'
        )->withPivot(['role_in_group', 'is_active_member', 'joined_era', 'left_era', 'participation_notes'])
            ->withTimestamps();
    }

    public function activeGroupRelationships(): BelongsToMany
    {
        return $this->groupRelationships()
            ->wherePivot('is_active_member', true);
    }

    // Temporal domain
    public function stateSnapshots(): HasMany
    {
        return $this->hasMany(CharacterStateTracker::class, 'entity_id')
            ->orderBy('timeline_position');
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(Timeline::class, 'event_entity_id');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(Timeline::class, 'timeline_id');
    }

    // Intelligence domain
    public function knowledgeStates(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'knower_entity_id')
            ->where('is_current', true);
    }

    public function knowledgeAbout(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'subject_entity_id')
            ->where('is_current', true);
    }

    public function currentPerceptionState(): HasMany
    {
        return $this->hasMany(PerceptionState::class, 'subject_id')
            ->where('subject_type', 'entity')
            ->where('is_current', true);
    }

    // Production domain
    public function pipelineItems(): BelongsToMany
    {
        return $this->belongsToMany(
            WritingPipeline::class,
            'pipeline_entities',
            'entity_id',
            'pipeline_item_id'
        )->withPivot(['involvement_type', 'notes'])
            ->withTimestamps();
    }

    // --- QUERY SCOPES ---

    // Type scopes
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    public function scopeCharacters(Builder $query): Builder
    {
        return $query->where('entity_type', EntityType::CHARACTER);
    }

    public function scopeLocations(Builder $query): Builder
    {
        return $query->whereIn('entity_type', EntityType::SPATIAL_TYPES);
    }

    public function scopeFactions(Builder $query): Builder
    {
        return $query->whereIn('entity_type', EntityType::FACTION_TYPES);
    }

    public function scopeEvents(Builder $query): Builder
    {
        return $query->whereIn('entity_type', EntityType::EVENT_TYPES);
    }

    public function scopeConstructedIntelligences(Builder $query): Builder
    {
        return $query->where('entity_type', EntityType::CONSTRUCTED_INTEL);
    }

    public function scopePowered(Builder $query): Builder
    {
        return $query->whereIn('entity_type', EntityType::POWERED_TYPES);
    }

    // Status scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['archived', 'concept']);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('visibility', VisibilityLevel::PUBLIC_KNOWLEDGE)
            ->whereNotNull('published_at');
    }

    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('visibility', VisibilityLevel::PRIVATE);
    }

    // Origin scopes
    public function scopeFromUniverse(Builder $query, string $universe): Builder
    {
        return $query->whereJsonContains('source_universes', $universe);
    }

    public function scopeNative(Builder $query): Builder
    {
        return $query->where('origin_type', 'native');
    }

    public function scopeCrossover(Builder $query): Builder
    {
        return $query->where('origin_type', 'canonical');
    }

    // Power scopes
    public function scopeAtCeilingTier(Builder $query, string $tier): Builder
    {
        return $query->where('power_tier_ceiling', $tier);
    }

    public function scopeAtOrAboveCeilingTier(Builder $query, string $tier): Builder
    {
        $weight = PowerTier::CEILING_WEIGHTS[$tier] ?? 0;
        $tiers = array_keys(array_filter(
            PowerTier::CEILING_WEIGHTS,
            fn (int $w) => $w >= $weight
        ));

        return $query->whereIn('power_tier_ceiling', $tiers);
    }

    // Control scopes
    public function scopePuppetControlled(Builder $query): Builder
    {
        return $query->where('control_state', 'puppet');
    }

    public function scopeControlledBy(Builder $query, int $entityId): Builder
    {
        return $query->where('controlling_entity_id', $entityId);
    }

    // Completion scopes
    public function scopeIncomplete(Builder $query, int $below = 100): Builder
    {
        return $query->where('completion_score', '<', $below);
    }

    public function scopeWithBlockingQuestions(Builder $query): Builder
    {
        return $query->whereHas('blockingQuestions');
    }

    // Search scope — uses PostgreSQL full text search vector
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        )->orderByRaw(
            "ts_rank(search_vector, plainto_tsquery('english', ?)) DESC",
            [$term]
        );
    }
}
