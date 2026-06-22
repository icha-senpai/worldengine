<?php

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\User;

class Revision extends Model
{
    protected $table = 'revisions';

    protected $fillable = [
        'resource_type',
        'resource_id',
        'action',
        'before_payload',
        'after_payload',
        'diff_payload',
        'reason',
        'source',
        'actor_user_id',
        'token_name',
        'base_revision_id',
        'restored_from_revision_id',
    ];

    protected $casts = [
        'before_payload' => 'array',
        'after_payload' => 'array',
        'diff_payload' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_revision_id');
    }

    public function scopeForResource(Builder $query, string $resourceType, int|string $resourceId): Builder
    {
        return $query
            ->where('resource_type', $resourceType)
            ->where('resource_id', (string) $resourceId);
    }
}
