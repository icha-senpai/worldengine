<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class MediaReference extends Model
{
    use SoftDeletes;

    protected $table = 'media_references';

    protected $fillable = [
        // Attachment targets — exactly one should be populated
        'entity_id',
        'group_relationship_id',
        'collection_id',
        'meta_id',
        'timeline_entry_id',
        'concurrency_group_id',
        'source_canon_reference_id',

        // Media identity
        'title',
        'description',
        'media_type',
        'purpose',

        // File
        'file_path',
        'url',
        'file_name',
        'file_extension',
        'file_size',
        'mime_type',

        // Image dimensions
        'width',
        'height',

        // Display
        'sort_order',
        'is_primary',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'file_size'   => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
        'sort_order'  => 'integer',
        'deleted_at'  => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    // --- SCOPES ---

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('media_type', $type);
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public function scopeImages(Builder $query): Builder
    {
        return $query->where('media_type', 'image');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // --- COMPUTED ---

    public function isLocalFile(): bool
    {
        return $this->file_path !== null;
    }

    public function isExternalLink(): bool
    {
        return $this->url !== null;
    }

    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    // Which record this media is attached to
    // Returns ['type' => string, 'id' => int]
    public function attachmentTarget(): array
    {
        return match(true) {
            $this->entity_id               !== null => ['type' => 'entity',                'id' => $this->entity_id],
            $this->group_relationship_id   !== null => ['type' => 'group_relationship',    'id' => $this->group_relationship_id],
            $this->collection_id           !== null => ['type' => 'collection',            'id' => $this->collection_id],
            $this->meta_id                 !== null => ['type' => 'meta',                  'id' => $this->meta_id],
            $this->timeline_entry_id       !== null => ['type' => 'timeline_entry',        'id' => $this->timeline_entry_id],
            $this->concurrency_group_id    !== null => ['type' => 'concurrency_group',     'id' => $this->concurrency_group_id],
            $this->source_canon_reference_id !== null => ['type' => 'source_canon_reference', 'id' => $this->source_canon_reference_id],
            default                                 => ['type' => 'unknown',               'id' => null],
        };
    }
}
