<?php

namespace App\Domain\Organization\Services;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Lore\Models\Document;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CollectionService
{
    // --- CREATE / UPDATE ---

    public function create(array $data): Collection
    {
        $data['visibility'] = filled($data['visibility'] ?? null)
            ? $data['visibility']
            : VisibilityLevel::PRIVATE;
        $data['content_classification'] = filled($data['content_classification'] ?? null)
            ? $data['content_classification']
            : ContentClassification::RESTRICTED;
        $data['completion_state'] = $this->normalizeCompletionState($data['completion_state'] ?? null);

        $collection = Collection::create($data);

        // If smart or hybrid, run initial population
        if ($collection->isSmart() && ! empty($collection->rules)) {
            $this->syncSmartMembers($collection);
        }

        return $collection->fresh();
    }

    public function update(Collection $collection, array $data): Collection
    {
        if (array_key_exists('completion_state', $data)) {
            $data['completion_state'] = $this->normalizeCompletionState($data['completion_state']);
        }

        $collection->update($data);

        // If rules changed, re-sync smart members
        if ($collection->isSmart() && isset($data['rules'])) {
            $this->syncSmartMembers($collection);
        }

        return $collection->fresh();
    }

    // --- MANUAL ENTITY MEMBERSHIP ---

    public function addEntity(
        Collection $collection,
        Entity $entity,
        array $data = []
    ): CollectionEntity {
        // Do not add excluded entities
        if ($collection->isExcluded($entity->id)) {
            throw new \RuntimeException(
                "Entity '{$entity->name}' is in the exclusion list for collection '{$collection->name}'."
            );
        }

        // Upsert — if already exists (added by rule), mark as also manually added
        return CollectionEntity::updateOrCreate(
            ['collection_id' => $collection->id, 'entity_id' => $entity->id],
            array_merge($data, ['added_manually' => true])
        );
    }

    public function removeEntity(Collection $collection, Entity $entity): void
    {
        $entry = CollectionEntity::where('collection_id', $collection->id)
            ->where('entity_id', $entity->id)
            ->first();

        if (! $entry) {
            return;
        }

        // If was also added by rule, only remove manual flag
        // Entity stays in collection via rule until rule is cleared
        if ($entry->added_by_rule) {
            $entry->update(['added_manually' => false]);
        } else {
            $entry->delete();
        }
    }

    public function excludeEntity(Collection $collection, int $entityId): void
    {
        $excluded = $collection->excluded_entity_ids ?? [];
        $excluded[] = $entityId;

        $collection->update(['excluded_entity_ids' => array_unique($excluded)]);

        // Remove from collection if currently a member
        CollectionEntity::where('collection_id', $collection->id)
            ->where('entity_id', $entityId)
            ->delete();
    }

    // --- DOCUMENT MEMBERSHIP ---

    public function addDocument(
        Collection $collection,
        Document $document,
        array $data = []
    ): CollectionDocument {
        return CollectionDocument::updateOrCreate(
            ['collection_id' => $collection->id, 'document_id' => $document->id],
            $data
        );
    }

    public function removeDocument(Collection $collection, Document $document): void
    {
        CollectionDocument::where('collection_id', $collection->id)
            ->where('document_id', $document->id)
            ->delete();
    }

    // --- SMART COLLECTION ENGINE ---
    // Evaluates the collection's rules JSONB against entity fields
    // and syncs collection_entities records accordingly

    public function syncSmartMembers(Collection $collection): int
    {
        if (empty($collection->rules)) {
            return 0;
        }

        $query = Entity::query();

        // Apply each rule condition to the query
        foreach ($collection->rules as $rule) {
            $query = $this->applyRule($query, $rule);
        }

        // Apply exclusions
        if (! empty($collection->excluded_entity_ids)) {
            $query->whereNotIn('id', $collection->excluded_entity_ids);
        }

        $matchingIds = $query->pluck('id');
        $synced = 0;

        DB::transaction(function () use ($collection, $matchingIds, &$synced) {
            foreach ($matchingIds as $entityId) {
                CollectionEntity::updateOrCreate(
                    ['collection_id' => $collection->id, 'entity_id' => $entityId],
                    [
                        'added_manually' => false,
                        'added_by_rule' => true,
                        'matched_rule_snapshot' => $collection->rules,
                    ]
                );
                $synced++;
            }

            // Remove rule-added entries that no longer match
            // Manual entries are left alone
            CollectionEntity::where('collection_id', $collection->id)
                ->where('added_by_rule', true)
                ->where('added_manually', false)
                ->whereNotIn('entity_id', $matchingIds)
                ->delete();
        });

        return $synced;
    }

    // Resync all smart collections — called on a schedule or manually
    public function resyncAll(): void
    {
        Collection::smart()->each(function (Collection $collection) {
            $this->syncSmartMembers($collection);
        });
    }

    // --- PRIVATE: RULE EVALUATION ---
    // Rule structure: { field, operator, value }
    // Example: { "field": "entity_type", "operator": "equals", "value": "character" }
    // Example: { "field": "source_universes", "operator": "contains", "value": "Harry Potter" }
    // Example: { "field": "power_tier_ceiling", "operator": "in", "value": ["cosmic", "multiversal"] }

    private function applyRule(Builder $query, array $rule): Builder
    {
        $field = $rule['field'] ?? null;
        $operator = $rule['operator'] ?? null;
        $value = $rule['value'] ?? null;

        if (! $field || ! $operator) {
            return $query;
        }

        return match ($operator) {
            'equals' => $query->where($field, $value),
            'not_equals' => $query->where($field, '!=', $value),
            'in' => $query->whereIn($field, (array) $value),
            'not_in' => $query->whereNotIn($field, (array) $value),
            'contains' => $query->whereJsonContains($field, $value),
            'not_contains' => $query->whereJsonDoesntContain($field, $value),
            'is_null' => $query->whereNull($field),
            'is_not_null' => $query->whereNotNull($field),
            'greater_than' => $query->where($field, '>', $value),
            'less_than' => $query->where($field, '<', $value),
            default => $query, // Unknown operator — skip silently
        };
    }

    private function normalizeCompletionState(?string $state): string
    {
        return in_array($state, Collection::COMPLETION_STATES, true)
            ? $state
            : 'not_started';
    }
}
