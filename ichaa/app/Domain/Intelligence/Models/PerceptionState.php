<?php

namespace App\Domain\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class PerceptionState extends Model
{
    use SoftDeletes;

    protected $table = 'perception_states';

    protected $fillable = [
        // What type of record is the subject
        'subject_type',
        // The ID of that record
        'subject_id',

        'true_state',
        'perceived_state',
        'divergence_level',

        // Who is actively maintaining the false perception
        'maintained_by_entity_ids',
        'maintenance_method',
        'maintenance_effort',

        // Who perceives the false state — empty = universal
        'perceiving_entity_ids',
        // Who sees through it
        'immune_entity_ids',

        // Revelation
        'revelation_condition',
        'revelation_consequence',
        'revelation_risk',
        'revealed_at_era',

        'is_current',

        // Links to related records
        'related_secret_id',
        'related_knowledge_state_ids',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'true_state'                  => 'array', // Tiptap JSON
        'perceived_state'             => 'array', // Tiptap JSON
        'revelation_condition'        => 'array', // Tiptap JSON
        'revelation_consequence'      => 'array', // Tiptap JSON
        'maintained_by_entity_ids'    => 'array',
        'perceiving_entity_ids'       => 'array',
        'immune_entity_ids'           => 'array',
        'related_knowledge_state_ids' => 'array',
        'is_current'                  => 'boolean',
        'deleted_at'                  => 'datetime',
    ];

    const SUBJECT_TYPES = [
        'entity',
        'relationship',
        'group_relationship',
        'event',
        'document',
        'faction',
        'location',
    ];

    const DIVERGENCE_LEVELS = [
        'none',
        'surface',
        'significant',
        'complete',
    ];

    const MAINTENANCE_METHODS = [
        'propaganda',
        'memory_modification',
        'strategic_information_control',
        'legilimency_based_manipulation',
        'puppet_narrative_control',
        'deliberate_misdirection',
        'social_pressure',
    ];

    const MAINTENANCE_EFFORTS = [
        'passive',   // Maintains itself without active work
        'active',    // Requires regular intervention
        'critical',  // Would collapse without constant effort
    ];

    const REVELATION_RISKS = [
        'low',
        'medium',
        'high',
        'critical',
        'inevitable',
    ];

    // --- RELATIONSHIPS ---

    public function relatedSecret(): BelongsTo
    {
        return $this->belongsTo(Secret::class, 'related_secret_id');
    }

    // --- SCOPES ---

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)
            ->where('subject_id', $id);
    }

    public function scopeCriticalMaintenance(Builder $query): Builder
    {
        return $query->where('maintenance_effort', 'critical')
            ->where('is_current', true);
    }

    public function scopeInevitable(Builder $query): Builder
    {
        return $query->where('revelation_risk', 'inevitable');
    }

    public function scopeHighRisk(Builder $query): Builder
    {
        return $query->whereIn('revelation_risk', ['high', 'critical', 'inevitable'])
            ->where('is_current', true);
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('divergence_level', 'complete');
    }

    public function scopeMaintainedBy(Builder $query, int $entityId): Builder
    {
        return $query->whereJsonContains('maintained_by_entity_ids', $entityId);
    }

    public function scopeImmuneEntityCount(Builder $query): Builder
    {
        // Orders by immune list size descending
        // Largest immune lists = most tension
        return $query->orderByRaw(
            "jsonb_array_length(COALESCE(immune_entity_ids, '[]'::jsonb)) DESC"
        );
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

    public function maintainerCount(): int
    {
        return count($this->maintained_by_entity_ids ?? []);
    }

    public function immuneCount(): int
    {
        return count($this->immune_entity_ids ?? []);
    }

    public function perceiverCount(): int
    {
        return count($this->perceiving_entity_ids ?? []);
    }

    // The tension meter — immune entities growing beyond those
    // the maintainer controls signals approaching revelation
    // Returns ratio of immune to maintainer count
    public function immuneTensionRatio(): float
    {
        $maintainers = $this->maintainerCount();

        if ($maintainers === 0) {
            return 0.0;
        }

        return round($this->immuneCount() / $maintainers, 2);
    }

    public function isUniversalPerception(): bool
    {
        return empty($this->perceiving_entity_ids);
    }

    public function isImmune(int $entityId): bool
    {
        return in_array($entityId, $this->immune_entity_ids ?? [], true);
    }

    public function isMaintainedBy(int $entityId): bool
    {
        return in_array($entityId, $this->maintained_by_entity_ids ?? [], true);
    }

    public function isRevelationInevitable(): bool
    {
        return $this->revelation_risk === 'inevitable';
    }

    public function isCriticalMaintenance(): bool
    {
        return $this->maintenance_effort === 'critical';
    }

    public function isCompletelyDivergent(): bool
    {
        return $this->divergence_level === 'complete';
    }
}
