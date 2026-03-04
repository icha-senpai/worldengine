<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Production\Models\SessionLog;

class EntityQuestion extends Model
{
    use SoftDeletes;

    protected $table = 'entity_questions';

    protected $fillable = [
        'entity_id',
        'question',
        'context',
        'status',
        'resolution',
        'resolved_at',
        'priority',
        'linked_entity_ids',
        'linked_group_relationship_ids',
        'source_session_log_id',
        'sort_order',
    ];

    protected $casts = [
        'context'                       => 'string',
        'resolution'                    => 'string',
        'linked_entity_ids'             => 'array',
        'linked_group_relationship_ids' => 'array',
        'resolved_at'                   => 'datetime',
        'sort_order'                    => 'integer',
        'deleted_at'                    => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(SessionLog::class, 'source_session_log_id');
    }

    // --- SCOPES ---

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('priority', 'blocking');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'deferred']);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderByRaw("
            CASE priority
                WHEN 'blocking' THEN 1
                WHEN 'high'     THEN 2
                WHEN 'medium'   THEN 3
                WHEN 'low'      THEN 4
                ELSE 5
            END
        ");
    }

    public function scopeForDashboard(Builder $query): Builder
    {
        return $query->blocking()->unresolved();
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isBlocking(): bool
    {
        return $this->priority === 'blocking';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }
}