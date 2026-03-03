<?php

namespace App\Domain\World\Services;

use Illuminate\Support\Facades\DB;

use App\Domain\Identity\Models\Entity;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Models\GalacticRegion;

class WorldService
{
    // --- POWER INTERACTIONS ---

    public function createPowerInteraction(array $data): PowerInteraction
    {
        // Enforce unordered pair convention
        // Lower entity ID is always system_a
        if (
            isset($data['system_a_entity_id'], $data['system_b_entity_id']) &&
            $data['system_a_entity_id'] > $data['system_b_entity_id']
        ) {
            [$data['system_a_entity_id'], $data['system_b_entity_id']] =
                [$data['system_b_entity_id'], $data['system_a_entity_id']];
        }

        $interaction = PowerInteraction::create($data);

        // Auto-set unresolved_flag if knowledge state and danger rating qualify
        if ($interaction->shouldBeUnresolved()) {
            $interaction->update(['unresolved_flag' => true]);
        }

        return $interaction->fresh();
    }

    public function updatePowerInteraction(PowerInteraction $interaction, array $data): PowerInteraction
    {
        $interaction->update($data);

        // Re-evaluate unresolved flag after update
        $shouldBeUnresolved = $interaction->fresh()->shouldBeUnresolved();

        if ($interaction->unresolved_flag !== $shouldBeUnresolved) {
            $interaction->update(['unresolved_flag' => $shouldBeUnresolved]);
        }

        return $interaction->fresh();
    }

    public function recordInstance(
        PowerInteraction $interaction,
        Entity $eventEntity,
        array $data
    ): PowerInteractionInstance {
        $instance = PowerInteractionInstance::create(array_merge($data, [
            'power_interaction_id' => $interaction->id,
            'event_entity_id'      => $eventEntity->id,
        ]));

        // If instance contradicts the established rule, flag as unresolved
        if ($instance->contradicts() && !$interaction->unresolved_flag) {
            $interaction->update(['unresolved_flag' => true]);
        }

        return $instance;
    }

    public function resolveInteraction(PowerInteraction $interaction, array $data): PowerInteraction
    {
        $interaction->update(array_merge($data, [
            'unresolved_flag' => false,
            'knowledge_state' => $data['knowledge_state'] ?? 'established',
        ]));

        return $interaction->fresh();
    }

    // --- LOCATION CONTAINMENT ---

    public function contain(
        Entity $child,
        Entity $parent,
        string $type,
        array $data = []
    ): LocationContainment {
        return LocationContainment::create(array_merge($data, [
            'child_location_entity_id'  => $child->id,
            'parent_location_entity_id' => $parent->id,
            'containment_type'          => $type,
            'is_active'                 => true,
        ]));
    }

    public function deactivateContainment(LocationContainment $containment, ?string $eraEnd = null): LocationContainment
    {
        $containment->update([
            'is_active' => false,
            'era_end'   => $eraEnd,
        ]);

        return $containment->fresh();
    }

    // Get all containers (parents) for a location
    // Returns all containment types — physical, political, dimensional, etc.
    public function getContainers(Entity $location): \Illuminate\Database\Eloquent\Collection
    {
        return LocationContainment::childrenOf($location->id)
            ->active()
            ->with('parentLocation')
            ->get();
    }

    // Get all contents (children) of a location
    public function getContents(Entity $location, ?string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = LocationContainment::childrenOf($location->id)->active();

        if ($type) {
            $query->ofType($type);
        }

        return $query->with('childLocation')->get();
    }

    // --- LOCATION CONTROL ---

    public function recordControlChange(
        Entity $location,
        Entity $controller,
        string $controlType,
        array $data = []
    ): LocationControlHistory {
        return DB::transaction(function () use ($location, $controller, $controlType, $data) {
            // Mark existing current control as ended
            LocationControlHistory::forLocation($location->id)
                ->current()
                ->update([
                    'is_current'        => false,
                    'control_end_era'   => $data['control_start_era'] ?? null,
                    'how_control_ended' => $data['previous_control_ended_how'] ?? null,
                ]);

            return LocationControlHistory::create(array_merge($data, [
                'location_entity_id'   => $location->id,
                'controlling_entity_id'=> $controller->id,
                'control_type'         => $controlType,
                'is_current'           => true,
            ]));
        });
    }

    // --- TRAVEL ROUTES ---

    public function createRoute(
        Entity $origin,
        Entity $destination,
        string $routeType,
        array $data = []
    ): TravelRoute {
        return TravelRoute::create(array_merge($data, [
            'origin_location_entity_id'      => $origin->id,
            'destination_location_entity_id' => $destination->id,
            'route_type'                     => $routeType,
            'is_active'                      => true,
        ]));
    }

    // Creates both directions — origin→destination and destination→origin
    public function createBidirectionalRoute(
        Entity $locationA,
        Entity $locationB,
        string $routeType,
        array $data = []
    ): array {
        return DB::transaction(function () use ($locationA, $locationB, $routeType, $data) {
            $forward = $this->createRoute($locationA, $locationB, $routeType, $data);
            $reverse = $this->createRoute($locationB, $locationA, $routeType, $data);

            return [$forward, $reverse];
        });
    }

    // --- GALACTIC REGION GRADUATION ---
    // When a location in a galactic region becomes narratively significant
    // enough to warrant a full entity record, graduate it

    public function graduateToEntity(
        GalacticRegion $region,
        Entity $newLocationEntity
    ): GalacticRegion {
        $region->addGraduatedLocation($newLocationEntity->id);

        return $region->fresh();
    }
}
