<?php

namespace Tests\Feature\Production;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Organization\Models\Collection;
use App\Domain\Production\Models\SessionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionLogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_logs_index_reports_recent_stats_only_for_non_deleted_rows(): void
    {
        $user = $this->verifiedUser();

        SessionLog::create([
            'title' => 'Recent Major',
            'session_date' => now()->subDays(5)->toDateString(),
            'external_tool' => 'chatgpt',
            'session_significance' => 'major',
        ]);

        SessionLog::create([
            'title' => 'Recent Minor',
            'session_date' => now()->subDays(3)->toDateString(),
            'external_tool' => 'claude',
            'session_significance' => 'minor',
        ]);

        SessionLog::create([
            'title' => 'Old Major',
            'session_date' => now()->subDays(45)->toDateString(),
            'external_tool' => 'notion',
            'session_significance' => 'major',
        ]);

        $deleted = SessionLog::create([
            'title' => 'Deleted Major',
            'session_date' => now()->subDays(2)->toDateString(),
            'external_tool' => 'qwen',
            'session_significance' => 'major',
        ]);
        $deleted->delete();

        $this->actingAs($user)
            ->get(route('session-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Sessions/Index')
                ->where('stats.session_count', 2)
                ->where('stats.major_count', 1)
            );
    }

    public function test_session_logs_can_be_created_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create(['name' => 'Seraphine']);
        $groupRelationship = GroupRelationship::create([
            'name' => 'Night Council',
            'relationship_type' => 'alliance',
            'current_tension_charge' => 'neutral',
            'is_active' => true,
        ]);
        $collection = Collection::create([
            'name' => 'Current Arc',
            'collection_type' => 'custom',
            'collection_mode' => 'manual',
        ]);

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('session-logs.store'), [
                'title' => 'Thread untangling',
                'external_tool' => 'chatgpt',
                'focus_entity_ids' => [$entity->id],
                'focus_group_relationship_ids' => [$groupRelationship->id],
                'focus_collection_ids' => [$collection->id],
                'focus_description' => 'Straightening the current arc.',
                'decisions_made' => ['type' => 'doc', 'content' => []],
                'changes_applied' => ['type' => 'doc', 'content' => []],
                'open_threads' => ['type' => 'doc', 'content' => []],
                'session_significance' => 'foundational',
                'notes' => ['type' => 'doc', 'content' => []],
            ]);

        $session = SessionLog::first();

        $storeResponse
            ->assertRedirect(route('session-logs.show', $session))
            ->assertSessionHas('success');

        $this->assertNotNull($session);
        $this->assertSame(now()->toDateString(), $session->session_date->toDateString());
        $this->assertSame([$entity->id], $session->focus_entity_ids);
        $this->assertSame([$groupRelationship->id], $session->focus_group_relationship_ids);
        $this->assertSame([$collection->id], $session->focus_collection_ids);

        $this->actingAs($user)
            ->from(route('session-logs.show', $session))
            ->put(route('session-logs.update', $session), [
                'title' => 'Thread untangling revised',
                'session_date' => now()->subDay()->toDateString(),
                'external_tool' => 'claude',
                'focus_description' => 'Updated focus note.',
                'decisions_made' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'changes_applied' => ['type' => 'doc', 'content' => []],
                'open_threads' => ['type' => 'doc', 'content' => []],
                'session_significance' => 'major',
                'notes' => ['type' => 'doc', 'content' => []],
            ])
            ->assertRedirect(route('session-logs.show', $session))
            ->assertSessionHas('success');

        $session->refresh();

        $this->assertSame('Thread untangling revised', $session->title);
        $this->assertSame('claude', $session->external_tool);
        $this->assertSame('Updated focus note.', $session->focus_description);
        $this->assertSame('major', $session->session_significance);

        $this->actingAs($user)
            ->delete(route('session-logs.destroy', $session))
            ->assertRedirect(route('session-logs.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('session_log', ['id' => $session->id]);
    }

    public function test_session_log_edit_route_includes_focus_form_options_and_existing_ids(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create(['name' => 'Seraphine', 'entity_type' => 'character']);
        $groupRelationship = GroupRelationship::create([
            'name' => 'Night Council',
            'relationship_type' => 'alliance',
            'current_tension_charge' => 'neutral',
            'is_active' => true,
        ]);
        $collection = Collection::create([
            'name' => 'Current Arc',
            'collection_type' => 'custom',
            'collection_mode' => 'manual',
        ]);
        $session = SessionLog::create([
            'title' => 'Thread untangling',
            'session_date' => now()->toDateString(),
            'external_tool' => 'claude',
            'focus_entity_ids' => [$entity->id],
            'focus_group_relationship_ids' => [$groupRelationship->id],
            'focus_collection_ids' => [$collection->id],
            'focus_description' => 'Updated focus note.',
            'session_significance' => 'major',
        ]);

        $this->actingAs($user)
            ->get(route('session-logs.edit', $session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Sessions/Show')
                ->where('session.focus_entity_ids', [$entity->id])
                ->where('session.focus_group_relationship_ids', [$groupRelationship->id])
                ->where('session.focus_collection_ids', [$collection->id])
                ->has('editDrawer.entities', 1)
                ->has('editDrawer.groupRelationships', 1)
                ->has('editDrawer.collections', 1)
                ->where('editDrawer.entities.0.id', $entity->id)
                ->where('editDrawer.groupRelationships.0.id', $groupRelationship->id)
                ->where('editDrawer.collections.0.id', $collection->id)
                ->where('editDrawer.significanceLevels', SessionLog::SIGNIFICANCE_LEVELS)
            );
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
