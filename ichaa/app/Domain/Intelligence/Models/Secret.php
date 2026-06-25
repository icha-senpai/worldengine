<?php

namespace App\Domain\Intelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Database\PostgresPrefixSearch;

class Secret extends Model
{
    use SoftDeletes;

    protected $table = 'secrets';

    protected $fillable = [
        'title',
        'secret_content',
        'secret_type',

        // JSONB arrays of entity IDs
        'subject_entity_ids',   // Who the secret is about
        'holder_entity_ids',    // Actively concealing it
        'known_by_entity_ids',  // Everyone who knows (includes holders)

        'exposure_risk',
        'exposure_consequences',
        'revelation_trigger',
        'status',
        'revealed_at_era',

        // Links to related intelligence records
        'related_knowledge_state_ids',
        'related_perception_state_ids',

        'visibility',
        'content_classification',
    ];

    protected $casts = [
        'secret_content'              => 'array', // Tiptap JSON
        'exposure_consequences'       => 'array', // Tiptap JSON
        'subject_entity_ids'          => 'array',
        'holder_entity_ids'           => 'array',
        'known_by_entity_ids'         => 'array',
        'related_knowledge_state_ids' => 'array',
        'related_perception_state_ids'=> 'array',
        'deleted_at'                  => 'datetime',
    ];

    const SECRET_TYPES = [
        'identity',
        'event',
        'relationship',
        'power',
        'origin',
        'plan',
        'location',
        'cosmological',
    ];

    const EXPOSURE_RISKS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    const STATUSES = [
        'active',
        'partially_exposed',
        'fully_exposed',
        'irrelevant',
    ];

    // --- RELATIONSHIPS ---

    // Knowledge states where this secret is the subject
    public function knowledgeStates(): HasMany
    {
        return $this->hasMany(KnowledgeState::class, 'subject_secret_id');
    }

    // --- SCOPES ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePartiallyExposed(Builder $query): Builder
    {
        return $query->where('status', 'partially_exposed');
    }

    public function scopeHighRisk(Builder $query): Builder
    {
        return $query->whereIn('exposure_risk', ['high', 'critical']);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('exposure_risk', 'critical');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('secret_type', $type);
    }

    public function scopeAboutEntity(Builder $query, int $entityId): Builder
    {
        return $query->whereJsonContains('subject_entity_ids', $entityId);
    }

    public function scopeHeldBy(Builder $query, int $entityId): Builder
    {
        return $query->whereJsonContains('holder_entity_ids', $entityId);
    }

    public function scopeKnownBy(Builder $query, int $entityId): Builder
    {
        return $query->whereJsonContains('known_by_entity_ids', $entityId);
    }

    // Secrets where known_by has grown beyond holders
    // Signals the secret is leaking at the edges
    public function scopeLeaking(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereRaw(
                "jsonb_array_length(known_by_entity_ids) > jsonb_array_length(holder_entity_ids)"
            );
    }

    public function scopeForDashboard(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'partially_exposed'])
            ->whereIn('exposure_risk', ['high', 'critical']);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return PostgresPrefixSearch::apply($query, $term);
    }

    // --- COMPUTED ---

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExposed(): bool
    {
        return in_array($this->status, ['partially_exposed', 'fully_exposed'], true);
    }

    public function isCritical(): bool
    {
        return $this->exposure_risk === 'critical';
    }

    public function holderCount(): int
    {
        return count($this->holder_entity_ids ?? []);
    }

    public function knownByCount(): int
    {
        return count($this->known_by_entity_ids ?? []);
    }

    // The ratio of how many know vs how many are actively holding
    // Ratio > 1 means the secret has spread beyond the holders
    // Ratio growing toward known_by_count signals approaching exposure
    public function exposureRatio(): float
    {
        $holders = $this->holderCount();

        if ($holders === 0) {
            return 0.0;
        }

        return round($this->knownByCount() / $holders, 2);
    }

    // Whether the secret is leaking — more people know than are holding
    public function isLeaking(): bool
    {
        return $this->knownByCount() > $this->holderCount();
    }

    public function isHeldBy(int $entityId): bool
    {
        return in_array($entityId, $this->holder_entity_ids ?? [], true);
    }

    public function isKnownBy(int $entityId): bool
    {
        return in_array($entityId, $this->known_by_entity_ids ?? [], true);
    }

    public function isAbout(int $entityId): bool
    {
        return in_array($entityId, $this->subject_entity_ids ?? [], true);
    }
}
