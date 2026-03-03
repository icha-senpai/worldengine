<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class CollectionEntity extends Model
{
    protected $table = 'collection_entities';

    protected $fillable = [
        'collection_id',
        'entity_id',
        'added_manually',
        'added_by_rule',
        'matched_rule_snapshot',
        'role_in_collection',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'added_manually'         => 'boolean',
        'added_by_rule'          => 'boolean',
        'matched_rule_snapshot'  => 'array',
        'sort_order'             => 'integer',
    ];

    // --- RELATIONSHIPS ---

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeManuallyAdded(Builder $query): Builder
    {
        return $query->where('added_manually', true);
    }

    public function scopeRuleAdded(Builder $query): Builder
    {
        return $query->where('added_by_rule', true);
    }

    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->where('role_in_collection', $role);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // --- COMPUTED ---

    // Entity was added both manually and by rule
    public function isDoubleConfirmed(): bool
    {
        return $this->added_manually && $this->added_by_rule;
    }
}
