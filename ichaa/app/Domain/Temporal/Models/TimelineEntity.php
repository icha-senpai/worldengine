<?php

namespace App\Domain\Temporal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class TimelineEntity extends Model
{
    protected $table = 'timeline_entities';

    protected $fillable = [
        'timeline_id',
        'event_entity_id',
        'position',
        'perspective_label',
        'perspective_notes',
    ];

    protected $casts = [
        'position'          => 'decimal:6',
        'perspective_notes' => 'array', // Tiptap JSON
    ];

    // --- RELATIONSHIPS ---

    public function timeline(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'timeline_id');
    }

    public function eventEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'event_entity_id');
    }

    // --- SCOPES ---

    public function scopeOnTimeline(Builder $query, int $timelineEntityId): Builder
    {
        return $query->where('timeline_id', $timelineEntityId);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('position');
    }

    public function scopeWithPerspective(Builder $query, string $label): Builder
    {
        return $query->where('perspective_label', $label);
    }
}
