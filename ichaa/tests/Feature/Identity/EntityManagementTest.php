<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\System\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_can_view_the_entities_index(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->character()->create(['name' => 'Alpha Echo']);
        Entity::factory()->create([
            'name' => 'Faction Prime',
            'entity_type' => EntityType::FACTION,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('entities.index', ['type' => EntityType::CHARACTER]));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Index')
                ->where('filters.type', EntityType::CHARACTER)
                ->where('entities.data.0.id', $matching->id)
                ->where('entities.data.0.name', $matching->name)
            );
    }

    public function test_type_category_filters_include_all_child_entity_types(): void
    {
        $user = $this->verifiedUser();
        $character = Entity::factory()->create([
            'name' => 'Alpha Echo',
            'entity_type' => EntityType::CHARACTER,
        ]);
        $historicalFigure = Entity::factory()->create([
            'name' => 'Archivist Prime',
            'entity_type' => EntityType::HISTORICAL_FIGURE,
        ]);
        Entity::factory()->create([
            'name' => 'Faction Prime',
            'entity_type' => EntityType::FACTION,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('entities.index', ['type' => 'category:people']));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Index')
                ->where('filters.type', 'category:people')
                ->where('entities.data.0.id', $character->id)
                ->where('entities.data.1.id', $historicalFigure->id)
            );
    }

    public function test_entities_index_can_filter_by_visibility_and_incomplete_state(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->create([
            'name' => 'Hidden Draft',
            'visibility' => VisibilityLevel::SECRET,
            'completion_score' => 45,
        ]);
        Entity::factory()->create([
            'name' => 'Hidden Complete',
            'visibility' => VisibilityLevel::SECRET,
            'completion_score' => 100,
        ]);
        Entity::factory()->create([
            'name' => 'Public Draft',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'completion_score' => 40,
        ]);

        $this->actingAs($user)
            ->get(route('entities.index', [
                'q' => 'Hidden Draft',
                'visibility' => VisibilityLevel::SECRET,
                'incomplete' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Index')
                ->where('filters.q', 'Hidden Draft')
                ->where('filters.visibility', VisibilityLevel::SECRET)
                ->where('filters.incomplete', '1')
                ->has('entities.data', 1)
                ->where('entities.data.0.id', $matching->id)
            );
    }

    public function test_entities_can_be_created_with_defaults_and_initial_version_history(): void
    {
        $user = $this->verifiedUser();

        $response = $this
            ->actingAs($user)
            ->post(route('entities.store'), [
                'name' => 'Seraphine Vale',
                'entity_type' => EntityType::CHARACTER,
                'summary' => 'A central character in the setting.',
                'source_universes' => ['Harry Potter'],
            ]);

        $entity = Entity::where('name', 'Seraphine Vale')->first();

        $response
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertNotNull($entity);
        $this->assertSame(VisibilityLevel::PRIVATE, $entity->visibility);

        $this->assertDatabaseHas('versions_and_canon_states', [
            'entity_id' => $entity->id,
            'version_number' => 1,
            'is_current' => true,
            'is_version_zero' => false,
        ]);
    }

    public function test_entities_can_use_recorded_status_and_canonical_access_values(): void
    {
        $user = $this->verifiedUser();

        $response = $this
            ->actingAs($user)
            ->post(route('entities.store'), [
                'name' => 'Archive Witness',
                'entity_type' => EntityType::CHARACTER,
                'status' => 'recorded',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::SECRET,
            ]);

        $entity = Entity::where('name', 'Archive Witness')->first();

        $response
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertNotNull($entity);
        $this->assertSame('recorded', $entity->status);
        $this->assertSame(VisibilityLevel::SECRET, $entity->visibility);
        $this->assertSame(ContentClassification::SECRET, $entity->content_classification);
    }

    public function test_entities_index_search_matches_partial_words(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->create([
            'name' => 'Archive Witness',
            'entity_type' => EntityType::CHARACTER,
        ]);
        Entity::factory()->create([
            'name' => 'Vault Sentinel',
            'entity_type' => EntityType::CHARACTER,
        ]);

        $this->actingAs($user)
            ->get(route('entities.index', ['q' => 'arch']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Index')
                ->where('filters.q', 'arch')
                ->has('entities.data', 1)
                ->where('entities.data.0.id', $matching->id)
            );
    }

    public function test_incomplete_entities_cannot_be_published(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->create([
            'completion_score' => 35,
            'published_at' => null,
            'visibility' => VisibilityLevel::PRIVATE,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.publish', $entity));

        $response
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHasErrors('publish');

        $entity->refresh();

        $this->assertNull($entity->published_at);
        $this->assertSame(VisibilityLevel::PRIVATE, $entity->visibility);
    }

    public function test_publishable_entities_can_be_published(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->publishable()->create([
            'published_at' => null,
            'visibility' => VisibilityLevel::PRIVATE,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.publish', $entity));

        $response
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHasNoErrors();

        $entity->refresh();

        $this->assertNotNull($entity->published_at);
        $this->assertSame(VisibilityLevel::PUBLIC_KNOWLEDGE, $entity->visibility);
    }

    public function test_entity_show_page_includes_intelligence_summary_counts(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create(['name' => 'Seraphine']);
        $other = Entity::factory()->create(['name' => 'Johnny']);

        KnowledgeState::create([
            'knower_entity_id' => $entity->id,
            'subject_entity_id' => $other->id,
            'knowledge_type' => 'secret',
            'accuracy' => 'true',
            'acquired_through' => 'observation',
            'current_belief_state' => 'believes',
            'acted_on' => false,
            'is_current' => true,
        ]);

        KnowledgeState::create([
            'knower_entity_id' => $other->id,
            'subject_entity_id' => $entity->id,
            'knowledge_type' => 'public_fact',
            'accuracy' => 'true',
            'acquired_through' => 'observation',
            'current_belief_state' => 'believes',
            'acted_on' => false,
            'is_current' => true,
        ]);

        Secret::create([
            'title' => 'Puppet Cycle',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [$entity->id],
            'holder_entity_ids' => [$entity->id],
            'known_by_entity_ids' => [$entity->id, $other->id],
            'exposure_risk' => 'critical',
            'status' => 'active',
        ]);

        PerceptionState::create([
            'subject_type' => 'entity',
            'subject_id' => $entity->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'significant',
            'maintained_by_entity_ids' => [],
            'immune_entity_ids' => [],
            'revelation_risk' => 'high',
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get(route('entities.show', $entity))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Show')
                ->where('intelligenceSummary.counts.knowledge_held', 1)
                ->where('intelligenceSummary.counts.knowledge_about', 1)
                ->where('intelligenceSummary.counts.secrets_about', 1)
                ->where('intelligenceSummary.counts.secrets_held', 1)
                ->where('intelligenceSummary.counts.secrets_known', 1)
                ->where('intelligenceSummary.counts.perception_states', 1)
            );
    }

    public function test_status_change_auto_save_respects_settings_preferences(): void
    {
        $entity = app(EntityService::class)->create([
            'name' => 'Status Flag Entity',
            'entity_type' => EntityType::CHARACTER,
            'status' => 'concept',
        ]);

        $settings = Setting::singleton();
        $settings->update([
            'notification_preferences' => array_merge($settings->notification_preferences ?? [], [
                'auto_save_canon_state_on_status_change' => false,
            ]),
        ]);

        $this->actingAs($this->verifiedUser())
            ->patch(route('entities.update', $entity), [
                'status' => 'active',
            ])
            ->assertRedirect(route('entities.show', $entity));

        $this->assertSame(1, VersionAndCanonState::where('entity_id', $entity->id)->count());

        $settings->update([
            'notification_preferences' => array_merge($settings->notification_preferences ?? [], [
                'auto_save_canon_state_on_status_change' => true,
            ]),
        ]);

        $this->actingAs($this->verifiedUser())
            ->patch(route('entities.update', $entity), [
                'status' => 'archived',
            ])
            ->assertRedirect(route('entities.show', $entity));

        $latestVersion = VersionAndCanonState::where('entity_id', $entity->id)
            ->orderByDesc('version_number')
            ->first();

        $this->assertNotNull($latestVersion);
        $this->assertSame(2, VersionAndCanonState::where('entity_id', $entity->id)->count());
        $this->assertSame('automatic', $latestVersion->trigger_type);
        $this->assertSame('status', $latestVersion->triggered_by_field);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
