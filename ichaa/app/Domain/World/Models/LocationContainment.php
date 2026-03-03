<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class LocationContainment extends Model
{
    use SoftDeletes;

    protected $table = 'location_containment';

    protected $fillable = [
        'child_location_entity_id',
        'parent_location_entity_id',
        'containment_type',
        'era_start',
        'era_end',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'notes'      => 'array', // Tiptap JSON
        'deleted_at' => 'datetime',
    ];

    const CONTAINMENT_TYPES = [
        'physical',      // Physically inside — London in England
        'planar',        // Exists within a plane
        'dimensional',   // Exists within a dimension
        'conceptual',    // Conceptually part of — an idea within a philosophy
        'political',     // Under political jurisdiction
        'cultural',      // Cultural territory
    ];

    // --- RELATIONSHIPS ---

    public function childLocation(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'child_location_entity_id');
    }

    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'parent_location_entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('containment_type', $type);
    }

    public function scopeChildrenOf(Builder $query, int $parentId): Builder
    {
        return $query->where('parent_location_entity_id', $parentId);
    }

    public function scopeParentsOf(Builder $query, int $childId): Builder
    {
        return $query->where('child_location_entity_id', $childId);
    }

    // --- COMPUTED ---

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
