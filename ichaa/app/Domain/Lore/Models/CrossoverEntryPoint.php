<?php

namespace App\Domain\Lore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

use App\Domain\Identity\Models\Entity;

class CrossoverEntryPoint extends Model
{
    use SoftDeletes;

    protected $table = 'crossover_entry_points';

    protected $fillable = [
        'source_universe',
        'entry_mechanism',
        'power_transition_rules',
        'physical_transition_rules',
        'memory_and_identity_rules',
        'psychological_transition_rules',
        'canon_deviation_notes',
        'known_examples',
        'known_entry_points',
        'status',
        'restrictions',
        'return_rules',
        'first_documented_crossing_event_id',
        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'entry_mechanism'                => 'array', // Tiptap JSON
        'power_transition_rules'         => 'array', // Tiptap JSON
        'physical_transition_rules'      => 'array', // Tiptap JSON
        'memory_and_identity_rules'      => 'array', // Tiptap JSON
        'psychological_transition_rules' => 'array', // Tiptap JSON
        'canon_deviation_notes'          => 'array', // Tiptap JSON
        'restrictions'                   => 'array', // Tiptap JSON
        'return_rules'                   => 'array', // Tiptap JSON
        'known_examples'                 => 'array', // JSONB entity ID array
        'known_entry_points'             => 'array', // JSONB entity ID array
        'deleted_at'                     => 'datetime',
    ];

    const STATUSES = [
        'theorized',
        'established',
        'documented',
    ];

    // --- RELATIONSHIPS ---

    // The event entity that first documented this crossing
    public function firstDocumentedCrossingEvent(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'first_documented_crossing_event_id');
    }

    // Source canon reference records that point to this entry point
    public function canonReferences(): HasMany
    {
        return $this->hasMany(SourceCanonReference::class, 'crossover_entry_point_id');
    }

    // --- SCOPES ---

    public function scopeForUniverse(Builder $query, string $universe): Builder
    {
        return $query->where('source_universe', $universe);
    }

    public function scopeEstablished(Builder $query): Builder
    {
        return $query->where('status', 'established');
    }

    public function scopeTheorized(Builder $query): Builder
    {
        return $query->where('status', 'theorized');
    }

    public function scopeDocumented(Builder $query): Builder
    {
        return $query->where('status', 'documented');
    }

    // --- COMPUTED ---

    public function isEstablished(): bool
    {
        return $this->status === 'established';
    }

    public function isTheorized(): bool
    {
        return $this->status === 'theorized';
    }

    public function hasReturnPath(): bool
    {
        return !empty($this->return_rules);
    }

    public function hasKnownExamples(): bool
    {
        return !empty($this->known_examples);
    }
}
