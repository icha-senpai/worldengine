<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntitySubresourceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_aliases_can_be_created_updated_and_removed_for_an_entity(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->create([
            'name' => 'Seraphine',
            'has_aliases' => false,
        ]);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.aliases.store', $entity), [
                'alias' => 'Silent Heir',
                'alias_type' => 'title',
                'context' => 'Used by the old court.',
                'era_start' => 'Cycle 1',
                'is_active' => true,
                'visibility' => '',
                'content_classification' => '',
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        /** @var EntityAlias $alias */
        $alias = EntityAlias::first();

        $this->assertNotNull($alias);
        $this->assertSame('Silent Heir', $alias->alias);
        $this->assertSame('title', $alias->alias_type);
        $this->assertSame('private', $alias->visibility);
        $this->assertSame('restricted', $alias->content_classification);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->put(route('entities.aliases.update', [$entity, $alias]), [
                'alias' => 'Hidden Heir',
                'alias_type' => 'title',
                'context' => 'After the fracture.',
                'era_end' => 'Cycle 2',
                'is_active' => false,
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $alias->refresh();

        $this->assertSame('Hidden Heir', $alias->alias);
        $this->assertSame('Cycle 2', $alias->era_end);
        $this->assertFalse($alias->is_active);

        $this->actingAs($user)
            ->delete(route('entities.aliases.destroy', [$entity, $alias]))
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('entity_aliases', ['id' => $alias->id]);
    }

    public function test_notes_can_be_created_with_automatic_sort_order_then_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->create(['name' => 'Neri Vale']);

        EntityNote::create([
            'entity_id' => $entity->id,
            'note_label' => 'Existing',
            'content' => 'First note',
            'sort_order' => 4,
        ]);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.notes.store', $entity), [
                'note_label' => 'Backstory',
                'content' => 'Second note',
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        /** @var EntityNote $note */
        $note = EntityNote::where('note_label', 'Backstory')->first();

        $this->assertNotNull($note);
        $this->assertSame(5, $note->sort_order);

        $this->actingAs($user)
            ->put(route('entities.notes.update', [$entity, $note]), [
                'note_label' => 'Backstory Revised',
                'content' => 'Expanded note',
                'sort_order' => 2,
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $note->refresh();

        $this->assertSame('Backstory Revised', $note->note_label);
        $this->assertSame('Expanded note', $note->content);
        $this->assertSame(2, $note->sort_order);

        $this->actingAs($user)
            ->delete(route('entities.notes.destroy', [$entity, $note]))
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('entity_notes', ['id' => $note->id]);
    }

    public function test_questions_can_be_created_sorted_and_marked_resolved(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->create(['name' => 'Aurelia Vex']);
        $linkedEntity = Entity::factory()->create(['name' => 'Seraphine']);

        EntityQuestion::create([
            'entity_id' => $entity->id,
            'question' => 'Existing question',
            'priority' => 'medium',
            'status' => 'open',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.questions.store', $entity), [
                'question' => 'Does Aurelia foresee the fracture?',
                'context' => 'A key lore hinge.',
                'priority' => 'blocking',
                'status' => 'open',
                'linked_entity_ids' => [$linkedEntity->id],
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        /** @var EntityQuestion $question */
        $question = EntityQuestion::where('question', 'Does Aurelia foresee the fracture?')->first();

        $this->assertNotNull($question);
        $this->assertSame(2, $question->sort_order);
        $this->assertSame([$linkedEntity->id], $question->linked_entity_ids);

        $this->actingAs($user)
            ->put(route('entities.questions.update', [$entity, $question]), [
                'status' => 'resolved',
                'resolution' => 'Yes, but too late to stop it.',
                'priority' => 'blocking',
            ])
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $question->refresh();

        $this->assertSame('resolved', $question->status);
        $this->assertSame('Yes, but too late to stop it.', $question->resolution);
        $this->assertNotNull($question->resolved_at);

        $this->actingAs($user)
            ->delete(route('entities.questions.destroy', [$entity, $question]))
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('entity_questions', ['id' => $question->id]);
    }

    public function test_versions_can_be_listed_viewed_and_saved_without_breaking_current_chain(): void
    {
        $user = $this->verifiedUser();
        $entity = app(EntityService::class)->create([
            'name' => 'Mirror Seraphine',
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'Current state',
            'visibility' => VisibilityLevel::PRIVATE,
        ]);

        $current = $entity->versions()->where('is_current', true)->first();

        $this->assertNotNull($current);
        $this->assertSame(1, $current->version_number);

        $this->actingAs($user)
            ->get(route('entities.versions.index', $entity))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Versions/Index')
                ->where('entity.id', $entity->id)
                ->has('versions', 1)
                ->where('versions.0.id', $current->id)
            );

        $this->actingAs($user)
            ->post(route('entities.versions.store', $entity), [
                'version_label' => 'Post-fracture',
                'what_changed' => 'Shifted the current canon.',
                'why_changed' => 'A major narrative turn.',
                'valid_from_era' => 'Year 0',
            ])
            ->assertRedirect(route('entities.versions.index', $entity))
            ->assertSessionHas('success');

        /** @var VersionAndCanonState $manualVersion */
        $manualVersion = VersionAndCanonState::where('version_label', 'Post-fracture')->first();

        $this->assertNotNull($manualVersion);
        $this->assertSame(2, $manualVersion->version_number);
        $this->assertTrue($manualVersion->is_current);
        $this->assertFalse($current->fresh()->is_current);

        $this->actingAs($user)
            ->post(route('entities.versions.store', $entity), [
                'version_label' => 'Source Canon Capture',
                'is_version_zero' => true,
            ])
            ->assertRedirect(route('entities.versions.index', $entity))
            ->assertSessionHas('success');

        $versionZero = VersionAndCanonState::where('version_label', 'Source Canon Capture')->first();

        $this->assertNotNull($versionZero);
        $this->assertTrue($versionZero->is_version_zero);
        $this->assertFalse($versionZero->is_current);
        $this->assertTrue($manualVersion->fresh()->is_current);

        $this->actingAs($user)
            ->get(route('entities.versions.show', [$entity, $manualVersion]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Versions/Show')
                ->where('entity.id', $entity->id)
                ->where('version.id', $manualVersion->id)
                ->where('version.version_label', 'Post-fracture')
            );
    }

    public function test_entities_can_be_unpublished_and_archived(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->character()->publishable()->create([
            'name' => 'Grey Witness',
            'published_at' => now(),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.unpublish', $entity))
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $entity->refresh();

        $this->assertNull($entity->published_at);
        $this->assertSame(VisibilityLevel::PRIVATE, $entity->visibility);

        $this->actingAs($user)
            ->from(route('entities.show', $entity))
            ->post(route('entities.archive', $entity))
            ->assertRedirect(route('entities.show', $entity))
            ->assertSessionHas('success');

        $this->assertSame('archived', $entity->fresh()->status);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
