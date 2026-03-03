<?php

namespace App\Domain\Temporal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Connections\Models\Relationship;

class StateRelationship extends Model
{
    protected $table = 'state_relationships';

    protected $fillable = [
        'character_state_id',
        'relationship_id',
        'is_active_at_snapshot',
        'relationship_state_at_snapshot',
    ];

    protected $casts = [
        'is_active_at_snapshot'          => 'boolean',
        'relationship_state_at_snapshot' => 'array', // Tiptap JSON
    ];

    // --- RELATIONSHIPS ---

    public function characterState(): BelongsTo
    {
        return $this->belongsTo(CharacterStateTracker::class, 'character_state_id');
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class, 'relationship_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active_at_snapshot', true);
    }

    public function scopeForSnapshot(Builder $query, int $stateId): Builder
    {
        return $query->where('character_state_id', $stateId);
    }
}
