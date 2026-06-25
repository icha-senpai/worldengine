<?php

namespace App\Domain\World\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;
use App\Support\Database\PostgresPrefixSearch;

class GalacticRegion extends Model
{
    use SoftDeletes;

    protected $table = 'galactic_regions';

    protected $fillable = [
        'region_name',
        'region_type',
        'parent_region_id',
        'approximate_scale',
        'notable_features',
        'known_inhabited_systems',
        'strategic_significance',
        'controlling_entity_id',
        'control_era_start',
        'control_era_end',
        'is_fully_mapped',
        'mapping_notes',

        // Entity IDs of significant locations that have
        // graduated to full entity records
        'connected_location_entity_ids',

        'source_universe',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'notable_features'             => 'array', // Tiptap JSON
        'known_inhabited_systems'      => 'array',
        'strategic_significance'       => 'array', // Tiptap JSON
        'mapping_notes'                => 'array', // Tiptap JSON
        'connected_location_entity_ids'=> 'array',
        'is_fully_mapped'              => 'boolean',
        'deleted_at'                   => 'datetime',
    ];

    const REGION_TYPES = [
        'star_system',
        'sector',
        'quadrant',
        'arm',
        'galaxy',
        'void',
        'nebula',
        'cluster',
    ];

    // --- RELATIONSHIPS ---

    // Self-referencing hierarchy — star_system inside sector inside quadrant
    // nullOnDelete prevents cascade deletion of nested regions
    public function parentRegion(): BelongsTo
    {
        return $this->belongsTo(GalacticRegion::class, 'parent_region_id');
    }

    public function childRegions(): HasMany
    {
        return $this->hasMany(GalacticRegion::class, 'parent_region_id');
    }

    public function controllingEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'controlling_entity_id');
    }

    // --- SCOPES ---

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('region_type', $type);
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_region_id');
    }

    public function scopeFromUniverse(Builder $query, string $universe): Builder
    {
        return $query->where('source_universe', $universe);
    }

    public function scopeFullyMapped(Builder $query): Builder
    {
        return $query->where('is_fully_mapped', true);
    }

    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where('is_fully_mapped', false);
    }

    public function scopeControlledBy(Builder $query, int $entityId): Builder
    {
        return $query->where('controlling_entity_id', $entityId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return PostgresPrefixSearch::apply($query, $term);
    }

    // --- COMPUTED ---

    public function isTopLevel(): bool
    {
        return $this->parent_region_id === null;
    }

    public function isFullyMapped(): bool
    {
        return (bool) $this->is_fully_mapped;
    }

    public function hasGraduatedLocations(): bool
    {
        return !empty($this->connected_location_entity_ids);
    }

    // Add a graduated entity ID to the connected locations array
    // Called by WorldService when a location graduates to a full entity
    public function addGraduatedLocation(int $entityId): void
    {
        $connected   = $this->connected_location_entity_ids ?? [];
        $connected[] = $entityId;

        $this->update(['connected_location_entity_ids' => array_unique($connected)]);
    }
}
