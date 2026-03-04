<?php

namespace App\Domain\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class PipelineItem extends Model
{
    use SoftDeletes;

    // Actual table name in the database
    protected $table = 'writing_pipeline';

    protected $fillable = [
        'title',
        'pipeline_type',
        'parent_pipeline_item_id',
        'sort_order',
        'pipeline_stage',
        'content',
        'word_count',
        'reading_time_minutes',
        'revision_history',
        'timeline_entry_id',
        'timeline_position',
        'pov_character_entity_id',
        'location_entity_id',
        'emotional_beat',
        'narrative_purpose',
        'scene_content_warnings',
        'sensory_palette_meta_id',
        'speaker_entity_id',
        'speakers_entity_ids',
        'add_to_voice_samples',
        'tracked_entity_id',
        'arc_stage',
        'arc_notes',
        'inspiration_source_universe',
        'inspiration_source_element',
        'influenced_entity_ids',
        'how_used',
        'how_changed',
        'deviation_level',
        'why_it_fits',
        'notes',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'content'                 => 'string',
        'revision_history'        => 'array',
        'scene_content_warnings'  => 'array',
        'speakers_entity_ids'     => 'array',
        'influenced_entity_ids'   => 'array',
        'notes'                   => 'string',
        'add_to_voice_samples'    => 'boolean',
        'sort_order'              => 'integer',
        'word_count'              => 'integer',
        'reading_time_minutes'    => 'integer',
        'timeline_position'       => 'integer',
        'deleted_at'              => 'datetime',
    ];

    const PIPELINE_TYPES = [
        'scene',
        'chapter',
        'arc',
        'interlude',
        'prologue',
        'epilogue',
        'outline',
        'note',
        'inspiration',
        'character_study',
    ];

    const PIPELINE_STAGES = [
        'concept',
        'outlined',
        'drafted',
        'revised',
        'complete',
        'cut',
    ];

    // --- RELATIONSHIPS ---

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PipelineItem::class, 'parent_pipeline_item_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PipelineItem::class, 'parent_pipeline_item_id')
            ->orderBy('sort_order');
    }

    public function povCharacter(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'pov_character_entity_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'location_entity_id');
    }

    public function trackedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'tracked_entity_id');
    }

    public function sensoryPalette(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'sensory_palette_meta_id');
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('pipeline_type', $type);
    }

    public function scopeAtStage(Builder $query, string $stage): Builder
    {
        return $query->where('pipeline_stage', $stage);
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_pipeline_item_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeWithWords(Builder $query): Builder
    {
        return $query->where('word_count', '>', 0);
    }

    public function scopeForEntity(Builder $query, int $entityId): Builder
    {
        return $query->where('tracked_entity_id', $entityId)
            ->orWhere('pov_character_entity_id', $entityId);
    }

    // --- COMPUTED ---

    public function isComplete(): bool
    {
        return $this->pipeline_stage === 'complete';
    }

    public function isCut(): bool
    {
        return $this->pipeline_stage === 'cut';
    }

    public function readingTimeFormatted(): string
    {
        if (!$this->reading_time_minutes) {
            return '—';
        }

        $h = intdiv($this->reading_time_minutes, 60);
        $m = $this->reading_time_minutes % 60;

        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }
}