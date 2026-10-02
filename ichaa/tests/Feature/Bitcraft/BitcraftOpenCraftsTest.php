<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftOpenCrafts;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BitcraftOpenCraftsTest extends TestCase
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
    }

    public function test_public_crafts_show_remaining_and_full_xp_and_player_levels(): void
    {
        $this->fakeFeed($this->feed());
        $user = $this->createVerifiedAdminUser();
        $this->actingAs($user)->withSession($this->selection($user))->get(route('bitcraft.open-crafts'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bitcraft/OpenCrafts')
            ->where('bitcraft.player.username', 'Juice')->has('crafts', 1)
            ->where('crafts.0.name', 'Rough Plank')->where('crafts.0.remainingXp', 120)
            ->where('crafts.0.fullXp', 200)->where('crafts.0.currentLevel', 1)
            ->where('crafts.0.afterLevel', 3)->where('crafts.0.levelsGained', 2)
            ->where('crafts.0.fullLevel', 3)->where('crafts.0.meetsLevel', false)
            ->where('refresh.provider', 'bitjuice')->where('error', null));
        Http::assertSentCount(3);
    }

    public function test_closed_private_and_fully_progressed_crafts_are_excluded(): void
    {
        $feed = $this->feed();
        $craft = $feed['craftResults'][0];
        $feed['craftResults'] = [$craft, [...$craft, 'entityId' => '2', 'completed' => true], [...$craft, 'entityId' => '3', 'isPublic' => false], [...$craft, 'entityId' => '4', 'progress' => 100]];
        $rows = app(BitcraftOpenCrafts::class)->rows($feed, null, [], []);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['afterLevel']);
        $this->assertNull($rows[0]['meetsLevel']);
    }

    public function test_object_and_tuple_xp_formats_match_at_level_boundaries(): void
    {
        $object = $this->feed();
        $object['craftResults'][0]['experiencePerProgress'] = [['skill_id' => 2, 'quantity' => 2.00000001]];
        $object['craftResults'][0]['levelRequirements'] = [['skill_id' => 2, 'level' => 2]];
        $player = [...$this->player(), 'experience' => [['skill_id' => 2, 'quantity' => 80]]];
        $service = app(BitcraftOpenCrafts::class);
        $tupleRows = $service->rows($this->feed(), $player, $this->levels(), []);
        $objectRows = $service->rows($object, $player, $this->levels(), []);
        $this->assertSame($tupleRows, $objectRows);
        $this->assertSame(3, $objectRows[0]['afterLevel']);
    }

    public function test_whole_craft_is_distinct_from_remaining_work_and_caps_at_last_known_level(): void
    {
        $feed = $this->feed();
        $feed['craftResults'][0]['progress'] = 90;
        $feed['craftResults'][0]['experiencePerProgress'] = [[2, 5]];
        $row = app(BitcraftOpenCrafts::class)->rows($feed, $this->player(), $this->levels(), [])[0];
        $this->assertSame(2, $row['afterLevel']);
        $this->assertSame(4, $row['fullLevel']);
        $this->assertSame(1, $row['levelsGained']);
    }

    public function test_missing_xp_or_thresholds_does_not_manufacture_zero_or_levels(): void
    {
        $feed = $this->feed();
        unset($feed['craftResults'][0]['experiencePerProgress']);
        $row = app(BitcraftOpenCrafts::class)->rows($feed, $this->player(), $this->levels(), [])[0];
        $this->assertNull($row['remainingXp']);
        $this->assertNull($row['afterLevel']);
        $row = app(BitcraftOpenCrafts::class)->rows($this->feed(), $this->player(), [], [])[0];
        $this->assertSame(120.0, $row['remainingXp']);
        $this->assertNull($row['currentLevel']);
        $this->assertNull($row['afterLevel']);
    }

    public function test_craft_feed_cache_is_shared_between_site_users(): void
    {
        $this->fakeFeed($this->feed());
        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.open-crafts'))->assertOk();
        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.open-crafts'))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_malformed_primary_uses_bitjita_crafts(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/crafts' => Http::response(['craftResults' => [['entityId' => '1']]]),
            'bitjita.com/api/crafts' => Http::response($this->feed()),
        ]);
        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.open-crafts'))
            ->assertInertia(fn (Assert $page) => $page->has('crafts', 1)->where('refresh.provider', 'bitjita')->where('refresh.fallback', true)->where('error', null));
        Http::assertSentCount(2);
    }

    public function test_valid_empty_primary_does_not_fall_back(): void
    {
        Http::fake(['bitjuiceapi.deeznuts.chat/api/crafts' => Http::response(['craftResults' => []])]);
        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.open-crafts'))
            ->assertInertia(fn (Assert $page) => $page->has('crafts', 0)->where('error', null));
        Http::assertSentCount(1);
    }

    public function test_failures_are_reported_instead_of_claiming_no_open_crafts(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.open-crafts'))
            ->assertInertia(fn (Assert $page) => $page->has('crafts', 0)->where('error', 'Open crafts are temporarily unavailable. Try again shortly.'));
    }

    public function test_filtering_pagination_and_level_up_filters(): void
    {
        $feed = $this->feed();
        $feed['craftResults'] = array_map(fn (int $id): array => [...$feed['craftResults'][0], 'entityId' => (string) $id], range(1, 30));
        $this->fakeFeed($feed);
        $user = $this->createVerifiedAdminUser();
        $this->actingAs($user)->withSession($this->selection($user))->get(route('bitcraft.open-crafts', ['q' => 'plank', 'region' => 8, 'skill' => 2, 'mine' => 1, 'levelUps' => 1, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('crafts', 5)->where('pagination.total', 30)->where('pagination.page', 2));
        $this->get(route('bitcraft.open-crafts', ['meetsLevel' => 1]))->assertInertia(fn (Assert $page) => $page->has('crafts', 0));
    }

    public function test_guests_and_users_without_bitcraft_access_cannot_browse_crafts(): void
    {
        $this->get(route('bitcraft.open-crafts'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->get(route('bitcraft.open-crafts'))->assertRedirect();
        Http::assertNothingSent();
    }

    private function fakeFeed(array $feed): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/crafts' => Http::response($feed),
            'bitjuiceapi.deeznuts.chat/api/players/123' => Http::response(['player' => $this->player()]),
            'bitjita.com/static/experience/levels.json' => Http::response($this->levels()),
        ]);
    }

    private function feed(): array
    {
        return ['craftResults' => [[
            'entityId' => '100', 'recipeId' => 1, 'isPublic' => true, 'completed' => false,
            'totalActionsRequired' => 100, 'progress' => 40, 'craftCount' => 10,
            'experiencePerProgress' => [[2, 2]], 'levelRequirements' => [[2, 2]],
            'toolRequirements' => [[1, 1, 1]], 'craftedItem' => [['item_id' => 1, 'item_type' => 'item', 'quantity' => 1]],
            'claimName' => 'Juice Town', 'ownerUsername' => 'Juice', 'ownerEntityId' => '123', 'regionId' => 8,
        ]], 'items' => [['id' => 1, 'name' => 'Rough Plank', 'iconAssetName' => 'GeneratedIcons/Items/PlankUntreated']], 'skillMap' => [['id' => 2, 'name' => 'Carpentry']]];
    }

    private function player(): array
    {
        return ['entityId' => '123', 'username' => 'Juice', 'experience' => [['skill_id' => 2, 'quantity' => 90]]];
    }

    private function levels(): array
    {
        return [['level' => 1, 'xp' => 0], ['level' => 2, 'xp' => 100], ['level' => 3, 'xp' => 200], ['level' => 4, 'xp' => 400]];
    }

    private function selection(User $user): array
    {
        return ['bitcraft' => ['site_player' => [$user->id => ['entityId' => '123', 'username' => 'Juice']]]];
    }
}
