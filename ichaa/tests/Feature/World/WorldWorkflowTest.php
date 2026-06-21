<?php

namespace Tests\Feature\World;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_power_interactions_can_be_filtered_created_resolved_and_record_instances(): void
    {
        $user = $this->verifiedUser();
        $systemA = Entity::factory()->create([
            'name' => 'Null Weave',
            'entity_type' => EntityType::POWER_SYSTEM,
        ]);
        $systemB = Entity::factory()->create([
            'name' => 'Storm Binding',
            'entity_type' => EntityType::MAGIC_SYSTEM,
        ]);
        $systemC = Entity::factory()->create([
            'name' => 'Archive Flame',
            'entity_type' => EntityType::MAGIC_SYSTEM,
        ]);
        $event = Entity::factory()->create([
            'name' => 'Collision at the Glass Sea',
            'entity_type' => EntityType::EVENT,
        ]);

        $interaction = PowerInteraction::create([
            'system_a_entity_id' => $systemA->id,
            'system_b_entity_id' => $systemB->id,
            'interaction_name' => 'Stable Pairing',
            'directionality' => 'symmetrical',
            'knowledge_state' => 'established',
            'danger_rating' => 'moderate',
            'unresolved_flag' => false,
        ]);

        $this->actingAs($user)
            ->from(route('power-interactions.show', $interaction))
            ->post(route('power-interactions.instances.store', $interaction), [
                'event_entity_id' => $event->id,
                'outcome_match' => 'contradicted',
                'outcome_notes' => ['type' => 'doc', 'content' => []],
                'observed_at_era' => 'Year 0',
            ])
            ->assertRedirect(route('power-interactions.show', $interaction))
            ->assertSessionHas('success');

        $instance = PowerInteractionInstance::first();

        $this->assertNotNull($instance);
        $this->assertSame($interaction->id, $instance->power_interaction_id);
        $this->assertTrue($interaction->fresh()->unresolved_flag);

        $this->actingAs($user)
            ->get(route('power-interactions.index', ['unresolved' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('World/PowerInteractions/Index')
                ->where('filters.unresolved', '1')
                ->has('interactions.data', 1)
                ->where('interactions.data.0.id', $interaction->id)
            );

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('power-interactions.store'), [
                'system_a_entity_id' => $systemC->id,
                'system_b_entity_id' => $systemA->id,
                'interaction_name' => 'Chaotic Pairing',
                'knowledge_state' => 'unknown',
                'danger_rating' => 'existential_risk',
                'directionality' => 'contextual',
                'proximity_required' => true,
            ]);

        $created = PowerInteraction::where('interaction_name', 'Chaotic Pairing')->first();

        $storeResponse
            ->assertRedirect(route('power-interactions.show', $created))
            ->assertSessionHas('success');

        $this->assertNotNull($created);
        $this->assertSame(min($systemA->id, $systemC->id), $created->system_a_entity_id);
        $this->assertSame(max($systemA->id, $systemC->id), $created->system_b_entity_id);
        $this->assertTrue($created->unresolved_flag);
        $this->assertTrue($created->proximity_required);

        $this->actingAs($user)
            ->from(route('power-interactions.show', $created))
            ->post(route('power-interactions.resolve', $created), [
                'resolution_notes' => ['type' => 'doc', 'content' => []],
                'knowledge_state' => 'established',
            ])
            ->assertRedirect(route('power-interactions.show', $created))
            ->assertSessionHas('success');

        $created->refresh();

        $this->assertFalse($created->unresolved_flag);
        $this->assertSame('established', $created->knowledge_state);
    }

    public function test_location_containments_are_indexed_as_active_and_can_be_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();
        $child = $this->spatialEntity('Mirror Library');
        $parent = $this->spatialEntity('Grey London');
        $otherChild = $this->spatialEntity('Sunken Hall');
        $otherParent = $this->spatialEntity('Aster Province');

        $matching = LocationContainment::create([
            'child_location_entity_id' => $child->id,
            'parent_location_entity_id' => $parent->id,
            'containment_type' => 'dimensional',
            'is_active' => true,
        ]);

        LocationContainment::create([
            'child_location_entity_id' => $otherChild->id,
            'parent_location_entity_id' => $otherParent->id,
            'containment_type' => 'political',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('location-containment.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('World/LocationContainment/Index')
                ->has('containments', 1)
                ->where('containments.0.id', $matching->id)
            );

        $this->actingAs($user)
            ->from(route('location-containment.index'))
            ->post(route('location-containment.store'), [
                'child_location_entity_id' => $otherChild->id,
                'parent_location_entity_id' => $parent->id,
                'containment_type' => 'physical',
                'era_start' => 'Cycle 1',
            ])
            ->assertRedirect(route('location-containment.index'))
            ->assertSessionHas('success');

        $created = LocationContainment::where([
            'child_location_entity_id' => $otherChild->id,
            'parent_location_entity_id' => $parent->id,
        ])->first();

        $this->assertNotNull($created);
        $this->assertTrue($created->is_active);

        $this->actingAs($user)
            ->from(route('location-containment.index'))
            ->put(route('location-containment.update', $created), [
                'era_end' => 'Cycle 2',
                'is_active' => false,
            ])
            ->assertRedirect(route('location-containment.index'))
            ->assertSessionHas('success');

        $created->refresh();

        $this->assertSame('Cycle 2', $created->era_end);
        $this->assertFalse($created->is_active);

        $this->actingAs($user)
            ->from(route('location-containment.index'))
            ->delete(route('location-containment.destroy', $created))
            ->assertRedirect(route('location-containment.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('location_containment', ['id' => $created->id]);
    }

    public function test_travel_routes_can_be_created_bidirectionally_updated_and_soft_deleted(): void
    {
        $user = $this->verifiedUser();
        $origin = $this->spatialEntity('Grey London');
        $destination = $this->spatialEntity('Mirror Library');

        $this->actingAs($user)
            ->from(route('travel-routes.index'))
            ->post(route('travel-routes.store'), [
                'origin_location_entity_id' => $origin->id,
                'destination_location_entity_id' => $destination->id,
                'route_type' => 'planar',
                'bidirectional' => true,
                'standard_duration' => 'Two nights',
                'method_variants' => [['method_name' => 'Gatewalk']],
            ])
            ->assertRedirect(route('travel-routes.index'))
            ->assertSessionHas('success');

        $routes = TravelRoute::orderBy('id')->get();

        $this->assertCount(2, $routes);
        $this->assertSame($origin->id, $routes[0]->origin_location_entity_id);
        $this->assertSame($destination->id, $routes[0]->destination_location_entity_id);
        $this->assertSame($destination->id, $routes[1]->origin_location_entity_id);
        $this->assertSame($origin->id, $routes[1]->destination_location_entity_id);

        $forward = $routes->first();

        $this->actingAs($user)
            ->from(route('travel-routes.show', $forward))
            ->put(route('travel-routes.update', $forward), [
                'standard_duration' => 'Three nights',
                'hazards' => [['hazard_type' => 'storm', 'severity' => 'high']],
                'is_active' => false,
            ])
            ->assertRedirect(route('travel-routes.show', $forward))
            ->assertSessionHas('success');

        $forward->refresh();

        $this->assertSame('Three nights', $forward->standard_duration);
        $this->assertFalse($forward->is_active);
        $this->assertSame('storm', $forward->hazards[0]['hazard_type']);

        $this->actingAs($user)
            ->from(route('travel-routes.show', $forward))
            ->delete(route('travel-routes.destroy', $forward))
            ->assertRedirect(route('travel-routes.show', $forward))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('travel_routes', ['id' => $forward->id]);
    }

    public function test_location_control_changes_end_previous_current_records_and_support_updates(): void
    {
        $user = $this->verifiedUser();
        $location = $this->spatialEntity('Aster Province');
        $firstController = Entity::factory()->create(['name' => 'Old Crown']);
        $secondController = Entity::factory()->create(['name' => 'New Accord']);

        $previous = LocationControlHistory::create([
            'location_entity_id' => $location->id,
            'controlling_entity_id' => $firstController->id,
            'control_type' => 'sovereign',
            'control_start_era' => 'Cycle 1',
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->from(route('location-control.index'))
            ->post(route('location-control.store'), [
                'location_entity_id' => $location->id,
                'controlling_entity_id' => $secondController->id,
                'control_type' => 'occupied',
                'control_start_era' => 'Cycle 2',
            ])
            ->assertRedirect(route('location-control.index'))
            ->assertSessionHas('success');

        $current = LocationControlHistory::where('controlling_entity_id', $secondController->id)->first();

        $this->assertNotNull($current);
        $this->assertFalse($previous->fresh()->is_current);
        $this->assertSame('Cycle 2', $previous->fresh()->control_end_era);
        $this->assertTrue($current->is_current);

        $this->actingAs($user)
            ->get(route('location-control.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('World/LocationControl/Index')
                ->has('records', 1)
                ->where('records.0.id', $current->id)
            );

        $this->actingAs($user)
            ->from(route('location-control.index'))
            ->put(route('location-control.update', $current), [
                'resistance_level' => 'active_conflict',
                'control_end_era' => 'Cycle 3',
                'how_control_ended' => ['type' => 'doc', 'content' => []],
            ])
            ->assertRedirect(route('location-control.index'))
            ->assertSessionHas('success');

        $current->refresh();

        $this->assertSame('active_conflict', $current->resistance_level);
        $this->assertSame('Cycle 3', $current->control_end_era);

        $this->actingAs($user)
            ->from(route('location-control.index'))
            ->delete(route('location-control.destroy', $current))
            ->assertRedirect(route('location-control.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('location_control_history', ['id' => $current->id]);
    }

    private function spatialEntity(string $name): Entity
    {
        return Entity::factory()->create([
            'name' => $name,
            'entity_type' => EntityType::LOCATION,
        ]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
