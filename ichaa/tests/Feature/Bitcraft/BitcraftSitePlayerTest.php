<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftWidgetProfile;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BitcraftSitePlayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Http::preventStrayRequests();
        config(['services.bitjuice.enabled' => true, 'services.bitjuice.enabled_in_tests' => true]);
        $this->mock(BitcraftSpacetimeStaticData::class, fn (MockInterface $mock) => $mock->shouldReceive('skillMap')->andReturn([['id' => 2, 'name' => 'Carpentry']]));
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/players/123' => Http::response(['player' => ['entityId' => '123', 'username' => 'Canonical Juice', 'experience' => []]]),
            'bitjuiceapi.deeznuts.chat/api/players?q=Juice' => Http::response(['players' => [['entityId' => '123', 'username' => 'Canonical Juice']]]),
            'bitjuiceapi.deeznuts.chat/api/crafts' => Http::response(['craftResults' => []]),
            '*' => Http::response([]),
        ]);
    }

    public function test_player_search_returns_public_identities_and_validates_query(): void
    {
        $this->actingAs($this->createVerifiedAdminUser())->getJson(route('bitcraft.players.search', ['q' => 'Juice']))
            ->assertOk()->assertJsonPath('players.0.entityId', '123')->assertJsonPath('players.0.username', 'Canonical Juice');
        $this->getJson(route('bitcraft.players.search', ['q' => 'a']))->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_selection_resolves_canonical_identity_is_shared_and_can_be_cleared(): void
    {
        $user = $this->createVerifiedAdminUser();
        $this->actingAs($user)->from(route('bitcraft.open-crafts'))->put(route('bitcraft.player.update'), ['entityId' => '123', 'username' => 'spoofed'])
            ->assertRedirect(route('bitcraft.open-crafts'))->assertSessionHas('bitcraft.site_player.'.$user->id, ['entityId' => '123', 'username' => 'Canonical Juice']);
        $this->get(route('bitcraft.open-crafts'))->assertInertia(fn (Assert $page) => $page->where('bitcraft.player.username', 'Canonical Juice'));
        $this->put(route('bitcraft.player.update'), ['entityId' => null])->assertRedirect()->assertSessionMissing('bitcraft.site_player.'.$user->id);
    }

    public function test_bad_identity_is_rejected_without_storing_a_selection(): void
    {
        $user = $this->createVerifiedAdminUser();
        $this->actingAs($user)->putJson(route('bitcraft.player.update'), ['entityId' => 'not-an-id'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->putJson(route('bitcraft.player.update'), ['entityId' => '999'])->assertUnprocessable()->assertJsonValidationErrors('entityId');
        $this->assertNull(session('bitcraft.site_player.'.$user->id));
    }

    public function test_selection_is_not_shared_with_another_account_in_the_same_session(): void
    {
        $first = $this->createVerifiedAdminUser();
        $second = $this->createVerifiedAdminUser();
        $this->actingAs($first)->put(route('bitcraft.player.update'), ['entityId' => '123']);
        $this->actingAs($second)->get(route('bitcraft.open-crafts'))->assertInertia(fn (Assert $page) => $page->where('bitcraft.player', null));
    }

    public function test_tracker_setup_defaults_to_site_player_and_keeps_explicit_overrides(): void
    {
        $user = $this->createVerifiedAdminUser();
        $this->actingAs($user)->put(route('bitcraft.player.update'), ['entityId' => '123']);
        foreach (['activity', 'inventory-tracker', 'passive-crafts'] as $tool) {
            $this->get(route('bitcraft.'.$tool.'.setup', ['source' => 'default']))
                ->assertInertia(fn (Assert $page) => $page->where('filters.character', '123'));
            $this->get(route('bitcraft.'.$tool.'.setup', ['character' => 'Other']))
                ->assertInertia(fn (Assert $page) => $page->where('filters.character', 'Other'));
        }
    }

    public function test_setup_follows_site_player_without_repointing_saved_obs_profiles(): void
    {
        $user = $this->createVerifiedAdminUser();
        $profile = BitcraftWidgetProfile::query()->create(['user_id' => $user->id, 'widget' => 'activity', 'source' => 'default', 'settings' => ['character' => 'Old player']]);
        $this->actingAs($user)->put(route('bitcraft.player.update'), ['entityId' => '123']);
        $this->get(route('bitcraft.activity.setup', ['source' => 'default']))->assertInertia(fn (Assert $page) => $page->where('filters.character', '123'));
        $this->assertSame('Old player', $profile->fresh()->settings['character']);
        $this->get(route('bitcraft.activity', ['source' => 'default']))->assertInertia(fn (Assert $page) => $page->where('filters.character', 'Old player'));
    }

    public function test_selection_endpoints_require_bitcraft_access(): void
    {
        $this->putJson(route('bitcraft.player.update'), ['entityId' => '123'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->put(route('bitcraft.player.update'), ['entityId' => '123'])->assertRedirect();
        Http::assertNothingSent();
    }
}
