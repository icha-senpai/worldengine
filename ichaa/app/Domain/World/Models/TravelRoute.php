<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class TravelRoute extends Model
{
    use SoftDeletes;

    protected $table = 'travel_routes';

    protected $fillable = [
        'origin_location_entity_id',
        'destination_location_entity_id',
        'route_type',
        'standard_duration',

        // Each variant: method, required_ability_or_artifact,
        // duration, conditions, notes
        'method_variants',

        'hazards',
        'era_specific_variants',

        // Empty = publicly known
        'known_by_entity_ids',

        'controlled_by_entity_id',
        'is_active',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'method_variants'      => 'array',
        'hazards'              => 'array',
        'era_specific_variants'=> 'array',
        'known_by_entity_ids'  => 'array',
        'notes'                => 'array', // Tiptap JSON
        'is_active'            => 'boolean',
        'deleted_at'           => 'datetime',
    ];

    const ROUTE_TYPES = [
        'overland',
        'maritime',
        'aerial',
        'magical',
        'temporal',
        'planar',
        'dimensional',
        'conceptual',
    ];

    // --- RELATIONSHIPS ---

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'origin_location_entity_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'destination_location_entity_id');
    }

    public function controlledBy(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'controlled_by_entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('route_type', $type);
    }

    public function scopeFromOrigin(Builder $query, int $entityId): Builder
    {
        return $query->where('origin_location_entity_id', $entityId);
    }

    public function scopeToDestination(Builder $query, int $entityId): Builder
    {
        return $query->where('destination_location_entity_id', $entityId);
    }

    public function scopePubliclyKnown(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('known_by_entity_ids')
              ->orWhereJsonLength('known_by_entity_ids', 0);
        });
    }

    public function scopeKnownBy(Builder $query, int $entityId): Builder
    {
        return $query->where(function (Builder $q) use ($entityId) {
            $q->whereJsonLength('known_by_entity_ids', 0)
              ->orWhereJsonContains('known_by_entity_ids', $entityId);
        });
    }

    public function scopeControlledBy(Builder $query, int $entityId): Builder
    {
        return $query->where('controlled_by_entity_id', $entityId);
    }

    // --- COMPUTED ---

    public function isPubliclyKnown(): bool
    {
        return empty($this->known_by_entity_ids);
    }

    public function isControlled(): bool
    {
        return $this->controlled_by_entity_id !== null;
    }

    public function methodsRequiringAbility(): array
    {
        return array_filter(
            $this->method_variants ?? [],
            fn(array $method) => !empty($method['required_ability_or_artifact'])
        );
    }
}
