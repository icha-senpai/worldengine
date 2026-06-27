<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerceptionStateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_new_perception_gap_retires_the_previous_current_gap_for_the_same_subject(): void
    {
        $subject = Entity::factory()->create();
        $maintainer = Entity::factory()->create();

        $service = app(IntelligenceService::class);

        $original = $service->createPerceptionGap([
            'subject_type' => 'entity',
            'subject_id' => $subject->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'significant',
            'maintained_by_entity_ids' => [$maintainer->id],
            'revelation_risk' => 'medium',
        ]);

        $replacement = $service->createPerceptionGap([
            'subject_type' => 'entity',
            'subject_id' => $subject->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [$maintainer->id],
            'revelation_risk' => 'high',
        ]);

        $this->assertFalse($original->fresh()->is_current);
        $this->assertTrue($replacement->fresh()->is_current);
        $this->assertSame('complete', $replacement->divergence_level);
    }

    public function test_immune_list_growth_escalates_revelation_risk_and_stays_unique(): void
    {
        $maintainer = Entity::factory()->create();
        $immuneOne = Entity::factory()->create();
        $immuneTwo = Entity::factory()->create();

        $state = PerceptionState::create([
            'subject_type' => 'entity',
            'subject_id' => Entity::factory()->create()->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [$maintainer->id],
            'immune_entity_ids' => [],
            'revelation_risk' => 'medium',
            'is_current' => true,
        ]);

        $service = app(IntelligenceService::class);

        $service->addImmuneEntity($state, $immuneOne->id);
        $state->refresh();

        $this->assertSame('critical', $state->revelation_risk);
        $this->assertSame([$immuneOne->id], $state->immune_entity_ids);

        $service->addImmuneEntity($state, $immuneOne->id);
        $state->refresh();

        $this->assertSame([$immuneOne->id], $state->immune_entity_ids);

        $service->addImmuneEntity($state, $immuneTwo->id);
        $state->refresh();

        $this->assertSame('inevitable', $state->revelation_risk);
        $this->assertSame([$immuneOne->id, $immuneTwo->id], $state->immune_entity_ids);
    }

    public function test_collapse_endpoint_marks_the_gap_revealed_and_not_current(): void
    {
        $user = $this->verifiedUser();
        $state = PerceptionState::create([
            'subject_type' => 'entity',
            'subject_id' => Entity::factory()->create()->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'significant',
            'maintained_by_entity_ids' => [],
            'revelation_risk' => 'high',
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->from(route('perception-states.show', $state))
            ->post(route('perception-states.collapse', $state), [
                'era' => 'Year 2000',
            ])
            ->assertRedirect(route('perception-states.show', $state))
            ->assertSessionHasNoErrors();

        $state->refresh();

        $this->assertFalse($state->is_current);
        $this->assertSame('Year 2000', $state->revealed_at_era);
        $this->assertSame('inevitable', $state->revelation_risk);
    }

    public function test_immune_entities_can_be_removed_from_the_show_surface_route(): void
    {
        $user = $this->verifiedUser();
        $immune = Entity::factory()->create(['name' => 'Johnny Voss']);
        $state = PerceptionState::create([
            'subject_type' => 'entity',
            'subject_id' => Entity::factory()->create()->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [],
            'immune_entity_ids' => [$immune->id],
            'revelation_risk' => 'critical',
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->from(route('perception-states.show', $state))
            ->delete(route('perception-states.immune.remove', ['perceptionState' => $state, 'entity' => $immune]))
            ->assertRedirect(route('perception-states.show', $state))
            ->assertSessionHas('success');

        $this->assertSame([], $state->fresh()->immune_entity_ids);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
