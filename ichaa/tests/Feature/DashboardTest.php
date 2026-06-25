<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\System\Models\Setting;
use App\Domain\World\Models\PowerInteraction;
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
                ->has('recentPipeline')
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

    public function test_dashboard_notification_preferences_gate_the_optional_alert_panels(): void
    {
        $user = $this->verifiedUser();
        $systemA = Entity::factory()->create([
            'name' => 'Storm Binding',
            'entity_type' => 'power_system',
        ]);
        $systemB = Entity::factory()->create([
            'name' => 'Null Weave',
            'entity_type' => 'magic_system',
        ]);

        Meta::create([
            'title' => 'Contradiction: breach timing',
            'category' => 'tensions_and_contradictions',
            'meta_note_type' => 'question',
            'priority' => 'blocking',
            'action_status' => 'pending',
        ]);

        PowerInteraction::create([
            'system_a_entity_id' => $systemA->id,
            'system_b_entity_id' => $systemB->id,
            'interaction_name' => 'Storm and Null',
            'directionality' => 'contextual',
            'knowledge_state' => 'rumored',
            'danger_rating' => 'high',
            'unresolved_flag' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        VersionAndCanonState::create([
            'entity_id' => $systemA->id,
            'version_type' => 'soft',
            'version_number' => 3,
            'version_label' => 'Old Storm Binding',
            'version_state' => 'deprecated',
            'is_current' => false,
            'is_version_zero' => false,
            'entity_snapshot' => [],
            'trigger_type' => 'manual',
            'deprecated_at' => now(),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockingContradictions', 1)
                ->has('unresolvedInteractions', 1)
                ->has('deprecatedCanonStates', 1)
            );

        $settings = Setting::singleton();
        $settings->update([
            'notification_preferences' => array_merge($settings->notification_preferences ?? [], [
                'flag_blocking_contradictions' => false,
                'flag_unresolved_power_interactions' => false,
                'flag_deprecated_canon_states' => false,
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockingContradictions', 0)
                ->has('unresolvedInteractions', 0)
                ->has('deprecatedCanonStates', 0)
            );
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
