<?php

namespace App\Support\Api;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Services\CollectionService;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Production\Services\ProductionService;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use Illuminate\Database\Eloquent\Model;

class ApiMutationService
{
    public function __construct(
        private readonly EntityService $entityService,
        private readonly RelationshipService $relationshipService,
        private readonly IntelligenceService $intelligenceService,
        private readonly CollectionService $collectionService,
        private readonly TemporalService $temporalService,
        private readonly WorldService $worldService,
        private readonly ProductionService $productionService,
    ) {}

    public function create(string $resource, array $payload): Model
    {
        return match ($resource) {
            'entities' => $this->entityService->create($payload),
            'timelines' => $this->entityService->create(array_merge($payload, ['entity_type' => 'timeline'])),
            'relationships' => $this->relationshipService->create(
                Entity::findOrFail($payload['from_entity_id']),
                Entity::findOrFail($payload['to_entity_id']),
                $payload,
            ),
            'group-relationships' => $this->relationshipService->createGroup($payload, $payload['members'] ?? []),
            'faction-memberships' => $this->relationshipService->createFactionMembership(
                Entity::findOrFail($payload['faction_entity_id']),
                Entity::findOrFail($payload['member_entity_id']),
                $payload,
            ),
            'collections' => $this->collectionService->create($payload),
            'knowledge-states' => $this->intelligenceService->recordKnowledge(Entity::findOrFail($payload['knower_entity_id']), $payload),
            'secrets' => $this->intelligenceService->createSecret($payload),
            'perception-states' => $this->intelligenceService->createPerceptionGap($payload),
            'power-interactions' => $this->worldService->createPowerInteraction($payload),
            'location-containment' => $this->worldService->contain(
                Entity::findOrFail($payload['child_location_entity_id']),
                Entity::findOrFail($payload['parent_location_entity_id']),
                (string) $payload['containment_type'],
                $payload,
            ),
            'location-control-records' => $this->worldService->recordControlChange(
                Entity::findOrFail($payload['location_entity_id']),
                Entity::findOrFail($payload['controlling_entity_id']),
                (string) $payload['control_type'],
                $payload,
            ),
            'travel-routes' => $this->worldService->createRoute(
                Entity::findOrFail($payload['origin_location_entity_id']),
                Entity::findOrFail($payload['destination_location_entity_id']),
                (string) $payload['route_type'],
                $payload,
            ),
            'timeline-entries' => $this->temporalService->placeEvent(
                Entity::findOrFail($payload['timeline_id']),
                Entity::findOrFail($payload['event_entity_id']),
                $payload,
            ),
            'character-states' => $this->temporalService->createStateSnapshot(Entity::findOrFail($payload['entity_id']), $payload),
            'meta' => $this->productionService->createMeta($payload),
            'pipeline-items' => $this->createPipelineItem($payload),
            'session-logs' => $this->productionService->startSession($payload),
            default => $this->genericCreate($resource, $payload),
        };
    }

    public function update(string $resource, Model $record, array $payload): Model
    {
        return match ($resource) {
            'entities', 'timelines' => $this->entityService->update($record, $payload),
            'relationships' => $this->updateRelationship($record, $payload),
            'group-relationships' => $this->updateGroupRelationship($record, $payload),
            'faction-memberships' => $this->relationshipService->updateFactionMembership($record, $payload),
            'collections' => $this->collectionService->update($record, $payload),
            'secrets' => $this->intelligenceService->updateSecret($record, $payload),
            'power-interactions' => $this->worldService->updatePowerInteraction($record, $payload),
            'timeline-entries' => $this->temporalService->updateTimelineEntry($record, $payload),
            'character-states' => $this->temporalService->updateStateSnapshot($record, $payload),
            'meta' => $this->productionService->updateMeta($record, $payload),
            'pipeline-items' => $this->productionService->updatePipelineItem($record, $payload),
            'session-logs' => $this->productionService->updateSession($record, $payload),
            default => $this->genericUpdate($record, $payload),
        };
    }

    public function delete(string $resource, Model $record): void
    {
        match ($resource) {
            'entities', 'timelines' => $this->entityService->delete($record),
            'relationships' => $this->relationshipService->delete($record),
            'timeline-entries' => $this->temporalService->removeFromTimeline($record),
            'character-states' => $this->temporalService->deleteStateSnapshot($record),
            default => $record->delete(),
        };
    }

    public function restore(Model $record): Model
    {
        if (method_exists($record, 'restore')) {
            $record->restore();
        }

        return $record->fresh();
    }

    private function genericCreate(string $resource, array $payload): Model
    {
        $modelClass = ApiResourceRegistry::modelClass($resource);
        $model = new $modelClass();

        $model->fill($this->fillablePayload($model, $payload));
        $model->save();

        return $model->fresh();
    }

    private function genericUpdate(Model $record, array $payload): Model
    {
        $record->fill($this->fillablePayload($record, $payload));
        $record->save();

        return $record->fresh();
    }

    private function createPipelineItem(array $payload): Model
    {
        if (! empty($payload['meta_id'])) {
            return $this->productionService->createPipelineItem(Meta::findOrFail($payload['meta_id']), $payload);
        }

        if (! empty($payload['entity_id'])) {
            return $this->productionService->createEntityPipelineItem(
                Entity::findOrFail($payload['entity_id']),
                $payload,
                ! empty($payload['meta_id']) ? Meta::findOrFail($payload['meta_id']) : null,
            );
        }

        $item = new PipelineItem();
        $item->fill($this->fillablePayload($item, $payload));
        $item->save();

        return $item->fresh();
    }

    private function updateRelationship(Relationship $relationship, array $payload): Relationship
    {
        if (
            isset($payload['current_tension_charge']) &&
            $payload['current_tension_charge'] !== $relationship->current_tension_charge
        ) {
            $relationship = $this->relationshipService->updateTensionCharge(
                $relationship,
                (string) $payload['current_tension_charge'],
                $payload['charge_change_reason'] ?? null,
            );

            unset($payload['current_tension_charge'], $payload['charge_change_reason']);
        }

        return $this->relationshipService->update($relationship, $payload);
    }

    private function updateGroupRelationship(GroupRelationship $groupRelationship, array $payload): GroupRelationship
    {
        if (
            isset($payload['current_tension_charge']) &&
            $payload['current_tension_charge'] !== $groupRelationship->current_tension_charge
        ) {
            $groupRelationship = $this->relationshipService->updateGroupTensionCharge(
                $groupRelationship,
                (string) $payload['current_tension_charge'],
                $payload['charge_change_reason'] ?? null,
            );

            unset($payload['current_tension_charge'], $payload['charge_change_reason']);
        }

        $groupRelationship->update($this->fillablePayload($groupRelationship, $payload));

        return $groupRelationship->fresh();
    }

    private function fillablePayload(Model $model, array $payload): array
    {
        return array_intersect_key($payload, array_flip($model->getFillable()));
    }
}
