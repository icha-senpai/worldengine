<?php

namespace App\Domain\Identity\Models;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Production\Models\Meta;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class MediaReference extends Model
{
    use SoftDeletes;

    public const MEDIA_TYPES = [
        'image',
        'video',
        'audio',
        'document',
        'link',
    ];

    public const PURPOSES = [
        'reference',
        'inspiration',
        'portrait',
        'map',
        'mood',
        'symbol',
        'era_visual',
        'galactic_map',
        'power_system_diagram',
        'other',
    ];

    public const ATTACHMENT_FIELDS = [
        'entity' => 'entity_id',
        'group_relationship' => 'group_relationship_id',
        'collection' => 'collection_id',
        'meta' => 'meta_id',
        'timeline_entry' => 'timeline_entry_id',
        'concurrency_group' => 'concurrency_group_id',
        'source_canon_reference' => 'source_canon_reference_id',
    ];

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
        'file_size_bytes',
        'mime_type',

        // Image dimensions
        'width_px',
        'height_px',

        // Display
        'sort_order',
        'is_primary',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'file_size_bytes' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
        'sort_order'  => 'integer',
        'deleted_at'  => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function groupRelationship(): BelongsTo
    {
        return $this->belongsTo(GroupRelationship::class, 'group_relationship_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function meta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'meta_id');
    }

    public function timelineEntry(): BelongsTo
    {
        return $this->belongsTo(Timeline::class, 'timeline_entry_id');
    }

    public function concurrencyGroup(): BelongsTo
    {
        return $this->belongsTo(ConcurrencyGroup::class, 'concurrency_group_id');
    }

    public function sourceCanonReference(): BelongsTo
    {
        return $this->belongsTo(SourceCanonReference::class, 'source_canon_reference_id');
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

    public function isManagedUpload(): bool
    {
        if (! is_string($this->file_path) || $this->file_path === '') {
            return false;
        }

        $root = Storage::disk('public')->path('media-library');
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->file_path);
        $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root);

        return str_starts_with($normalizedPath, $normalizedRoot);
    }

    public function widthPx(): ?int
    {
        return $this->width_px;
    }

    public function heightPx(): ?int
    {
        return $this->height_px;
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
