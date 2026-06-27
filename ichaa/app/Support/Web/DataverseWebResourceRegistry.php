<?php

namespace App\Support\Web;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Models\Revision;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\GalacticRegion;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DataverseWebResourceRegistry
{
    public function label(string $resourceType): string
    {
        return $this->definitions()[$resourceType]['label']
            ?? Str::of($resourceType)->replace(['_', '-'], ' ')->title()->value();
    }

    public function find(string $resourceType, int|string $id, bool $withTrashed = false): ?Model
    {
        $definition = $this->definitions()[$resourceType] ?? null;

        if (! $definition) {
            return null;
        }

        if (isset($definition['finder'])) {
            return $definition['finder']($id, $withTrashed);
        }

        $modelClass = $definition['model'];
        $query = $modelClass::query();

        if ($withTrashed && $this->supportsSoftDeletes($modelClass)) {
            $query->withTrashed();
        }

        if (! empty($definition['with'])) {
            $query->with($definition['with']);
        }

        return $query->find($id);
    }

    public function linkForResourceType(string $resourceType, int|string $id): ?array
    {
        $definition = $this->definitions()[$resourceType] ?? null;

        if (! $definition) {
            return null;
        }

        $record = $this->find($resourceType, $id, true);

        if (! $record) {
            return null;
        }

        $link = $definition['link']($record);

        if (! is_array($link)) {
            return null;
        }

        return [
            'label' => $link['label'] ?? $this->label($resourceType).' #'.$id,
            'href' => $link['href'] ?? null,
        ];
    }

    public function linkForModel(?string $modelClass, int|string|null $id): ?array
    {
        if (! $modelClass || $id === null) {
            return null;
        }

        if ($modelClass === Entity::class) {
            /** @var Entity|null $entity */
            $entity = Entity::query()->withTrashed()->find($id);

            if (! $entity) {
                return null;
            }

            return $entity->entity_type === 'timeline'
                ? $this->linkForResourceType('timelines', $entity->getKey())
                : $this->linkForResourceType('entities', $entity->getKey());
        }

        foreach ($this->definitions() as $resourceType => $definition) {
            if (($definition['model'] ?? null) !== $modelClass) {
                continue;
            }

            return $this->linkForResourceType($resourceType, $id);
        }

        return null;
    }

    private function supportsSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    private function definitions(): array
    {
        return [
            'entities' => [
                'label' => 'Entities',
                'model' => Entity::class,
                'with' => [],
                'link' => fn (Entity $entity) => [
                    'label' => $entity->name ?: 'Entity #'.$entity->getKey(),
                    'href' => route('entities.show', $entity),
                ],
            ],
            'entity-aliases' => [
                'label' => 'Entity Aliases',
                'model' => EntityAlias::class,
                'with' => ['entity:id,name'],
                'link' => fn (EntityAlias $alias) => [
                    'label' => $alias->alias ?: 'Alias #'.$alias->getKey(),
                    'href' => route('entities.show', ['entity' => $alias->entity_id, 'tab' => 'aliases']),
                ],
            ],
            'entity-notes' => [
                'label' => 'Entity Notes',
                'model' => EntityNote::class,
                'with' => ['entity:id,name'],
                'link' => fn (EntityNote $note) => [
                    'label' => $note->note_label ?: 'Entity Note #'.$note->getKey(),
                    'href' => route('entities.show', ['entity' => $note->entity_id, 'tab' => 'notes']),
                ],
            ],
            'entity-questions' => [
                'label' => 'Entity Questions',
                'model' => EntityQuestion::class,
                'with' => ['entity:id,name'],
                'link' => fn (EntityQuestion $question) => [
                    'label' => $question->question ?: 'Entity Question #'.$question->getKey(),
                    'href' => route('entities.show', ['entity' => $question->entity_id, 'tab' => 'questions']),
                ],
            ],
            'media-references' => [
                'label' => 'Media References',
                'model' => MediaReference::class,
                'with' => [],
                'link' => fn (MediaReference $reference) => [
                    'label' => $reference->title ?: 'Media Reference #'.$reference->getKey(),
                    'href' => route('media-references.show', $reference),
                ],
            ],
            'entity-versions' => [
                'label' => 'Entity Versions',
                'model' => VersionAndCanonState::class,
                'with' => [],
                'link' => fn (VersionAndCanonState $version) => [
                    'label' => $version->version_label ?: 'Entity Version #'.$version->getKey(),
                    'href' => route('entities.versions.show', [$version->entity_id, $version->getKey()]),
                ],
            ],
            'relationships' => [
                'label' => 'Relationships',
                'model' => Relationship::class,
                'with' => ['fromEntity:id,name', 'toEntity:id,name'],
                'link' => fn (Relationship $relationship) => [
                    'label' => ($relationship->fromEntity?->name ?: 'Unknown').' -> '.($relationship->toEntity?->name ?: 'Unknown'),
                    'href' => route('relationships.show', $relationship),
                ],
            ],
            'group-relationships' => [
                'label' => 'Group Relationships',
                'model' => GroupRelationship::class,
                'with' => [],
                'link' => fn (GroupRelationship $group) => [
                    'label' => $group->name ?: 'Group Relationship #'.$group->getKey(),
                    'href' => route('group-relationships.show', $group),
                ],
            ],
            'group-relationship-memberships' => [
                'label' => 'Group Relationship Memberships',
                'model' => GroupRelationshipEntity::class,
                'with' => ['groupRelationship:id,name', 'entity:id,name'],
                'link' => fn (GroupRelationshipEntity $membership) => [
                    'label' => ($membership->entity?->name ?: 'Unknown').' in '.($membership->groupRelationship?->name ?: 'Unknown'),
                    'href' => route('group-relationship-memberships.show', $membership->getKey()),
                ],
            ],
            'faction-memberships' => [
                'label' => 'Faction Memberships',
                'model' => FactionMembership::class,
                'with' => ['faction:id,name', 'member:id,name'],
                'link' => fn (FactionMembership $membership) => [
                    'label' => ($membership->member?->name ?: 'Unknown').' in '.($membership->faction?->name ?: 'Unknown'),
                    'href' => route('faction-memberships.show', $membership->getKey()),
                ],
            ],
            'collections' => [
                'label' => 'Collections',
                'model' => Collection::class,
                'with' => [],
                'link' => fn (Collection $collection) => [
                    'label' => $collection->name ?: 'Collection #'.$collection->getKey(),
                    'href' => route('collections.show', $collection),
                ],
            ],
            'collection-entities' => [
                'label' => 'Collection Entities',
                'model' => CollectionEntity::class,
                'with' => ['collection:id,name', 'entity:id,name'],
                'link' => fn (CollectionEntity $membership) => [
                    'label' => ($membership->entity?->name ?: 'Unknown').' in '.($membership->collection?->name ?: 'Unknown'),
                    'href' => route('collection-entities.show', $membership->getKey()),
                ],
            ],
            'collection-documents' => [
                'label' => 'Collection Documents',
                'model' => CollectionDocument::class,
                'with' => ['collection:id,name', 'document:id,title'],
                'link' => fn (CollectionDocument $membership) => [
                    'label' => ($membership->document?->title ?: 'Unknown').' in '.($membership->collection?->name ?: 'Unknown'),
                    'href' => route('collection-documents.show', $membership->getKey()),
                ],
            ],
            'glossary' => [
                'label' => 'Glossary',
                'model' => Glossary::class,
                'with' => [],
                'link' => fn (Glossary $glossary) => [
                    'label' => $glossary->term ?: 'Glossary #'.$glossary->getKey(),
                    'href' => route('glossary.show', $glossary),
                ],
            ],
            'documents' => [
                'label' => 'Documents',
                'model' => Document::class,
                'with' => [],
                'link' => fn (Document $document) => [
                    'label' => $document->title ?: 'Document #'.$document->getKey(),
                    'href' => route('documents.show', $document),
                ],
            ],
            'document-entities' => [
                'label' => 'Document Entities',
                'model' => DocumentEntity::class,
                'with' => ['document:id,title', 'entity:id,name'],
                'link' => fn (DocumentEntity $membership) => [
                    'label' => ($membership->entity?->name ?: 'Unknown').' in '.($membership->document?->title ?: 'Unknown'),
                    'href' => route('document-entities.show', $membership->getKey()),
                ],
            ],
            'canon-references' => [
                'label' => 'Canon References',
                'model' => SourceCanonReference::class,
                'with' => [],
                'link' => fn (SourceCanonReference $reference) => [
                    'label' => $reference->title ?: 'Canon Reference #'.$reference->getKey(),
                    'href' => route('canon-references.show', $reference),
                ],
            ],
            'canon-reference-entities' => [
                'label' => 'Canon Reference Entities',
                'model' => CanonReferenceEntity::class,
                'with' => ['canonReference:id,title', 'entity:id,name'],
                'link' => fn (CanonReferenceEntity $membership) => [
                    'label' => ($membership->entity?->name ?: 'Unknown').' in '.($membership->canonReference?->title ?: 'Unknown'),
                    'href' => route('canon-reference-entities.show', $membership->getKey()),
                ],
            ],
            'crossover-entry-points' => [
                'label' => 'Crossover Entry Points',
                'model' => CrossoverEntryPoint::class,
                'with' => [],
                'link' => fn (CrossoverEntryPoint $entryPoint) => [
                    'label' => $entryPoint->source_universe ?: 'Crossover Entry #'.$entryPoint->getKey(),
                    'href' => route('crossover-entry-points.show', $entryPoint),
                ],
            ],
            'timelines' => [
                'label' => 'Timelines',
                'model' => Entity::class,
                'finder' => function (int|string $id, bool $withTrashed = false) {
                    $query = Entity::query()->where('entity_type', 'timeline');

                    if ($withTrashed) {
                        $query->withTrashed();
                    }

                    return $query->find($id);
                },
                'link' => fn (Entity $timeline) => [
                    'label' => $timeline->name ?: 'Timeline #'.$timeline->getKey(),
                    'href' => route('timelines.show', $timeline),
                ],
            ],
            'timeline-entries' => [
                'label' => 'Timeline Entries',
                'model' => Timeline::class,
                'with' => ['timeline:id,name', 'eventEntity:id,name'],
                'link' => fn (Timeline $entry) => [
                    'label' => $entry->entry_label ?: ($entry->eventEntity?->name ?: 'Timeline Entry #'.$entry->getKey()),
                    'href' => route('timelines.events.edit', ['timeline' => $entry->timeline_id, 'entry' => $entry->getKey()]),
                ],
            ],
            'timeline-placements' => [
                'label' => 'Timeline Placements',
                'model' => TimelineEntity::class,
                'with' => ['timeline:id,name', 'eventEntity:id,name'],
                'link' => fn (TimelineEntity $placement) => [
                    'label' => ($placement->eventEntity?->name ?: 'Unknown').' on '.($placement->timeline?->name ?: 'Unknown'),
                    'href' => route('timeline-placements.show', $placement->getKey()),
                ],
            ],
            'character-states' => [
                'label' => 'Character States',
                'model' => CharacterStateTracker::class,
                'with' => ['entity:id,name'],
                'link' => fn (CharacterStateTracker $state) => [
                    'label' => $state->snapshot_label ?: ($state->entity?->name ?: 'Character State #'.$state->getKey()),
                    'href' => route('character-states.show', $state),
                ],
            ],
            'state-relationships' => [
                'label' => 'State Relationships',
                'model' => StateRelationship::class,
                'with' => ['characterState:id,snapshot_label', 'relationship.fromEntity:id,name', 'relationship.toEntity:id,name'],
                'link' => fn (StateRelationship $stateRelationship) => [
                    'label' => ($stateRelationship->characterState?->snapshot_label ?: 'State').' -> Relationship #'.$stateRelationship->relationship_id,
                    'href' => route('state-relationships.show', $stateRelationship->getKey()),
                ],
            ],
            'concurrency-groups' => [
                'label' => 'Concurrency Groups',
                'model' => ConcurrencyGroup::class,
                'with' => [],
                'link' => fn (ConcurrencyGroup $group) => [
                    'label' => $group->name ?: 'Concurrency Group #'.$group->getKey(),
                    'href' => route('concurrency-groups.show', $group),
                ],
            ],
            'power-interactions' => [
                'label' => 'Power Interactions',
                'model' => PowerInteraction::class,
                'with' => [],
                'link' => fn (PowerInteraction $interaction) => [
                    'label' => $interaction->interaction_name ?: 'Power Interaction #'.$interaction->getKey(),
                    'href' => route('power-interactions.show', $interaction),
                ],
            ],
            'power-interaction-instances' => [
                'label' => 'Power Interaction Instances',
                'model' => PowerInteractionInstance::class,
                'with' => ['powerInteraction:id,interaction_name', 'eventEntity:id,name'],
                'link' => fn (PowerInteractionInstance $instance) => [
                    'label' => ($instance->eventEntity?->name ?: 'Unknown').' for '.($instance->powerInteraction?->interaction_name ?: 'Unknown'),
                    'href' => route('power-interaction-instances.show', $instance->getKey()),
                ],
            ],
            'location-containment' => [
                'label' => 'Location Containment',
                'model' => LocationContainment::class,
                'with' => ['childLocation:id,name', 'parentLocation:id,name'],
                'link' => fn (LocationContainment $containment) => [
                    'label' => ($containment->childLocation?->name ?: 'Unknown').' in '.($containment->parentLocation?->name ?: 'Unknown'),
                    'href' => route('location-containment.show', $containment),
                ],
            ],
            'location-control-records' => [
                'label' => 'Location Control',
                'model' => LocationControlHistory::class,
                'with' => ['location:id,name', 'controllingEntity:id,name'],
                'link' => fn (LocationControlHistory $record) => [
                    'label' => ($record->location?->name ?: 'Unknown').' -> '.($record->controllingEntity?->name ?: 'Unknown'),
                    'href' => route('location-control.show', $record),
                ],
            ],
            'travel-routes' => [
                'label' => 'Travel Routes',
                'model' => TravelRoute::class,
                'with' => ['origin:id,name', 'destination:id,name'],
                'link' => fn (TravelRoute $routeRecord) => [
                    'label' => ($routeRecord->origin?->name ?: 'Unknown').' -> '.($routeRecord->destination?->name ?: 'Unknown'),
                    'href' => route('travel-routes.show', $routeRecord),
                ],
            ],
            'galactic-regions' => [
                'label' => 'Galactic Regions',
                'model' => GalacticRegion::class,
                'with' => [],
                'link' => fn (GalacticRegion $region) => [
                    'label' => $region->region_name ?: 'Galactic Region #'.$region->getKey(),
                    'href' => route('galactic-regions.show', $region),
                ],
            ],
            'knowledge-states' => [
                'label' => 'Knowledge States',
                'model' => KnowledgeState::class,
                'with' => ['knower:id,name'],
                'link' => fn (KnowledgeState $state) => [
                    'label' => ($state->knower?->name ?: 'Unknown').' Knowledge',
                    'href' => route('knowledge-states.show', $state),
                ],
            ],
            'secrets' => [
                'label' => 'Secrets',
                'model' => Secret::class,
                'with' => [],
                'link' => fn (Secret $secret) => [
                    'label' => $secret->title ?: 'Secret #'.$secret->getKey(),
                    'href' => route('secrets.show', $secret),
                ],
            ],
            'perception-states' => [
                'label' => 'Perception States',
                'model' => PerceptionState::class,
                'with' => [],
                'link' => fn (PerceptionState $state) => [
                    'label' => Str::of($state->subject_type ?: 'perception')->replace('_', ' ')->lower()->value().' perception gap',
                    'href' => route('perception-states.show', $state),
                ],
            ],
            'meta' => [
                'label' => 'Meta Notes',
                'model' => Meta::class,
                'with' => [],
                'link' => fn (Meta $meta) => [
                    'label' => $meta->title ?: 'Meta Note #'.$meta->getKey(),
                    'href' => route('meta.show', $meta),
                ],
            ],
            'pipeline-items' => [
                'label' => 'Pipeline Items',
                'model' => PipelineItem::class,
                'with' => [],
                'link' => fn (PipelineItem $item) => [
                    'label' => $item->title ?: 'Pipeline Item #'.$item->getKey(),
                    'href' => route('pipeline.show', $item),
                ],
            ],
            'session-logs' => [
                'label' => 'Session Logs',
                'model' => SessionLog::class,
                'with' => [],
                'link' => fn (SessionLog $session) => [
                    'label' => $session->title ?: 'Session Log #'.$session->getKey(),
                    'href' => route('session-logs.show', $session),
                ],
            ],
            'notion-notes' => [
                'label' => 'Notion Notes',
                'model' => NotionNote::class,
                'with' => [],
                'link' => fn (NotionNote $note) => [
                    'label' => 'Notion Note '.$note->notion_page_id,
                    'href' => route('admin.notion-notes.show', $note),
                ],
            ],
            'notion-sync-mappings' => [
                'label' => 'Notion Sync Mappings',
                'model' => NotionSyncMapping::class,
                'with' => [],
                'link' => fn (NotionSyncMapping $mapping) => [
                    'label' => 'Notion Mapping '.$mapping->notion_page_id,
                    'href' => route('admin.notion-sync-mappings.show', $mapping),
                ],
            ],
            'revisions' => [
                'label' => 'Revisions',
                'model' => Revision::class,
                'with' => [],
                'link' => fn (Revision $revision) => [
                    'label' => 'Revision #'.$revision->getKey(),
                    'href' => route('admin.revisions.show', $revision),
                ],
            ],
        ];
    }
}
