<?php

namespace App\Domain\Temporal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class ConcurrencyGroup extends Model
{
    use SoftDeletes;

    protected $table = 'concurrency_groups';

    protected $fillable = [
        'name',
        'au_date',
        'description',
        'narrative_significance',
    ];

    protected $casts = [
        'description'          => 'array', // Tiptap JSON
        'deleted_at'           => 'datetime',
    ];

    const SIGNIFICANCE_LEVELS = [
        'minor',
        'moderate',
        'major',
        'pivotal',
    ];

    // --- RELATIONSHIPS ---

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(Timeline::class, 'concurrency_group_id');
    }

    // --- SCOPES ---

    public function scopePivotal(Builder $query): Builder
    {
        return $query->where('narrative_significance', 'pivotal');
    }

    public function scopeMajor(Builder $query): Builder
    {
        return $query->whereIn('narrative_significance', ['major', 'pivotal']);
    }

    public function scopeAtDate(Builder $query, string $auDate): Builder
    {
        return $query->where('au_date', $auDate);
    }

    // --- COMPUTED ---

    public function isPivotal(): bool
    {
        return $this->narrative_significance === 'pivotal';
    }

    public function eventCount(): int
    {
        return $this->timelineEntries()->count();
    }
}
