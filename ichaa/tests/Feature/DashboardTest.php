<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_can_view_the_dashboard_with_curated_overview_panels(): void
    {
        $user = $this->verifiedUser();

        $knower = Entity::factory()->character()->create(['name' => 'Johnny']);
        $subject = Entity::factory()->character()->create(['name' => 'Seraphine']);
        $maintainer = Entity::factory()->create([
            'name' => 'Mirror Council',
            'entity_type' => EntityType::FACTION,
        ]);

        PipelineItem::create([
            'title' => 'Library breach',
            'pipeline_type' => 'scene',
            'pipeline_stage' => 'drafted',
            'word_count' => 1400,
            'sort_order' => 1,
        ]);

        SessionLog::create([
            'title' => 'Recent major session',
            'session_date' => now()->subDays(3)->toDateString(),
            'external_tool' => 'claude',
            'session_significance' => 'major',
        ]);

        SessionLog::create([
            'title' => 'Recent minor session',
            'session_date' => now()->subDays(1)->toDateString(),
            'external_tool' => 'chatgpt',
            'session_significance' => 'minor',
        ]);

        KnowledgeState::create([
            'knower_entity_id' => $knower->id,
            'subject_entity_id' => $subject->id,
            'knowledge_type' => 'secret',
            'accuracy' => 'true',
            'current_belief_state' => 'believes',
            'acquired_through' => 'observation',
            'acted_on' => false,
            'is_current' => true,
        ]);

        Secret::create([
            'title' => 'Puppet Cycle',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [$subject->id],
            'holder_entity_ids' => [$maintainer->id],
            'known_by_entity_ids' => [$maintainer->id, $knower->id],
            'exposure_risk' => 'critical',
            'status' => 'active',
        ]);

        PerceptionState::create([
            'subject_type' => 'entity',
            'subject_id' => $subject->id,
            'true_state' => ['type' => 'doc', 'content' => []],
            'perceived_state' => ['type' => 'doc', 'content' => []],
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [$maintainer->id],
            'immune_entity_ids' => [$knower->id],
            'maintenance_effort' => 'critical',
            'revelation_risk' => 'high',
            'is_current' => true,
        ]);

        EntityQuestion::create([
            'entity_id' => $subject->id,
            'question' => 'Who taught Seraphine the fracture ritual?',
            'priority' => 'critical',
            'status' => 'unresolved',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('sessionStats.session_count', 2)
                ->where('sessionStats.major_count', 1)
                ->where('sessionStats.tools_used', ['claude', 'chatgpt'])
                ->has('recentPipeline', 1)
                ->where('recentPipeline.0.title', 'Library breach')
                ->has('latentTension', 1)
                ->where('latentTension.0.knower.name', 'Johnny')
                ->where('latentTension.0.subject_name', 'Seraphine')
                ->has('exposureRisk', 1)
                ->where('exposureRisk.0.title', 'Puppet Cycle')
                ->where('exposureRisk.0.is_leaking', true)
                ->has('perceptionGaps', 1)
                ->where('perceptionGaps.0.revelation_risk', 'high')
                ->has('blockingQuestions', 1)
                ->where('blockingQuestions.0.entity.name', 'Seraphine')
            );
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
