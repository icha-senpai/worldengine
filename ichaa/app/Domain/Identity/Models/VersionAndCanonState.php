<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class VersionAndCanonState extends Model
{
    use SoftDeletes;

    protected $table = 'versions_and_canon_states';

    protected $fillable = [
        'entity_id',
        'version_type',
        'version_number',
        'version_label',
        'version_state',
        'is_current',
        'is_version_zero',
        'version_zero_confidence',
        'version_zero_notes',
        'entity_snapshot',
        'what_changed',
        'why_changed',
        'trigger_type',
        'triggered_by_field',
        'valid_from_era',
        'valid_until_era',

        // Hard iteration fields
        'iteration_number',
        'source_entity_id',
        'retained_from_previous',
        'what_failed',
        'failure_era',
        'terminated_by_entity_id',

        // Deprecation fields
        'deprecated_at',
        'deprecation_reason',
        'superseded_by_version_id',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'is_current'         => 'boolean',
        'is_version_zero'    => 'boolean',
        'entity_snapshot'    => 'array',
        'what_changed'       => 'array', // Tiptap JSON
        'why_changed'        => 'array', // Tiptap JSON
        'retained_from_previous' => 'array', // Tiptap JSON
        'deprecation_reason' => 'array', // Tiptap JSON
        'version_number'     => 'integer',
        'iteration_number'   => 'integer',
        'deprecated_at'      => 'datetime',
        'deleted_at'         => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // The abstract parent construct for hard iterations
    // Harry v1-v69 all point to the abstract Harry construct entity
    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    // Who terminated this iteration
    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'terminated_by_entity_id');
    }

    // The version that superseded this deprecated version
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(VersionAndCanonState::class, 'superseded_by_version_id');
    }

    // --- SCOPES ---

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeVersionZero(Builder $query): Builder
    {
        return $query->where('is_version_zero', true);
    }

    public function scopeHardIterations(Builder $query): Builder
    {
        return $query->where('version_type', 'hard_iteration');
    }

    public function scopeSoftVersions(Builder $query): Builder
    {
        return $query->where('version_type', 'soft');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('version_state', 'iteration_failed');
    }

    public function scopeDeprecated(Builder $query): Builder
    {
        return $query->where('version_state', 'deprecated');
    }

    public function scopeForConstruct(Builder $query, int $sourceEntityId): Builder
    {
        return $query->where('source_entity_id', $sourceEntityId)
            ->orderBy('iteration_number');
    }

    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('trigger_type', 'automatic');
    }

    public function scopeTriggeredBy(Builder $query, string $field): Builder
    {
        return $query->where('triggered_by_field', $field);
    }

    // --- COMPUTED ---

    public function isHardIteration(): bool
    {
        return $this->version_type === 'hard_iteration';
    }

    public function isSoftVersion(): bool
    {
        return $this->version_type === 'soft';
    }

    public function hasFailed(): bool
    {
        return $this->version_state === 'iteration_failed';
    }

    public function isDeprecated(): bool
    {
        return $this->version_state === 'deprecated';
    }

    public function wasAutomaticallyTriggered(): bool
    {
        return $this->trigger_type === 'automatic';
    }
}
