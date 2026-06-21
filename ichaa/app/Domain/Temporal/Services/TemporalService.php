<?php

namespace App\Domain\Temporal\Services;

use Illuminate\Support\Facades\DB;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Listeners\FlipEntityCompletionFlags;

use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\StateRelationship;

class TemporalService
{
    public function __construct(
        private readonly FlipEntityCompletionFlags $flagFlipper,
    ) {}

    // --- TIMELINE EVENT PLACEMENT ---

    // Place an event entity on a timeline
    // Calculates position automatically if not provided
    public function placeEvent(
        Entity $timelineEntity,
        Entity $eventEntity,
        array $data
    ): Timeline {
        return DB::transaction(function () use ($timelineEntity, $eventEntity, $data) {
            // Auto-calculate position if not provided
            if (!isset($data['timeline_position'])) {
                $data['timeline_position'] = $this->calculatePosition(
                    $timelineEntity->id,
                    $data['au_date'] ?? null
                );
            }

            $entry = Timeline::create(array_merge($data, [
                'timeline_id'    => $timelineEntity->id,
                'event_entity_id'=> $eventEntity->id,
            ]));

            // Flip has_timeline_entries on the event entity
            $this->flagFlipper->flipTimelineEntries($eventEntity);

            return $entry;
        });
    }

    // Place an event on multiple timelines simultaneously
    // Used for crossover events that appear on both AU timeline and source universe timeline
    public function placeEventOnMultipleTimelines(
        Entity $eventEntity,
        array  $timelineData // [['timeline_entity' => Entity, 'data' => array], ...]
    ): array {
        return DB::transaction(function () use ($eventEntity, $timelineData) {
            $entries = [];

            foreach ($timelineData as $placement) {
                $timelineEntity = $placement['timeline_entity'];
                $data           = $placement['data'];

                // Create the primary timeline entry on the first timeline
                if (empty($entries)) {
                    $primary = $this->placeEvent($timelineEntity, $eventEntity, $data);
                    $entries[] = $primary;
                } else {
                    // Additional timelines get TimelineEntity pivot records
                    TimelineEntity::create([
                        'timeline_id'       => $timelineEntity->id,
                        'event_entity_id'   => $eventEntity->id,
                        'position'          => $data['timeline_position']
                            ?? $this->calculatePosition($timelineEntity->id, $data['au_date'] ?? null),
                        'perspective_label' => $data['perspective_label'] ?? null,
                        'perspective_notes' => $data['perspective_notes'] ?? null,
                    ]);
                }
            }

            return $entries;
        });
    }

    public function updateTimelineEntry(Timeline $entry, array $data): Timeline
    {
        $entry->update($data);

        return $entry->fresh();
    }

    public function removeFromTimeline(Timeline $entry): void
    {
        $eventEntity = $entry->eventEntity;

        $entry->delete();

        // Check if the event entity still has timeline entries
        $this->flagFlipper->flipTimelineEntries($eventEntity);
    }

    // --- CONCURRENCY GROUPS ---

    public function createConcurrencyGroup(array $data): ConcurrencyGroup
    {
        return ConcurrencyGroup::create($data);
    }

    public function assignToGroup(Timeline $entry, ConcurrencyGroup $group): Timeline
    {
        $entry->update(['concurrency_group_id' => $group->id]);

        return $entry->fresh();
    }

    public function removeFromGroup(Timeline $entry): Timeline
    {
        $entry->update(['concurrency_group_id' => null]);

        return $entry->fresh();
    }

    // --- CHARACTER STATE SNAPSHOTS ---

    // Create a manual state snapshot for any entity at a specific era/position
    public function createStateSnapshot(Entity $entity, array $data): CharacterStateTracker
    {
        return DB::transaction(function () use ($entity, $data) {
            $snapshot = CharacterStateTracker::create(array_merge($data, [
                'entity_id' => $entity->id,
            ]));

            // Attach active relationship states if provided
            if (!empty($data['relationship_states'])) {
                foreach ($data['relationship_states'] as $relationshipState) {
                    StateRelationship::create([
                        'character_state_id'             => $snapshot->id,
                        'relationship_id'                => $relationshipState['relationship_id'],
                        'is_active_at_snapshot'          => $relationshipState['is_active'] ?? true,
                        'relationship_state_at_snapshot' => $relationshipState['state_notes'] ?? null,
                    ]);
                }
            }

            // Flip has_state_snapshots on the entity
            $this->flagFlipper->flipStateSnapshots($entity);

            return $snapshot->fresh();
        });
    }

    public function updateStateSnapshot(CharacterStateTracker $snapshot, array $data): CharacterStateTracker
    {
        $snapshot->update($data);

        return $snapshot->fresh();
    }

    public function deleteStateSnapshot(CharacterStateTracker $snapshot): void
    {
        $entity = $snapshot->entity;

        $snapshot->stateRelationships()->delete();
        $snapshot->delete();

        $this->flagFlipper->flipStateSnapshots($entity);
    }

    // --- QUERIES ---

    // Get all state snapshots for an entity in chronological order
    public function getEntityStateHistory(Entity $entity): \Illuminate\Database\Eloquent\Collection
    {
        return CharacterStateTracker::forEntity($entity->id)
            ->chronological()
            ->get();
    }

    // Get the most recent state snapshot for an entity
    public function getLatestState(Entity $entity): ?CharacterStateTracker
    {
        return CharacterStateTracker::forEntity($entity->id)
            ->chronological()
            ->latest('timeline_position')
            ->first();
    }

    // Get all events on a timeline in chronological order
    // Atemporal events returned separately for header display
    public function getTimelineEvents(int $timelineEntityId): array
    {
        $atemporal = Timeline::onTimeline($timelineEntityId)
            ->atemporal()
            ->chronological()
            ->with(['eventEntity', 'era', 'concurrencyGroup'])
            ->get();

        $chronological = Timeline::onTimeline($timelineEntityId)
            ->where('is_atemporal', false)
            ->chronological()
            ->with(['eventEntity', 'era', 'concurrencyGroup'])
            ->get();

        return [
            'atemporal'     => $atemporal,
            'chronological' => $chronological,
        ];
    }

    // --- PRIVATE ---

    // Assigns an integer position based on existing entries
    // Appends to end of timeline by default
    // Leaves gaps so later inserts can still fit between entries
    private function calculatePosition(int $timelineEntityId, ?string $auDate): int
    {
        $lastEntry = Timeline::onTimeline($timelineEntityId)
            ->where('is_atemporal', false)
            ->orderByDesc('timeline_position')
            ->first();

        // Nothing on timeline yet — start at 10
        if (!$lastEntry) {
            return 10;
        }

        // Append after last entry with spacing for future inserts
        return (int) $lastEntry->timeline_position + 10;
    }

    // Insert between two existing positions using integer midpoint
    // Call this explicitly when you need to insert between events
    public function calculateMidpoint(int $before, int $after): int
    {
        return intdiv($before + $after, 2);
    }
}
