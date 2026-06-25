<?php

namespace App\Domain\System\Services;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Organization\Services\CollectionService;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

class NotionDataverseSyncService
{
    public const RESOURCE_ALL = 'all';
    public const RESOURCE_CONNECTIONS = 'connections';
    public const RESOURCE_RELATIONSHIPS = 'relationships';
    public const RESOURCE_GROUP_RELATIONSHIPS = 'group_relationships';
    public const RESOURCE_FACTION_MEMBERSHIPS = 'faction_memberships';
    public const RESOURCE_LORE = 'lore';
    public const RESOURCE_DOCUMENTS = 'documents';
    public const RESOURCE_CANON_REFERENCES = 'canon_references';
    public const RESOURCE_CROSSOVER_ENTRY_POINTS = 'crossover_entry_points';
    public const RESOURCE_ORGANIZATION = 'organization';
    public const RESOURCE_COLLECTIONS = 'collections';
    public const RESOURCE_GLOSSARY = 'glossary';
    public const RESOURCE_TEMPORAL = 'temporal';
    public const RESOURCE_TIMELINES = 'timelines';
    public const RESOURCE_CHARACTER_STATES = 'character_states';
    public const RESOURCE_CONCURRENCY_GROUPS = 'concurrency_groups';
    public const RESOURCE_WORLD = 'world';
    public const RESOURCE_POWER_INTERACTIONS = 'power_interactions';
    public const RESOURCE_TRAVEL_ROUTES = 'travel_routes';
    public const RESOURCE_LOCATION_CONTAINMENT = 'location_containment';
    public const RESOURCE_LOCATION_CONTROL = 'location_control';
    public const RESOURCE_INTELLIGENCE = 'intelligence';
    public const RESOURCE_SECRETS = 'secrets';
    public const RESOURCE_KNOWLEDGE_STATES = 'knowledge_states';
    public const RESOURCE_PERCEPTION_STATES = 'perception_states';
    public const RESOURCE_PRODUCTION = 'production';
    public const RESOURCE_META = 'meta';
    public const RESOURCE_PIPELINE_ITEMS = 'pipeline_items';
    public const RESOURCE_SESSION_LOGS = 'session_logs';

    public function __construct(
        private readonly NotionClient $client,
        private readonly NotionPropertyMapper $mapper,
        private readonly NotionNoteSyncService $notionNoteSync,
        private readonly NotionIdentitySyncService $identitySyncService,
        private readonly EntityService $entityService,
        private readonly RelationshipService $relationshipService,
        private readonly CollectionService $collectionService,
        private readonly TemporalService $temporalService,
        private readonly WorldService $worldService,
        private readonly IntelligenceService $intelligenceService,
    ) {}

    public static function supportedResources(): array
    {
        return [
            self::RESOURCE_ALL,
            ...NotionIdentitySyncService::supportedResources(),
            self::RESOURCE_CONNECTIONS,
            self::RESOURCE_RELATIONSHIPS,
            self::RESOURCE_GROUP_RELATIONSHIPS,
            self::RESOURCE_FACTION_MEMBERSHIPS,
            self::RESOURCE_LORE,
            self::RESOURCE_DOCUMENTS,
            self::RESOURCE_CANON_REFERENCES,
            self::RESOURCE_CROSSOVER_ENTRY_POINTS,
            self::RESOURCE_ORGANIZATION,
            self::RESOURCE_COLLECTIONS,
            self::RESOURCE_GLOSSARY,
            self::RESOURCE_TEMPORAL,
            self::RESOURCE_TIMELINES,
            self::RESOURCE_CHARACTER_STATES,
            self::RESOURCE_CONCURRENCY_GROUPS,
            self::RESOURCE_WORLD,
            self::RESOURCE_POWER_INTERACTIONS,
            self::RESOURCE_TRAVEL_ROUTES,
            self::RESOURCE_LOCATION_CONTAINMENT,
            self::RESOURCE_LOCATION_CONTROL,
            self::RESOURCE_INTELLIGENCE,
            self::RESOURCE_SECRETS,
            self::RESOURCE_KNOWLEDGE_STATES,
            self::RESOURCE_PERCEPTION_STATES,
            self::RESOURCE_PRODUCTION,
            self::RESOURCE_META,
            self::RESOURCE_PIPELINE_ITEMS,
            self::RESOURCE_SESSION_LOGS,
        ];
    }

    public function sync(string $resource = self::RESOURCE_ALL, bool $includeDrafts = false, bool $dryRun = false): array
    {
        if (! in_array($resource, self::supportedResources(), true)) {
            throw new RuntimeException("Unsupported resource [{$resource}].");
        }

        if (! $this->client->isConfigured()) {
            throw new RuntimeException('NOTION_API_TOKEN is not configured.');
        }

        if (in_array($resource, NotionIdentitySyncService::supportedResources(), true)) {
            return $this->identitySyncService->sync($resource, $includeDrafts, $dryRun);
        }

        return match ($resource) {
            self::RESOURCE_ALL => $this->syncMany([
                NotionIdentitySyncService::RESOURCE_IDENTITY,
                self::RESOURCE_CONNECTIONS,
                self::RESOURCE_LORE,
                self::RESOURCE_TEMPORAL,
                self::RESOURCE_WORLD,
                self::RESOURCE_INTELLIGENCE,
                self::RESOURCE_ORGANIZATION,
                self::RESOURCE_PRODUCTION,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_CONNECTIONS => $this->syncMany([
                self::RESOURCE_RELATIONSHIPS,
                self::RESOURCE_GROUP_RELATIONSHIPS,
                self::RESOURCE_FACTION_MEMBERSHIPS,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_LORE => $this->syncMany([
                self::RESOURCE_DOCUMENTS,
                self::RESOURCE_CROSSOVER_ENTRY_POINTS,
                self::RESOURCE_CANON_REFERENCES,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_ORGANIZATION => $this->syncMany([
                self::RESOURCE_COLLECTIONS,
                self::RESOURCE_GLOSSARY,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_TEMPORAL => $this->syncMany([
                self::RESOURCE_TIMELINES,
                self::RESOURCE_CONCURRENCY_GROUPS,
                self::RESOURCE_CHARACTER_STATES,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_WORLD => $this->syncMany([
                self::RESOURCE_POWER_INTERACTIONS,
                self::RESOURCE_TRAVEL_ROUTES,
                self::RESOURCE_LOCATION_CONTAINMENT,
                self::RESOURCE_LOCATION_CONTROL,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_INTELLIGENCE => $this->syncMany([
                self::RESOURCE_SECRETS,
                self::RESOURCE_KNOWLEDGE_STATES,
                self::RESOURCE_PERCEPTION_STATES,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_PRODUCTION => $this->syncMany([
                self::RESOURCE_META,
                self::RESOURCE_PIPELINE_ITEMS,
                self::RESOURCE_SESSION_LOGS,
            ], $includeDrafts, $dryRun, true),
            self::RESOURCE_RELATIONSHIPS => $this->syncRelationships($includeDrafts, $dryRun),
            self::RESOURCE_GROUP_RELATIONSHIPS => $this->syncGroupRelationships($includeDrafts, $dryRun),
            self::RESOURCE_FACTION_MEMBERSHIPS => $this->syncFactionMemberships($includeDrafts, $dryRun),
            self::RESOURCE_DOCUMENTS => $this->syncDocuments($includeDrafts, $dryRun),
            self::RESOURCE_CROSSOVER_ENTRY_POINTS => $this->syncCrossoverEntryPoints($includeDrafts, $dryRun),
            self::RESOURCE_CANON_REFERENCES => $this->syncCanonReferences($includeDrafts, $dryRun),
            self::RESOURCE_COLLECTIONS => $this->syncCollections($includeDrafts, $dryRun),
            self::RESOURCE_GLOSSARY => $this->syncGlossary($includeDrafts, $dryRun),
            self::RESOURCE_TIMELINES => $this->syncTimelines($includeDrafts, $dryRun),
            self::RESOURCE_CHARACTER_STATES => $this->syncCharacterStates($includeDrafts, $dryRun),
            self::RESOURCE_CONCURRENCY_GROUPS => $this->syncConcurrencyGroups($includeDrafts, $dryRun),
            self::RESOURCE_POWER_INTERACTIONS => $this->syncPowerInteractions($includeDrafts, $dryRun),
            self::RESOURCE_TRAVEL_ROUTES => $this->syncTravelRoutes($includeDrafts, $dryRun),
            self::RESOURCE_LOCATION_CONTAINMENT => $this->syncLocationContainment($includeDrafts, $dryRun),
            self::RESOURCE_LOCATION_CONTROL => $this->syncLocationControl($includeDrafts, $dryRun),
            self::RESOURCE_SECRETS => $this->syncSecrets($includeDrafts, $dryRun),
            self::RESOURCE_KNOWLEDGE_STATES => $this->syncKnowledgeStates($includeDrafts, $dryRun),
            self::RESOURCE_PERCEPTION_STATES => $this->syncPerceptionStates($includeDrafts, $dryRun),
            self::RESOURCE_META => $this->syncMeta($includeDrafts, $dryRun),
            self::RESOURCE_PIPELINE_ITEMS => $this->syncPipelineItems($includeDrafts, $dryRun),
            self::RESOURCE_SESSION_LOGS => $this->syncSessionLogs($includeDrafts, $dryRun),
            default => throw new RuntimeException("Unsupported resource [{$resource}]."),
        };
    }

    private function syncRelationships(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_RELATIONSHIPS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_RELATIONSHIPS, $mapping, $page, Relationship::class),
            function (array $page, ?Relationship $relationship) {
                $from = $this->requiredRelatedModelId(
                    $page,
                    ['From Entity', 'From'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'from entity'
                );
                $to = $this->requiredRelatedModelId(
                    $page,
                    ['To Entity', 'To'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'to entity'
                );

                return [
                    'from_entity_id' => $from,
                    'to_entity_id' => $to,
                    'relationship_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Relationship Type'], $relationship?->relationship_type),
                        RelationshipType::ALL,
                        RelationshipType::NARRATIVE,
                    ),
                    'other_type_notes' => $this->richTextFrom($page, ['Other Type Notes'], $relationship?->other_type_notes),
                    'direction' => $this->normalizedSelectFrom($page, ['Direction'], $relationship?->direction) ?? 'one_way',
                    'perspective_a' => $this->documentFrom($page, ['Perspective A'], $relationship?->perspective_a),
                    'perspective_b' => $this->documentFrom($page, ['Perspective B'], $relationship?->perspective_b),
                    'current_tension_charge' => $this->normalizedSelectFrom($page, ['Tension Charge'], $relationship?->current_tension_charge) ?? 'neutral',
                    'strength_from_a' => $this->numberFrom($page, ['Strength A', 'Strength From A'], $relationship?->strength_from_a),
                    'strength_from_b' => $this->numberFrom($page, ['Strength B', 'Strength From B'], $relationship?->strength_from_b),
                    'time_period_start' => $this->richTextFrom($page, ['Time Period Start'], $relationship?->time_period_start),
                    'time_period_end' => $this->richTextFrom($page, ['Time Period End'], $relationship?->time_period_end),
                    'is_active' => $this->checkboxFrom($page, ['Active', 'Is Active'], $relationship?->is_active ?? true),
                    'relationship_history' => $this->jsonArrayFrom($page, ['Relationship History'], $relationship?->relationship_history ?? []),
                    'perceived_type' => $this->richTextFrom($page, ['Perceived Type'], $relationship?->perceived_type),
                    'true_type' => $this->richTextFrom($page, ['True Type'], $relationship?->true_type),
                    'perception_divergence' => $this->richTextFrom($page, ['Perception Divergence'], $relationship?->perception_divergence),
                    'perceived_by' => $this->relatedModelIdsFrom($page, ['Perceived By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $relationship?->perceived_by ?? []),
                    'notes' => $this->documentFrom($page, ['Notes'], $relationship?->notes),
                    'visibility' => $this->visibilityFrom($page, $relationship?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $relationship?->content_classification),
                ];
            },
            fn (array $data) => $this->relationshipService->create(Entity::findOrFail($data['from_entity_id']), Entity::findOrFail($data['to_entity_id']), $data),
            fn (Relationship $relationship, array $data) => $this->relationshipService->update($relationship, $data),
        );
    }

    private function syncGroupRelationships(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_GROUP_RELATIONSHIPS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_GROUP_RELATIONSHIPS, $mapping, $page, GroupRelationship::class),
            function (array $page, ?GroupRelationship $group) {
                $name = $this->titleFrom($page, ['Name', 'Group Relationship'], $group?->name);

                if (blank($name)) {
                    throw new RuntimeException('missing a group relationship name');
                }

                return [
                    'name' => $name,
                    'relationship_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Relationship Type'], $group?->relationship_type),
                        RelationshipType::ALL,
                        RelationshipType::NARRATIVE,
                    ),
                    'other_type_notes' => $this->richTextFrom($page, ['Other Type Notes'], $group?->other_type_notes),
                    'dynamic_description' => $this->documentFrom($page, ['Description', 'Dynamic Description'], $group?->dynamic_description),
                    'current_tension_charge' => $this->normalizedSelectFrom($page, ['Tension Charge'], $group?->current_tension_charge) ?? 'neutral',
                    'time_period_start' => $this->richTextFrom($page, ['Time Period Start'], $group?->time_period_start),
                    'time_period_end' => $this->richTextFrom($page, ['Time Period End'], $group?->time_period_end),
                    'is_active' => $this->checkboxFrom($page, ['Active', 'Is Active'], $group?->is_active ?? true),
                    'group_history' => $this->jsonArrayFrom($page, ['Group History'], $group?->group_history ?? []),
                    'perceived_type' => $this->richTextFrom($page, ['Perceived Type'], $group?->perceived_type),
                    'true_type' => $this->richTextFrom($page, ['True Type'], $group?->true_type),
                    'perception_divergence' => $this->richTextFrom($page, ['Perception Divergence'], $group?->perception_divergence),
                    'perceived_by' => $this->relatedModelIdsFrom($page, ['Perceived By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $group?->perceived_by ?? []),
                    'notes' => $this->documentFrom($page, ['Notes'], $group?->notes),
                    'visibility' => $this->visibilityFrom($page, $group?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $group?->content_classification),
                    '__member_ids' => $this->relatedModelIdsFrom(
                        $page,
                        ['Members'],
                        NotionIdentitySyncService::RESOURCE_ENTITIES,
                        Entity::class,
                        $group
                            ? $group->memberEntries()->where('is_active_member', true)->pluck('entity_id')->all()
                            : [],
                    ),
                ];
            },
            function (array $data) {
                $memberData = collect($data['__member_ids'] ?? [])
                    ->map(static fn (int $id) => ['entity_id' => $id])
                    ->all();

                unset($data['__member_ids']);

                return $this->relationshipService->createGroup($data, $memberData);
            },
            function (GroupRelationship $group, array $data) {
                $memberIds = $data['__member_ids'] ?? [];

                unset($data['__member_ids']);

                $group->update($data);
                $this->syncGroupRelationshipMembers($group, $memberIds);

                return $group->fresh();
            },
            function (array $data) {
                $hash = $data;
                $hash['__member_ids'] = collect($data['__member_ids'] ?? [])->sort()->values()->all();

                return $hash;
            },
        );
    }

    private function syncFactionMemberships(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_FACTION_MEMBERSHIPS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_FACTION_MEMBERSHIPS, $mapping, $page, FactionMembership::class),
            function (array $page, ?FactionMembership $membership) {
                $factionId = $this->requiredRelatedModelId(
                    $page,
                    ['Faction', 'Faction Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'faction entity'
                );
                $memberId = $this->requiredRelatedModelId(
                    $page,
                    ['Member', 'Member Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'member entity'
                );

                return [
                    'faction_entity_id' => $factionId,
                    'member_entity_id' => $memberId,
                    'rank_or_role' => $this->richTextFrom($page, ['Rank or Role', 'Role'], $membership?->rank_or_role),
                    'membership_status' => $this->normalizedSelectFrom($page, ['Membership Status'], $membership?->membership_status) ?? 'active',
                    'joined_era' => $this->richTextFrom($page, ['Joined Era'], $membership?->joined_era),
                    'left_era' => $this->richTextFrom($page, ['Left Era'], $membership?->left_era),
                    'departure_reason' => $this->documentFrom($page, ['Departure Reason'], $membership?->departure_reason),
                    'true_loyalty_entity_id' => $this->relatedModelIdFrom($page, ['True Loyalty'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $membership?->true_loyalty_entity_id),
                    'is_undercover' => $this->checkboxFrom($page, ['Undercover', 'Is Undercover'], $membership?->is_undercover ?? false),
                    'public_membership_known' => $this->checkboxFrom($page, ['Publicly Known', 'Public Membership Known'], $membership?->public_membership_known ?? true),
                    'recruited_by_entity_id' => $this->relatedModelIdFrom($page, ['Recruited By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $membership?->recruited_by_entity_id),
                    'notes' => $this->documentFrom($page, ['Notes'], $membership?->notes),
                    'visibility' => $this->visibilityFrom($page, $membership?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $membership?->content_classification),
                ];
            },
            fn (array $data) => $this->relationshipService->createFactionMembership(Entity::findOrFail($data['faction_entity_id']), Entity::findOrFail($data['member_entity_id']), $data),
            fn (FactionMembership $membership, array $data) => $this->relationshipService->updateFactionMembership($membership, $data),
        );
    }

    private function syncDocuments(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_DOCUMENTS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_DOCUMENTS, $mapping, $page, Document::class),
            function (array $page, ?Document $document) {
                $title = $this->titleFrom($page, ['Title', 'Document Title'], $document?->title);

                if (blank($title)) {
                    throw new RuntimeException('missing a document title');
                }

                return [
                    'title' => $title,
                    'document_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Document Type'], $document?->document_type),
                        Document::DOCUMENT_TYPES,
                        'other',
                    ),
                    'owner_entity_id' => $this->relatedModelIdFrom($page, ['Owner', 'Owner Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $document?->owner_entity_id),
                    'official_author_entity_id' => $this->relatedModelIdFrom($page, ['Official Author'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $document?->official_author_entity_id),
                    'true_author_entity_id' => $this->relatedModelIdFrom($page, ['True Author'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $document?->true_author_entity_id),
                    'document_status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Document Status'], $document?->document_status),
                        Document::DOCUMENT_STATUSES,
                        'extant',
                    ),
                    'document_authenticity' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Authenticity'], $document?->document_authenticity),
                        Document::AUTHENTICITY_STATES,
                        'unknown',
                    ),
                    'official_narrative' => $this->documentFrom($page, ['Official Narrative'], $document?->official_narrative),
                    'true_content' => $this->documentFrom($page, ['True Content', 'Content'], $document?->true_content),
                    'authorship_divergence_notes' => $this->documentFrom($page, ['Authorship Divergence Notes'], $document?->authorship_divergence_notes),
                    'era_created' => $this->richTextFrom($page, ['Era Created'], $document?->era_created),
                    'era_discovered' => $this->richTextFrom($page, ['Era Discovered'], $document?->era_discovered),
                    'parent_document_id' => $this->relatedModelIdFrom($page, ['Parent Document'], self::RESOURCE_DOCUMENTS, Document::class, $document?->parent_document_id),
                    'version_number' => $this->numberFrom($page, ['Version Number'], $document?->version_number),
                    'superseded_by_document_id' => $this->relatedModelIdFrom($page, ['Superseded By'], self::RESOURCE_DOCUMENTS, Document::class, $document?->superseded_by_document_id),
                    'suppressed_by_entity_id' => $this->relatedModelIdFrom($page, ['Suppressed By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $document?->suppressed_by_entity_id),
                    'suppression_notes' => $this->documentFrom($page, ['Suppression Notes'], $document?->suppression_notes),
                    'access_level' => $this->richTextFrom($page, ['Access Level'], $document?->access_level),
                    'known_by_entity_ids' => $this->relatedModelIdsFrom($page, ['Known By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $document?->known_by_entity_ids ?? []),
                    'visibility' => $this->visibilityFrom($page, $document?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $document?->content_classification),
                ];
            },
        );
    }

    private function syncCrossoverEntryPoints(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_CROSSOVER_ENTRY_POINTS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_CROSSOVER_ENTRY_POINTS, $mapping, $page, CrossoverEntryPoint::class),
            function (array $page, ?CrossoverEntryPoint $entryPoint) {
                $sourceUniverse = $this->richTextFrom($page, ['Source Universe'], $entryPoint?->source_universe);

                if (blank($sourceUniverse)) {
                    throw new RuntimeException('missing a source universe');
                }

                return [
                    'source_universe' => $sourceUniverse,
                    'entry_mechanism' => $this->documentFrom($page, ['Entry Mechanism'], $entryPoint?->entry_mechanism),
                    'power_transition_rules' => $this->documentFrom($page, ['Power Transition Rules'], $entryPoint?->power_transition_rules),
                    'physical_transition_rules' => $this->documentFrom($page, ['Physical Transition Rules'], $entryPoint?->physical_transition_rules),
                    'memory_and_identity_rules' => $this->documentFrom($page, ['Memory and Identity Rules'], $entryPoint?->memory_and_identity_rules),
                    'psychological_transition_rules' => $this->documentFrom($page, ['Psychological Transition Rules'], $entryPoint?->psychological_transition_rules),
                    'canon_deviation_notes' => $this->documentFrom($page, ['Canon Deviation Notes'], $entryPoint?->canon_deviation_notes),
                    'known_examples' => $this->relatedModelIdsFrom($page, ['Known Examples'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $entryPoint?->known_examples ?? []),
                    'known_entry_points' => $this->relatedModelIdsFrom($page, ['Known Entry Points'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $entryPoint?->known_entry_points ?? []),
                    'status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Status'], $entryPoint?->status),
                        CrossoverEntryPoint::STATUSES,
                        'theorized',
                    ),
                    'restrictions' => $this->documentFrom($page, ['Restrictions'], $entryPoint?->restrictions),
                    'return_rules' => $this->documentFrom($page, ['Return Rules'], $entryPoint?->return_rules),
                    'first_documented_crossing_event_id' => $this->relatedModelIdFrom($page, ['First Documented Crossing Event'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $entryPoint?->first_documented_crossing_event_id),
                    'visibility' => $this->visibilityFrom($page, $entryPoint?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $entryPoint?->content_classification),
                ];
            },
        );
    }

    private function syncCanonReferences(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_CANON_REFERENCES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_CANON_REFERENCES, $mapping, $page, SourceCanonReference::class),
            function (array $page, ?SourceCanonReference $reference) {
                $universe = $this->richTextFrom($page, ['Universe'], $reference?->universe);
                $title = $this->titleFrom($page, ['Title'], $reference?->title);

                if (blank($universe)) {
                    throw new RuntimeException('missing a canon-reference universe');
                }

                if (blank($title)) {
                    throw new RuntimeException('missing a canon-reference title');
                }

                return [
                    'universe' => $universe,
                    'level' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Level'], $reference?->level),
                        SourceCanonReference::LEVELS,
                        'element',
                    ),
                    'parent_reference_id' => $this->relatedModelIdFrom($page, ['Parent Reference'], self::RESOURCE_CANON_REFERENCES, SourceCanonReference::class, $reference?->parent_reference_id),
                    'title' => $title,
                    'content' => $this->documentFrom($page, ['Content'], $reference?->content),
                    'universe_overview' => $this->documentFrom($page, ['Universe Overview'], $reference?->universe_overview),
                    'universe_priority' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Universe Priority'], $reference?->universe_priority),
                        SourceCanonReference::UNIVERSE_PRIORITIES,
                    ),
                    'universe_depth_rating' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Universe Depth Rating'], $reference?->universe_depth_rating),
                        SourceCanonReference::DEPTH_RATINGS,
                    ),
                    'overall_divergence_summary' => $this->documentFrom($page, ['Overall Divergence Summary'], $reference?->overall_divergence_summary),
                    'primary_elements_borrowed' => $this->jsonArrayFrom($page, ['Primary Elements Borrowed'], $reference?->primary_elements_borrowed),
                    'primary_divergences' => $this->jsonArrayFrom($page, ['Primary Divergences'], $reference?->primary_divergences),
                    'crossover_entry_point_id' => $this->relatedModelIdFrom($page, ['Crossover Entry Point'], self::RESOURCE_CROSSOVER_ENTRY_POINTS, CrossoverEntryPoint::class, $reference?->crossover_entry_point_id),
                    'category_type' => $this->normalizedSelectFrom($page, ['Category Type'], $reference?->category_type),
                    'category_overview' => $this->documentFrom($page, ['Category Overview'], $reference?->category_overview),
                    'element_type' => $this->normalizedSelectFrom($page, ['Element Type'], $reference?->element_type),
                    'canonical_properties' => $this->jsonArrayFrom($page, ['Canonical Properties'], $reference?->canonical_properties),
                    'first_appearance' => $this->richTextFrom($page, ['First Appearance'], $reference?->first_appearance),
                    'source_material_references' => $this->jsonArrayFrom($page, ['Source Material References'], $reference?->source_material_references),
                    'au_entity_id' => $this->relatedModelIdFrom($page, ['AU Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $reference?->au_entity_id),
                    'canon_disputed' => $this->checkboxFrom($page, ['Canon Disputed'], $reference?->canon_disputed ?? false),
                    'dispute_description' => $this->richTextFrom($page, ['Dispute Description'], $reference?->dispute_description),
                    'dispute_sources' => $this->jsonArrayFrom($page, ['Dispute Sources'], $reference?->dispute_sources),
                    'your_ruling' => $this->documentFrom($page, ['Your Ruling'], $reference?->your_ruling),
                    'research_status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Research Status'], $reference?->research_status),
                        SourceCanonReference::RESEARCH_STATUSES,
                        'unstarted',
                    ),
                    'research_notes' => $this->documentFrom($page, ['Research Notes'], $reference?->research_notes),
                    'last_researched_at' => $this->dateFrom($page, ['Last Researched'], $reference?->last_researched_at?->toDateString()),
                    'research_confidence' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Research Confidence'], $reference?->research_confidence),
                        SourceCanonReference::RESEARCH_CONFIDENCES,
                    ),
                    'visibility' => $this->visibilityFrom($page, $reference?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $reference?->content_classification),
                ];
            },
        );
    }

    private function syncCollections(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_COLLECTIONS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_COLLECTIONS, $mapping, $page, Collection::class),
            function (array $page, ?Collection $collection) {
                $name = $this->titleFrom($page, ['Name', 'Collection'], $collection?->name);

                if (blank($name)) {
                    throw new RuntimeException('missing a collection name');
                }

                return [
                    'name' => $name,
                    'description' => $this->documentFrom($page, ['Description'], $collection?->description),
                    'collection_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Collection Type'], $collection?->collection_type),
                        Collection::TYPES,
                        'custom',
                    ),
                    'collection_mode' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Collection Mode'], $collection?->collection_mode),
                        Collection::MODES,
                        'manual',
                    ),
                    'rules' => $this->ruleArrayFrom($page, ['Rules'], $collection?->rules ?? []),
                    'excluded_entity_ids' => $this->relatedModelIdsFrom($page, ['Excluded Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $collection?->excluded_entity_ids ?? []),
                    'parent_collection_id' => $this->relatedModelIdFrom($page, ['Parent Collection'], self::RESOURCE_COLLECTIONS, Collection::class, $collection?->parent_collection_id),
                    'sort_order' => $this->numberFrom($page, ['Sort Order'], $collection?->sort_order),
                    'completion_state' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Completion State'], $collection?->completion_state),
                        Collection::COMPLETION_STATES,
                        'not_started',
                    ),
                    'visibility' => $this->visibilityFrom($page, $collection?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $collection?->content_classification),
                ];
            },
            fn (array $data) => $this->collectionService->create($data),
            fn (Collection $collection, array $data) => $this->collectionService->update($collection, $data),
        );
    }

    private function syncGlossary(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_GLOSSARY,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_GLOSSARY, $mapping, $page, Glossary::class),
            function (array $page, ?Glossary $glossary) {
                $term = $this->titleFrom($page, ['Term'], $glossary?->term);

                if (blank($term)) {
                    throw new RuntimeException('missing a glossary term');
                }

                return [
                    'term' => $term,
                    'usage_context' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Usage Context'], $glossary?->usage_context),
                        Glossary::USAGE_CONTEXTS,
                        'both',
                    ),
                    'definition' => $this->documentFrom($page, ['Definition'], $glossary?->definition, true),
                    'extended_notes' => $this->documentFrom($page, ['Extended Notes'], $glossary?->extended_notes),
                    'origin_universe' => $this->richTextFrom($page, ['Origin Universe'], $glossary?->origin_universe),
                    'era_introduced' => $this->richTextFrom($page, ['Era Introduced'], $glossary?->era_introduced),
                    'era_obsolete' => $this->richTextFrom($page, ['Era Obsolete'], $glossary?->era_obsolete),
                    'term_status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Term Status'], $glossary?->term_status),
                        Glossary::TERM_STATUSES,
                        'active',
                    ),
                    'suppressed_by_entity_id' => $this->relatedModelIdFrom($page, ['Suppressed By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $glossary?->suppressed_by_entity_id),
                    'suppression_notes' => $this->documentFrom($page, ['Suppression Notes'], $glossary?->suppression_notes),
                    'first_appearance_entity_id' => $this->relatedModelIdFrom($page, ['First Appearance'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $glossary?->first_appearance_entity_id),
                    'related_term_ids' => $this->relatedModelIdsFrom($page, ['Related Terms'], self::RESOURCE_GLOSSARY, Glossary::class, $glossary?->related_term_ids ?? []),
                    'visibility' => $this->visibilityFrom($page, $glossary?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $glossary?->content_classification),
                ];
            },
        );
    }

    private function syncTimelines(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_TIMELINES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_TIMELINES, $mapping, $page, Entity::class),
            function (array $page, ?Entity $timeline) {
                $name = $this->titleFrom($page, ['Timeline Name', 'Name', 'Entity Name'], $timeline?->name);

                if (blank($name)) {
                    throw new RuntimeException('missing a timeline name');
                }

                $visibility = $this->visibilityFrom($page, $timeline?->visibility);
                $isPublic = $visibility === VisibilityLevel::PUBLIC_KNOWLEDGE;

                return [
                    'name' => $name,
                    'entity_type' => EntityType::TIMELINE,
                    'summary' => $this->documentFrom($page, ['Summary', 'Description'], $timeline?->summary),
                    'source_universes' => $this->multiSelectFrom($page, ['Source Universes'], $timeline?->source_universes ?? []),
                    'origin_type' => $this->normalizedSelectFrom($page, ['Origin Type'], $timeline?->origin_type),
                    'visibility' => $visibility,
                    'published_at' => $isPublic ? ($timeline?->published_at ?? now()) : null,
                    'content_classification' => $this->contentClassificationFrom($page, $timeline?->content_classification),
                ];
            },
            fn (array $data) => $this->entityService->create($data),
            fn (Entity $timeline, array $data) => $this->entityService->update($timeline, $data),
            fn (array $data) => [
                ...$data,
                'published_at' => ! empty($data['published_at']),
            ],
        );
    }

    private function syncCharacterStates(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_CHARACTER_STATES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_CHARACTER_STATES, $mapping, $page, CharacterStateTracker::class),
            function (array $page, ?CharacterStateTracker $state) {
                $entityId = $this->requiredRelatedModelId(
                    $page,
                    ['Entity', 'Character'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'state entity'
                );

                return [
                    'entity_id' => $entityId,
                    'timeline_id' => $this->relatedModelIdFrom($page, ['Timeline'], self::RESOURCE_TIMELINES, Entity::class, $state?->timeline_id),
                    'era_entity_id' => $this->relatedModelIdFrom($page, ['Era'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->era_entity_id),
                    'au_date' => $this->dateOrRichTextFrom($page, ['AU Date'], $state?->au_date),
                    'source_date' => $this->richTextFrom($page, ['Source Date'], $state?->source_date),
                    'timeline_position' => $this->numberFrom($page, ['Timeline Position'], $state?->timeline_position),
                    'snapshot_label' => $this->titleFrom($page, ['Snapshot Label'], $state?->snapshot_label),
                    'snapshot_significance' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Snapshot Significance'], $state?->snapshot_significance),
                        CharacterStateTracker::SNAPSHOT_SIGNIFICANCE_LEVELS,
                    ),
                    'significance_reason' => $this->richTextFrom($page, ['Significance Reason'], $state?->significance_reason),
                    'current_trauma_profile' => $this->documentFrom($page, ['Current Trauma Profile'], $state?->current_trauma_profile),
                    'active_psychological_patterns' => $this->documentFrom($page, ['Active Psychological Patterns'], $state?->active_psychological_patterns),
                    'coping_mechanisms' => $this->richTextFrom($page, ['Coping Mechanisms'], $state?->coping_mechanisms),
                    'breaking_points' => $this->richTextFrom($page, ['Breaking Points'], $state?->breaking_points),
                    'current_stability_level' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Stability Level', 'Current Stability Level'], $state?->current_stability_level),
                        CharacterStateTracker::STABILITY_LEVELS,
                    ),
                    'self_perception' => $this->richTextFrom($page, ['Self Perception'], $state?->self_perception),
                    'core_wound' => $this->richTextFrom($page, ['Core Wound'], $state?->core_wound),
                    'current_desire' => $this->richTextFrom($page, ['Current Desire'], $state?->current_desire),
                    'current_fear' => $this->richTextFrom($page, ['Current Fear'], $state?->current_fear),
                    'shadow_self' => $this->richTextFrom($page, ['Shadow Self'], $state?->shadow_self),
                    'relational_patterns' => $this->richTextFrom($page, ['Relational Patterns'], $state?->relational_patterns),
                    'current_relational_state' => $this->richTextFrom($page, ['Current Relational State'], $state?->current_relational_state),
                    'performed_self' => $this->richTextFrom($page, ['Performed Self'], $state?->performed_self),
                    'true_self' => $this->richTextFrom($page, ['True Self'], $state?->true_self),
                    'mask_integrity' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Mask Integrity'], $state?->mask_integrity),
                        CharacterStateTracker::MASK_INTEGRITY_LEVELS,
                    ),
                    'physical_state_notes' => $this->richTextFrom($page, ['Physical State Notes'], $state?->physical_state_notes),
                    'significant_physical_changes' => $this->jsonArrayFrom($page, ['Significant Physical Changes'], $state?->significant_physical_changes),
                    'physical_integrity' => $this->richTextFrom($page, ['Physical Integrity'], $state?->physical_integrity),
                    'current_power_tier_operating' => $this->richTextFrom($page, ['Current Power Tier Operating'], $state?->current_power_tier_operating),
                    'current_power_tier_influence' => $this->richTextFrom($page, ['Current Power Tier Influence'], $state?->current_power_tier_influence),
                    'available_abilities' => $this->jsonArrayFrom($page, ['Available Abilities'], $state?->available_abilities),
                    'restricted_abilities' => $this->jsonArrayFrom($page, ['Restricted Abilities'], $state?->restricted_abilities),
                    'lost_abilities' => $this->jsonArrayFrom($page, ['Lost Abilities'], $state?->lost_abilities),
                    'current_artifacts_and_hallows' => $this->jsonArrayFrom($page, ['Current Artifacts and Hallows'], $state?->current_artifacts_and_hallows),
                    'power_state_notes' => $this->richTextFrom($page, ['Power State Notes'], $state?->power_state_notes),
                    'key_relationships_summary' => $this->jsonArrayFrom($page, ['Key Relationships Summary'], $state?->key_relationships_summary),
                    'active_group_relationship_ids' => $this->relatedModelIdsFrom($page, ['Active Group Relationships'], self::RESOURCE_GROUP_RELATIONSHIPS, GroupRelationship::class, $state?->active_group_relationship_ids ?? []),
                    'notes' => $this->documentFrom($page, ['Notes'], $state?->notes),
                    'visibility' => $this->visibilityFrom($page, $state?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $state?->content_classification),
                ];
            },
            fn (array $data) => $this->temporalService->createStateSnapshot(Entity::findOrFail($data['entity_id']), $data),
            fn (CharacterStateTracker $state, array $data) => $this->temporalService->updateStateSnapshot($state, $data),
        );
    }

    private function syncConcurrencyGroups(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_CONCURRENCY_GROUPS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_CONCURRENCY_GROUPS, $mapping, $page, ConcurrencyGroup::class),
            function (array $page, ?ConcurrencyGroup $group) {
                $name = $this->titleFrom($page, ['Name'], $group?->name);

                if (blank($name)) {
                    throw new RuntimeException('missing a concurrency-group name');
                }

                return [
                    'name' => $name,
                    'au_date' => $this->dateOrRichTextFrom($page, ['AU Date'], $group?->au_date),
                    'description' => $this->documentFrom($page, ['Description'], $group?->description),
                    'narrative_significance' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Narrative Significance'], $group?->narrative_significance),
                        ConcurrencyGroup::SIGNIFICANCE_LEVELS,
                    ),
                ];
            },
            fn (array $data) => $this->temporalService->createConcurrencyGroup($data),
        );
    }

    private function syncPowerInteractions(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_POWER_INTERACTIONS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_POWER_INTERACTIONS, $mapping, $page, PowerInteraction::class),
            function (array $page, ?PowerInteraction $interaction) {
                $systemA = $this->requiredRelatedModelId(
                    $page,
                    ['System A', 'System A Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'system A entity'
                );
                $systemB = $this->requiredRelatedModelId(
                    $page,
                    ['System B', 'System B Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'system B entity'
                );
                $name = $this->titleFrom($page, ['Interaction Name', 'Name'], $interaction?->interaction_name);

                if (blank($name)) {
                    throw new RuntimeException('missing an interaction name');
                }

                return [
                    'system_a_entity_id' => $systemA,
                    'system_b_entity_id' => $systemB,
                    'interaction_name' => $name,
                    'description' => $this->documentFrom($page, ['Description'], $interaction?->description),
                    'directionality' => $this->normalizedSelectFrom($page, ['Directionality'], $interaction?->directionality),
                    'dominant_system_entity_id' => $this->relatedModelIdFrom($page, ['Dominant System'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $interaction?->dominant_system_entity_id),
                    'effects' => $this->jsonArrayFrom($page, ['Effects'], $interaction?->effects),
                    'proximity_required' => $this->checkboxFrom($page, ['Proximity Required'], $interaction?->proximity_required ?? false),
                    'location_conditions' => $this->jsonArrayFrom($page, ['Location Conditions'], $interaction?->location_conditions),
                    'practitioner_conditions' => $this->jsonArrayFrom($page, ['Practitioner Conditions'], $interaction?->practitioner_conditions),
                    'temporal_conditions' => $this->jsonArrayFrom($page, ['Temporal Conditions'], $interaction?->temporal_conditions),
                    'artifact_conditions' => $this->jsonArrayFrom($page, ['Artifact Conditions'], $interaction?->artifact_conditions),
                    'trigger_type' => $this->richTextFrom($page, ['Trigger Type'], $interaction?->trigger_type),
                    'trigger_description' => $this->documentFrom($page, ['Trigger Description'], $interaction?->trigger_description),
                    'trigger_frequency' => $this->richTextFrom($page, ['Trigger Frequency'], $interaction?->trigger_frequency),
                    'interaction_scale' => $this->normalizedSelectFrom($page, ['Interaction Scale'], $interaction?->interaction_scale),
                    'scale_variance' => $this->richTextFrom($page, ['Scale Variance'], $interaction?->scale_variance),
                    'knowledge_state' => $this->normalizedSelectFrom($page, ['Knowledge State'], $interaction?->knowledge_state),
                    'danger_rating' => $this->normalizedSelectFrom($page, ['Danger Rating'], $interaction?->danger_rating),
                    'resolution_notes' => $this->documentFrom($page, ['Resolution Notes'], $interaction?->resolution_notes),
                    'source_universe_a' => $this->richTextFrom($page, ['Source Universe A'], $interaction?->source_universe_a),
                    'source_universe_b' => $this->richTextFrom($page, ['Source Universe B'], $interaction?->source_universe_b),
                    'visibility' => $this->visibilityFrom($page, $interaction?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $interaction?->content_classification),
                ];
            },
            fn (array $data) => $this->worldService->createPowerInteraction($data),
            fn (PowerInteraction $interaction, array $data) => $this->worldService->updatePowerInteraction($interaction, $data),
        );
    }

    private function syncTravelRoutes(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_TRAVEL_ROUTES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_TRAVEL_ROUTES, $mapping, $page, TravelRoute::class),
            function (array $page, ?TravelRoute $route) {
                $originId = $this->requiredRelatedModelId(
                    $page,
                    ['Origin', 'Origin Location'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'origin location'
                );
                $destinationId = $this->requiredRelatedModelId(
                    $page,
                    ['Destination', 'Destination Location'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'destination location'
                );

                return [
                    'origin_location_entity_id' => $originId,
                    'destination_location_entity_id' => $destinationId,
                    'route_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Route Type'], $route?->route_type),
                        TravelRoute::ROUTE_TYPES,
                        'overland',
                    ),
                    'standard_duration' => $this->richTextFrom($page, ['Standard Duration'], $route?->standard_duration),
                    'method_variants' => $this->jsonArrayFrom($page, ['Method Variants'], $route?->method_variants),
                    'hazards' => $this->jsonArrayFrom($page, ['Hazards'], $route?->hazards),
                    'era_specific_variants' => $this->jsonArrayFrom($page, ['Era Specific Variants'], $route?->era_specific_variants),
                    'known_by_entity_ids' => $this->relatedModelIdsFrom($page, ['Known By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $route?->known_by_entity_ids ?? []),
                    'controlled_by_entity_id' => $this->relatedModelIdFrom($page, ['Controlled By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $route?->controlled_by_entity_id),
                    'is_active' => $this->checkboxFrom($page, ['Active', 'Is Active'], $route?->is_active ?? true),
                    'notes' => $this->documentFrom($page, ['Notes'], $route?->notes),
                    'visibility' => $this->visibilityFrom($page, $route?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $route?->content_classification),
                    '__bidirectional' => $this->checkboxFrom($page, ['Bidirectional'], false),
                ];
            },
            function (array $data) {
                $bidirectional = (bool) ($data['__bidirectional'] ?? false);

                unset($data['__bidirectional']);

                if ($bidirectional) {
                    return $this->worldService->createBidirectionalRoute(
                        Entity::findOrFail($data['origin_location_entity_id']),
                        Entity::findOrFail($data['destination_location_entity_id']),
                        $data['route_type'],
                        $data,
                    )[0];
                }

                return $this->worldService->createRoute(
                    Entity::findOrFail($data['origin_location_entity_id']),
                    Entity::findOrFail($data['destination_location_entity_id']),
                    $data['route_type'],
                    $data,
                );
            },
            function (TravelRoute $route, array $data) {
                unset($data['__bidirectional']);
                $route->update($data);

                return $route->fresh();
            },
            function (array $data) {
                $hash = $data;
                unset($hash['__bidirectional']);

                return $hash;
            },
        );
    }

    private function syncLocationContainment(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_LOCATION_CONTAINMENT,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_LOCATION_CONTAINMENT, $mapping, $page, LocationContainment::class),
            function (array $page, ?LocationContainment $containment) {
                $childId = $this->requiredRelatedModelId(
                    $page,
                    ['Child Location', 'Child'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'child location'
                );
                $parentId = $this->requiredRelatedModelId(
                    $page,
                    ['Parent Location', 'Parent'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'parent location'
                );

                return [
                    'child_location_entity_id' => $childId,
                    'parent_location_entity_id' => $parentId,
                    'containment_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Containment Type'], $containment?->containment_type),
                        LocationContainment::CONTAINMENT_TYPES,
                        'physical',
                    ),
                    'era_start' => $this->richTextFrom($page, ['Era Start'], $containment?->era_start),
                    'era_end' => $this->richTextFrom($page, ['Era End'], $containment?->era_end),
                    'is_active' => $this->checkboxFrom($page, ['Active', 'Is Active'], $containment?->is_active ?? true),
                    'notes' => $this->documentFrom($page, ['Notes'], $containment?->notes),
                ];
            },
            fn (array $data) => $this->worldService->contain(Entity::findOrFail($data['child_location_entity_id']), Entity::findOrFail($data['parent_location_entity_id']), $data['containment_type'], $data),
        );
    }

    private function syncLocationControl(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_LOCATION_CONTROL,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_LOCATION_CONTROL, $mapping, $page, LocationControlHistory::class),
            function (array $page, ?LocationControlHistory $control) {
                $locationId = $this->requiredRelatedModelId(
                    $page,
                    ['Location', 'Location Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'location entity'
                );
                $controllerId = $this->requiredRelatedModelId(
                    $page,
                    ['Controller', 'Controlling Entity'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'controlling entity'
                );

                return [
                    'location_entity_id' => $locationId,
                    'controlling_entity_id' => $controllerId,
                    'control_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Control Type'], $control?->control_type),
                        LocationControlHistory::CONTROL_TYPES,
                        'sovereign',
                    ),
                    'control_start_era' => $this->richTextFrom($page, ['Control Start Era'], $control?->control_start_era),
                    'control_end_era' => $this->richTextFrom($page, ['Control End Era'], $control?->control_end_era),
                    'is_current' => $this->checkboxFrom($page, ['Current', 'Is Current'], $control?->is_current ?? true),
                    'how_control_was_established' => $this->documentFrom($page, ['How Control Was Established'], $control?->how_control_was_established),
                    'how_control_ended' => $this->documentFrom($page, ['How Control Ended'], $control?->how_control_ended),
                    'resistance_level' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Resistance Level'], $control?->resistance_level),
                        LocationControlHistory::RESISTANCE_LEVELS,
                    ),
                    'resistance_entity_ids' => $this->relatedModelIdsFrom($page, ['Resistance Entities', 'Resistance Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $control?->resistanceEntities?->modelKeys() ?? []),
                    'notes' => $this->documentFrom($page, ['Notes'], $control?->notes),
                    'visibility' => $this->visibilityFrom($page, $control?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $control?->content_classification),
                ];
            },
            fn (array $data) => $this->worldService->recordControlChange(Entity::findOrFail($data['location_entity_id']), Entity::findOrFail($data['controlling_entity_id']), $data['control_type'], $data),
        );
    }

    private function syncSecrets(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_SECRETS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_SECRETS, $mapping, $page, Secret::class),
            function (array $page, ?Secret $secret) {
                $title = $this->titleFrom($page, ['Title'], $secret?->title);

                if (blank($title)) {
                    throw new RuntimeException('missing a secret title');
                }

                return [
                    'title' => $title,
                    'secret_content' => $this->documentFrom($page, ['Secret Content', 'Content'], $secret?->secret_content, true),
                    'secret_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Secret Type'], $secret?->secret_type),
                        Secret::SECRET_TYPES,
                        'identity',
                    ),
                    'subject_entity_ids' => $this->relatedModelIdsFrom($page, ['Subject Entities', 'Subjects'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $secret?->subject_entity_ids ?? []),
                    'holder_entity_ids' => $this->relatedModelIdsFrom($page, ['Holders'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $secret?->holder_entity_ids ?? []),
                    'known_by_entity_ids' => $this->relatedModelIdsFrom($page, ['Known By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $secret?->known_by_entity_ids ?? []),
                    'exposure_risk' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Exposure Risk'], $secret?->exposure_risk),
                        Secret::EXPOSURE_RISKS,
                        'medium',
                    ),
                    'exposure_consequences' => $this->documentFrom($page, ['Exposure Consequences'], $secret?->exposure_consequences),
                    'revelation_trigger' => $this->richTextFrom($page, ['Revelation Trigger'], $secret?->revelation_trigger),
                    'status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Status'], $secret?->status),
                        Secret::STATUSES,
                        'active',
                    ),
                    'revealed_at_era' => $this->richTextFrom($page, ['Revealed At Era'], $secret?->revealed_at_era),
                    'related_knowledge_state_ids' => $this->relatedModelIdsFrom($page, ['Related Knowledge States'], self::RESOURCE_KNOWLEDGE_STATES, KnowledgeState::class, $secret?->related_knowledge_state_ids ?? []),
                    'related_perception_state_ids' => $this->relatedModelIdsFrom($page, ['Related Perception States'], self::RESOURCE_PERCEPTION_STATES, PerceptionState::class, $secret?->related_perception_state_ids ?? []),
                    'visibility' => $this->visibilityFrom($page, $secret?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $secret?->content_classification),
                ];
            },
            fn (array $data) => $this->intelligenceService->createSecret($data),
            fn (Secret $secret, array $data) => $this->intelligenceService->updateSecret($secret, $data),
        );
    }

    private function syncKnowledgeStates(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_KNOWLEDGE_STATES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_KNOWLEDGE_STATES, $mapping, $page, KnowledgeState::class),
            function (array $page, ?KnowledgeState $state) {
                $knowerId = $this->requiredRelatedModelId(
                    $page,
                    ['Knower'],
                    NotionIdentitySyncService::RESOURCE_ENTITIES,
                    Entity::class,
                    'knower entity'
                );

                return [
                    'knower_entity_id' => $knowerId,
                    'subject_entity_id' => $this->relatedModelIdFrom($page, ['Subject Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->subject_entity_id),
                    'subject_relationship_id' => $this->relatedModelIdFrom($page, ['Subject Relationship'], self::RESOURCE_RELATIONSHIPS, Relationship::class, $state?->subject_relationship_id),
                    'subject_group_relationship_id' => $this->relatedModelIdFrom($page, ['Subject Group Relationship'], self::RESOURCE_GROUP_RELATIONSHIPS, GroupRelationship::class, $state?->subject_group_relationship_id),
                    'subject_secret_id' => $this->relatedModelIdFrom($page, ['Subject Secret'], self::RESOURCE_SECRETS, Secret::class, $state?->subject_secret_id),
                    'knowledge_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Knowledge Type'], $state?->knowledge_type),
                        KnowledgeState::KNOWLEDGE_TYPES,
                        'public_fact',
                    ),
                    'knowledge_content' => $this->documentFrom($page, ['Knowledge Content', 'Content'], $state?->knowledge_content),
                    'accuracy' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Accuracy'], $state?->accuracy),
                        KnowledgeState::ACCURACY_LEVELS,
                        'unknown_to_knower',
                    ),
                    'acquired_at_era' => $this->richTextFrom($page, ['Acquired At Era'], $state?->acquired_at_era),
                    'acquired_through' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Acquired Through'], $state?->acquired_through),
                        KnowledgeState::ACQUISITION_METHODS,
                        'other',
                    ),
                    'acquired_from_entity_id' => $this->relatedModelIdFrom($page, ['Acquired From'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->acquired_from_entity_id),
                    'current_belief_state' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Belief State', 'Current Belief State'], $state?->current_belief_state),
                        KnowledgeState::BELIEF_STATES,
                        'believes',
                    ),
                    'acted_on' => $this->checkboxFrom($page, ['Acted On'], $state?->acted_on ?? false),
                    'action_notes' => $this->documentFrom($page, ['Action Notes'], $state?->action_notes),
                    'valid_from_era' => $this->richTextFrom($page, ['Valid From Era'], $state?->valid_from_era),
                    'valid_until_era' => $this->richTextFrom($page, ['Valid Until Era'], $state?->valid_until_era),
                    'is_current' => $this->checkboxFrom($page, ['Current', 'Is Current'], $state?->is_current ?? true),
                    'superseded_by_knowledge_id' => $this->relatedModelIdFrom($page, ['Superseded By'], self::RESOURCE_KNOWLEDGE_STATES, KnowledgeState::class, $state?->superseded_by_knowledge_id),
                    'visibility' => $this->visibilityFrom($page, $state?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $state?->content_classification),
                ];
            },
            fn (array $data) => $this->intelligenceService->recordKnowledge(Entity::findOrFail($data['knower_entity_id']), $data),
            function (KnowledgeState $state, array $data) {
                $state->update($data);

                return $state->fresh();
            },
        );
    }

    private function syncPerceptionStates(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_PERCEPTION_STATES,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_PERCEPTION_STATES, $mapping, $page, PerceptionState::class),
            function (array $page, ?PerceptionState $state) {
                $subjectType = $this->normalizeChoice(
                    $this->normalizedSelectFrom($page, ['Subject Type'], $state?->subject_type),
                    PerceptionState::SUBJECT_TYPES,
                    'entity',
                );
                $subjectId = $this->resolvePerceptionSubjectId($page, $subjectType, $state?->subject_id);

                if (! $subjectId) {
                    throw new RuntimeException('could not resolve the perception-state subject');
                }

                return [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'true_state' => $this->documentFrom($page, ['True State'], $state?->true_state, true),
                    'perceived_state' => $this->documentFrom($page, ['Perceived State'], $state?->perceived_state, true),
                    'divergence_level' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Divergence Level'], $state?->divergence_level),
                        PerceptionState::DIVERGENCE_LEVELS,
                        'surface',
                    ),
                    'maintained_by_entity_ids' => $this->relatedModelIdsFrom($page, ['Maintained By'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->maintained_by_entity_ids ?? []),
                    'maintenance_method' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Maintenance Method'], $state?->maintenance_method),
                        PerceptionState::MAINTENANCE_METHODS,
                    ),
                    'maintenance_effort' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Maintenance Effort'], $state?->maintenance_effort),
                        PerceptionState::MAINTENANCE_EFFORTS,
                    ),
                    'perceiving_entity_ids' => $this->relatedModelIdsFrom($page, ['Perceiving Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->perceiving_entity_ids ?? []),
                    'immune_entity_ids' => $this->relatedModelIdsFrom($page, ['Immune Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $state?->immune_entity_ids ?? []),
                    'revelation_condition' => $this->documentFrom($page, ['Revelation Condition'], $state?->revelation_condition),
                    'revelation_consequence' => $this->documentFrom($page, ['Revelation Consequence'], $state?->revelation_consequence),
                    'revelation_risk' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Revelation Risk'], $state?->revelation_risk),
                        PerceptionState::REVELATION_RISKS,
                        'medium',
                    ),
                    'revealed_at_era' => $this->richTextFrom($page, ['Revealed At Era'], $state?->revealed_at_era),
                    'is_current' => $this->checkboxFrom($page, ['Current', 'Is Current'], $state?->is_current ?? true),
                    'related_secret_id' => $this->relatedModelIdFrom($page, ['Related Secret'], self::RESOURCE_SECRETS, Secret::class, $state?->related_secret_id),
                    'related_knowledge_state_ids' => $this->relatedModelIdsFrom($page, ['Related Knowledge States'], self::RESOURCE_KNOWLEDGE_STATES, KnowledgeState::class, $state?->related_knowledge_state_ids ?? []),
                    'visibility' => $this->visibilityFrom($page, $state?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $state?->content_classification),
                ];
            },
            fn (array $data) => $this->intelligenceService->createPerceptionGap($data),
            function (PerceptionState $state, array $data) {
                $state->update($data);

                return $state->fresh();
            },
        );
    }

    private function syncMeta(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_META,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_META, $mapping, $page, Meta::class),
            function (array $page, ?Meta $meta) {
                $title = $this->titleFrom($page, ['Title'], $meta?->title);

                if (blank($title)) {
                    throw new RuntimeException('missing a meta title');
                }

                return [
                    'title' => $title,
                    'category' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Category'], $meta?->category),
                        Meta::CATEGORIES,
                        'design_notes_and_author_intent',
                    ),
                    'meta_note_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Meta Type', 'Meta Note Type'], $meta?->meta_note_type),
                        Meta::NOTE_TYPES,
                        'passive',
                    ),
                    'content' => $this->documentFrom($page, ['Content'], $meta?->content),
                    'sense_sight' => $this->richTextFrom($page, ['Sense Sight'], $meta?->sense_sight),
                    'sense_sound' => $this->richTextFrom($page, ['Sense Sound'], $meta?->sense_sound),
                    'sense_smell' => $this->richTextFrom($page, ['Sense Smell'], $meta?->sense_smell),
                    'sense_taste' => $this->richTextFrom($page, ['Sense Taste'], $meta?->sense_taste),
                    'sense_touch' => $this->richTextFrom($page, ['Sense Touch'], $meta?->sense_touch),
                    'sense_magical' => $this->richTextFrom($page, ['Sense Magical'], $meta?->sense_magical),
                    'emotional_register' => $this->richTextFrom($page, ['Emotional Register'], $meta?->emotional_register),
                    'symbol_name' => $this->richTextFrom($page, ['Symbol Name'], $meta?->symbol_name),
                    'symbol_origin_entity_id' => $this->relatedModelIdFrom($page, ['Symbol Origin Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $meta?->symbol_origin_entity_id),
                    'symbol_usage_context' => $this->richTextFrom($page, ['Symbol Usage Context'], $meta?->symbol_usage_context),
                    'symbol_associated_entity_ids' => $this->relatedModelIdsFrom($page, ['Symbol Associated Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $meta?->symbol_associated_entity_ids ?? []),
                    'symbol_scope' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Symbol Scope'], $meta?->symbol_scope),
                        Meta::SYMBOL_SCOPES,
                    ),
                    'priority' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Priority'], $meta?->priority),
                        Meta::PRIORITIES,
                        'medium',
                    ),
                    'action_status' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Action Status'], $meta?->action_status),
                        Meta::ACTION_STATUSES,
                        'pending',
                    ),
                    'resolved_at' => $this->dateFrom($page, ['Resolved At'], $meta?->resolved_at?->toDateString()),
                    'resolution_notes' => $this->documentFrom($page, ['Resolution Notes'], $meta?->resolution_notes),
                    'superseded_by_meta_id' => $this->relatedModelIdFrom($page, ['Superseded By'], self::RESOURCE_META, Meta::class, $meta?->superseded_by_meta_id),
                    'superseded_at' => $this->dateFrom($page, ['Superseded At'], $meta?->superseded_at?->toDateString()),
                    'supersession_reason' => $this->richTextFrom($page, ['Supersession Reason'], $meta?->supersession_reason),
                    'visibility' => $this->visibilityFrom($page, $meta?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $meta?->content_classification),
                ];
            },
        );
    }

    private function syncPipelineItems(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_PIPELINE_ITEMS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_PIPELINE_ITEMS, $mapping, $page, PipelineItem::class),
            function (array $page, ?PipelineItem $item, int $index) {
                $title = $this->titleFrom($page, ['Title'], $item?->title);

                if (blank($title)) {
                    throw new RuntimeException('missing a pipeline-item title');
                }

                return [
                    'title' => $title,
                    'pipeline_type' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Pipeline Type'], $item?->pipeline_type),
                        PipelineItem::PIPELINE_TYPES,
                        'note',
                    ),
                    'parent_pipeline_item_id' => $this->relatedModelIdFrom($page, ['Parent Item', 'Parent Pipeline Item'], self::RESOURCE_PIPELINE_ITEMS, PipelineItem::class, $item?->parent_pipeline_item_id),
                    'sort_order' => $this->numberFrom($page, ['Sort Order'], $item?->sort_order ?? (($index + 1) * 10)),
                    'pipeline_stage' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Stage', 'Pipeline Stage'], $item?->pipeline_stage),
                        PipelineItem::PIPELINE_STAGES,
                        'concept',
                    ),
                    'content' => $this->documentFrom($page, ['Content'], $item?->content),
                    'word_count' => $this->numberFrom($page, ['Word Count'], $item?->word_count),
                    'reading_time_minutes' => $this->numberFrom($page, ['Reading Time Minutes', 'Reading Time'], $item?->reading_time_minutes),
                    'timeline_position' => $this->numberFrom($page, ['Timeline Position'], $item?->timeline_position),
                    'pov_character_entity_id' => $this->relatedModelIdFrom($page, ['POV Character'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->pov_character_entity_id),
                    'location_entity_id' => $this->relatedModelIdFrom($page, ['Location'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->location_entity_id),
                    'emotional_beat' => $this->richTextFrom($page, ['Emotional Beat'], $item?->emotional_beat),
                    'narrative_purpose' => $this->documentFrom($page, ['Narrative Purpose'], $item?->narrative_purpose),
                    'scene_content_warnings' => $this->multiSelectOrJsonArrayFrom($page, ['Scene Content Warnings'], $item?->scene_content_warnings ?? []),
                    'sensory_palette_meta_id' => $this->relatedModelIdFrom($page, ['Sensory Palette'], self::RESOURCE_META, Meta::class, $item?->sensory_palette_meta_id),
                    'speaker_entity_id' => $this->relatedModelIdFrom($page, ['Speaker'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->speaker_entity_id),
                    'speakers_entity_ids' => $this->relatedModelIdsFrom($page, ['Speakers'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->speakers_entity_ids ?? []),
                    'add_to_voice_samples' => $this->checkboxFrom($page, ['Add to Voice Samples'], $item?->add_to_voice_samples ?? false),
                    'tracked_entity_id' => $this->relatedModelIdFrom($page, ['Tracked Entity'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->tracked_entity_id),
                    'arc_stage' => $this->richTextFrom($page, ['Arc Stage'], $item?->arc_stage),
                    'arc_notes' => $this->documentFrom($page, ['Arc Notes'], $item?->arc_notes),
                    'inspiration_source_universe' => $this->richTextFrom($page, ['Inspiration Source Universe'], $item?->inspiration_source_universe),
                    'inspiration_source_element' => $this->richTextFrom($page, ['Inspiration Source Element'], $item?->inspiration_source_element),
                    'influenced_entity_ids' => $this->relatedModelIdsFrom($page, ['Influenced Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $item?->influenced_entity_ids ?? []),
                    'how_used' => $this->richTextFrom($page, ['How Used'], $item?->how_used),
                    'how_changed' => $this->richTextFrom($page, ['How Changed'], $item?->how_changed),
                    'deviation_level' => $this->richTextFrom($page, ['Deviation Level'], $item?->deviation_level),
                    'why_it_fits' => $this->richTextFrom($page, ['Why It Fits'], $item?->why_it_fits),
                    'notes' => $this->documentFrom($page, ['Notes'], $item?->notes),
                    'visibility' => $this->visibilityFrom($page, $item?->visibility),
                    'content_classification' => $this->contentClassificationFrom($page, $item?->content_classification),
                ];
            },
        );
    }

    private function syncSessionLogs(bool $includeDrafts, bool $dryRun): array
    {
        return $this->syncMappedResource(
            self::RESOURCE_SESSION_LOGS,
            $includeDrafts,
            $dryRun,
            fn (?NotionSyncMapping $mapping, array $page) => $this->resolveModel(self::RESOURCE_SESSION_LOGS, $mapping, $page, SessionLog::class),
            function (array $page, ?SessionLog $sessionLog) {
                $title = $this->titleFrom($page, ['Title'], $sessionLog?->title);

                if (blank($title)) {
                    throw new RuntimeException('missing a session-log title');
                }

                return [
                    'title' => $title,
                    'session_date' => $this->dateFrom($page, ['Session Date'], $sessionLog?->session_date?->toDateString() ?? now()->toDateString()),
                    'external_tool' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['External Tool'], $sessionLog?->external_tool),
                        SessionLog::EXTERNAL_TOOLS,
                        'other',
                    ),
                    'focus_entity_ids' => $this->relatedModelIdsFrom($page, ['Focus Entities'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $sessionLog?->focus_entity_ids ?? []),
                    'focus_group_relationship_ids' => $this->relatedModelIdsFrom($page, ['Focus Group Relationships'], self::RESOURCE_GROUP_RELATIONSHIPS, GroupRelationship::class, $sessionLog?->focus_group_relationship_ids ?? []),
                    'focus_collection_ids' => $this->relatedModelIdsFrom($page, ['Focus Collections'], self::RESOURCE_COLLECTIONS, Collection::class, $sessionLog?->focus_collection_ids ?? []),
                    'focus_description' => $this->richTextFrom($page, ['Focus Description'], $sessionLog?->focus_description),
                    'decisions_made' => $this->documentFrom($page, ['Decisions Made'], $sessionLog?->decisions_made),
                    'changes_applied' => $this->documentFrom($page, ['Changes Applied'], $sessionLog?->changes_applied),
                    'open_threads' => $this->documentFrom($page, ['Open Threads'], $sessionLog?->open_threads),
                    'follow_up_question_ids' => $this->relatedModelIdsFrom($page, ['Follow Up Questions'], NotionIdentitySyncService::RESOURCE_ENTITY_QUESTIONS, \App\Domain\Identity\Models\EntityQuestion::class, $sessionLog?->follow_up_question_ids ?? []),
                    'session_significance' => $this->normalizeChoice(
                        $this->normalizedSelectFrom($page, ['Session Significance'], $sessionLog?->session_significance),
                        SessionLog::SIGNIFICANCE_LEVELS,
                        'moderate',
                    ),
                    'notes' => $this->documentFrom($page, ['Notes'], $sessionLog?->notes),
                ];
            },
        );
    }

    private function syncMappedResource(
        string $resource,
        bool $includeDrafts,
        bool $dryRun,
        callable $resolveExisting,
        callable $buildData,
        ?callable $createRecord = null,
        ?callable $updateRecord = null,
        ?callable $hashPayload = null,
    ): array {
        $stats = $this->emptyStats();
        $databaseId = $this->databaseIdFor($resource);

        foreach ($this->client->queryDatabase($databaseId) as $index => $page) {
            $pageId = $this->mapper->pageId($page);
            $syncState = $this->normalizeSyncState($page);

            if (! $this->shouldImportState($syncState, $includeDrafts)) {
                $stats['skipped']++;
                continue;
            }

            try {
                $mapping = $this->mappingFor($resource, $pageId);
                $existing = $resolveExisting($mapping, $page, $index);
                $data = $buildData($page, $existing, $index);
                $payload = $hashPayload ? $hashPayload($data, $existing, $page, $index) : $data;
                $hash = $this->payloadHash($payload);
                $shouldSyncNote = ! $dryRun && $existing && $this->notionNoteSync->shouldSyncPageBody($page, $mapping);

                if ($existing && $mapping?->last_payload_hash === $hash) {
                    $noteChanged = false;

                    if (! $dryRun) {
                        if ($shouldSyncNote) {
                            $noteChanged = $this->syncNotionNote($resource, $page, $existing, $stats);
                        }

                        $this->writeBack($page, (string) $existing->getKey());
                        $this->touchMapping($mapping, $databaseId, $existing::class, (int) $existing->getKey(), $page, $hash);
                    }

                    $stats[$noteChanged ? 'updated' : 'skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $stats[$existing ? 'updated' : 'created']++;
                    continue;
                }

                $model = $existing
                    ? ($updateRecord ? $updateRecord($existing, $data, $page, $index) : $this->updateModelFromData($existing, $data))
                    : ($createRecord ? $createRecord($data, $page, $index) : $this->createModelFromData($existing, $data));

                $this->storeMapping($resource, $pageId, $databaseId, $model::class, (int) $model->getKey(), $page, $hash);
                $this->writeBack($page, (string) $model->getKey());
                $this->syncNotionNote($resource, $page, $model, $stats);

                $stats[$existing ? 'updated' : 'created']++;
            } catch (Throwable $e) {
                $stats['warnings'][] = "{$this->resourceLabel($resource)} page {$pageId} {$e->getMessage()}.";
            }
        }

        return $stats;
    }

    private function syncMany(array $resources, bool $includeDrafts, bool $dryRun, bool $skipUnconfigured): array
    {
        $overall = $this->emptyStats();

        foreach ($resources as $resource) {
            try {
                $stats = $this->sync($resource, $includeDrafts, $dryRun);
            } catch (RuntimeException $e) {
                if ($skipUnconfigured && str_starts_with($e->getMessage(), 'Missing Notion database id for [')) {
                    $overall['warnings'][] = $e->getMessage();
                    continue;
                }

                throw $e;
            }

            $overall['resources'][$resource] = $stats;
            $overall['created'] += $stats['created'];
            $overall['updated'] += $stats['updated'];
            $overall['skipped'] += $stats['skipped'];
            $overall['warnings'] = array_merge($overall['warnings'], $stats['warnings']);
        }

        return $overall;
    }

    private function syncGroupRelationshipMembers(GroupRelationship $group, array $memberIds): void
    {
        GroupRelationshipEntity::query()
            ->where('group_relationship_id', $group->id)
            ->whereNotIn('entity_id', $memberIds)
            ->update(['is_active_member' => false]);

        foreach ($memberIds as $memberId) {
            GroupRelationshipEntity::updateOrCreate(
                [
                    'group_relationship_id' => $group->id,
                    'entity_id' => $memberId,
                ],
                [
                    'is_active_member' => true,
                ],
            );
        }
    }

    private function resolvePerceptionSubjectId(array $page, string $subjectType, ?int $fallbackId): ?int
    {
        return match ($subjectType) {
            'entity' => $this->relatedModelIdFrom($page, ['Subject Entity', 'Subject'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $fallbackId),
            'relationship' => $this->relatedModelIdFrom($page, ['Subject Relationship', 'Subject'], self::RESOURCE_RELATIONSHIPS, Relationship::class, $fallbackId),
            'group_relationship' => $this->relatedModelIdFrom($page, ['Subject Group Relationship', 'Subject'], self::RESOURCE_GROUP_RELATIONSHIPS, GroupRelationship::class, $fallbackId),
            'document' => $this->relatedModelIdFrom($page, ['Subject Document', 'Subject'], self::RESOURCE_DOCUMENTS, Document::class, $fallbackId),
            'event' => $this->resolvePerceptionEventSubjectId($page, $fallbackId),
            'faction', 'location' => $this->relatedModelIdFrom($page, ['Subject Entity', 'Subject'], NotionIdentitySyncService::RESOURCE_ENTITIES, Entity::class, $fallbackId),
            default => $fallbackId,
        };
    }

    private function resolvePerceptionEventSubjectId(array $page, ?int $fallbackId): ?int
    {
        $eventEntityId = $this->relatedModelIdFrom(
            $page,
            ['Subject Entity', 'Subject'],
            NotionIdentitySyncService::RESOURCE_ENTITIES,
            Entity::class,
        );

        if (! $eventEntityId) {
            return $fallbackId;
        }

        $timelineEntityId = $this->relatedModelIdFrom(
            $page,
            ['Timeline'],
            self::RESOURCE_TIMELINES,
            Entity::class,
        );

        $matches = Timeline::query()
            ->where('event_entity_id', $eventEntityId)
            ->when($timelineEntityId, fn ($query) => $query->where('timeline_id', $timelineEntityId))
            ->orderBy('id')
            ->pluck('id');

        if ($matches->isEmpty()) {
            return $fallbackId;
        }

        if ($fallbackId && $matches->contains($fallbackId)) {
            return $fallbackId;
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveModel(string $resource, ?NotionSyncMapping $mapping, array $page, string $modelClass): ?Model
    {
        if ($mapping && $mapping->local_model_type === $modelClass) {
            $model = $modelClass::find($mapping->local_model_id);

            if ($model) {
                return $model;
            }
        }

        $siteRecordId = $this->siteRecordId($page);

        return $siteRecordId ? $modelClass::find($siteRecordId) : null;
    }

    private function requiredRelatedModelId(
        array $page,
        array $properties,
        string $resource,
        string $modelClass,
        string $label,
    ): int {
        $id = $this->relatedModelIdFrom($page, $properties, $resource, $modelClass);

        if (! $id) {
            throw new RuntimeException("could not resolve its {$label}");
        }

        return $id;
    }

    private function relatedModelIdFrom(
        array $page,
        array $properties,
        string $resource,
        string $modelClass,
        ?int $fallbackId = null,
    ): ?int {
        if (! $this->hasAnyProperty($page, $properties)) {
            return $fallbackId;
        }

        foreach ($properties as $property) {
            if (! $this->hasProperty($page, $property)) {
                continue;
            }

            foreach ($this->mapper->relationIds($page, $property) as $notionPageId) {
                $mapping = $this->mappingFor($resource, $notionPageId);

                if ($mapping?->local_model_type === $modelClass) {
                    return (int) $mapping->local_model_id;
                }
            }

            return null;
        }

        return $fallbackId;
    }

    private function relatedModelIdsFrom(
        array $page,
        array $properties,
        string $resource,
        string $modelClass,
        array $fallback = [],
    ): array {
        if (! $this->hasAnyProperty($page, $properties)) {
            return $fallback;
        }

        foreach ($properties as $property) {
            if (! $this->hasProperty($page, $property)) {
                continue;
            }

            return collect($this->mapper->relationIds($page, $property))
                ->map(fn (string $notionPageId) => $this->mappingFor($resource, $notionPageId))
                ->filter(fn (?NotionSyncMapping $mapping) => $mapping?->local_model_type === $modelClass)
                ->map(fn (NotionSyncMapping $mapping) => (int) $mapping->local_model_id)
                ->values()
                ->all();
        }

        return $fallback;
    }

    private function titleFrom(array $page, array $properties, ?string $fallback = null): ?string
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return $this->mapper->title($page, $property);
            }
        }

        return $fallback;
    }

    private function richTextFrom(array $page, array $properties, ?string $fallback = null): ?string
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return $this->mapper->richText($page, $property);
            }
        }

        return $fallback;
    }

    private function normalizedSelectFrom(array $page, array $properties, ?string $fallback = null): ?string
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, $property));
            }
        }

        return $fallback;
    }

    private function multiSelectFrom(array $page, array $properties, array $fallback = []): array
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return $this->mapper->multiSelect($page, $property);
            }
        }

        return $fallback;
    }

    private function checkboxFrom(array $page, array $properties, ?bool $fallback = null): ?bool
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return $this->mapper->checkbox($page, $property);
            }
        }

        return $fallback;
    }

    private function dateFrom(array $page, array $properties, ?string $fallback = null): ?string
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                $resolvedProperty = $this->mapper->propertyKey($page, $property);
                $start = $resolvedProperty ? data_get($page, "properties.{$resolvedProperty}.date.start") : null;

                return filled($start) ? (string) $start : null;
            }
        }

        return $fallback;
    }

    private function dateOrRichTextFrom(array $page, array $properties, ?string $fallback = null): ?string
    {
        $date = $this->dateFrom($page, $properties);

        if ($date !== null) {
            return $date;
        }

        return $this->richTextFrom($page, $properties, $fallback);
    }

    private function numberFrom(array $page, array $properties, mixed $fallback = null): mixed
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                $resolvedProperty = $this->mapper->propertyKey($page, $property);

                return $resolvedProperty ? data_get($page, "properties.{$resolvedProperty}.number") : null;
            }
        }

        return $fallback;
    }

    private function documentFrom(array $page, array $properties, ?array $fallback = null, bool $emptyIfBlank = false): ?array
    {
        foreach ($properties as $property) {
            if (! $this->hasProperty($page, $property)) {
                continue;
            }

            return $this->toRichTextDocument($this->mapper->richText($page, $property), $emptyIfBlank);
        }

        return $fallback;
    }

    private function jsonArrayFrom(array $page, array $properties, mixed $fallback = null): mixed
    {
        foreach ($properties as $property) {
            if (! $this->hasProperty($page, $property)) {
                continue;
            }

            $value = $this->mapper->richText($page, $property);

            if (blank($value)) {
                return [];
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return collect(preg_split('/[\r\n,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY))
                ->map(static fn (string $item) => trim($item))
                ->filter()
                ->values()
                ->all();
        }

        return $fallback;
    }

    private function multiSelectOrJsonArrayFrom(array $page, array $properties, mixed $fallback = null): mixed
    {
        foreach ($properties as $property) {
            if (! $this->hasProperty($page, $property)) {
                continue;
            }

            $multiSelect = $this->mapper->multiSelect($page, $property);

            if ($multiSelect !== []) {
                return $multiSelect;
            }

            $value = $this->mapper->richText($page, $property);

            if (blank($value)) {
                return [];
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return collect(preg_split('/[\r\n,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY))
                ->map(static fn (string $item) => trim($item))
                ->filter()
                ->values()
                ->all();
        }

        return $fallback;
    }

    private function ruleArrayFrom(array $page, array $properties, mixed $fallback = null): mixed
    {
        $rules = $this->jsonArrayFrom($page, $properties, $fallback);

        if (! is_array($rules)) {
            return $fallback;
        }

        return collect($rules)
            ->filter(static fn ($rule) => is_array($rule) && filled($rule['field'] ?? null) && filled($rule['operator'] ?? null))
            ->values()
            ->all();
    }

    private function normalizeChoice(?string $value, array $allowed, ?string $default = null): ?string
    {
        if (blank($value)) {
            return $default;
        }

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function visibilityFrom(array $page, ?string $fallback = null): string
    {
        return $this->normalizeChoice(
            $this->normalizedSelectFrom($page, ['Visibility'], $fallback),
            VisibilityLevel::ALL,
            $fallback ?? VisibilityLevel::PRIVATE,
        ) ?? VisibilityLevel::PRIVATE;
    }

    private function contentClassificationFrom(array $page, ?string $fallback = null): string
    {
        return $this->normalizeChoice(
            $this->normalizedSelectFrom($page, ['Content Classification'], $fallback),
            ContentClassification::ALL,
            $fallback ?? ContentClassification::RESTRICTED,
        ) ?? ContentClassification::RESTRICTED;
    }

    private function toRichTextDocument(?string $value, bool $emptyIfBlank = false): ?array
    {
        if (blank($value)) {
            return $emptyIfBlank ? ['type' => 'doc', 'content' => []] : null;
        }

        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $value,
                ]],
            ]],
        ];
    }

    private function shouldImportState(?string $syncState, bool $includeDrafts): bool
    {
        if ($syncState === 'archived') {
            return false;
        }

        if ($includeDrafts) {
            return true;
        }

        return in_array(
            $syncState ?? 'draft',
            config('notion.dataverse.syncable_states', ['ready', 'synced']),
            true
        );
    }

    private function normalizeSyncState(array $page): ?string
    {
        return $this->mapper->normalizeKey($this->mapper->select($page, 'Sync State'));
    }

    private function writeBack(array $page, string $localId): void
    {
        try {
            $pageId = $this->mapper->pageId($page);
            $siteRecordIdProperty = $this->mapper->propertyKey($page, 'Site Record ID') ?? 'Site Record ID';
            $syncStateProperty = $this->mapper->propertyKey($page, 'Sync State') ?? 'Sync State';
            $lastSyncedProperty = $this->mapper->propertyKey($page, 'Last Synced') ?? 'Last Synced';

            $this->client->updatePageProperties($pageId, [
                $siteRecordIdProperty => $this->client->richTextProperty($localId),
                $syncStateProperty => $this->client->selectProperty('synced'),
                $lastSyncedProperty => $this->client->dateProperty(now()),
            ]);
        } catch (Throwable) {
            // Local sync should still succeed even when write-back is blocked.
        }
    }

    private function storeMapping(
        string $resource,
        string $pageId,
        string $databaseId,
        string $localModelType,
        int $localModelId,
        array $page,
        string $payloadHash,
    ): NotionSyncMapping {
        return NotionSyncMapping::updateOrCreate(
            [
                'sync_resource' => $resource,
                'notion_page_id' => $pageId,
            ],
            [
                'notion_parent_database_id' => $databaseId,
                'local_model_type' => $localModelType,
                'local_model_id' => $localModelId,
                'notion_last_edited_at' => $this->mapper->lastEditedAt($page),
                'last_synced_at' => now(),
                'last_payload_hash' => $payloadHash,
            ]
        );
    }

    private function touchMapping(
        ?NotionSyncMapping $mapping,
        string $databaseId,
        string $localModelType,
        int $localModelId,
        array $page,
        string $payloadHash,
    ): void {
        if (! $mapping) {
            return;
        }

        $mapping->update([
            'notion_parent_database_id' => $databaseId,
            'local_model_type' => $localModelType,
            'local_model_id' => $localModelId,
            'notion_last_edited_at' => $this->mapper->lastEditedAt($page),
            'last_synced_at' => now(),
            'last_payload_hash' => $payloadHash,
        ]);
    }

    private function mappingFor(string $resource, string $pageId): ?NotionSyncMapping
    {
        return NotionSyncMapping::query()
            ->forResource($resource)
            ->forNotionPage($pageId)
            ->first();
    }

    private function siteRecordId(array $page): ?int
    {
        $raw = $this->mapper->richText($page, 'Site Record ID');

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function databaseIdFor(string $resource): string
    {
        $databaseId = config("notion.dataverse.resources.{$resource}");

        if (blank($databaseId)) {
            throw new RuntimeException("Missing Notion database id for [{$resource}].");
        }

        return (string) $databaseId;
    }

    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function createModelFromData(?Model $existing, array $data): Model
    {
        if ($existing) {
            return $this->updateModelFromData($existing, $data);
        }

        throw new RuntimeException('could not determine how to create this record');
    }

    private function updateModelFromData(Model $model, array $data): Model
    {
        $model->update($data);

        return $model->fresh();
    }

    private function hasAnyProperty(array $page, array $properties): bool
    {
        foreach ($properties as $property) {
            if ($this->hasProperty($page, $property)) {
                return true;
            }
        }

        return false;
    }

    private function hasProperty(array $page, string $property): bool
    {
        return $this->mapper->hasProperty($page, $property);
    }

    private function resourceLabel(string $resource): string
    {
        return str_replace('_', ' ', ucfirst($resource));
    }

    private function syncNotionNote(string $resource, array $page, Model $model, array &$stats): bool
    {
        try {
            return $this->notionNoteSync->syncPageBody($resource, $page, $model);
        } catch (Throwable $e) {
            $stats['warnings'][] = "{$this->resourceLabel($resource)} page {$this->mapper->pageId($page)} notion notes sync failed: {$e->getMessage()}.";

            return false;
        }
    }

    private function emptyStats(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'warnings' => [],
            'resources' => [],
        ];
    }
}
