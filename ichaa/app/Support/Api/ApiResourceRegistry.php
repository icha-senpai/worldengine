<?php

namespace App\Support\Api;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class ApiResourceRegistry
{
    private static array $columnCache = [];

    public static function slugs(): array
    {
        return array_keys(self::definitions());
    }

    public static function definition(string $slug): array
    {
        $definition = self::definitions()[$slug] ?? null;

        if (! $definition) {
            abort(404, "Unknown API resource [{$slug}].");
        }

        return $definition;
    }

    public static function modelClass(string $slug): string
    {
        return self::definition($slug)['model'];
    }

    public static function searchableFields(string $slug): array
    {
        $definition = self::definition($slug);

        return array_values(array_filter(
            $definition['search_fields'] ?? [],
            fn (string $field) => self::hasColumn($slug, $field),
        ));
    }

    public static function filterableFields(string $slug): array
    {
        $model = new (self::modelClass($slug))();
        $fields = array_merge(['id'], $model->getFillable());

        return array_values(array_filter(
            array_unique($fields),
            fn (string $field) => self::hasColumn($slug, $field),
        ));
    }

    public static function sortableFields(string $slug): array
    {
        return self::filterableFields($slug);
    }

    public static function query(string $slug): Builder
    {
        $definition = self::definition($slug);

        if (isset($definition['query'])) {
            return $definition['query']();
        }

        $modelClass = $definition['model'];

        return $modelClass::query();
    }

    public static function resolveRecord(string $slug, int|string $id, bool $withTrashed = false): Model
    {
        $query = self::query($slug);
        $definition = self::definition($slug);
        $modelClass = $definition['model'];

        if ($withTrashed && self::supportsSoftDeletes($modelClass)) {
            $query->withTrashed();
        }

        $record = $query->whereKey($id)->firstOrFail();

        if (($definition['entity_type'] ?? null) && $record instanceof Entity && $record->entity_type !== $definition['entity_type']) {
            abort(404);
        }

        return $record;
    }

    public static function supportsSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    public static function hasColumn(string $slug, string $field): bool
    {
        $table = (new (self::modelClass($slug))())->getTable();

        if (! array_key_exists($table, self::$columnCache)) {
            self::$columnCache[$table] = Schema::getColumnListing($table);
        }

        return in_array($field, self::$columnCache[$table], true);
    }

    private static function definitions(): array
    {
        return [
            'entities' => self::base(Entity::class, ['aliases', 'notes', 'questions', 'versions'], ['name', 'summary', 'public_title'], 'entities'),
            'entity-aliases' => self::base(EntityAlias::class, ['entity'], ['alias', 'context'], 'entity_aliases'),
            'entity-notes' => self::base(EntityNote::class, ['entity'], ['note_label', 'content'], 'entity_notes'),
            'entity-questions' => self::base(EntityQuestion::class, ['entity'], ['question', 'context', 'resolution'], 'entity_questions'),
            'media-references' => self::base(MediaReference::class, ['entity'], ['title', 'description', 'media_type', 'purpose']),
            'entity-versions' => self::base(VersionAndCanonState::class, ['entity'], ['version_label', 'what_changed', 'why_changed']),

            'relationships' => self::base(Relationship::class, ['fromEntity', 'toEntity'], ['relationship_type', 'other_type_notes', 'true_type'], 'relationships'),
            'group-relationships' => self::base(GroupRelationship::class, ['members'], ['name', 'relationship_type'], 'group_relationships'),
            'group-relationship-memberships' => self::base(GroupRelationshipEntity::class, ['groupRelationship', 'entity'], ['role_in_group']),
            'faction-memberships' => self::base(FactionMembership::class, ['faction', 'member', 'trueLoyalty', 'recruitedBy'], ['rank_or_role', 'membership_status'], 'faction_memberships'),

            'collections' => self::base(Collection::class, ['entities', 'documents', 'childCollections'], ['name', 'description'], 'collections'),
            'collection-entities' => self::base(CollectionEntity::class, ['collection', 'entity'], ['role_in_collection', 'notes']),
            'collection-documents' => self::base(CollectionDocument::class, ['collection', 'document'], ['role_in_collection', 'notes']),
            'glossary' => self::base(Glossary::class, [], ['term', 'usage_context'], 'glossary'),

            'documents' => self::base(Document::class, [], ['title', 'document_type'], 'documents'),
            'document-entities' => self::base(DocumentEntity::class, ['document', 'entity'], ['relevance_notes']),
            'canon-references' => self::base(SourceCanonReference::class, [], ['title', 'universe', 'summary'], 'canon_references'),
            'canon-reference-entities' => self::base(CanonReferenceEntity::class, ['canonReference', 'entity'], ['relationship_to_reference']),
            'crossover-entry-points' => self::base(CrossoverEntryPoint::class, [], ['source_universe', 'target_location', 'hook_summary'], 'crossover_entry_points'),

            'timelines' => array_merge(
                self::base(Entity::class, ['timelineEvents'], ['name', 'summary'], 'timelines'),
                ['query' => fn (): Builder => Entity::query()->where('entity_type', 'timeline'), 'entity_type' => 'timeline']
            ),
            'timeline-entries' => self::base(Timeline::class, ['timeline', 'eventEntity', 'concurrencyGroup'], ['entry_label', 'au_date', 'public_narrative']),
            'timeline-placements' => self::base(TimelineEntity::class, ['timeline', 'eventEntity'], ['perspective_label', 'perspective_notes']),
            'character-states' => self::base(CharacterStateTracker::class, ['entity', 'timeline'], ['snapshot_label', 'state_summary'], 'character_states'),
            'state-relationships' => self::base(StateRelationship::class, ['snapshot', 'relationship'], ['relationship_state_at_snapshot']),
            'concurrency-groups' => self::base(ConcurrencyGroup::class, [], ['name', 'summary'], 'concurrency_groups'),

            'power-interactions' => self::base(PowerInteraction::class, ['systemA', 'systemB', 'instances'], ['interaction_name', 'description'], 'power_interactions'),
            'power-interaction-instances' => self::base(PowerInteractionInstance::class, ['powerInteraction', 'eventEntity'], ['outcome_notes']),
            'location-containment' => self::base(LocationContainment::class, ['childLocation', 'parentLocation'], ['containment_type', 'era_start'], 'location_containment'),
            'location-control-records' => self::base(LocationControlHistory::class, ['location', 'controllingEntity', 'resistanceEntities'], ['control_type', 'control_start_era'], 'location_control'),
            'travel-routes' => self::base(TravelRoute::class, ['originLocation', 'destinationLocation'], ['route_type', 'notes'], 'travel_routes'),
            'galactic-regions' => self::base(GalacticRegion::class, [], ['name', 'summary']),

            'knowledge-states' => self::base(KnowledgeState::class, ['knower', 'subjectEntity', 'subjectSecret'], ['knowledge_type'], 'knowledge_states'),
            'secrets' => self::base(Secret::class, [], ['title', 'secret_type'], 'secrets'),
            'perception-states' => self::base(PerceptionState::class, [], ['subject_type', 'revelation_risk'], 'perception_states'),

            'meta' => self::base(Meta::class, ['entities', 'supersededBy'], ['title', 'category', 'meta_note_type'], 'meta'),
            'pipeline-items' => self::base(PipelineItem::class, ['parent', 'children', 'povCharacter', 'location'], ['title', 'pipeline_type', 'pipeline_stage'], 'pipeline_items'),
            'session-logs' => self::base(SessionLog::class, [], ['title', 'focus_description', 'external_tool'], 'session_logs'),

            'notion-notes' => self::base(NotionNote::class, [], ['sync_resource', 'content']),
            'notion-sync-mappings' => self::base(NotionSyncMapping::class, [], ['sync_resource', 'notion_page_id']),
            'revisions' => self::base(Revision::class, ['actor', 'restoredFrom'], ['resource_type', 'action', 'reason']),
        ];
    }

    private static function base(string $modelClass, array $includes = [], array $searchFields = [], ?string $notionResource = null): array
    {
        return [
            'model' => $modelClass,
            'includes' => array_values(array_unique(array_merge($includes, $notionResource ? ['notion_note'] : []))),
            'search_fields' => $searchFields,
            'notion_resource' => $notionResource,
        ];
    }
}
