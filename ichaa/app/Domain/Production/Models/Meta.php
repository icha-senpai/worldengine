<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\Models\GroupRelationship;

class Meta extends Model
{
    use SoftDeletes;

    protected $table = 'meta';

    protected $fillable = [
        'title',
        'meta_type',
        'status',
        'synopsis',
        'full_outline',
        'themes',
        'author_notes',
        'target_word_count',
        'current_word_count',
        'draft_version',
        'target_completion_era',
        'started_at',
        'completed_at',
        'sort_order',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'synopsis'      => 'array', // Tiptap JSON
        'full_outline'  => 'array', // Tiptap JSON
        'themes'        => 'array', // Tiptap JSON
        'author_notes'  => 'array', // Tiptap JSON
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'sort_order'    => 'integer',
        'deleted_at'    => 'datetime',
    ];

    const META_TYPES = [
        'novel',
        'novella',
        'short_story',
        'arc',
        'chapter',
        'scene',
        'anthology',
        'screenplay',
        'outline_only',
    ];

    const STATUSES = [
        'planned',
        'outlining',
        'drafting',
        'revising',
        'complete',
        'abandoned',
        'on_hold',
    ];

    // --- RELATIONSHIPS ---

    public function pipelineItems(): HasMany
    {
        return $this->hasMany(PipelineItem::class, 'meta_id')
            ->orderBy('sort_order');
    }

    public function pendingItems(): HasMany
    {
        return $this->hasMany(PipelineItem::class, 'meta_id')
            ->whereNotIn('status', ['done', 'dropped'])
            ->orderBy('sort_order');
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'meta_entities',
            'meta_id',
            'entity_id'
        )->withPivot(['role_in_meta', 'appearance_notes', 'sort_order'])
         ->withTimestamps()
         ->orderByPivot('sort_order');
    }

    public function groupRelationships(): BelongsToMany
    {
        return $this->belongsToMany(
            GroupRelationship::class,
            'meta_group_relationships',
            'meta_id',
            'group_relationship_id'
        )->withPivot(['connection_notes'])
         ->withTimestamps();
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('meta_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['outlining', 'drafting', 'revising']);
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('status', 'complete');
    }

    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('status', 'planned');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function isActive(): bool
    {
        return in_array($this->status, ['outlining', 'drafting', 'revising'], true);
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function wordCountProgress(): ?float
    {
        if (!$this->target_word_count || !$this->current_word_count) {
            return null;
        }

        return round(($this->current_word_count / $this->target_word_count) * 100, 1);
    }

    public function hasPendingItems(): bool
    {
        return $this->pendingItems()->exists();
    }
}
