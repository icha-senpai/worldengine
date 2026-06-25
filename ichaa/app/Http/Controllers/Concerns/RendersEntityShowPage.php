<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\System\Models\NotionNote;
use Inertia\Response;

trait RendersEntityShowPage
{
    protected function showEntityPage(Entity $entity, array $props = []): Response
    {
        $entity->load([
            'aliases',
            'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
            'questions' => fn ($q) => $q->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('created_at'),
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

        $this->attachEmbeddedNotionNotes($entity);

        return $this->pageWithNotionNote('Entities/Show', $entity, 'entities', array_merge([
            'entity' => $entity,
            'factionRoster' => $entity->controlledFactions,
            'memberMemberships' => $entity->factionMemberships,
            'isFactionEntity' => in_array($entity->entity_type, EntityType::FACTION_TYPES, true),
        ], $props));
    }

    protected function attachEmbeddedNotionNotes(Entity $entity): void
    {
        $entity->setRelation('aliases', $this->attachNotionNotesToRecords(
            $entity->aliases,
            EntityAlias::class,
            'entity_aliases',
        ));

        $entity->setRelation('notes', $this->attachNotionNotesToRecords(
            $entity->notes,
            EntityNote::class,
            'entity_notes',
        ));

        $entity->setRelation('questions', $this->attachNotionNotesToRecords(
            $entity->questions,
            EntityQuestion::class,
            'entity_questions',
        ));
    }

    protected function attachNotionNotesToRecords($records, string $modelClass, string $resource)
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $noteMap = NotionNote::query()
            ->where('noteable_type', $modelClass)
            ->where('sync_resource', $resource)
            ->whereIn('noteable_id', $records->pluck('id'))
            ->get()
            ->keyBy('noteable_id');

        return $records->map(function ($record) use ($noteMap) {
            $record->setAttribute('notion_note', $this->formatNotionNote($noteMap->get($record->getKey())));

            return $record;
        })->values();
    }
}
