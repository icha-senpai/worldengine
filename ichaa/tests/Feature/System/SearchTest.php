<?php

namespace Tests\Feature\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_search_returns_an_empty_result_set(): void
    {
        $response = $this
            ->actingAs($this->verifiedUser())
            ->get(route('search'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->where('term', '')
                ->where('results', [])
            );
    }

    public function test_search_returns_matching_entities_from_postgres_full_text_search(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->create([
            'name' => 'Seraphine Vale',
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'An immortal strategist with a hidden empire.',
        ]);
        Entity::factory()->create([
            'name' => 'Aurelian March',
            'entity_type' => EntityType::FACTION,
            'summary' => 'A military bloc focused on border defense.',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('search', ['q' => 'Seraphine']));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->where('term', 'Seraphine')
                ->has('results.entities', 1)
                ->where('results.entities.0.id', $matching->id)
                ->where('results.entities.0.name', $matching->name)
            );
    }

    public function test_search_matches_partial_words_from_prefix_queries(): void
    {
        $user = $this->verifiedUser();
        $matching = Entity::factory()->create([
            'name' => 'Archive Keeper',
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'Protects the oldest records in the stacks.',
        ]);
        Entity::factory()->create([
            'name' => 'Vault Runner',
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'Moves fast and keeps quiet.',
        ]);

        $this->actingAs($user)
            ->get(route('search', ['q' => 'arch']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Search/Index')
                ->where('term', 'arch')
                ->has('results.entities', 1)
                ->where('results.entities.0.id', $matching->id)
            );
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
