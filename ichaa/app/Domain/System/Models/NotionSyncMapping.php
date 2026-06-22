<?php

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotionSyncMapping extends Model
{
    protected $table = 'notion_sync_mappings';

    protected $fillable = [
        'sync_resource',
        'notion_page_id',
        'notion_parent_database_id',
        'local_model_type',
        'local_model_id',
        'notion_last_edited_at',
        'last_synced_at',
        'last_payload_hash',
    ];

    protected $casts = [
        'notion_last_edited_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function scopeForResource(Builder $query, string $resource): Builder
    {
        return $query->where('sync_resource', $resource);
    }

    public function scopeForNotionPage(Builder $query, string $pageId): Builder
    {
        return $query->where('notion_page_id', $pageId);
    }
}
