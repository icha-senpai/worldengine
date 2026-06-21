<?php

namespace Tests\Feature\Temporal;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemporalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_state_index_filters_by_entity_and_breaking_status(): void
    {
        $user = $this->verifiedUser();
        $matchingEntity = Entity::factory()->create([
            'name' => 'Seraphine',
            'entity_type' => EntityType::CHARACTER,
        ]);
        $otherEntity = Entity::factory()->create([
            'name' => 'Johnny',
            'entity_type' => EntityType::CHARACTER,
        ]);

        $matchingState = CharacterStateTracker::create([
            'entity_id' => $matchingEntity->id,
            'snapshot_label' => 'Year 0',
            'current_stability_level' => 'breaking',
            'timeline_position' => 10,
        ]);

        CharacterStateTracker::create([
            'entity_id' => $matchingEntity->id,
            'snapshot_label' => 'Aftermath',
            'current_stability_level' => 'stable',
            'timeline_position' => 20,
        ]);

        CharacterStateTracker::create([
            'entity_id' => $otherEntity->id,
            'snapshot_label' => 'Collateral',
            'current_stability_level' => 'broken',
            'timeline_position' => 30,
        ]);

        $this->actingAs($user)
            ->get(route('character-states.index', [
                'entity' => $matchingEntity->id,
                'breaking' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Temporal/CharacterStates/Index')
                ->where('filters.entity', (string) $matchingEntity->id)
                ->where('filters.breaking', '1')
                ->has('states.data', 1)
                ->where('states.data.0.id', $matchingState->id)
            );
    }

    public function test_character_states_can_be_created_updated_and_deleted_while_flipping_entity_flags(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create([
            'name' => 'Neri Vale',
            'entity_type' => EntityType::CHARACTER,
            'has_state_snapshots' => false,
        ]);
        $timeline = Entity::factory()->create([
            'name' => 'Grey Line',
            'entity_type' => EntityType::TIMELINE,
        ]);
        $era = Entity::factory()->create([
            'name' => 'Cycle 12',
            'entity_type' => EntityType::ERA,
        ]);

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('character-states.store'), [
                'entity_id' => $entity->id,
                'timeline_id' => $timeline->id,
                'era_entity_id' => $era->id,
                'au_date' => 'Year 0',
                'snapshot_label' => 'Before the fracture',
                'snapshot_significance' => 'major',
                'current_stability_level' => 'strained',
                'mask_integrity' => 'cracking',
                'current_desire' => 'Keep everyone alive.',
                'timeline_position' => 15,
            ]);

        $state = CharacterStateTracker::first();

        $storeResponse
            ->assertRedirect(route('character-states.show', $state))
            ->assertSessionHas('success');

        $this->assertNotNull($state);
        $this->assertSame($entity->id, $state->entity_id);
        $this->assertSame($timeline->id, $state->timeline_id);
        $this->assertSame($era->id, $state->era_entity_id);
        $this->assertTrue($entity->fresh()->has_state_snapshots);

        $this->actingAs($user)
            ->from(route('character-states.show', $state))
            ->put(route('character-states.update', $state), [
                'snapshot_label' => 'After the fracture',
                'snapshot_significance' => 'transformative',
                'current_stability_level' => 'broken',
                'mask_integrity' => 'shattered',
                'current_desire' => 'Rebuild the mask.',
                'timeline_position' => 25,
            ])
            ->assertRedirect(route('character-states.show', $state))
            ->assertSessionHas('success');

        $state->refresh();

        $this->assertSame('After the fracture', $state->snapshot_label);
        $this->assertSame('transformative', $state->snapshot_significance);
        $this->assertSame('broken', $state->current_stability_level);
        $this->assertSame('shattered', $state->mask_integrity);
        $this->assertSame(25, $state->timeline_position);

        $this->actingAs($user)
            ->delete(route('character-states.destroy', $state))
            ->assertRedirect(route('character-states.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('character_state_tracker', ['id' => $state->id]);
        $this->assertFalse($entity->fresh()->has_state_snapshots);
    }

    public function test_concurrency_groups_index_includes_timeline_entry_counts(): void
    {
        $user = $this->verifiedUser();
        $timeline = Entity::factory()->create([
            'name' => 'Main AU',
            'entity_type' => EntityType::TIMELINE,
        ]);
        $event = Entity::factory()->create([
            'name' => 'Hermione Falls',
            'entity_type' => EntityType::EVENT,
        ]);

        $matchingGroup = ConcurrencyGroup::create([
            'name' => 'Night of Falling',
            'au_date' => 'Year 0',
            'narrative_significance' => 'pivotal',
        ]);

        ConcurrencyGroup::create([
            'name' => 'Quiet Dawn',
            'au_date' => 'Year 1',
            'narrative_significance' => 'minor',
        ]);

        Timeline::create([
            'timeline_id' => $timeline->id,
            'event_entity_id' => $event->id,
            'entry_label' => 'Impact',
            'concurrency_group_id' => $matchingGroup->id,
            'timeline_position' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('concurrency-groups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Temporal/ConcurrencyGroups/Index')
                ->has('groups', 2)
                ->where('groups.0.id', $matchingGroup->id)
                ->where('groups.0.timeline_entries_count', 1)
            );
    }

    public function test_concurrency_groups_can_be_created_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();

        $storeResponse = $this
            ->actingAs($user)
            ->from(route('concurrency-groups.index'))
            ->post(route('concurrency-groups.store'), [
                'name' => 'The Same Hour',
                'au_date' => 'Cycle 12',
                'description' => ['type' => 'doc', 'content' => []],
                'narrative_significance' => 'major',
            ]);

        $group = ConcurrencyGroup::first();

        $storeResponse
            ->assertRedirect(route('concurrency-groups.index'))
            ->assertSessionHas('success');

        $this->assertNotNull($group);
        $this->assertSame('The Same Hour', $group->name);
        $this->assertSame('major', $group->narrative_significance);

        $this->actingAs($user)
            ->from(route('concurrency-groups.show', $group))
            ->put(route('concurrency-groups.update', $group), [
                'name' => 'The Same Hour Revised',
                'au_date' => 'Cycle 13',
                'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'narrative_significance' => 'pivotal',
            ])
            ->assertRedirect(route('concurrency-groups.show', $group))
            ->assertSessionHas('success');

        $group->refresh();

        $this->assertSame('The Same Hour Revised', $group->name);
        $this->assertSame('Cycle 13', $group->au_date);
        $this->assertSame('pivotal', $group->narrative_significance);

        $this->actingAs($user)
            ->from(route('concurrency-groups.show', $group))
            ->delete(route('concurrency-groups.destroy', $group))
            ->assertRedirect(route('concurrency-groups.show', $group))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('concurrency_groups', ['id' => $group->id]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
