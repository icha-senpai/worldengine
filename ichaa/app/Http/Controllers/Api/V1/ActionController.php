<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\Services\MediaReferenceUploadService;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Services\CollectionService;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Services\ProductionService;
use App\Domain\System\Services\RevisionService;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Services\WorldService;
use App\Support\Api\ApiMutationService;
use App\Support\Api\ApiPayload;
use App\Support\Api\ApiResourceRegistry;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActionController extends ApiController
{
    public function __construct(
        private readonly EntityService $entityService,
        private readonly RelationshipService $relationshipService,
        private readonly CollectionService $collectionService,
        private readonly TemporalService $temporalService,
        private readonly IntelligenceService $intelligenceService,
        private readonly WorldService $worldService,
        private readonly ProductionService $productionService,
        private readonly ApiMutationService $mutations,
        private readonly MediaReferenceUploadService $mediaUploads,
        private readonly RevisionService $revisions,
    ) {}

    public function uploadMediaReference(Request $request): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'media-references');

        $validator = Validator::make($request->all(), DataverseRules::apiMediaUpload());
        $validator->after(function ($validator) use ($request) {
            $relationshipValues = collect(MediaReference::ATTACHMENT_FIELDS)
                ->map(fn (string $field) => data_get($request->input('data', []), "relationships.{$field}"))
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->values();

            if ($relationshipValues->count() !== 1) {
                $validator->errors()->add(
                    'data.relationships',
                    'Provide exactly one attachment relationship for the media reference.',
                );
            }
        });
        $validator->validate();

        if ($this->shouldValidateOnly($request)) {
            return response()->json([
                'data' => [
                    'type' => 'media-references',
                    'id' => null,
                    'attributes' => data_get($request->input('data', []), 'attributes', []),
                    'relationships' => data_get($request->input('data', []), 'relationships', []),
                    'file' => [
                        'name' => data_get($request->input('data', []), 'file.name'),
                        'mime_type' => data_get($request->input('data', []), 'file.mime_type'),
                    ],
                ],
                'included' => [],
                'meta' => $this->responseMeta($request, [
                    'validated' => true,
                    'validate_only' => true,
                    'current_revision_id' => 0,
                ]),
            ]);
        }

        $payload = array_merge(
            ApiPayload::fromRequest($request),
            $this->mediaUploads->payloadFromBase64(
                (string) $request->input('data.file.name'),
                (string) $request->input('data.file.content_base64'),
                $request->input('data.file.mime_type'),
            ),
        );

        $record = $this->mutations->create('media-references', $payload);
        $this->revisions->record('media-references', $record, 'create', null, $record->fresh()->attributesToArray(), $request);

        return $this->jsonRecord('media-references', $record, $request, 201);
    }

    public function replaceMediaReferenceFile(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'media-references');

        $validator = Validator::make($request->all(), DataverseRules::apiMediaReplace());
        $validator->validate();

        $media = ApiResourceRegistry::resolveRecord('media-references', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'media-references', $media)) {
            return $response;
        }

        $this->assertRevision($request, 'media-references', $media);

        $before = $media->attributesToArray();
        $previousManagedPath = $media->isManagedUpload() ? $media->file_path : null;

        $updated = $this->mutations->update(
            'media-references',
            $media,
            $this->mediaUploads->payloadFromBase64(
                (string) $request->input('data.file.name'),
                (string) $request->input('data.file.content_base64'),
                $request->input('data.file.mime_type'),
            ),
        );

        if ($previousManagedPath && $previousManagedPath !== $updated->file_path) {
            $this->mediaUploads->deleteManagedUpload($updated, $previousManagedPath);
        }

        $this->revisions->record('media-references', $updated, 'replace_file', $before, $updated->fresh()->attributesToArray(), $request);

        return $this->jsonRecord('media-references', $updated, $request);
    }

    public function publishEntity(Request $request, string $record): JsonResponse
    {
        return $this->entityMutation($request, $record, 'publish', fn (Entity $entity) => $this->entityService->publish($entity));
    }

    public function unpublishEntity(Request $request, string $record): JsonResponse
    {
        return $this->entityMutation($request, $record, 'unpublish', fn (Entity $entity) => $this->entityService->unpublish($entity));
    }

    public function archiveEntity(Request $request, string $record): JsonResponse
    {
        return $this->entityMutation($request, $record, 'archive', fn (Entity $entity) => $this->entityService->archive($entity));
    }

    public function entityVersionsIndex(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'read', 'entities');
        $entity = ApiResourceRegistry::resolveRecord('entities', $record, true);

        return $this->jsonCollection(
            'entity-versions',
            $entity->versions()->orderByDesc('version_number')->get(),
            $request,
        );
    }

    public function entityVersionsShow(Request $request, string $record, VersionAndCanonState $version): JsonResponse
    {
        $this->authorizeToken($request, 'read', 'entities');
        $entity = ApiResourceRegistry::resolveRecord('entities', $record, true);
        abort_unless((int) $version->entity_id === (int) $entity->getKey(), 404);

        return $this->jsonRecord('entity-versions', $version, $request);
    }

    public function entityVersionsStore(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'entities');
        $this->validateAction($request, 'entity-save-version');
        $entity = ApiResourceRegistry::resolveRecord('entities', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'entities', $entity)) {
            return $response;
        }

        $this->assertRevision($request, 'entities', $entity);

        $before = $entity->attributesToArray();
        $version = (bool) data_get($request->input('data', []), 'attributes.is_version_zero', false)
            ? $this->entityService->saveVersionZero($entity, $request->input('data.attributes', []))
            : $this->entityService->saveManualCanonState($entity, $request->input('data.attributes', []));

        $entity = $entity->fresh();
        $revision = $this->revisions->record('entities', $entity, 'save_version', $before, $entity->attributesToArray(), $request);

        return $this->jsonRecord('entity-versions', $version, $request, 201, [
            'current_revision_id' => $revision->id,
            'revised_resource' => 'entities',
            'revised_resource_id' => (int) $entity->getKey(),
        ]);
    }

    public function relationshipTensionCharge(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'relationships');
        $this->validateAction($request, 'relationship-tension-charge');
        $relationship = ApiResourceRegistry::resolveRecord('relationships', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'relationships', $relationship)) {
            return $response;
        }

        $this->assertRevision($request, 'relationships', $relationship);

        $before = $relationship->attributesToArray();
        $updated = $this->relationshipService->updateTensionCharge(
            $relationship,
            (string) $request->input('data.attributes.new_charge'),
            $request->input('data.attributes.reason'),
        );

        $this->revisions->record('relationships', $updated, 'tension_charge', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('relationships', $updated, $request);
    }

    public function groupRelationshipTensionCharge(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'group-relationships');
        $this->validateAction($request, 'group-relationship-tension-charge');
        $group = ApiResourceRegistry::resolveRecord('group-relationships', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'group-relationships', $group)) {
            return $response;
        }

        $this->assertRevision($request, 'group-relationships', $group);

        $before = $group->attributesToArray();
        $updated = $this->relationshipService->updateGroupTensionCharge(
            $group,
            (string) $request->input('data.attributes.new_charge'),
            $request->input('data.attributes.reason'),
        );

        $this->revisions->record('group-relationships', $updated, 'tension_charge', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('group-relationships', $updated, $request);
    }

    public function groupRelationshipAddMember(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'group-relationships');
        $this->validateAction($request, 'group-relationship-add-member');
        $group = ApiResourceRegistry::resolveRecord('group-relationships', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'group-relationships', $group)) {
            return $response;
        }

        $this->assertRevision($request, 'group-relationships', $group);

        $membership = $this->relationshipService->addMemberToGroup(
            $group,
            Entity::findOrFail($request->integer('data.relationships.entity_id')),
            ApiPayload::fromRequest($request),
        );

        $this->revisions->record('group-relationship-memberships', $membership, 'create', null, $membership->attributesToArray(), $request);

        return $this->jsonRecord('group-relationship-memberships', $membership, $request, 201);
    }

    public function groupRelationshipRemoveMember(Request $request, string $record, GroupRelationshipEntity $membership): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'group-relationships');
        $this->validateAction($request, 'group-relationship-remove-member');
        $group = ApiResourceRegistry::resolveRecord('group-relationships', $record, true);
        abort_unless((int) $membership->group_relationship_id === (int) $group->getKey(), 404);

        if ($response = $this->validateOnlyResponse($request, 'group-relationship-memberships', $membership)) {
            return $response;
        }

        $this->assertRevision($request, 'group-relationship-memberships', $membership);

        $before = $membership->attributesToArray();
        $updated = $this->relationshipService->removeMemberFromGroup($membership, ApiPayload::fromRequest($request));
        $this->revisions->record('group-relationship-memberships', $updated, 'deactivate', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('group-relationship-memberships', $updated, $request);
    }

    public function terminateFactionMembership(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'faction-memberships');
        $this->validateAction($request, 'faction-membership-terminate');
        $membership = ApiResourceRegistry::resolveRecord('faction-memberships', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'faction-memberships', $membership)) {
            return $response;
        }

        $this->assertRevision($request, 'faction-memberships', $membership);

        $before = $membership->attributesToArray();
        $updated = $this->relationshipService->terminateFactionMembership($membership, ApiPayload::fromRequest($request));
        $this->revisions->record('faction-memberships', $updated, 'terminate', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('faction-memberships', $updated, $request);
    }

    public function syncCollection(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'collections');
        $this->validateAction($request, 'collection-sync');
        $collection = ApiResourceRegistry::resolveRecord('collections', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'collections', $collection)) {
            return $response;
        }

        $this->assertRevision($request, 'collections', $collection);

        $before = $collection->load('entities')->toArray();
        $count = $this->collectionService->syncSmartMembers($collection);
        $collection = $collection->fresh()->load('entities');
        $this->revisions->record('collections', $collection, 'sync', $before, $collection->toArray(), $request);

        return $this->jsonRecord('collections', $collection, $request, 200, ['synced_count' => $count]);
    }

    public function timelinePlaceEvent(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'timelines');
        $this->validateAction($request, 'timeline-place-event');
        $timeline = ApiResourceRegistry::resolveRecord('timelines', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'timelines', $timeline)) {
            return $response;
        }

        $this->assertRevision($request, 'timelines', $timeline);

        $payload = ApiPayload::fromRequest($request);
        $entry = $this->temporalService->placeEvent($timeline, Entity::findOrFail($payload['event_entity_id']), $payload);
        $this->revisions->record('timeline-entries', $entry, 'create', null, $entry->attributesToArray(), $request);

        return $this->jsonRecord('timeline-entries', $entry, $request, 201);
    }

    public function timelineUpdateEvent(Request $request, string $record, Timeline $entry): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'timelines');
        $this->validateAction($request, 'timeline-update-event');
        $timeline = ApiResourceRegistry::resolveRecord('timelines', $record, true);
        abort_unless((int) $entry->timeline_id === (int) $timeline->getKey(), 404);

        if ($response = $this->validateOnlyResponse($request, 'timeline-entries', $entry)) {
            return $response;
        }

        $this->assertRevision($request, 'timeline-entries', $entry);

        $before = $entry->attributesToArray();
        $updated = $this->temporalService->updateTimelineEntry($entry, ApiPayload::fromRequest($request));
        $this->revisions->record('timeline-entries', $updated, 'update', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('timeline-entries', $updated, $request);
    }

    public function timelineRemoveEvent(Request $request, string $record, Timeline $entry): JsonResponse
    {
        $this->authorizeToken($request, 'delete', 'timelines');
        $this->validateAction($request, 'timeline-remove-event');
        $timeline = ApiResourceRegistry::resolveRecord('timelines', $record, true);
        abort_unless((int) $entry->timeline_id === (int) $timeline->getKey(), 404);

        if ($response = $this->validateOnlyResponse($request, 'timeline-entries', $entry)) {
            return $response;
        }

        $this->assertRevision($request, 'timeline-entries', $entry);

        $before = $entry->attributesToArray();
        $this->temporalService->removeFromTimeline($entry);
        $entry = ApiResourceRegistry::resolveRecord('timeline-entries', $entry->getKey(), true);
        $this->revisions->record('timeline-entries', $entry, 'delete', $before, $entry->attributesToArray(), $request);

        return response()->json([
            'data' => null,
            'included' => [],
            'meta' => $this->responseMeta($request, ['deleted' => true]),
        ]);
    }

    public function resolvePowerInteraction(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'power-interactions');
        $this->validateAction($request, 'power-interaction-resolve');
        $interaction = ApiResourceRegistry::resolveRecord('power-interactions', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'power-interactions', $interaction)) {
            return $response;
        }

        $this->assertRevision($request, 'power-interactions', $interaction);

        $before = $interaction->attributesToArray();
        $updated = $this->worldService->resolveInteraction($interaction, ApiPayload::fromRequest($request));
        $this->revisions->record('power-interactions', $updated, 'resolve', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('power-interactions', $updated, $request);
    }

    public function recordPowerInteractionInstance(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'power-interactions');
        $this->validateAction($request, 'power-interaction-instance');
        $interaction = ApiResourceRegistry::resolveRecord('power-interactions', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'power-interactions', $interaction)) {
            return $response;
        }

        $this->assertRevision($request, 'power-interactions', $interaction);

        $payload = ApiPayload::fromRequest($request);
        $instance = $this->worldService->recordInstance($interaction, Entity::findOrFail($payload['event_entity_id']), $payload);
        $this->revisions->record('power-interaction-instances', $instance, 'create', null, $instance->attributesToArray(), $request);

        return $this->jsonRecord('power-interaction-instances', $instance, $request, 201);
    }

    public function markKnowledgeStateActedOn(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'knowledge-states');
        $this->validateAction($request, 'knowledge-state-act-on');
        $state = ApiResourceRegistry::resolveRecord('knowledge-states', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'knowledge-states', $state)) {
            return $response;
        }

        $this->assertRevision($request, 'knowledge-states', $state);

        $before = $state->attributesToArray();
        $updated = $this->intelligenceService->markActedOn($state, $request->input('data.attributes.action_notes'));
        $this->revisions->record('knowledge-states', $updated, 'act_on', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('knowledge-states', $updated, $request);
    }

    public function exposeSecret(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'secrets');
        $this->validateAction($request, 'secret-expose');
        $secret = ApiResourceRegistry::resolveRecord('secrets', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'secrets', $secret)) {
            return $response;
        }

        $this->assertRevision($request, 'secrets', $secret);

        $before = $secret->attributesToArray();
        $updated = $this->intelligenceService->exposeSecret(
            $secret,
            (string) $request->input('data.attributes.era'),
            (string) $request->input('data.attributes.exposure_level', 'partially_exposed'),
        );
        $this->revisions->record('secrets', $updated, 'expose', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('secrets', $updated, $request);
    }

    public function addSecretKnownBy(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'secrets');
        $this->validateAction($request, 'secret-known-by');
        $secret = ApiResourceRegistry::resolveRecord('secrets', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'secrets', $secret)) {
            return $response;
        }

        $this->assertRevision($request, 'secrets', $secret);

        $before = $secret->attributesToArray();
        $updated = $this->intelligenceService->addToKnownBy($secret, (int) $request->input('data.relationships.entity_id'));
        $this->revisions->record('secrets', $updated, 'add_known_by', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('secrets', $updated, $request);
    }

    public function removeSecretKnownBy(Request $request, string $record, Entity $entity): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'secrets');
        $this->validateAction($request, 'secret-remove-known-by');
        $secret = ApiResourceRegistry::resolveRecord('secrets', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'secrets', $secret)) {
            return $response;
        }

        $this->assertRevision($request, 'secrets', $secret);

        $before = $secret->attributesToArray();
        $knownBy = collect($secret->known_by_entity_ids ?? [])->reject(fn ($id) => (int) $id === (int) $entity->id)->values()->all();
        $secret->update(['known_by_entity_ids' => $knownBy]);
        $secret = $secret->fresh();
        $this->revisions->record('secrets', $secret, 'remove_known_by', $before, $secret->attributesToArray(), $request);

        return $this->jsonRecord('secrets', $secret, $request);
    }

    public function addSecretHolder(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'secrets');
        $this->validateAction($request, 'secret-holders');
        $secret = ApiResourceRegistry::resolveRecord('secrets', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'secrets', $secret)) {
            return $response;
        }

        $this->assertRevision($request, 'secrets', $secret);

        $before = $secret->attributesToArray();
        $updated = $this->intelligenceService->addToHolders($secret, (int) $request->input('data.relationships.entity_id'));
        $this->revisions->record('secrets', $updated, 'add_holder', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('secrets', $updated, $request);
    }

    public function removeSecretHolder(Request $request, string $record, Entity $entity): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'secrets');
        $this->validateAction($request, 'secret-remove-holder');
        $secret = ApiResourceRegistry::resolveRecord('secrets', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'secrets', $secret)) {
            return $response;
        }

        $this->assertRevision($request, 'secrets', $secret);

        $before = $secret->attributesToArray();
        $holders = collect($secret->holder_entity_ids ?? [])->reject(fn ($id) => (int) $id === (int) $entity->id)->values()->all();
        $secret->update(['holder_entity_ids' => $holders]);
        $secret = $secret->fresh();
        $this->revisions->record('secrets', $secret, 'remove_holder', $before, $secret->attributesToArray(), $request);

        return $this->jsonRecord('secrets', $secret, $request);
    }

    public function addPerceptionImmune(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'perception-states');
        $this->validateAction($request, 'perception-immune');
        $state = ApiResourceRegistry::resolveRecord('perception-states', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'perception-states', $state)) {
            return $response;
        }

        $this->assertRevision($request, 'perception-states', $state);

        $before = $state->attributesToArray();
        $updated = $this->intelligenceService->addImmuneEntity($state, (int) $request->input('data.relationships.entity_id'));
        $this->revisions->record('perception-states', $updated, 'add_immune', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('perception-states', $updated, $request);
    }

    public function removePerceptionImmune(Request $request, string $record, Entity $entity): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'perception-states');
        $this->validateAction($request, 'perception-remove-immune');
        $state = ApiResourceRegistry::resolveRecord('perception-states', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'perception-states', $state)) {
            return $response;
        }

        $this->assertRevision($request, 'perception-states', $state);

        $before = $state->attributesToArray();
        $immune = collect($state->immune_entity_ids ?? [])->reject(fn ($id) => (int) $id === (int) $entity->id)->values()->all();
        $state->update(['immune_entity_ids' => $immune]);
        $state = $state->fresh();
        $this->revisions->record('perception-states', $state, 'remove_immune', $before, $state->attributesToArray(), $request);

        return $this->jsonRecord('perception-states', $state, $request);
    }

    public function collapsePerceptionState(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'perception-states');
        $this->validateAction($request, 'perception-collapse');
        $state = ApiResourceRegistry::resolveRecord('perception-states', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'perception-states', $state)) {
            return $response;
        }

        $this->assertRevision($request, 'perception-states', $state);

        $before = $state->attributesToArray();
        $updated = $this->intelligenceService->collapsePerceptionGap($state, (string) $request->input('data.attributes.era'));
        $this->revisions->record('perception-states', $updated, 'collapse', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('perception-states', $updated, $request);
    }

    public function resolveMeta(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'meta');
        $this->validateAction($request, 'meta-resolve');
        $meta = ApiResourceRegistry::resolveRecord('meta', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'meta', $meta)) {
            return $response;
        }

        $this->assertRevision($request, 'meta', $meta);

        $before = $meta->attributesToArray();
        $updated = $this->productionService->updateMeta($meta, [
            'action_status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $request->input('data.attributes.resolution_notes'),
        ]);
        $this->revisions->record('meta', $updated, 'resolve', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('meta', $updated, $request);
    }

    public function supersedeMeta(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'meta');
        $this->validateAction($request, 'meta-supersede');
        $meta = ApiResourceRegistry::resolveRecord('meta', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'meta', $meta)) {
            return $response;
        }

        $this->assertRevision($request, 'meta', $meta);

        $before = $meta->attributesToArray();
        $updated = $this->productionService->updateMeta($meta, [
            'superseded_by_meta_id' => $request->input('data.relationships.superseded_by_meta_id'),
            'supersession_reason' => $request->input('data.attributes.supersession_reason'),
            'superseded_at' => now(),
        ]);
        $this->revisions->record('meta', $updated, 'supersede', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('meta', $updated, $request);
    }

    public function linkMetaEntity(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'meta');
        $this->validateAction($request, 'meta-link-entity');
        $meta = ApiResourceRegistry::resolveRecord('meta', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'meta', $meta)) {
            return $response;
        }

        $this->assertRevision($request, 'meta', $meta);

        $before = $meta->load('entities')->toArray();
        $this->productionService->linkEntity($meta, Entity::findOrFail((int) $request->input('data.relationships.entity_id')));
        $meta = $meta->fresh()->load('entities');
        $this->revisions->record('meta', $meta, 'link_entity', $before, $meta->toArray(), $request);

        return $this->jsonRecord('meta', $meta, $request);
    }

    public function unlinkMetaEntity(Request $request, string $record, Entity $entity): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'meta');
        $this->validateAction($request, 'meta-unlink-entity');
        $meta = ApiResourceRegistry::resolveRecord('meta', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'meta', $meta)) {
            return $response;
        }

        $this->assertRevision($request, 'meta', $meta);

        $before = $meta->load('entities')->toArray();
        $this->productionService->unlinkEntity($meta, $entity);
        $meta = $meta->fresh()->load('entities');
        $this->revisions->record('meta', $meta, 'unlink_entity', $before, $meta->toArray(), $request);

        return $this->jsonRecord('meta', $meta, $request);
    }

    public function advancePipelineItem(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'pipeline-items');
        $this->validateAction($request, 'pipeline-advance');
        $item = ApiResourceRegistry::resolveRecord('pipeline-items', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'pipeline-items', $item)) {
            return $response;
        }

        $this->assertRevision($request, 'pipeline-items', $item);

        $before = $item->attributesToArray();
        $progression = [
            'concept' => 'outlined',
            'outlined' => 'drafted',
            'drafted' => 'revised',
            'revised' => 'complete',
        ];
        $next = $progression[$item->pipeline_stage] ?? null;
        if ($next) {
            $item = $this->productionService->updatePipelineItem($item, ['pipeline_stage' => $next]);
        }
        $this->revisions->record('pipeline-items', $item, 'advance', $before, $item->attributesToArray(), $request);

        return $this->jsonRecord('pipeline-items', $item, $request);
    }

    public function resolvePipelineItem(Request $request, string $record): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'pipeline-items');
        $this->validateAction($request, 'pipeline-resolve');
        $item = ApiResourceRegistry::resolveRecord('pipeline-items', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'pipeline-items', $item)) {
            return $response;
        }

        $this->assertRevision($request, 'pipeline-items', $item);

        $before = $item->attributesToArray();
        $updated = $this->productionService->resolvePipelineItem($item, $request->input('data.attributes.resolution_notes'));
        $this->revisions->record('pipeline-items', $updated, 'resolve', $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('pipeline-items', $updated, $request);
    }

    private function entityMutation(Request $request, string $record, string $action, callable $callback): JsonResponse
    {
        $this->authorizeToken($request, 'write', 'entities');
        $this->validateAction($request, "entity-{$action}");
        $entity = ApiResourceRegistry::resolveRecord('entities', $record, true);

        if ($response = $this->validateOnlyResponse($request, 'entities', $entity)) {
            return $response;
        }

        $this->assertRevision($request, 'entities', $entity);

        $before = $entity->attributesToArray();
        $updated = $callback($entity);
        $this->revisions->record('entities', $updated, $action, $before, $updated->attributesToArray(), $request);

        return $this->jsonRecord('entities', $updated, $request);
    }
}
