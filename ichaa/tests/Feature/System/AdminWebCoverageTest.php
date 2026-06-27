<?php

namespace Tests\Feature\System;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Models\Revision;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\GalacticRegion;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminWebCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_level_modeled_resources_and_galactic_regions_render_in_web_surfaces(): void
    {
        $user = $this->verifiedUser();
        $memberEntity = Entity::factory()->create([
            'name' => 'Seraphine Vale',
            'entity_type' => EntityType::CHARACTER,
        ]);
        $otherEntity = Entity::factory()->create([
            'name' => 'Aurelian March',
            'entity_type' => EntityType::CHARACTER,
        ]);
        $factionEntity = Entity::factory()->create([
            'name' => 'Night Council',
            'entity_type' => EntityType::FACTION,
        ]);
        $timelineEntity = Entity::factory()->create([
            'name' => 'Prime Timeline',
            'entity_type' => 'timeline',
        ]);
        $eventEntity = Entity::factory()->create([
            'name' => 'Battle of Dawn',
            'entity_type' => EntityType::EVENT,
        ]);

        $group = GroupRelationship::create([
            'name' => 'Quiet Accord',
            'relationship_type' => 'alliance',
        ]);
        $collection = Collection::create([
            'name' => 'Archive Dossiers',
            'collection_type' => 'research_set',
            'collection_mode' => 'manual',
            'completion_state' => 'in_progress',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $document = Document::create([
            'title' => 'Sealed Ledger',
            'document_type' => 'research_notes',
            'document_status' => 'extant',
            'document_authenticity' => 'authentic',
            'access_level' => 'restricted',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $reference = SourceCanonReference::create([
            'title' => 'Canon Ledger',
            'universe' => 'Harry Potter',
            'level' => 'element',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $relationship = Relationship::create([
            'from_entity_id' => $memberEntity->id,
            'to_entity_id' => $otherEntity->id,
            'relationship_type' => RelationshipType::KNOWLEDGE,
            'direction' => 'mutual_equal',
            'current_tension_charge' => TensionCharge::POSITIVE,
            'is_active' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $state = CharacterStateTracker::create([
            'entity_id' => $memberEntity->id,
            'snapshot_label' => 'Arc Start',
            'snapshot_significance' => 'moderate',
            'current_stability_level' => 'stable',
            'mask_integrity' => 'intact',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $interaction = PowerInteraction::create([
            'system_a_entity_id' => $memberEntity->id,
            'system_b_entity_id' => $otherEntity->id,
            'interaction_name' => 'Archive Resonance',
            'knowledge_state' => 'established',
            'danger_rating' => 'moderate',
            'directionality' => 'contextual',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $groupMembership = GroupRelationshipEntity::create([
            'group_relationship_id' => $group->id,
            'entity_id' => $memberEntity->id,
            'role_in_group' => 'Mediator',
            'is_active_member' => true,
            'joined_era' => 'Year 2',
        ]);
        $factionMembership = FactionMembership::create([
            'faction_entity_id' => $factionEntity->id,
            'member_entity_id' => $memberEntity->id,
            'rank_or_role' => 'Archivist',
            'membership_status' => 'active',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $collectionEntity = CollectionEntity::create([
            'collection_id' => $collection->id,
            'entity_id' => $memberEntity->id,
            'added_manually' => true,
            'added_by_rule' => false,
            'role_in_collection' => 'lead',
            'sort_order' => 1,
            'notes' => 'Pinned',
        ]);
        $collectionDocument = CollectionDocument::create([
            'collection_id' => $collection->id,
            'document_id' => $document->id,
            'role_in_collection' => 'primary_source',
            'sort_order' => 2,
            'notes' => 'Key reference',
        ]);
        $documentEntity = DocumentEntity::create([
            'document_id' => $document->id,
            'entity_id' => $memberEntity->id,
            'relationship_type' => 'subject',
            'notes' => ['type' => 'doc', 'content' => []],
        ]);
        $canonLink = CanonReferenceEntity::create([
            'canon_reference_id' => $reference->id,
            'entity_id' => $memberEntity->id,
            'relationship_type' => 'au_version',
            'divergence_level' => 'moderate',
            'divergence_notes' => ['type' => 'doc', 'content' => []],
        ]);
        $timelinePlacement = TimelineEntity::create([
            'timeline_id' => $timelineEntity->id,
            'event_entity_id' => $eventEntity->id,
            'position' => 4,
            'perspective_label' => 'Field Report',
            'perspective_notes' => ['type' => 'doc', 'content' => []],
        ]);
        $stateRelationship = StateRelationship::create([
            'character_state_id' => $state->id,
            'relationship_id' => $relationship->id,
            'is_active_at_snapshot' => true,
            'relationship_state_at_snapshot' => ['type' => 'doc', 'content' => []],
        ]);
        $powerInstance = PowerInteractionInstance::create([
            'power_interaction_id' => $interaction->id,
            'event_entity_id' => $eventEntity->id,
            'outcome_match' => 'confirmed',
            'observed_at_era' => 'Year 3',
        ]);
        $region = GalacticRegion::create([
            'region_name' => 'Outer Reach',
            'region_type' => 'sector',
            'approximate_scale' => 'Sparse frontier',
            'source_universe' => 'Star Wars',
            'is_fully_mapped' => true,
            'connected_location_entity_ids' => [$memberEntity->id],
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $genericIndexes = [
            'group-relationship-memberships.index',
            'faction-memberships.index',
            'collection-entities.index',
            'collection-documents.index',
            'document-entities.index',
            'canon-reference-entities.index',
            'timeline-placements.index',
            'state-relationships.index',
            'power-interaction-instances.index',
        ];

        foreach ($genericIndexes as $routeName) {
            $this->actingAs($user)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('ModeledResources/Index')
                    ->has('items', 1)
                );
        }

        $genericShows = [
            ['route' => 'group-relationship-memberships.show', 'record' => $groupMembership->id, 'title' => 'Seraphine Vale in Quiet Accord'],
            ['route' => 'faction-memberships.show', 'record' => $factionMembership->id, 'title' => 'Seraphine Vale -> Night Council'],
            ['route' => 'collection-entities.show', 'record' => $collectionEntity->id, 'title' => 'Seraphine Vale in Archive Dossiers'],
            ['route' => 'collection-documents.show', 'record' => $collectionDocument->id, 'title' => 'Sealed Ledger in Archive Dossiers'],
            ['route' => 'document-entities.show', 'record' => $documentEntity->id, 'title' => 'Seraphine Vale in Sealed Ledger'],
            ['route' => 'canon-reference-entities.show', 'record' => $canonLink->id, 'title' => 'Seraphine Vale in Canon Ledger'],
            ['route' => 'timeline-placements.show', 'record' => $timelinePlacement->id, 'title' => 'Battle of Dawn on Prime Timeline'],
            ['route' => 'state-relationships.show', 'record' => $stateRelationship->id, 'title' => 'Arc Start -> Relationship #'.$relationship->id],
            ['route' => 'power-interaction-instances.show', 'record' => $powerInstance->id, 'title' => 'Battle of Dawn for Archive Resonance'],
        ];

        foreach ($genericShows as $surface) {
            $this->actingAs($user)
                ->get(route($surface['route'], $surface['record']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('ModeledResources/Show')
                    ->where('title', $surface['title'])
                );
        }

        $this->actingAs($user)
            ->get(route('galactic-regions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('World/GalacticRegions/Index')
                ->has('items', 1)
                ->where('filters.q', null)
            );

        $this->actingAs($user)
            ->get(route('galactic-regions.show', $region))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('World/GalacticRegions/Show')
                ->where('title', 'Outer Reach')
            );
    }

    public function test_notion_admin_pages_render_as_read_only_web_surfaces(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create(['name' => 'Mapped Entity']);
        $note = NotionNote::create([
            'sync_resource' => 'entities',
            'notion_page_id' => 'page-123',
            'noteable_type' => Entity::class,
            'noteable_id' => $entity->id,
            'content' => 'Mirrored note body',
        ]);
        $mapping = NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => 'page-123',
            'local_model_type' => Entity::class,
            'local_model_id' => $entity->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.notion-notes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NotionNotes/Index')
                ->has('items', 1)
            );

        $this->actingAs($user)
            ->get(route('admin.notion-notes.show', $note))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NotionNotes/Show')
                ->where('note.id', $note->id)
                ->where('note.record_link.label', 'Mapped Entity')
            );

        $this->actingAs($user)
            ->get(route('admin.notion-sync-mappings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NotionSyncMappings/Index')
                ->has('items', 1)
            );

        $this->actingAs($user)
            ->get(route('admin.notion-sync-mappings.show', $mapping))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NotionSyncMappings/Show')
                ->where('mapping.id', $mapping->id)
                ->where('mapping.record_link.label', 'Mapped Entity')
            );
    }

    public function test_revision_index_show_compare_and_restore_work_in_the_web_app(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create([
            'name' => 'Before Restore',
            'entity_type' => EntityType::CHARACTER,
        ]);

        $older = Revision::create([
            'resource_type' => 'entities',
            'resource_id' => (string) $entity->id,
            'action' => 'update',
            'before_payload' => ['name' => 'Original'],
            'after_payload' => ['name' => 'Stable Name'],
            'diff_payload' => ['name' => ['before' => 'Original', 'after' => 'Stable Name']],
            'source' => 'web',
            'actor_user_id' => $user->id,
        ]);
        $newer = Revision::create([
            'resource_type' => 'entities',
            'resource_id' => (string) $entity->id,
            'action' => 'update',
            'before_payload' => ['name' => 'Stable Name'],
            'after_payload' => ['name' => 'Before Restore'],
            'diff_payload' => ['name' => ['before' => 'Stable Name', 'after' => 'Before Restore']],
            'source' => 'web',
            'actor_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.revisions.index', ['resource_type' => 'entities']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Revisions/Index')
                ->where('filters.resource_type', 'entities')
                ->has('items', 2)
            );

        $this->actingAs($user)
            ->get(route('admin.revisions.show', $newer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Revisions/Show')
                ->where('revision.id', $newer->id)
                ->where('revision.record_link.label', 'Before Restore')
            );

        $this->actingAs($user)
            ->get(route('admin.revisions.compare', ['left' => $older->id, 'right' => $newer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Revisions/Compare')
                ->where('comparison.left.id', $older->id)
                ->where('comparison.right.id', $newer->id)
            );

        $response = $this->actingAs($user)
            ->post(route('admin.revisions.restore', $older));

        $restoredRevision = Revision::query()->latest('id')->first();

        $response
            ->assertRedirect(route('admin.revisions.show', $restoredRevision))
            ->assertSessionHas('success');

        $entity->refresh();

        $this->assertSame('Stable Name', $entity->name);
        $this->assertSame('restore_revision', $restoredRevision?->action);
        $this->assertSame($older->id, $restoredRevision?->restored_from_revision_id);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
