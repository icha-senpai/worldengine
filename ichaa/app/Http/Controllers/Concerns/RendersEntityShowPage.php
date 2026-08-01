<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use Inertia\Response;

trait RendersEntityShowPage
{
    protected function showEntityPage(Entity $entity, array $props = []): Response
    {
        $entity->load([
            'aliases',
            'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
            'questions' => fn ($q) => $q
                ->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
                ->orderBy('sort_order')
                ->orderBy('created_at'),
            'controlledFactions' => fn ($q) => $q
                ->with([
                    'member:id,name,entity_type,public_title,status,visibility,completion_score',
                    'trueLoyalty:id,name',
                    'recruitedBy:id,name',
                ])
                ->orderByRaw("CASE membership_status WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 WHEN 'former' THEN 3 ELSE 4 END")
                ->orderBy('rank_or_role')
                ->orderBy('created_at'),
            'factionMemberships' => fn ($q) => $q
                ->with([
                    'faction:id,name,entity_type,public_title,status,visibility,completion_score',
                    'trueLoyalty:id,name',
                    'recruitedBy:id,name',
                ])
                ->orderByRaw("CASE membership_status WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 WHEN 'former' THEN 3 ELSE 4 END")
                ->orderBy('created_at'),
        ]);

        $this->attachAliasAudienceDisplays($entity);
        $this->attachQuestionLinkDisplays($entity);

        return $this->page('Entities/Show', array_merge([
            'entity' => $entity,
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'groupRelationships' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'factionRoster' => $entity->controlledFactions,
            'memberMemberships' => $entity->factionMemberships,
            'isFactionEntity' => in_array($entity->entity_type, EntityType::FACTION_TYPES, true),
        ], $props));
    }

    protected function attachAliasAudienceDisplays(Entity $entity): void
    {
        $knownByIds = $entity->aliases
            ->pluck('known_by_entity_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        if ($knownByIds->isEmpty()) {
            return;
        }

        $knownByMap = Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('id', $knownByIds)
            ->get()
            ->keyBy('id');

        $entity->setRelation('aliases', $entity->aliases->map(function ($alias) use ($knownByMap) {
            $alias->setAttribute('known_by_entities_display', collect($alias->known_by_entity_ids ?? [])
                ->map(fn ($id) => $knownByMap->get($id))
                ->filter()
                ->map(fn (Entity $knownBy) => [
                    'id' => $knownBy->id,
                    'name' => $knownBy->name,
                    'entity_type' => $knownBy->entity_type,
                ])
                ->values());

            return $alias;
        })->values());
    }

    protected function attachQuestionLinkDisplays(Entity $entity): void
    {
        $linkedEntityIds = $entity->questions
            ->pluck('linked_entity_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $linkedGroupIds = $entity->questions
            ->pluck('linked_group_relationship_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $linkedEntityMap = $linkedEntityIds->isEmpty()
            ? collect()
            : Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('id', $linkedEntityIds)
                ->get()
                ->keyBy('id');

        $linkedGroupMap = $linkedGroupIds->isEmpty()
            ? collect()
            : GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->whereIn('id', $linkedGroupIds)
                ->get()
                ->keyBy('id');

        $entity->setRelation('questions', $entity->questions->map(function ($question) use ($linkedEntityMap, $linkedGroupMap) {
            $question->setAttribute('linked_entities_display', collect($question->linked_entity_ids ?? [])
                ->map(fn ($id) => $linkedEntityMap->get($id))
                ->filter()
                ->map(fn (Entity $linked) => [
                    'id' => $linked->id,
                    'name' => $linked->name,
                    'entity_type' => $linked->entity_type,
                ])
                ->values());

            $question->setAttribute('linked_group_relationships_display', collect($question->linked_group_relationship_ids ?? [])
                ->map(fn ($id) => $linkedGroupMap->get($id))
                ->filter()
                ->map(fn (GroupRelationship $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'relationship_type' => $group->relationship_type,
                ])
                ->values());

            return $question;
        })->values());
    }
}
