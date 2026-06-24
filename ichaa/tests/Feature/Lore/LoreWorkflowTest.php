<?php

namespace Tests\Feature\Lore;

use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_index_filters_by_type_and_returns_all_matching_statuses(): void
    {
        $user = $this->verifiedUser();
        $extant = Document::create([
            'title' => 'Mirror Treaty',
            'document_type' => 'treaty',
            'document_status' => 'extant',
        ]);

        $classified = Document::create([
            'title' => 'Lost Treaty',
            'document_type' => 'treaty',
            'document_status' => 'classified',
        ]);

        Document::create([
            'title' => 'Public Decree',
            'document_type' => 'decree',
            'document_status' => 'extant',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('documents.index', ['type' => 'treaty']));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Lore/Documents/Index')
                ->where('filters.type', 'treaty')
                ->has('documents.data', 2)
                ->where('documents.data.0.id', $classified->id)
                ->where('documents.data.1.id', $extant->id)
            );
    }

    public function test_documents_can_be_created_with_hidden_authorship_and_found_in_search(): void
    {
        $user = $this->verifiedUser();
        $officialAuthor = Entity::factory()->create(['name' => 'Public Scribe']);
        $trueAuthor = Entity::factory()->create(['name' => 'Shadow Editor']);

        $response = $this
            ->actingAs($user)
            ->post(route('documents.store'), [
                'title' => 'Mirror Concordance',
                'document_type' => 'research_notes',
                'document_authenticity' => 'disputed',
                'document_status' => 'extant',
                'official_narrative' => ['type' => 'doc', 'content' => []],
                'true_content' => ['type' => 'doc', 'content' => []],
                'official_author_entity_id' => $officialAuthor->id,
                'true_author_entity_id' => $trueAuthor->id,
                'era_created' => 'Cycle 12',
            ]);

        $document = Document::where('title', 'Mirror Concordance')->first();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('success');

        $this->assertNotNull($document);
        $this->assertSame($officialAuthor->id, $document->official_author_entity_id);
        $this->assertSame($trueAuthor->id, $document->true_author_entity_id);
        $this->assertTrue($document->hasAuthorshipDivergence());

        $this->actingAs($user)
            ->get(route('search', ['q' => 'Mirror Concordance']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->has('results.documents', 1)
                ->where('results.documents.0.id', $document->id)
                ->where('results.documents.0.title', 'Mirror Concordance')
            );
    }

    public function test_documents_can_be_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();
        $document = Document::create([
            'title' => 'Red Ledger',
            'document_type' => 'intelligence_report',
            'document_status' => 'extant',
        ]);
        $suppressor = Entity::factory()->create(['name' => 'Archivist Prime']);

        $this->actingAs($user)
            ->put(route('documents.update', $document), [
                'title' => 'Red Ledger Revised',
                'document_authenticity' => 'forged',
                'document_status' => 'suppressed',
                'true_content' => ['type' => 'doc', 'content' => []],
                'suppressed_by_entity_id' => $suppressor->id,
            ])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('success');

        $document->refresh();

        $this->assertSame('Red Ledger Revised', $document->title);
        $this->assertSame('suppressed', $document->document_status);
        $this->assertSame($suppressor->id, $document->suppressed_by_entity_id);

        $this->actingAs($user)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_canon_references_can_be_filtered_created_and_updated(): void
    {
        $user = $this->verifiedUser();
        $matching = SourceCanonReference::create([
            'universe' => 'Harry Potter',
            'level' => 'universe',
            'title' => 'Harry Potter Universe',
            'universe_priority' => 'primary',
            'research_status' => 'solid',
        ]);

        SourceCanonReference::create([
            'universe' => 'Cosmere',
            'level' => 'universe',
            'title' => 'Cosmere Universe',
            'universe_priority' => 'moderate',
            'research_status' => 'rough',
        ]);

        $this->actingAs($user)
            ->get(route('canon-references.index', ['universe' => 'Harry Potter']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Lore/CanonReferences/Index')
                ->where('filters.universe', 'Harry Potter')
                ->has('references', 1)
                ->where('references.0.id', $matching->id)
            );

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('canon-references.store'), [
                'universe' => 'Wheel of Time',
                'level' => 'category',
                'title' => 'WoT Politics',
                'parent_reference_id' => $matching->id,
                'universe_priority' => 'significant',
                'research_status' => 'developing',
                'research_confidence' => 'rough',
                'category_type' => 'history',
                'content' => ['type' => 'doc', 'content' => []],
            ]);

        $reference = SourceCanonReference::where('title', 'WoT Politics')->first();

        $storeResponse
            ->assertRedirect(route('canon-references.show', $reference))
            ->assertSessionHas('success');

        $auEntity = Entity::factory()->create(['name' => 'Pattern Echo']);

        $this->actingAs($user)
            ->from(route('canon-references.show', $reference))
            ->put(route('canon-references.update', $reference), [
                'universe' => 'Wheel of Time - Prime',
                'level' => 'element',
                'title' => 'WoT Politics Revised',
                'parent_reference_id' => null,
                'universe_priority' => 'primary',
                'content' => ['type' => 'doc', 'content' => []],
                'research_status' => 'solid',
                'research_confidence' => 'verified',
                'category_type' => 'political_structure',
                'element_type' => 'concept',
                'canon_disputed' => true,
                'au_entity_id' => $auEntity->id,
            ])
            ->assertRedirect(route('canon-references.show', $reference))
            ->assertSessionHas('success');

        $reference->refresh();

        $this->assertSame('Wheel of Time - Prime', $reference->universe);
        $this->assertSame('element', $reference->level);
        $this->assertSame('WoT Politics Revised', $reference->title);
        $this->assertSame('primary', $reference->universe_priority);
        $this->assertTrue($reference->canon_disputed);
        $this->assertSame($auEntity->id, $reference->au_entity_id);
    }

    public function test_crossover_entry_points_can_be_created_updated_and_deleted(): void
    {
        $user = $this->verifiedUser();

        $storeResponse = $this
            ->actingAs($user)
            ->post(route('crossover-entry-points.store'), [
                'source_universe' => 'Cosmere',
                'entry_mechanism' => ['type' => 'doc', 'content' => []],
                'status' => 'established',
            ]);

        $entryPoint = CrossoverEntryPoint::where('source_universe', 'Cosmere')->first();

        $storeResponse
            ->assertRedirect(route('crossover-entry-points.show', $entryPoint))
            ->assertSessionHas('success');

        $this->assertNotNull($entryPoint);
        $this->assertSame('established', $entryPoint->status);

        $this->actingAs($user)
            ->from(route('crossover-entry-points.show', $entryPoint))
            ->put(route('crossover-entry-points.update', $entryPoint), [
                'source_universe' => 'Stormlight Archive',
                'entry_mechanism' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'power_transition_rules' => ['type' => 'doc', 'content' => []],
                'return_rules' => ['type' => 'doc', 'content' => []],
                'status' => 'documented',
            ])
            ->assertRedirect(route('crossover-entry-points.show', $entryPoint))
            ->assertSessionHas('success');

        $entryPoint->refresh();

        $this->assertSame('Stormlight Archive', $entryPoint->source_universe);
        $this->assertSame('documented', $entryPoint->status);
        $this->assertTrue($entryPoint->hasReturnPath());

        $this->actingAs($user)
            ->delete(route('crossover-entry-points.destroy', $entryPoint))
            ->assertRedirect(route('crossover-entry-points.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('crossover_entry_points', ['id' => $entryPoint->id]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
