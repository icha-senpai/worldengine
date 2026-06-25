<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Support\Database\PostgresPrefixSearch;

class PowerInteraction extends Model
{
    use SoftDeletes;

    protected $table = 'power_interactions';

    protected $fillable = [
        'system_a_entity_id',
        'system_b_entity_id',
        'interaction_name',
        'description',
        'directionality',
        'dominant_system_entity_id',

        // Effects array — each element has:
        // effect_type, affected_aspect, magnitude, notes
        'effects',

        // Conditions
        'proximity_required',
        'location_conditions',
        'practitioner_conditions',
        'temporal_conditions',
        'artifact_conditions',
        'trigger_type',
        'trigger_description',
        'trigger_frequency',

        // Scale
        'interaction_scale',
        'scale_variance',

        // Knowledge state
        'knowledge_state',
        'danger_rating',
        'unresolved_flag',
        'resolution_notes',

        'source_universe_a',
        'source_universe_b',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'description'             => 'array', // Tiptap JSON
        'effects'                 => 'array', // JSONB array of effect objects
        'location_conditions'     => 'array',
        'practitioner_conditions' => 'array',
        'temporal_conditions'     => 'array',
        'artifact_conditions'     => 'array',
        'trigger_description'     => 'array', // Tiptap JSON
        'resolution_notes'        => 'array', // Tiptap JSON
        'proximity_required'      => 'boolean',
        'unresolved_flag'         => 'boolean',
        'deleted_at'              => 'datetime',
    ];

    const DIRECTIONALITY_TYPES = [
        'symmetrical',    // Both systems affect each other equally
        'asymmetrical',   // system_a affects system_b differently than reverse
        'contextual',     // Depends on circumstances
    ];

    const EFFECT_TYPES = [
        'suppresses',
        'amplifies',
        'negates',
        'transforms',
        'corrupts',
        'destabilizes',
        'catalyzes',
        'unpredictable',
    ];

    const AFFECTED_ASPECTS = [
        'raw_power',
        'cognitive_function',
        'physical_manifestation',
        'spiritual_component',
        'emotional_resonance',
        'reality_anchor',
    ];

    const EFFECT_MAGNITUDES = [
        'negligible',
        'minor',
        'moderate',
        'significant',
        'catastrophic',
    ];

    const SCALE_TYPES = [
        'personal',
        'local',
        'regional',
        'planetary',
        'cosmic',
        'multiversal',
        'universal',
    ];

    const SCALE_VARIANCE_TYPES = [
        'uniform',               // Same effect at all scales
        'intensifies_with_scale',
        'degrades_with_scale',
        'transforms_with_scale', // Different effect type at different scales
    ];

    const KNOWLEDGE_STATES = [
        'established',
        'theorized',
        'rumored',
        'unknown',
        'forbidden_knowledge',
    ];

    const DANGER_RATINGS = [
        'benign',
        'low',
        'moderate',
        'high',
        'catastrophic',
        'existential_risk',
        'unknown_risk',
    ];

    // Danger ratings that when combined with uncertain knowledge
    // states auto-set the unresolved_flag
    const HIGH_DANGER_RATINGS = [
        'catastrophic',
        'existential_risk',
        'unknown_risk',
    ];

    const UNCERTAIN_KNOWLEDGE_STATES = [
        'unknown',
        'rumored',
    ];

    // --- RELATIONSHIPS ---

    public function systemA(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'system_a_entity_id');
    }

    public function systemB(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'system_b_entity_id');
    }

    public function dominantSystem(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dominant_system_entity_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(PowerInteractionInstance::class, 'power_interaction_id');
    }

    // --- SCOPES ---

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('unresolved_flag', true);
    }

    public function scopeHighDanger(Builder $query): Builder
    {
        return $query->whereIn('danger_rating', self::HIGH_DANGER_RATINGS);
    }

    public function scopeForDashboard(Builder $query): Builder
    {
        return $query->where('unresolved_flag', true);
    }

    public function scopeBetweenSystems(Builder $query, int $systemAId, int $systemBId): Builder
    {
        // Order-agnostic lookup — either direction
        return $query->where(function (Builder $q) use ($systemAId, $systemBId) {
            $q->where('system_a_entity_id', $systemAId)
              ->where('system_b_entity_id', $systemBId);
        })->orWhere(function (Builder $q) use ($systemAId, $systemBId) {
            $q->where('system_a_entity_id', $systemBId)
              ->where('system_b_entity_id', $systemAId);
        });
    }

    public function scopeInvolvingSystem(Builder $query, int $entityId): Builder
    {
        return $query->where('system_a_entity_id', $entityId)
            ->orWhere('system_b_entity_id', $entityId);
    }

    public function scopeByScale(Builder $query, string $scale): Builder
    {
        return $query->where('interaction_scale', $scale);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return PostgresPrefixSearch::apply($query, $term);
    }

    // --- COMPUTED ---

    public function isUnresolved(): bool
    {
        return (bool) $this->unresolved_flag;
    }

    public function isExistentialRisk(): bool
    {
        return $this->danger_rating === 'existential_risk';
    }

    public function isSymmetrical(): bool
    {
        return $this->directionality === 'symmetrical';
    }

    // Whether this interaction should auto-flag as unresolved
    // Used by the service when creating/updating interactions
    public function shouldBeUnresolved(): bool
    {
        return in_array($this->knowledge_state, self::UNCERTAIN_KNOWLEDGE_STATES, true)
            && in_array($this->danger_rating, self::HIGH_DANGER_RATINGS, true);
    }

    // Effects filtered by type
    public function effectsOfType(string $type): array
    {
        return array_filter(
            $this->effects ?? [],
            fn(array $effect) => ($effect['effect_type'] ?? null) === $type
        );
    }

    // Whether an effect of catastrophic or higher magnitude exists
    public function hasCatastrophicEffect(): bool
    {
        $catastrophicMagnitudes = ['catastrophic'];

        foreach ($this->effects ?? [] as $effect) {
            if (in_array($effect['magnitude'] ?? null, $catastrophicMagnitudes, true)) {
                return true;
            }
        }

        return false;
    }
}
