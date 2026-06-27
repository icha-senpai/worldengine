<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KnowledgeStateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_new_knowledge_supersedes_the_existing_current_state_for_the_same_subject(): void
    {
        $knower = Entity::factory()->create();
        $subject = Entity::factory()->create();

        $service = app(IntelligenceService::class);

        $original = $service->recordKnowledge($knower, [
            'subject_entity_id' => $subject->id,
            'knowledge_type' => 'suspicion',
            'accuracy' => 'partial',
            'current_belief_state' => 'suspects',
            'acquired_through' => 'deduction',
            'valid_from_era' => 'Cycle 12',
        ]);

        $replacement = $service->recordKnowledge($knower, [
            'subject_entity_id' => $subject->id,
            'knowledge_type' => 'true_nature',
            'accuracy' => 'true',
            'current_belief_state' => 'compartmentalizing',
            'acquired_through' => 'observation',
            'valid_from_era' => 'Year 2000',
        ]);

        $this->assertFalse($original->fresh()->is_current);
        $this->assertSame('Cycle 12', $original->fresh()->valid_until_era);
        $this->assertTrue($replacement->fresh()->is_current);
        $this->assertSame('true_nature', $replacement->knowledge_type);
        $this->assertFalse($replacement->acted_on);

        $currentStates = KnowledgeState::query()
            ->where('knower_entity_id', $knower->id)
            ->where('subject_entity_id', $subject->id)
            ->where('is_current', true)
            ->count();

        $this->assertSame(1, $currentStates);
    }

    public function test_mark_acted_on_endpoint_updates_action_state_and_notes(): void
    {
        $user = $this->verifiedUser();
        $state = KnowledgeState::create([
            'knower_entity_id' => Entity::factory()->create()->id,
            'subject_entity_id' => Entity::factory()->create()->id,
            'knowledge_type' => 'secret',
            'accuracy' => 'true',
            'acquired_through' => 'told_by',
            'current_belief_state' => 'believes',
            'acted_on' => false,
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->from(route('knowledge-states.show', $state))
            ->post(route('knowledge-states.act-on', $state), [
                'action_notes' => ['type' => 'doc', 'content' => []],
            ])
            ->assertRedirect(route('knowledge-states.show', $state))
            ->assertSessionHasNoErrors();

        $state->refresh();

        $this->assertTrue($state->acted_on);
        $this->assertSame(['type' => 'doc', 'content' => []], $state->action_notes);
    }

    public function test_show_page_renders_alternate_subject_display_for_relationship_subjects(): void
    {
        $user = $this->verifiedUser();
        $from = Entity::factory()->create(['name' => 'Seraphine']);
        $to = Entity::factory()->create(['name' => 'Johnny']);
        $relationship = Relationship::create([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relationship_type' => 'conflict',
            'direction' => 'one_way',
            'current_tension_charge' => 'volatile',
            'is_active' => true,
        ]);
        $state = KnowledgeState::create([
            'knower_entity_id' => Entity::factory()->create()->id,
            'subject_relationship_id' => $relationship->id,
            'knowledge_type' => 'public_fact',
            'accuracy' => 'true',
            'acquired_through' => 'observation',
            'current_belief_state' => 'believes',
            'acted_on' => false,
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get(route('knowledge-states.show', $state))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Intelligence/KnowledgeStates/Show')
                ->where('subjectDisplay.typeLabel', 'Relationship')
                ->where('subjectDisplay.label', 'Seraphine -> Johnny')
            );
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
