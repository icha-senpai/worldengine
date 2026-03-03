<?php

namespace App\Domain\Connections\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Production\Models\Meta;

class GroupRelationship extends Model
{
    use SoftDeletes;

    protected $table = 'group_relationships';

    protected $fillable = [
        'name',
        'relationship_type',
        'other_type_notes',
        'dynamic_description',
        'current_tension_charge',
        'charge_history',
        'time_period_start',
        'time_period_end',
        'is_active',
        'group_history',
        'perceived_type',
        'true_type',
        'perception_divergence',
        'perceived_by',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'dynamic_description' => 'array', // Tiptap JSON
        'charge_history'      => 'array',
        'group_history'       => 'array',
        'perceived_by'        => 'array',
        'notes'               => 'array', // Tiptap JSON
        'is_active'           => 'boolean',
        'deleted_at'          => 'datetime',
    ];

    // --- VALUE OBJECT ACCESSORS ---

    public function tensionCharge(): TensionCharge
    {
        return TensionCharge::from($this->current_tension_charge);
    }

    // --- RELATIONSHIPS ---

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'group_relationship_entities',
            'group_relationship_id',
            'entity_id'
        )->withPivot([
            'role_in_group',
            'participation_notes',
            'is_active_member',
            'joined_era',
            'left_era',
            'departure_notes',
        ])->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()
            ->wherePivot('is_active_member', true);
    }

    public function memberEntries(): HasMany
    {
        return $this->hasMany(GroupRelationshipEntity::class, 'group_relationship_id');
    }

    public function activeMemberEntries(): HasMany
    {
        return $this->hasMany(GroupRelationshipEntity::class, 'group_relationship_id')
            ->where('is_active_member', true);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'group_relationship_id');
    }

    public function knowledgeStates(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'subject_group_relationship_id');
    }

    public function metaEntries(): BelongsToMany
    {
        return $this->belongsToMany(
            Meta::class,
            'meta_group_relationships',
            'group_relationship_id',
            'meta_id'
        )->withPivot(['connection_notes'])
         ->withTimestamps();
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    public function scopeInvolving(Builder $query, int $entityId): Builder
    {
        return $query->whereHas('members', function (Builder $q) use ($entityId) {
            $q->where('entities.id', $entityId);
        });
    }

    // --- COMPUTED ---

    public function isMasked(): bool
    {
        return $this->true_type !== null
            && $this->perceived_type !== $this->true_type;
    }

    public function memberCount(): int
    {
        return $this->activeMembers()->count();
    }
}
