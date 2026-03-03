<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Models\Entity;

class FlipEntityCompletionFlags
{
    // Called by other domain listeners and services when related
    // records are created or deleted — not bound to Identity events directly
    //
    // Examples of what calls this:
    //   RelationshipService::create()    → flipRelationships()
    //   TimelineService::attachEvent()   → flipTimelineEntries()
    //   MediaReference::created          → flipMedia()
    //   EntityAlias::created             → flipAliases()
    //   CharacterStateTracker::created   → flipStateSnapshots()

    public function flipRelationships(Entity $entity): void
    {
        $this->flip($entity, 'has_relationships', 'relationships');
    }

    public function flipTimelineEntries(Entity $entity): void
    {
        $this->flip($entity, 'has_timeline_entries', 'timelineEntries');
    }

    public function flipDocuments(Entity $entity): void
    {
        // Documents are connected via pivot — check pivot count
        $has = $entity->loadCount('documentEntities')->document_entities_count > 0;
        $this->update($entity, ['has_documents' => $has]);
    }

    public function flipStateSnapshots(Entity $entity): void
    {
        $this->flip($entity, 'has_state_snapshots', 'stateSnapshots');
    }

    public function flipAliases(Entity $entity): void
    {
        $this->flip($entity, 'has_aliases', 'aliases');
    }

    public function flipMedia(Entity $entity): void
    {
        $this->flip($entity, 'has_media', 'media');
    }

    public function flipAttributes(Entity $entity): void
    {
        $has = !empty($entity->attributes);
        $this->update($entity, ['has_attributes' => $has]);
    }

    // Flip all flags at once — used after bulk operations
    public function flipAll(Entity $entity): void
    {
        $entity->loadCount([
            'aliases',
            'media',
            'stateSnapshots',
            'timelineEntries',
            'relationshipsFrom',
            'relationshipsTo',
        ]);

        $this->update($entity, [
            'has_aliases'          => $entity->aliases_count > 0,
            'has_media'            => $entity->media_count > 0,
            'has_state_snapshots'  => $entity->state_snapshots_count > 0,
            'has_timeline_entries' => $entity->timeline_entries_count > 0,
            'has_relationships'    => ($entity->relationships_from_count + $entity->relationships_to_count) > 0,
            'has_attributes'       => !empty($entity->attributes),
            'has_documents'        => $entity->loadCount('documentEntities')->document_entities_count > 0,
        ]);
    }

    // --- PRIVATE ---

    private function flip(Entity $entity, string $flag, string $relation): void
    {
        $has = $entity->$relation()->exists();
        $this->update($entity, [$flag => $has]);
    }

    private function update(Entity $entity, array $flags): void
    {
        Entity::withoutEvents(function () use ($entity, $flags) {
            $entity->update($flags);
        });
    }
}
