<?php

namespace App\Domain\Temporal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class CharacterStateTracker extends Model
{
    use SoftDeletes;

    protected $table = 'character_state_tracker';

    protected $fillable = [
        'entity_id',
        'timeline_id',
        'era_entity_id',
        'au_date',
        'timeline_position',
        'snapshot_label',
        'snapshot_significance',

        // Psychological state — plain text for direct search indexing
        'current_trauma_profile',
        'active_psychological_patterns',
        'coping_mechanisms',
        'breaking_points',
        'current_stability_level',
        'self_perception',
        'core_wound',
        'current_desire',
        'current_fear',
        'shadow_self',
        'relational_patterns',
        'performed_self',
        'true_self',
        'mask_integrity',

        // Physical state
        'physical_state_notes',
        'significant_physical_changes',
        'physical_integrity',

        // Power state
        'current_power_tier_operating',
        'current_power_tier_influence',
        'available_abilities',
        'restricted_abilities',
        'lost_abilities',
        'current_artifacts_and_hallows',

        // Relational state
        'key_relationships_summary',
        'active_group_relationship_ids',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        // JSONB fields
        'significant_physical_changes' => 'array',
        'available_abilities'          => 'array',
        'restricted_abilities'         => 'array',
        'lost_abilities'               => 'array',
        'current_artifacts_and_hallows'=> 'array',
        'key_relationships_summary'    => 'array',
        'active_group_relationship_ids'=> 'array',

        // Decimal for positional ordering
        'timeline_position'            => 'decimal:6',

        'deleted_at'                   => 'datetime',

        // All psychological fields are plain text strings — NOT cast to array
        // This is deliberate: plain text enables PostgreSQL full text search
        // "afraid of what she is" finds the snapshot, not just a JSON path
    ];

    const STABILITY_LEVELS = [
        'stable',
        'stressed',
        'strained',
        'breaking',
        'broken',
        'transformed',
    ];

    const MASK_INTEGRITY_LEVELS = [
        'intact',
        'cracking',
        'compromised',
        'shattered',
    ];

    const SNAPSHOT_SIGNIFICANCE_LEVELS = [
        'minor',
        'moderate',
        'major',
        'transformative',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function timeline(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'timeline_id');
    }

    public function era(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'era_entity_id');
    }

    public function stateRelationships(): HasMany
    {
        return $this->hasMany(StateRelationship::class, 'character_state_id');
    }

    // --- SCOPES ---

    public function scopeForEntity(Builder $query, int $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeInEra(Builder $query, int $eraEntityId): Builder
    {
        return $query->where('era_entity_id', $eraEntityId);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('timeline_position');
    }

    public function scopeTransformative(Builder $query): Builder
    {
        return $query->where('snapshot_significance', 'transformative');
    }

    public function scopeMajor(Builder $query): Builder
    {
        return $query->whereIn('snapshot_significance', ['major', 'transformative']);
    }

    public function scopeAtStabilityLevel(Builder $query, string $level): Builder
    {
        return $query->where('current_stability_level', $level);
    }

    public function scopeBreaking(Builder $query): Builder
    {
        return $query->whereIn('current_stability_level', ['breaking', 'broken']);
    }

    public function scopeWithMaskCompromised(Builder $query): Builder
    {
        return $query->whereIn('mask_integrity', ['compromised', 'shattered']);
    }

    // Full text search across psychological plain text fields
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isStable(): bool
    {
        return $this->current_stability_level === 'stable';
    }

    public function isBreaking(): bool
    {
        return in_array($this->current_stability_level, ['breaking', 'broken'], true);
    }

    public function isMaskIntact(): bool
    {
        return $this->mask_integrity === 'intact';
    }

    public function isMaskShattered(): bool
    {
        return $this->mask_integrity === 'shattered';
    }

    public function isTransformative(): bool
    {
        return $this->snapshot_significance === 'transformative';
    }

    public function hasLostAbilities(): bool
    {
        return !empty($this->lost_abilities);
    }

    public function hasRestrictedAbilities(): bool
    {
        return !empty($this->restricted_abilities);
    }
}
