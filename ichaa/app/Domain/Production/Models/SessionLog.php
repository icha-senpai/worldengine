<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SessionLog extends Model
{
    use SoftDeletes;

    protected $table = 'session_logs';

    protected $fillable = [
        'session_date',
        'session_title',
        'session_type',
        'summary',
        'decisions_made',
        'questions_raised',
        'entities_created_ids',
        'entities_modified_ids',
        'relationships_created_ids',
        'documents_created_ids',
        'word_count_added',
        'mood_rating',
        'energy_level',
        'session_notes',
        'next_session_goals',
        'duration_minutes',
    ];

    protected $casts = [
        'summary'                    => 'array', // Tiptap JSON
        'decisions_made'             => 'array', // Tiptap JSON
        'questions_raised'           => 'array', // Tiptap JSON
        'session_notes'              => 'array', // Tiptap JSON
        'next_session_goals'         => 'array', // Tiptap JSON
        'entities_created_ids'       => 'array',
        'entities_modified_ids'      => 'array',
        'relationships_created_ids'  => 'array',
        'documents_created_ids'      => 'array',
        'session_date'               => 'date',
        'deleted_at'                 => 'datetime',
    ];

    const SESSION_TYPES = [
        'world_building',
        'character_development',
        'plot_planning',
        'drafting',
        'revision',
        'research',
        'brainstorming',
        'mixed',
    ];

    const MOOD_RATINGS = [1, 2, 3, 4, 5];

    const ENERGY_LEVELS = [
        'low',
        'medium',
        'high',
    ];

    // --- RELATIONSHIPS ---

    // Questions raised during this session that became entity questions
    public function entityQuestions(): HasMany
    {
        return $this->hasMany(
            \App\Domain\Identity\Models\EntityQuestion::class,
            'source_session_log_id'
        );
    }

    // Pipeline items created or linked to this session
    public function pipelineItems(): HasMany
    {
        return $this->hasMany(PipelineItem::class, 'source_session_log_id');
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('session_type', $type);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('session_date', '>=', now()->subDays($days)->toDateString());
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereYear('session_date', now()->year)
            ->whereMonth('session_date', now()->month);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('session_date');
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('session_date');
    }

    public function scopeProductiveSessions(Builder $query): Builder
    {
        return $query->where('word_count_added', '>', 0);
    }

    // --- COMPUTED ---

    public function entityCreatedCount(): int
    {
        return count($this->entities_created_ids ?? []);
    }

    public function entityModifiedCount(): int
    {
        return count($this->entities_modified_ids ?? []);
    }

    public function wasProductive(): bool
    {
        return ($this->word_count_added ?? 0) > 0
            || $this->entityCreatedCount() > 0;
    }

    public function addCreatedEntity(int $entityId): void
    {
        $ids   = $this->entities_created_ids ?? [];
        $ids[] = $entityId;

        $this->update(['entities_created_ids' => array_unique($ids)]);
    }

    public function addModifiedEntity(int $entityId): void
    {
        $ids   = $this->entities_modified_ids ?? [];
        $ids[] = $entityId;

        $this->update(['entities_modified_ids' => array_unique($ids)]);
    }
}
