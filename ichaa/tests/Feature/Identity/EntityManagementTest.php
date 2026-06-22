<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
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
                ->has('entities.data', 1)
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
                ->has('entities.data', 2)
                ->where('entities.data.0.id', $character->id)
                ->where('entities.data.1.id', $historicalFigure->id)
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

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
