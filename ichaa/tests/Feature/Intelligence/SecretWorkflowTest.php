<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecretWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_known_by_entities_escalates_exposure_risk_and_keeps_ids_unique(): void
    {
        $holder = Entity::factory()->create();
        $outsiderOne = Entity::factory()->create();
        $outsiderTwo = Entity::factory()->create();

        $secret = Secret::create([
            'title' => 'The Puppet Cycle',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [$holder->id],
            'holder_entity_ids' => [$holder->id],
            'known_by_entity_ids' => [$holder->id],
            'exposure_risk' => 'medium',
            'status' => 'active',
        ]);

        $service = app(IntelligenceService::class);

        $service->addToKnownBy($secret, $outsiderOne->id);
        $secret->refresh();

        $this->assertSame('high', $secret->exposure_risk);
        $this->assertSame([$holder->id, $outsiderOne->id], $secret->known_by_entity_ids);

        $service->addToKnownBy($secret, $outsiderOne->id);
        $secret->refresh();

        $this->assertSame([$holder->id, $outsiderOne->id], $secret->known_by_entity_ids);

        $service->addToKnownBy($secret, $outsiderTwo->id);
        $secret->refresh();

        $this->assertSame('critical', $secret->exposure_risk);
        $this->assertSame([$holder->id, $outsiderOne->id, $outsiderTwo->id], $secret->known_by_entity_ids);
    }

    public function test_secret_exposure_endpoint_updates_status_and_reveal_era(): void
    {
        $user = $this->verifiedUser();
        $secret = Secret::create([
            'title' => 'Seraphine Killed Hermione',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'event',
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [],
            'exposure_risk' => 'critical',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('secrets.expose', $secret), [
                'era' => 'Year 2000',
                'exposure_level' => 'fully_exposed',
            ])
            ->assertSessionHasNoErrors();

        $secret->refresh();

        $this->assertSame('fully_exposed', $secret->status);
        $this->assertSame('Year 2000', $secret->revealed_at_era);
    }

    public function test_secret_show_actions_can_add_and_remove_known_by_and_holders(): void
    {
        $user = $this->verifiedUser();
        $holder = Entity::factory()->create(['name' => 'Mirror Council']);
        $knower = Entity::factory()->create(['name' => 'Johnny Voss']);
        $secret = Secret::create([
            'title' => 'The Puppet Cycle',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [],
            'exposure_risk' => 'medium',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->from(route('secrets.show', $secret))
            ->post(route('secrets.holders.add', ['secret' => $secret, 'entity' => $holder]))
            ->assertRedirect(route('secrets.show', $secret))
            ->assertSessionHas('success');

        $secret->refresh();

        $this->assertSame([$holder->id], $secret->holder_entity_ids);
        $this->assertSame([$holder->id], $secret->known_by_entity_ids);

        $this->actingAs($user)
            ->from(route('secrets.show', $secret))
            ->post(route('secrets.known-by.add', ['secret' => $secret, 'entity' => $knower]))
            ->assertRedirect(route('secrets.show', $secret))
            ->assertSessionHas('success');

        $secret->refresh();

        $this->assertSame([$holder->id, $knower->id], $secret->known_by_entity_ids);

        $this->actingAs($user)
            ->from(route('secrets.show', $secret))
            ->delete(route('secrets.known-by.remove', ['secret' => $secret, 'entity' => $knower]))
            ->assertRedirect(route('secrets.show', $secret))
            ->assertSessionHas('success');

        $secret->refresh();

        $this->assertSame([$holder->id], $secret->known_by_entity_ids);

        $this->actingAs($user)
            ->from(route('secrets.show', $secret))
            ->delete(route('secrets.holders.remove', ['secret' => $secret, 'entity' => $holder]))
            ->assertRedirect(route('secrets.show', $secret))
            ->assertSessionHas('success');

        $secret->refresh();

        $this->assertSame([], $secret->holder_entity_ids);
        $this->assertSame([$holder->id], $secret->known_by_entity_ids);
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
