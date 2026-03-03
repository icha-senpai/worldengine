<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class EntityAlias extends Model
{
    use SoftDeletes;

    protected $table = 'entity_aliases';

    protected $fillable = [
        'entity_id',
        'alias',
        'alias_type',
        'context',
        'era_start',
        'era_end',
        'is_active',
        'known_by_entity_ids',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'known_by_entity_ids'  => 'array',
        'deleted_at'           => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('alias_type', $type);
    }

    // Public aliases — known_by_entity_ids is empty meaning universally known
    public function scopePubliclyKnown(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('known_by_entity_ids')
              ->orWhereJsonLength('known_by_entity_ids', 0);
        });
    }

    // Aliases known by a specific entity
    public function scopeKnownBy(Builder $query, int $entityId): Builder
    {
        return $query->where(function (Builder $q) use ($entityId) {
            $q->whereJsonLength('known_by_entity_ids', 0)
              ->orWhereJsonContains('known_by_entity_ids', $entityId);
        });
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isPubliclyKnown(): bool
    {
        return empty($this->known_by_entity_ids);
    }

    public function isActiveInEra(string $era): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // If no era bounds set, alias is always active
        if (!$this->era_start && !$this->era_end) {
            return true;
        }

        // Era comparison is string-based — application layer handles ordering
        // This is a simple boundary check
        if ($this->era_start && $era < $this->era_start) {
            return false;
        }

        if ($this->era_end && $era > $this->era_end) {
            return false;
        }

        return true;
    }
}
