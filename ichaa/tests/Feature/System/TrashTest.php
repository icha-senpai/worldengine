<?php

namespace Tests\Feature\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_trashed_entities_appear_in_the_trash_index(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create([
            'name' => 'Discarded Echo',
            'entity_type' => EntityType::CHARACTER,
        ]);

        $entity->delete();

        $this->actingAs($user)
            ->get(route('trash.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('System/Trash/Index')
                ->where('filters.type', '')
                ->where('filters.q', '')
                ->has('items', 1)
                ->where('items.0.type', 'entities')
                ->where('items.0.title', 'Discarded Echo')
            );
    }

    public function test_trashed_entities_can_be_restored(): void
    {
        $user = $this->verifiedUser();
        $entity = Entity::factory()->create([
            'name' => 'Restorable Echo',
            'entity_type' => EntityType::CHARACTER,
        ]);

        $entity->delete();

        $this->actingAs($user)
            ->from(route('trash.index'))
            ->post(route('trash.restore', [
                'type' => 'entities',
                'record' => $entity->id,
            ]))
            ->assertRedirect(route('trash.index'));

        $this->assertDatabaseHas('entities', [
            'id' => $entity->id,
            'deleted_at' => null,
        ]);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
