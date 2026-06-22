<?php

namespace Tests\Feature\Temporal;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimelineWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_timelines_can_be_created_using_the_summary_field(): void
    {
        $response = $this
            ->actingAs($this->verifiedUser())
            ->post(route('timelines.store'), [
                'name' => 'Main AU Timeline',
                'summary' => 'Tracks the primary crossover chronology.',
                'visibility' => '',
            ]);

        $timeline = Entity::where('name', 'Main AU Timeline')->first();

        $response->assertRedirect(route('timelines.show', $timeline));

        $this->assertNotNull($timeline);
        $this->assertSame(EntityType::TIMELINE, $timeline->entity_type);
        $this->assertSame('Tracks the primary crossover chronology.', $timeline->summary);
        $this->assertSame(VisibilityLevel::PRIVATE, $timeline->visibility);
        $this->assertSame('restricted', $timeline->content_classification);
    }

    public function test_events_can_be_placed_on_and_removed_from_timelines_with_auto_positions(): void
    {
        $user = $this->verifiedUser();
        $timeline = Entity::factory()->create([
            'name' => 'Grey Line',
            'entity_type' => EntityType::TIMELINE,
        ]);
        $firstEvent = Entity::factory()->create([
            'name' => 'Transformation',
            'entity_type' => EntityType::EVENT,
            'has_timeline_entries' => false,
        ]);
        $secondEvent = Entity::factory()->create([
            'name' => 'Revelation',
            'entity_type' => EntityType::EVENT,
            'has_timeline_entries' => false,
        ]);

        $this->actingAs($user)
            ->from(route('timelines.show', $timeline))
            ->post(route('timelines.events.place', ['timeline' => $timeline, 'event' => $firstEvent]), [
                'entry_label' => 'Year 0',
            ])
            ->assertRedirect(route('timelines.show', $timeline))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->from(route('timelines.show', $timeline))
            ->post(route('timelines.events.place', ['timeline' => $timeline, 'event' => $secondEvent]), [
                'entry_label' => 'Year 2000',
            ])
            ->assertRedirect(route('timelines.show', $timeline))
            ->assertSessionHasNoErrors();

        $entries = Timeline::where('timeline_id', $timeline->id)
            ->orderBy('timeline_position')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame(10, $entries[0]->timeline_position);
        $this->assertSame(20, $entries[1]->timeline_position);
        $this->assertTrue($firstEvent->fresh()->has_timeline_entries);
        $this->assertTrue($secondEvent->fresh()->has_timeline_entries);

        $this->actingAs($user)
            ->from(route('timelines.show', $timeline))
            ->delete(route('timelines.events.remove', ['timeline' => $timeline, 'entry' => $entries[0]]))
            ->assertRedirect(route('timelines.show', $timeline))
            ->assertSessionHasNoErrors();

        $this->assertFalse($firstEvent->fresh()->has_timeline_entries);
        $this->assertTrue($secondEvent->fresh()->has_timeline_entries);
    }

    public function test_timeline_entries_can_be_edited_after_placement(): void
    {
        $user = $this->verifiedUser();
        $timeline = Entity::factory()->create([
            'name' => 'Grey Line',
            'entity_type' => EntityType::TIMELINE,
        ]);
        $event = Entity::factory()->create([
            'name' => 'Archive Fire',
            'entity_type' => EntityType::EVENT,
        ]);
        $group = ConcurrencyGroup::create([
            'name' => 'Night of Falling',
            'au_date' => 'Year 0',
            'narrative_significance' => 'pivotal',
        ]);

        $entry = Timeline::create([
            'timeline_id' => $timeline->id,
            'event_entity_id' => $event->id,
            'entry_label' => 'Archive Fire',
            'timeline_position' => 10,
            'is_atemporal' => false,
        ]);

        $this->actingAs($user)
            ->get(route('timelines.events.edit', ['timeline' => $timeline, 'entry' => $entry]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Temporal/Timelines/Events/Edit')
                ->where('timeline.id', $timeline->id)
                ->where('entry.id', $entry->id)
                ->where('entry.event_entity.id', $event->id)
                ->has('concurrencyGroups', 1)
                ->where('eventSignificanceLevels', Timeline::EVENT_SIGNIFICANCE_LEVELS)
            );

        $this->actingAs($user)
            ->from(route('timelines.events.edit', ['timeline' => $timeline, 'entry' => $entry]))
            ->put(route('timelines.events.update', ['timeline' => $timeline, 'entry' => $entry]), [
                'entry_label' => 'Archive Fire Revised',
                'au_date' => 'Year 2000',
                'source_date' => '1998',
                'timeline_position' => 25,
                'concurrency_group_id' => $group->id,
                'event_significance' => 'major',
                'is_atemporal' => true,
            ])
            ->assertRedirect(route('timelines.show', $timeline))
            ->assertSessionHas('success');

        $entry->refresh();

        $this->assertSame('Archive Fire Revised', $entry->entry_label);
        $this->assertSame('Year 2000', $entry->au_date);
        $this->assertSame('1998', $entry->source_date);
        $this->assertSame(25, $entry->timeline_position);
        $this->assertSame($group->id, $entry->concurrency_group_id);
        if (Schema::hasColumn('timeline', 'event_significance')) {
            $this->assertSame('major', $entry->event_significance);
        }
        $this->assertTrue($entry->is_atemporal);
    }

    public function test_timeline_show_includes_direct_event_placement_data(): void
    {
        $user = $this->verifiedUser();
        $timeline = Entity::factory()->create([
            'name' => 'Grey Line',
            'entity_type' => EntityType::TIMELINE,
        ]);
        $placedEvent = Entity::factory()->create([
            'name' => 'Already Placed',
            'entity_type' => EntityType::EVENT,
        ]);
        $availableEvent = Entity::factory()->create([
            'name' => 'Still Available',
            'entity_type' => EntityType::EVENT,
        ]);
        $group = ConcurrencyGroup::create([
            'name' => 'Night of Falling',
            'au_date' => 'Year 0',
            'narrative_significance' => 'pivotal',
        ]);

        Timeline::create([
            'timeline_id' => $timeline->id,
            'event_entity_id' => $placedEvent->id,
            'entry_label' => 'Already Here',
            'timeline_position' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('timelines.show', $timeline))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Temporal/Timelines/Show')
                ->where('timeline.id', $timeline->id)
                ->has('events', 1)
                ->where('events.0.event_entity.id', $placedEvent->id)
                ->has('availableEvents', 1)
                ->where('availableEvents.0.id', $availableEvent->id)
                ->has('concurrencyGroups', 1)
                ->where('concurrencyGroups.0.id', $group->id)
                ->where('eventSignificanceLevels', Timeline::EVENT_SIGNIFICANCE_LEVELS)
            );
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
