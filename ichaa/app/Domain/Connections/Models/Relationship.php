<?php

namespace App\Domain\Connections\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Support\Database\PostgresPrefixSearch;

class Relationship extends Model
{
    use SoftDeletes;

    protected $table = 'relationships';

    protected $fillable = [
        'from_entity_id',
        'to_entity_id',
        'relationship_type',
        'other_type_notes',
        'direction',
        'perspective_a',
        'perspective_b',
        'current_tension_charge',
        'charge_history',
        'strength_from_a',
        'strength_from_b',
        'time_period_start',
        'time_period_end',
        'is_active',
        'relationship_history',
        'perceived_type',
        'true_type',
        'perception_divergence',
        'perceived_by',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'perspective_a'        => 'array',
        'perspective_b'        => 'array',
        'charge_history'       => 'array',
        'relationship_history' => 'array',
        'perceived_by'         => 'array',
        'notes'                => 'array', // Tiptap JSON
        'is_active'            => 'boolean',
        'deleted_at'           => 'datetime',
    ];

    // --- VALUE OBJECT ACCESSORS ---

    public function relationshipType(): RelationshipType
    {
        return RelationshipType::from($this->relationship_type);
    }

    public function tensionCharge(): TensionCharge
    {
        return TensionCharge::from($this->current_tension_charge);
    }

    // --- RELATIONSHIPS ---

    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'from_entity_id');
    }

    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'to_entity_id');
    }

    public function stateRelationships(): HasMany
    {
        return $this->hasMany(StateRelationship::class, 'relationship_id');
    }

    public function knowledgeStates(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'subject_relationship_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('relationship_type', $type);
    }

    public function scopeVolatile(Builder $query): Builder
    {
        return $query->where('current_tension_charge', TensionCharge::VOLATILE);
    }

    public function scopeUnderPressure(Builder $query): Builder
    {
        return $query->whereIn('current_tension_charge', TensionCharge::ACTIVE_PRESSURE);
    }

    public function scopeMasked(Builder $query): Builder
    {
        return $query->whereNotNull('true_type')
            ->whereColumn('perceived_type', '!=', 'true_type');
    }

    public function scopeBetween(Builder $query, int $entityA, int $entityB): Builder
    {
        return $query->where(function (Builder $q) use ($entityA, $entityB) {
            $q->where('from_entity_id', $entityA)->where('to_entity_id', $entityB);
        })->orWhere(function (Builder $q) use ($entityA, $entityB) {
            $q->where('from_entity_id', $entityB)->where('to_entity_id', $entityA);
        });
    }

    public function scopeInvolving(Builder $query, int $entityId): Builder
    {
        return $query->where('from_entity_id', $entityId)
            ->orWhere('to_entity_id', $entityId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return PostgresPrefixSearch::apply($query, $term);
    }

    // --- COMPUTED ---

    public function isMasked(): bool
    {
        return $this->true_type !== null
            && $this->perceived_type !== $this->true_type;
    }

    public function isMutual(): bool
    {
        return in_array($this->direction, ['mutual_equal', 'mutual_unequal'], true);
    }

    public function isVolatile(): bool
    {
        return $this->current_tension_charge === TensionCharge::VOLATILE;
    }

    // The other entity in the relationship relative to a given entity ID
    public function otherEntity(int $perspectiveEntityId): ?Entity
    {
        if ($this->from_entity_id === $perspectiveEntityId) {
            return $this->toEntity;
        }

        if ($this->to_entity_id === $perspectiveEntityId) {
            return $this->fromEntity;
        }

        return null;
    }
}
