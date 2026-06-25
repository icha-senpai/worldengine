<?php

namespace Tests\Feature\Production;

use App\Domain\Identity\Models\Entity;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_store_defaults_stage_and_appends_after_existing_siblings(): void
    {
        $user = $this->verifiedUser();

        PipelineItem::create([
            'title' => 'Existing Scene',
            'pipeline_type' => 'scene',
            'pipeline_stage' => 'outlined',
            'sort_order' => 1,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pipeline.store'), [
                'title' => 'New Scene',
                'pipeline_type' => 'scene',
                'visibility' => '',
                'content_classification' => '',
            ]);

        $item = PipelineItem::where('title', 'New Scene')->first();

        $response
            ->assertRedirect(route('pipeline.show', $item))
            ->assertSessionHas('success');

        $this->assertNotNull($item);
        $this->assertSame('concept', $item->pipeline_stage);
        $this->assertSame(2, $item->sort_order);
        $this->assertSame('private', $item->visibility);
        $this->assertSame('restricted', $item->content_classification);
    }

    public function test_pipeline_advance_moves_items_to_the_next_stage_until_complete(): void
    {
        $user = $this->verifiedUser();
        $item = PipelineItem::create([
            'title' => 'Arc Draft',
            'pipeline_type' => 'arc',
            'pipeline_stage' => 'concept',
            'sort_order' => 1,
        ]);

        $expectedStages = ['outlined', 'drafted', 'revised', 'complete', 'complete'];

        foreach ($expectedStages as $expectedStage) {
            $this->actingAs($user)
                ->post(route('pipeline.advance', $item))
                ->assertSessionHas('success');

            $this->assertSame($expectedStage, $item->fresh()->pipeline_stage);
        }
    }

    public function test_pipeline_items_can_update_the_tracked_entity_from_the_edit_payload(): void
    {
        $user = $this->verifiedUser();
        $trackedEntity = Entity::factory()->create(['name' => 'Johnny']);
        $item = PipelineItem::create([
            'title' => 'Johnny fracture arc',
            'pipeline_type' => 'character_study',
            'pipeline_stage' => 'drafted',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->put(route('pipeline.update', $item), [
                'tracked_entity_id' => $trackedEntity->id,
                'arc_stage' => 'transformation',
            ])
            ->assertRedirect(route('pipeline.show', $item))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertSame($trackedEntity->id, $item->tracked_entity_id);
        $this->assertSame('transformation', $item->arc_stage);
    }

    public function test_meta_notes_can_be_resolved_and_linked_to_entities(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create();
        $meta = Meta::create([
            'title' => 'Resolve a contradiction',
            'category' => Meta::CATEGORIES[1],
            'meta_note_type' => 'active_task',
            'priority' => 'blocking',
            'action_status' => 'pending',
        ]);

        $this->actingAs($user)
            ->post(route('meta.resolve', $meta), [
                'resolution_notes' => ['type' => 'doc', 'content' => []],
            ])
            ->assertSessionHasNoErrors();

        $meta->refresh();

        $this->assertSame('resolved', $meta->action_status);
        $this->assertNotNull($meta->resolved_at);
        $this->assertSame(['type' => 'doc', 'content' => []], $meta->resolution_notes);

        $this->actingAs($user)
            ->post(route('meta.entities.link', ['meta' => $meta, 'entity' => $entity]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meta_entities', [
            'meta_id' => $meta->id,
            'entity_id' => $entity->id,
        ]);

        $this->actingAs($user)
            ->delete(route('meta.entities.unlink', ['meta' => $meta, 'entity' => $entity]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('meta_entities', [
            'meta_id' => $meta->id,
            'entity_id' => $entity->id,
        ]);
    }

    public function test_meta_notes_can_be_created_with_default_access_values(): void
    {
        $user = $this->verifiedUser();

        $response = $this
            ->actingAs($user)
            ->post(route('meta.store'), [
                'title' => 'Track the lantern motif',
                'category' => Meta::CATEGORIES[0],
                'meta_note_type' => 'active_task',
                'visibility' => '',
                'content_classification' => '',
            ]);

        $note = Meta::where('title', 'Track the lantern motif')->first();

        $response
            ->assertRedirect(route('meta.show', $note))
            ->assertSessionHas('success');

        $this->assertNotNull($note);
        $this->assertSame('private', $note->visibility);
        $this->assertSame('restricted', $note->content_classification);
    }

    public function test_meta_notes_can_update_symbol_fields_and_access_values(): void
    {
        $user = $this->verifiedUser();
        $originEntity = Entity::factory()->create(['name' => 'Lantern']);
        $linkedEntity = Entity::factory()->create(['name' => 'Mirror Hall']);
        $note = Meta::create([
            'title' => 'Track the lantern motif',
            'category' => Meta::CATEGORIES[0],
            'meta_note_type' => 'active_task',
        ]);

        $this->actingAs($user)
            ->put(route('meta.update', $note), [
                'symbol_name' => 'Lantern',
                'symbol_origin_entity_id' => $originEntity->id,
                'symbol_usage_context' => 'Signals safety and false comfort.',
                'symbol_associated_entity_ids' => [$linkedEntity->id],
                'symbol_scope' => 'both',
                'visibility' => 'author_only',
                'content_classification' => 'sensitive',
            ])
            ->assertRedirect(route('meta.show', $note))
            ->assertSessionHas('success');

        $note->refresh();

        $this->assertSame('Lantern', $note->symbol_name);
        $this->assertSame($originEntity->id, $note->symbol_origin_entity_id);
        $this->assertSame('Signals safety and false comfort.', $note->symbol_usage_context);
        $this->assertSame([$linkedEntity->id], $note->symbol_associated_entity_ids);
        $this->assertSame('both', $note->symbol_scope);
        $this->assertSame('author_only', $note->visibility);
        $this->assertSame('sensitive', $note->content_classification);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
