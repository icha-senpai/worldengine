<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class Glossary extends Model
{
    use SoftDeletes;

    protected $table = 'glossary';

    protected $fillable = [
        'term',
        'usage_context',
        'definition',
        'extended_notes',
        'origin_universe',
        'era_introduced',
        'era_obsolete',
        'term_status',
        'suppressed_by_entity_id',
        'suppression_notes',
        'first_appearance_entity_id',
        'related_term_ids',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'definition'        => 'array', // Tiptap JSON
        'extended_notes'    => 'array', // Tiptap JSON
        'suppression_notes' => 'array', // Tiptap JSON
        'related_term_ids'  => 'array',
        'deleted_at'        => 'datetime',
    ];

    const TERM_STATUSES = [
        'active',
        'obsolete',
        'suppressed',
        'disputed',
        'in_world_only',
        'meta_only',
        'both',
    ];

    const USAGE_CONTEXTS = [
        'in_world',    // Characters use this term
        'meta',        // Author-level label, not used in-world
        'both',        // Used both in-world and as author shorthand
    ];

    // --- RELATIONSHIPS ---

    public function suppressedBy(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'suppressed_by_entity_id');
    }

    public function firstAppearance(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'first_appearance_entity_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('term_status', 'active');
    }

    public function scopeSuppressed(Builder $query): Builder
    {
        return $query->where('term_status', 'suppressed');
    }

    public function scopeObsolete(Builder $query): Builder
    {
        return $query->where('term_status', 'obsolete');
    }

    public function scopeInWorld(Builder $query): Builder
    {
        return $query->whereIn('usage_context', ['in_world', 'both']);
    }

    public function scopeMeta(Builder $query): Builder
    {
        return $query->whereIn('usage_context', ['meta', 'both']);
    }

    public function scopeFromUniverse(Builder $query, string $universe): Builder
    {
        return $query->where('origin_universe', $universe);
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
        return $this->term_status === 'active';
    }

    public function isSuppressed(): bool
    {
        return $this->term_status === 'suppressed';
    }

    public function isInWorld(): bool
    {
        return in_array($this->usage_context, ['in_world', 'both'], true);
    }

    public function isMeta(): bool
    {
        return in_array($this->usage_context, ['meta', 'both'], true);
    }
}
