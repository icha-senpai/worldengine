<?php

namespace App\Domain\Connections\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class GroupRelationshipEntity extends Model
{
    protected $table = 'group_relationship_entities';

    protected $fillable = [
        'group_relationship_id',
        'entity_id',
        'role_in_group',
        'participation_notes',
        'is_active_member',
        'joined_era',
        'left_era',
        'departure_notes',
    ];

    protected $casts = [
        'participation_notes' => 'array', // Tiptap JSON
        'departure_notes'     => 'array', // Tiptap JSON
        'is_active_member'    => 'boolean',
    ];

    // --- RELATIONSHIPS ---

    public function groupRelationship(): BelongsTo
    {
        return $this->belongsTo(GroupRelationship::class, 'group_relationship_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active_member', true);
    }

    public function scopeForEntity(Builder $query, int $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->where('role_in_group', $role);
    }

    // --- COMPUTED ---

    public function isDeparted(): bool
    {
        return !$this->is_active_member && $this->left_era !== null;
    }

    public function wasPresent(): bool
    {
        return $this->joined_era !== null;
    }
}
