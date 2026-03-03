<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class PowerInteractionInstance extends Model
{
    protected $table = 'power_interaction_instances';

    protected $fillable = [
        'power_interaction_id',
        'event_entity_id',
        'involved_entity_ids',
        'outcome_match',
        'outcome_notes',
        'observed_at_era',
        'observed_at_position',
    ];

    protected $casts = [
        'involved_entity_ids'  => 'array',
        'outcome_notes'        => 'array', // Tiptap JSON
        'observed_at_position' => 'decimal:6',
    ];

    const OUTCOME_MATCHES = [
        'confirmed',       // Outcome matched established rule exactly
        'partial',         // Outcome partially matched
        'contradicted',    // Outcome contradicted established rule
        'new_discovery',   // Outcome revealed something not in the rule
    ];

    // --- RELATIONSHIPS ---

    public function powerInteraction(): BelongsTo
    {
        return $this->belongsTo(PowerInteraction::class, 'power_interaction_id');
    }

    public function eventEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'event_entity_id');
    }

    // --- SCOPES ---

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('outcome_match', 'confirmed');
    }

    public function scopeContradicted(Builder $query): Builder
    {
        return $query->where('outcome_match', 'contradicted');
    }

    public function scopeNewDiscoveries(Builder $query): Builder
    {
        return $query->where('outcome_match', 'new_discovery');
    }

    public function scopeForInteraction(Builder $query, int $interactionId): Builder
    {
        return $query->where('power_interaction_id', $interactionId);
    }

    // --- COMPUTED ---

    public function contradicts(): bool
    {
        return $this->outcome_match === 'contradicted';
    }

    public function isNewDiscovery(): bool
    {
        return $this->outcome_match === 'new_discovery';
    }
}
