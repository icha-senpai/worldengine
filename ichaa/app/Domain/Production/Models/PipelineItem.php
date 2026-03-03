<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class PipelineItem extends Model
{
    use SoftDeletes;

    protected $table = 'pipeline_items';

    protected $fillable = [
        'meta_id',
        'entity_id',
        'item_type',
        'title',
        'description',
        'status',
        'priority',
        'blocking_question',
        'resolution_notes',
        'source_session_log_id',
        'resolved_at',
        'due_era',
        'sort_order',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'description'      => 'array', // Tiptap JSON
        'resolution_notes' => 'array', // Tiptap JSON
        'resolved_at'      => 'datetime',
        'sort_order'       => 'integer',
        'deleted_at'       => 'datetime',
    ];

    const ITEM_TYPES = [
        'research_needed',
        'decision_pending',
        'scene_to_write',
        'continuity_check',
        'character_question',
        'plot_hole',
        'revision_note',
        'world_building_gap',
        'consistency_issue',
        'structural_note',
    ];

    const STATUSES = [
        'backlog',
        'in_progress',
        'blocked',
        'done',
        'dropped',
    ];

    const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    // --- RELATIONSHIPS ---

    public function meta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'meta_id');
    }

    // The entity this pipeline item concerns — optional
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(SessionLog::class, 'source_session_log_id');
    }

    // --- SCOPES ---

    public function scopeForMeta(Builder $query, int $metaId): Builder
    {
        return $query->where('meta_id', $metaId);
    }

    public function scopeForEntity(Builder $query, int $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['done', 'dropped']);
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('status', 'blocked');
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('priority', 'critical');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('item_type', $type);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeForDashboard(Builder $query): Builder
    {
        return $query->whereIn('priority', ['high', 'critical'])
            ->whereNotIn('status', ['done', 'dropped'])
            ->orderByRaw("
                CASE priority
                    WHEN 'critical' THEN 1
                    WHEN 'high'     THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('sort_order');
    }

    // --- COMPUTED ---

    public function isPending(): bool
    {
        return !in_array($this->status, ['done', 'dropped'], true);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isCritical(): bool
    {
        return $this->priority === 'critical';
    }

    public function resolve(array $resolutionNotes = []): void
    {
        $this->update([
            'status'           => 'done',
            'resolution_notes' => $resolutionNotes ?: $this->resolution_notes,
            'resolved_at'      => now(),
        ]);
    }
}
