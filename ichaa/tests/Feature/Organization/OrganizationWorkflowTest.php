<?php

namespace Tests\Feature\Organization;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Organization\Models\Glossary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_collections_auto_sync_matching_entities_when_created(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->character()->create(['name' => 'Seraphine']);
        $nonMatching = Entity::factory()->create([
            'name' => 'The Sixth House',
            'entity_type' => EntityType::FACTION,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('collections.store'), [
                'name' => 'Character Roster',
                'collection_type' => 'character_roster',
                'collection_mode' => 'smart',
                'visibility' => '',
                'content_classification' => '',
                'rules' => [
                    ['field' => 'entity_type', 'operator' => 'equals', 'value' => EntityType::CHARACTER],
                ],
            ]);

        $collection = Collection::where('name', 'Character Roster')->first();

        $response
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('success');

        $this->assertNotNull($collection);
        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collection->id,
            'entity_id' => $matching->id,
            'added_by_rule' => true,
        ]);
        $this->assertSame('private', $collection->fresh()->visibility);
        $this->assertSame('restricted', $collection->fresh()->content_classification);
        $this->assertDatabaseMissing('collection_entities', [
            'collection_id' => $collection->id,
            'entity_id' => $nonMatching->id,
        ]);
    }

    public function test_rule_backed_collection_memberships_can_be_marked_manual_then_removed_back_to_rule_only(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->character()->create(['name' => 'Mira']);

        $collection = Collection::create([
            'name' => 'Hybrid Set',
            'collection_type' => 'smart',
            'collection_mode' => 'hybrid',
            'rules' => [
                ['field' => 'entity_type', 'operator' => 'equals', 'value' => EntityType::CHARACTER],
            ],
        ]);

        $this->actingAs($user)
            ->from(route('collections.show', $collection))
            ->post(route('collections.sync', $collection))
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->from(route('collections.show', $collection))
            ->post(route('collections.entities.add', ['collection' => $collection, 'entity' => $matching]), [
                'role_in_collection' => 'Lead',
                'sort_order' => 5,
            ])
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('success');

        $entry = CollectionEntity::where('collection_id', $collection->id)
            ->where('entity_id', $matching->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->added_by_rule);
        $this->assertTrue($entry->added_manually);
        $this->assertSame('Lead', $entry->role_in_collection);

        $this->actingAs($user)
            ->from(route('collections.show', $collection))
            ->delete(route('collections.entities.remove', ['collection' => $collection, 'entity' => $matching]))
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('success');

        $entry->refresh();

        $this->assertTrue($entry->added_by_rule);
        $this->assertFalse($entry->added_manually);
    }

    public function test_updating_collection_rules_resyncs_memberships(): void
    {
        $user = $this->verifiedUser();
        $character = Entity::factory()->character()->create(['name' => 'Lio']);
        $faction = Entity::factory()->create([
            'name' => 'Glass Parliament',
            'entity_type' => EntityType::FACTION,
        ]);

        $collection = Collection::create([
            'name' => 'Moving Target',
            'collection_type' => 'smart',
            'collection_mode' => 'smart',
            'rules' => [
                ['field' => 'entity_type', 'operator' => 'equals', 'value' => EntityType::FACTION],
            ],
        ]);

        $this->actingAs($user)
            ->from(route('collections.show', $collection))
            ->post(route('collections.sync', $collection))
            ->assertRedirect(route('collections.show', $collection));

        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collection->id,
            'entity_id' => $faction->id,
            'added_by_rule' => true,
        ]);

        $this->actingAs($user)
            ->put(route('collections.update', $collection), [
                'name' => 'Moving Target',
                'collection_type' => 'smart',
                'collection_mode' => 'smart',
                'completion_state' => 'in_progress',
                'rules' => [
                    ['field' => 'entity_type', 'operator' => 'equals', 'value' => EntityType::CHARACTER],
                ],
            ])
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('collection_entities', [
            'collection_id' => $collection->id,
            'entity_id' => $faction->id,
        ]);
        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collection->id,
            'entity_id' => $character->id,
            'added_by_rule' => true,
        ]);
    }

    public function test_collection_show_page_loads_child_collections_without_schema_errors(): void
    {
        $user = $this->verifiedUser();

        $parent = Collection::create([
            'name' => 'Main Archive',
            'collection_type' => 'custom',
            'collection_mode' => 'manual',
            'sort_order' => 1,
        ]);

        Collection::create([
            'name' => 'Nested Archive',
            'collection_type' => 'custom',
            'collection_mode' => 'manual',
            'parent_collection_id' => $parent->id,
            'sort_order' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('collections.show', $parent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Collections/Show')
                ->where('collection.name', 'Main Archive')
                ->has('collection.child_collections', 1)
                ->where('collection.child_collections.0.name', 'Nested Archive')
            );
    }

    public function test_glossary_index_filters_active_terms_by_universe_and_context(): void
    {
        $user = $this->verifiedUser();
        $matching = Glossary::create([
            'term' => 'Grey Line',
            'usage_context' => 'meta',
            'definition' => ['type' => 'doc', 'content' => []],
            'origin_universe' => 'Harry Potter',
            'term_status' => 'active',
        ]);

        Glossary::create([
            'term' => 'Buried Name',
            'usage_context' => 'meta',
            'definition' => ['type' => 'doc', 'content' => []],
            'origin_universe' => 'Harry Potter',
            'term_status' => 'suppressed',
        ]);

        Glossary::create([
            'term' => 'Elsewhere',
            'usage_context' => 'in_world',
            'definition' => ['type' => 'doc', 'content' => []],
            'origin_universe' => 'Marvel',
            'term_status' => 'active',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('glossary.index', [
                'universe' => 'Harry Potter',
                'context' => 'meta',
            ]));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Glossary/Index')
                ->where('filters.universe', 'Harry Potter')
                ->where('filters.context', 'meta')
                ->has('terms.data', 1)
                ->where('terms.data.0.id', $matching->id)
            );
    }

    public function test_glossary_terms_can_be_created_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('glossary.store'), [
                'term' => 'Mirror Archive',
                'usage_context' => 'both',
                'definition' => ['type' => 'doc', 'content' => []],
                'origin_universe' => 'Original',
                'era_introduced' => 'Book One',
                'term_status' => 'active',
            ]);

        $term = Glossary::where('term', 'Mirror Archive')->first();

        $storeResponse
            ->assertRedirect(route('glossary.show', $term))
            ->assertSessionHas('success');

        $this->assertNotNull($term);
        $this->assertTrue($term->isActive());

        $this->actingAs($user)
            ->put(route('glossary.update', $term), [
                'term' => 'Mirror Archive',
                'usage_context' => 'meta',
                'definition' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'term_status' => 'disputed',
            ])
            ->assertRedirect(route('glossary.show', $term))
            ->assertSessionHas('success');

        $term->refresh();

        $this->assertSame('meta', $term->usage_context);
        $this->assertSame('disputed', $term->term_status);

        $this->actingAs($user)
            ->delete(route('glossary.destroy', $term))
            ->assertRedirect(route('glossary.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('glossary', ['id' => $term->id]);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
