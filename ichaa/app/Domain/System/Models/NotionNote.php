<?php

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotionNote extends Model
{
    protected $table = 'notion_notes';

    protected $fillable = [
        'sync_resource',
        'notion_page_id',
        'noteable_type',
        'noteable_id',
        'content',
        'content_hash',
        'notion_last_edited_at',
        'last_synced_at',
    ];

    protected $casts = [
        'notion_last_edited_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForPage(Builder $query, string $pageId): Builder
    {
        return $query->where('notion_page_id', $pageId);
    }

    public function scopeForModel(Builder $query, Model $model, ?string $resource = null): Builder
    {
        $query = $query
            ->where('noteable_type', $model::class)
            ->where('noteable_id', $model->getKey());

        if (filled($resource)) {
            $query->where('sync_resource', $resource);
        }

        return $query;
    }
}
