<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class LocationControlHistory extends Model
{
    use SoftDeletes;

    protected $table = 'location_control_history';

    protected $fillable = [
        'location_entity_id',
        'controlling_entity_id',
        'control_type',
        'control_start_era',
        'control_end_era',
        'is_current',
        'how_control_was_established',
        'how_control_ended',
        'resistance_level',
        'resistance_entity_id',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'how_control_was_established' => 'array', // Tiptap JSON
        'how_control_ended'           => 'array', // Tiptap JSON
        'notes'                       => 'array', // Tiptap JSON
        'is_current'                  => 'boolean',
        'deleted_at'                  => 'datetime',
    ];

    const CONTROL_TYPES = [
        'sovereign',   // Full legitimate authority
        'occupied',    // Military occupation
        'contested',   // Multiple parties claim control
        'puppet',      // Nominally independent, actually controlled
        'abandoned',   // No current controlling entity
        'neutral',     // Formally neutral territory
        'protected',   // Under protection of another entity
    ];

    const RESISTANCE_LEVELS = [
        'none',
        'minor',
        'significant',
        'active_conflict',
    ];

    // --- RELATIONSHIPS ---

    public function location(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'location_entity_id');
    }

    public function controllingEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'controlling_entity_id');
    }

    public function resistanceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'resistance_entity_id');
    }

    // --- SCOPES ---

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeForLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_entity_id', $locationId);
    }

    public function scopeControlledBy(Builder $query, int $entityId): Builder
    {
        return $query->where('controlling_entity_id', $entityId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('control_type', $type);
    }

    public function scopePuppet(Builder $query): Builder
    {
        return $query->where('control_type', 'puppet');
    }

    public function scopeContested(Builder $query): Builder
    {
        return $query->where('control_type', 'contested');
    }

    public function scopeWithActiveResistance(Builder $query): Builder
    {
        return $query->where('resistance_level', 'active_conflict');
    }

    // --- COMPUTED ---

    public function isCurrent(): bool
    {
        return (bool) $this->is_current;
    }

    public function isPuppet(): bool
    {
        return $this->control_type === 'puppet';
    }

    public function hasActiveResistance(): bool
    {
        return $this->resistance_level === 'active_conflict';
    }
}
