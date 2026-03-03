<?php

namespace App\Domain\Lore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'title',
        'document_type',
        'owner_entity_id',
        'official_author_entity_id',
        'true_author_entity_id',
        'document_status',
        'document_authenticity',
        'official_narrative',
        'true_content',
        'authorship_divergence_notes',
        'era_created',
        'era_discovered',
        'parent_document_id',
        'version_number',
        'superseded_by_document_id',
        'suppressed_by_entity_id',
        'suppression_notes',
        'access_level',
        'known_by_entity_ids',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'official_narrative'           => 'array', // Tiptap JSON
        'true_content'                 => 'array', // Tiptap JSON
        'authorship_divergence_notes'  => 'array', // Tiptap JSON
        'suppression_notes'            => 'array', // Tiptap JSON
        'known_by_entity_ids'          => 'array',
        'version_number'               => 'integer',
        'deleted_at'                   => 'datetime',
    ];

    const DOCUMENT_TYPES = [
        'legal_code',
        'treaty',
        'prophecy',
        'scripture',
        'correspondence',
        'historical_record',
        'manifesto',
        'contract',
        'intelligence_report',
        'personal_journal',
        'research_notes',
        'map',
        'decree',
        'testimony',
        'myth_text',
        'ritual_text',
        'technical_document',
        'other',
    ];

    const AUTHENTICITY_STATES = [
        'authentic',
        'forged',
        'partially_forged',
        'redacted',
        'corrupted',
        'translated',
        'disputed',
        'unknown',
    ];

    const DOCUMENT_STATUSES = [
        'extant',
        'destroyed',
        'lost',
        'suppressed',
        'classified',
        'public',
    ];

    // --- RELATIONSHIPS ---

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'owner_entity_id');
    }

    public function officialAuthor(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'official_author_entity_id');
    }

    public function trueAuthor(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'true_author_entity_id');
    }

    public function suppressedBy(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'suppressed_by_entity_id');
    }

    // Version chain
    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_document_id');
    }

    public function childVersions(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_document_id')
            ->orderBy('version_number');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'superseded_by_document_id');
    }

    public function documentEntities(): HasMany
    {
        return $this->hasMany(DocumentEntity::class, 'document_id');
    }

    public function involvedEntities(): BelongsToMany
    {
        return $this->belongsToMany(
            Entity::class,
            'document_entities',
            'document_id',
            'entity_id'
        )->withPivot(['relationship_type', 'notes'])
         ->withTimestamps();
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopeExtant(Builder $query): Builder
    {
        return $query->where('document_status', 'extant');
    }

    public function scopeSuppressed(Builder $query): Builder
    {
        return $query->where('document_status', 'suppressed');
    }

    public function scopeForged(Builder $query): Builder
    {
        return $query->whereIn('document_authenticity', ['forged', 'partially_forged']);
    }

    public function scopeWithAuthorshipDivergence(Builder $query): Builder
    {
        return $query->whereColumn('official_author_entity_id', '!=', 'true_author_entity_id')
            ->whereNotNull('true_author_entity_id');
    }

    public function scopeVersionsOf(Builder $query, int $parentId): Builder
    {
        return $query->where('parent_document_id', $parentId)
            ->orderBy('version_number');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }

    // --- COMPUTED ---

    public function hasAuthorshipDivergence(): bool
    {
        return $this->true_author_entity_id !== null
            && $this->true_author_entity_id !== $this->official_author_entity_id;
    }

    public function isForged(): bool
    {
        return in_array($this->document_authenticity, ['forged', 'partially_forged'], true);
    }

    public function isSuppressed(): bool
    {
        return $this->document_status === 'suppressed';
    }

    public function isVersioned(): bool
    {
        return $this->parent_document_id !== null;
    }

    public function isKnownBy(int $entityId): bool
    {
        return empty($this->known_by_entity_ids)
            || in_array($entityId, $this->known_by_entity_ids, true);
    }
}
