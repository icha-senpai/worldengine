<?php

namespace App\Domain\Temporal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Support\Database\PostgresPrefixSearch;

class Timeline extends Model
{
    use SoftDeletes;

    protected $table = 'timeline';

    protected $fillable = [
        // Which timeline this entry belongs to
        'timeline_id',

        // The event entity being placed
        'event_entity_id',

        // Dating
        'entry_label',
        'au_date',
        'source_date',
        'source_date_universe',
        'primordial_era',
        'time_density',
        'au_date_end',
        'timeline_position',
        'temporal_certainty',
        'dating_notes',

        // Era
        'era_entity_id',

        // Concurrency
        'concurrency_group_id',
        'is_atemporal',

        // Causality
        'caused_by_event_ids',
        'caused_event_ids',
        'causality_type',
        'causality_notes',

        // Narrative
        'public_narrative',
        'true_narrative',
        'narrative_divergence',
        'truth_known_by',
        'truth_revealed_at_era',
        'event_significance',

        // Display
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'caused_by_event_ids' => 'array',
        'caused_event_ids'    => 'array',
        'public_narrative'    => 'array', // Tiptap JSON
        'true_narrative'      => 'array', // Tiptap JSON
        'narrative_divergence'=> 'string',
        'dating_notes'        => 'array', // Tiptap JSON
        'truth_known_by'      => 'array',
        'is_atemporal'        => 'boolean',
        'primordial_era'      => 'boolean',
        'timeline_position'   => 'integer',
        'deleted_at'          => 'datetime',
    ];

    const TEMPORAL_CERTAINTY_LEVELS = [
        'documented',
        'estimated',
        'legendary',
        'primordial',
        'atemporal',
    ];

    const EVENT_SIGNIFICANCE_LEVELS = [
        'background',
        'minor',
        'moderate',
        'major',
        'pivotal',
        'world_altering',
    ];

    const TIME_DENSITY_LEVELS = [
        'sparse',
        'moderate',
        'dense',
    ];

    const CAUSALITY_TYPES = [
        'direct',
        'contributory',
        'catalytic',
        'coincidental',
    ];

    const NARRATIVE_DIVERGENCE_LEVELS = [
        'none',
        'partial',
        'complete',
    ];

    // --- RELATIONSHIPS ---

    // The timeline entity this entry lives on
    public function timeline(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'timeline_id');
    }

    // The event entity being placed on the timeline
    public function eventEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'event_entity_id');
    }

    // The era entity this event falls within
    public function era(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'era_entity_id');
    }

    public function concurrencyGroup(): BelongsTo
    {
        return $this->belongsTo(ConcurrencyGroup::class, 'concurrency_group_id');
    }

    public function timelineEntities(): HasMany
    {
        return $this->hasMany(TimelineEntity::class, 'event_entity_id', 'event_entity_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'timeline_entry_id');
    }

    public function knowledgeStates(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'subject_event_id');
    }

    // --- SCOPES ---

    public function scopeOnTimeline(Builder $query, int $timelineEntityId): Builder
    {
        return $query->where('timeline_id', $timelineEntityId);
    }

    public function scopeInEra(Builder $query, int $eraEntityId): Builder
    {
        return $query->where('era_entity_id', $eraEntityId);
    }

    public function scopeAtemporal(Builder $query): Builder
    {
        return $query->where('is_atemporal', true);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('timeline_position');
    }

    public function scopeSignificant(Builder $query, string $minimumLevel = 'major'): Builder
    {
        $levels = self::EVENT_SIGNIFICANCE_LEVELS;
        $index  = array_search($minimumLevel, $levels, true);

        if ($index === false) {
            return $query;
        }

        $qualifyingLevels = array_slice($levels, $index);

        return $query->whereIn('event_significance', $qualifyingLevels);
    }

    public function scopeWithNarrativeDivergence(Builder $query): Builder
    {
        return $query->whereNotNull('narrative_divergence')
            ->whereNotNull('true_narrative');
    }

    public function scopeInConcurrencyGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('concurrency_group_id', $groupId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return PostgresPrefixSearch::apply($query, $term);
    }

    // --- COMPUTED ---

    public function hasNarrativeDivergence(): bool
    {
        return !empty($this->true_narrative)
            && !empty($this->narrative_divergence);
    }

    public function isTruthKnownBy(int $entityId): bool
    {
        return empty($this->truth_known_by)
            || in_array($entityId, $this->truth_known_by, true);
    }

    public function isAtemporal(): bool
    {
        return (bool) $this->is_atemporal;
    }

    public function isConcurrent(): bool
    {
        return $this->concurrency_group_id !== null;
    }

    public function isPivotal(): bool
    {
        return in_array($this->event_significance, ['pivotal', 'world_altering'], true);
    }
}
