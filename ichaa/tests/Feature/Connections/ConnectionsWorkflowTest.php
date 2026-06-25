<?php

namespace Tests\Feature\Connections;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectionsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_index_applies_type_charge_and_masked_filters(): void
    {
        $user = $this->verifiedUser();
        $from = Entity::factory()->create(['name' => 'Masked One']);
        $to = Entity::factory()->create(['name' => 'Masked Two']);
        $other = Entity::factory()->create(['name' => 'Neutral Witness']);

        $matching = Relationship::create([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relationship_type' => RelationshipType::CONFLICT,
            'direction' => 'one_way',
            'current_tension_charge' => TensionCharge::VOLATILE,
            'perceived_type' => 'alliance',
            'true_type' => 'betrayal',
            'is_active' => true,
        ]);

        Relationship::create([
            'from_entity_id' => $from->id,
            'to_entity_id' => $other->id,
            'relationship_type' => RelationshipType::FAMILIAL,
            'direction' => 'mutual_equal',
            'current_tension_charge' => TensionCharge::POSITIVE,
            'perceived_type' => 'familial',
            'true_type' => 'familial',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('relationships.index', [
                'type' => RelationshipType::CONFLICT,
                'charge' => TensionCharge::VOLATILE,
                'masked' => 1,
            ]));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Index')
                ->where('filters.type', RelationshipType::CONFLICT)
                ->where('filters.charge', TensionCharge::VOLATILE)
                ->where('filters.masked', '1')
                ->has('relationships.data', 1)
                ->where('relationships.data.0.id', $matching->id)
            );
    }

    public function test_relationship_create_and_destroy_flip_entity_relationship_flags(): void
    {
        $user = $this->verifiedUser();
        $from = Entity::factory()->create(['has_relationships' => false]);
        $to = Entity::factory()->create(['has_relationships' => false]);

        $response = $this
            ->actingAs($user)
            ->post(route('relationships.store'), [
                'from_entity_id' => $from->id,
                'to_entity_id' => $to->id,
                'relationship_type' => RelationshipType::POWER,
                'visibility' => '',
                'content_classification' => '',
            ]);

        $relationship = Relationship::first();

        $response
            ->assertRedirect(route('relationships.show', $relationship))
            ->assertSessionHas('success');

        $this->assertNotNull($relationship);
        $this->assertSame('one_way', $relationship->direction);
        $this->assertSame(TensionCharge::NEUTRAL, $relationship->current_tension_charge);
        $this->assertSame('private', $relationship->visibility);
        $this->assertSame('restricted', $relationship->content_classification);
        $this->assertTrue($relationship->is_active);
        $this->assertTrue($from->fresh()->has_relationships);
        $this->assertTrue($to->fresh()->has_relationships);

        $this->actingAs($user)
            ->delete(route('relationships.destroy', $relationship))
            ->assertRedirect(route('relationships.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('relationships', ['id' => $relationship->id]);
        $this->assertFalse($from->fresh()->has_relationships);
        $this->assertFalse($to->fresh()->has_relationships);
    }

    public function test_relationship_update_appends_tension_charge_history(): void
    {
        $user = $this->verifiedUser();
        $relationship = Relationship::create([
            'from_entity_id' => Entity::factory()->create()->id,
            'to_entity_id' => Entity::factory()->create()->id,
            'relationship_type' => RelationshipType::KNOWLEDGE,
            'direction' => 'one_way',
            'current_tension_charge' => TensionCharge::NEUTRAL,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('relationships.update', $relationship), [
                'relationship_type' => RelationshipType::KNOWLEDGE,
                'current_tension_charge' => TensionCharge::COMPLEX,
                'charge_change_reason' => 'A secret shifted the balance.',
                'is_active' => true,
            ])
            ->assertRedirect(route('relationships.show', $relationship))
            ->assertSessionHas('success');

        $relationship->refresh();

        $this->assertSame(TensionCharge::COMPLEX, $relationship->current_tension_charge);
        $this->assertCount(1, $relationship->charge_history);
        $this->assertSame(TensionCharge::NEUTRAL, $relationship->charge_history[0]['previous_charge']);
        $this->assertSame(TensionCharge::COMPLEX, $relationship->charge_history[0]['new_charge']);
        $this->assertSame('A secret shifted the balance.', $relationship->charge_history[0]['reason']);
    }

    public function test_group_relationships_can_update_charge_and_manage_members(): void
    {
        $user = $this->verifiedUser();
        $member = Entity::factory()->create();

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('group-relationships.store'), [
                'name' => 'The Quiet Accord',
                'relationship_type' => 'alliance',
                'current_tension_charge' => TensionCharge::NEGATIVE,
                'visibility' => '',
                'content_classification' => '',
            ]);

        $group = GroupRelationship::where('name', 'The Quiet Accord')->first();

        $storeResponse
            ->assertRedirect(route('group-relationships.show', $group))
            ->assertSessionHas('success');

        $this->assertNotNull($group);
        $this->assertSame(TensionCharge::NEGATIVE, $group->current_tension_charge);
        $this->assertSame('private', $group->visibility);
        $this->assertSame('restricted', $group->content_classification);

        $this->actingAs($user)
            ->put(route('group-relationships.update', $group), [
                'name' => 'The Quiet Accord',
                'relationship_type' => 'alliance',
                'current_tension_charge' => TensionCharge::VOLATILE,
                'charge_change_reason' => 'Internal betrayal.',
                'is_active' => true,
            ])
            ->assertRedirect(route('group-relationships.show', $group))
            ->assertSessionHas('success');

        $group->refresh();

        $this->assertSame(TensionCharge::VOLATILE, $group->current_tension_charge);
        $this->assertCount(1, $group->charge_history);
        $this->assertSame('Internal betrayal.', $group->charge_history[0]['reason']);

        $this->actingAs($user)
            ->from(route('group-relationships.show', $group))
            ->post(route('group-relationships.members.add', $group), [
                'entity_id' => $member->id,
                'role_in_group' => 'Mediator',
                'joined_era' => 'Year 5',
                'participation_notes' => ['type' => 'doc', 'content' => []],
            ])
            ->assertRedirect(route('group-relationships.show', $group))
            ->assertSessionHas('success');

        /** @var GroupRelationshipEntity $entry */
        $entry = GroupRelationshipEntity::first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->is_active_member);
        $this->assertSame('Mediator', $entry->role_in_group);

        $this->actingAs($user)
            ->from(route('group-relationships.show', $group))
            ->delete(route('group-relationships.members.remove', ['groupRelationship' => $group, 'entry' => $entry]), [
                'left_era' => 'Year 8',
                'departure_notes' => ['type' => 'doc', 'content' => []],
            ])
            ->assertRedirect(route('group-relationships.show', $group))
            ->assertSessionHas('success');

        $entry->refresh();

        $this->assertFalse($entry->is_active_member);
        $this->assertSame('Year 8', $entry->left_era);
    }

    public function test_faction_memberships_can_be_created_updated_and_removed(): void
    {
        $user = $this->verifiedUser();
        $faction = Entity::factory()->create([
            'entity_type' => EntityType::FACTION,
            'name' => 'Aster Court',
        ]);
        $member = Entity::factory()->create(['name' => 'Neri Vale']);
        $loyalty = Entity::factory()->create([
            'entity_type' => EntityType::FACTION,
            'name' => 'Hidden Chorus',
        ]);

        $this->actingAs($user)
            ->from(route('faction-memberships.create'))
            ->post(route('faction-memberships.store'), [
                'faction_entity_id' => $faction->id,
                'member_entity_id' => $member->id,
                'rank_or_role' => 'Archivist',
                'membership_status' => 'active',
                'joined_era' => 'Arc One',
                'true_loyalty_entity_id' => $loyalty->id,
                'is_undercover' => true,
                'public_membership_known' => false,
            ])
            ->assertRedirect(route('entities.show', [
                'entity' => $faction->id,
                'tab' => 'memberships',
            ]))
            ->assertSessionHas('success');

        /** @var FactionMembership $membership */
        $membership = FactionMembership::first();

        $this->assertNotNull($membership);
        $this->assertTrue($membership->is_undercover);
        $this->assertFalse($membership->public_membership_known);
        $this->assertTrue($membership->isDisloyal());

        $this->actingAs($user)
            ->from(route('entities.show', $faction))
            ->put(route('faction-memberships.update', $membership), [
                'rank_or_role' => 'Councilor',
                'membership_status' => 'active',
                'true_loyalty_entity_id' => $faction->id,
                'is_undercover' => false,
                'public_membership_known' => true,
            ])
            ->assertRedirect(route('entities.show', [
                'entity' => $faction->id,
                'tab' => 'memberships',
            ]))
            ->assertSessionHas('success');

        $membership->refresh();

        $this->assertSame('Councilor', $membership->rank_or_role);
        $this->assertFalse($membership->is_undercover);
        $this->assertTrue($membership->public_membership_known);
        $this->assertFalse($membership->isDisloyal());

        $this->actingAs($user)
            ->from(route('entities.show', $faction))
            ->delete(route('faction-memberships.destroy', $membership))
            ->assertRedirect(route('entities.show', [
                'entity' => $faction->id,
                'tab' => 'memberships',
            ]))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('faction_memberships', ['id' => $membership->id]);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
