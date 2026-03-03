<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SessionLog extends Model
{
    use SoftDeletes;

    // Actual table name — no trailing 's'
    protected $table = 'session_log';

    protected $fillable = [
        'title',
        'session_date',
        'external_tool',
        'focus_entity_ids',
        'focus_group_relationship_ids',
        'focus_collection_ids',
        'focus_description',
        'decisions_made',
        'changes_applied',
        'open_threads',
        'follow_up_question_ids',
        'session_significance',
        'notes',
    ];

    protected $casts = [
        'focus_entity_ids'             => 'array',
        'focus_group_relationship_ids' => 'array',
        'focus_collection_ids'         => 'array',
        'follow_up_question_ids'       => 'array',
        'decisions_made'               => 'array',
        'changes_applied'              => 'array',
        'open_threads'                 => 'array',
        'notes'                        => 'array',
        'session_date'                 => 'date',
        'deleted_at'                   => 'datetime',
    ];

    const SIGNIFICANCE_LEVELS = [
        'minor',
        'moderate',
        'major',
    ];

    // --- RELATIONSHIPS ---

    public function entityQuestions(): HasMany
    {
        return $this->hasMany(
            \App\Domain\Identity\Models\EntityQuestion::class,
            'source_session_log_id'
        );
    }

    // --- SCOPES ---

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('session_date', '>=', now()->subDays($days)->toDateString());
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereYear('session_date', now()->year)
            ->whereMonth('session_date', now()->month);
    }

    public function scopeOfSignificance(Builder $query, string $level): Builder
    {
        return $query->where('session_significance', $level);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('session_date');
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('session_date');
    }

    // --- COMPUTED ---

    public function isMajor(): bool
    {
        return $this->session_significance === 'major';
    }

    public function focusEntityCount(): int
    {
        return count($this->focus_entity_ids ?? []);
    }

    public function hasOpenThreads(): bool
    {
        $threads = $this->open_threads ?? [];
        return !empty($threads);
    }
}