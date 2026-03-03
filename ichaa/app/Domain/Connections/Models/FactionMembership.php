<?php

namespace App\Domain\Connections\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class FactionMembership extends Model
{
    use SoftDeletes;

    protected $table = 'faction_memberships';

    protected $fillable = [
        'faction_entity_id',
        'member_entity_id',
        'rank_or_role',
        'membership_status',
        'joined_era',
        'left_era',
        'departure_reason',
        'true_loyalty_entity_id',
        'is_undercover',
        'public_membership_known',
        'recruited_by_entity_id',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'departure_reason'       => 'array', // Tiptap JSON
        'notes'                  => 'array', // Tiptap JSON
        'is_undercover'          => 'boolean',
        'public_membership_known'=> 'boolean',
        'deleted_at'             => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function faction(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'faction_entity_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'member_entity_id');
    }

    // Who this member truly serves — null if loyalty matches faction
    public function trueLoyalty(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'true_loyalty_entity_id');
    }

    public function recruitedBy(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'recruited_by_entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('membership_status', 'active');
    }

    public function scopeUndercover(Builder $query): Builder
    {
        return $query->where('is_undercover', true);
    }

    public function scopePubliclyKnown(Builder $query): Builder
    {
        return $query->where('public_membership_known', true);
    }

    public function scopeForFaction(Builder $query, int $factionId): Builder
    {
        return $query->where('faction_entity_id', $factionId);
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_entity_id', $memberId);
    }

    // Members whose true loyalty is to a different entity than the faction
    public function scopeDisloyal(Builder $query): Builder
    {
        return $query->whereNotNull('true_loyalty_entity_id')
            ->whereColumn('true_loyalty_entity_id', '!=', 'faction_entity_id');
    }

    // --- COMPUTED ---

    public function isActive(): bool
    {
        return $this->membership_status === 'active';
    }

    public function isUndercover(): bool
    {
        return (bool) $this->is_undercover;
    }

    // Returns true if the member's loyalty is not to this faction
    public function isDisloyal(): bool
    {
        return $this->true_loyalty_entity_id !== null
            && $this->true_loyalty_entity_id !== $this->faction_entity_id;
    }

    // Returns true if this membership is hidden from public record
    public function isSecret(): bool
    {
        return !$this->public_membership_known;
    }
}
