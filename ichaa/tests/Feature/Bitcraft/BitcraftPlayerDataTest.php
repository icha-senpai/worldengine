<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Exceptions\BitjitaRefreshDelayed;
use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Domain\Bitcraft\Services\BitjitaRequestBudget;
use App\Domain\Bitcraft\Services\BitjuiceClient;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;
use UnexpectedValueException;

class BitcraftPlayerDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'services.bitjuice.enabled' => true,
            'services.bitjuice.enabled_in_tests' => true,
            'services.bitjuice.requests_per_minute' => 200,
            'services.bitjuice.user_requests_per_minute' => 30,
            'services.bitjuice.player_cache_seconds' => 30,
            'services.bitjuice.failure_cooldown_seconds' => 30,
        ]);
        $this->skills();
        $this->actor(1);
        $this->freezeTime();
    }

    public function test_player_data_uses_bitjuice_and_adds_local_skills_without_bitjita_requests(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/players?q=Juice' => Http::response(['players' => [$this->player()['player']]]),
            'bitjuiceapi.deeznuts.chat/api/players/123' => Http::response($this->player()),
        ]);
        $service = app(BitcraftPlayerData::class);
        $this->assertSame('bitjuice', $service->players('Juice')['source']);
        $player = $service->player('123');
        $this->assertSame('bitjuice', $player['source']);
        $this->assertSame('Mining', $player['player']['skillMap'][0]['name']);
        $this->assertSame(5, $player['player']['skillMap'][0]['id']);
        $this->assertSame('bitjuice', $service->refreshStatus()['provider']);
        $this->assertFalse($service->refreshStatus()['fallback']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'bitjita.com'));
    }

    public function test_delay_messages_keep_retry_timing_without_advertising_the_provider(): void
    {
        foreach (['bitjuice', 'bitjita'] as $provider) {
            $exception = new BitjitaRefreshDelayed(60, $provider);
            $this->assertSame(60, $exception->retryAfter);
            $this->assertStringStartsWith('Refresh delayed.', $exception->getMessage());
            $this->assertDoesNotMatchRegularExpression('/bitjuice|bitjita/i', $exception->getMessage());
        }
    }

    public function test_fresh_caches_are_shared_across_users_and_expire(): void
    {
        Http::fake(['bitjuiceapi.deeznuts.chat/api/players/123' => Http::response($this->player())]);
        app(BitcraftPlayerData::class)->player('123');
        $this->actor(2);
        app(BitcraftPlayerData::class)->player('123');
        Http::assertSentCount(1);
        $this->travel(30)->seconds();
        app(BitcraftPlayerData::class)->player('123');
        Http::assertSentCount(2);
    }

    public function test_valid_empty_search_inventory_and_crafts_do_not_trigger_fallback(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/players?q=nobody' => Http::response(['players' => []]),
            'bitjuiceapi.deeznuts.chat/api/players/123/inventories' => Http::response(['inventories' => [], 'items' => [], 'cargos' => []]),
            'bitjuiceapi.deeznuts.chat/api/players/123/passive-crafts' => Http::response(['craftResults' => [], 'items' => []]),
        ]);
        $service = app(BitcraftPlayerData::class);
        $this->assertSame([], $service->players('nobody')['players']);
        $this->assertSame([], $service->playerInventories('123')['inventories']);
        $this->assertSame([], $service->playerPassiveCrafts('123')['craftResults']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'bitjita.com'));
    }

    public function test_server_failure_cools_down_bitjuice_across_users_and_endpoints(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response([], 503),
            'bitjita.com/api/players/123' => Http::response($this->player()),
            'bitjita.com/api/players/456' => Http::response($this->player('456')),
        ]);
        $first = app(BitcraftPlayerData::class);
        $this->assertSame('bitjita', $first->player('123')['source']);
        $this->assertTrue($first->refreshStatus()['fallback']);
        $this->assertFalse($first->refreshStatus()['delayed']);
        $this->assertSame(30, $first->refreshStatus()['primaryRetryAfter']);
        $this->actor(2);
        app(BitcraftPlayerData::class)->player('456');
        app(BitcraftPlayerData::class)->player('123');
        Http::assertSentCount(3);
        $this->assertSame(1, app(BitjuiceClient::class)->usage()['lastMinute']);
        $this->assertSame(2, app(BitjitaRequestBudget::class)->usage()['lastMinute']);
    }

    public function test_connection_failure_uses_bitjita(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::failedConnection(),
            'bitjita.com/api/players/123' => Http::response($this->player()),
        ]);
        $service = app(BitcraftPlayerData::class);
        $this->assertSame('bitjita', $service->player('123')['source']);
        $this->assertTrue($service->refreshStatus()['fallback']);
        $this->assertSame(30, app(BitjuiceClient::class)->usage()['retryAfter']);
    }

    public function test_missing_response_fields_trigger_fallback_instead_of_fake_empty_data(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response([]),
            'bitjita.com/api/players/123/inventories' => Http::response(['inventories' => [], 'items' => [], 'cargos' => []]),
        ]);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->playerInventories('123')['source']);
        Http::assertSentCount(2);
    }

    public function test_wrong_player_identity_triggers_fallback(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response($this->player('456')),
            'bitjita.com/api/players/123' => Http::response($this->player()),
        ]);
        $this->assertSame('123', app(BitcraftPlayerData::class)->player('123')['player']['entityId']);
        Http::assertSentCount(2);
    }

    public function test_numeric_retry_after_is_shared_and_does_not_cool_down_bitjita(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response([], 429, ['Retry-After' => '90']),
            'bitjita.com/api/players/123' => Http::response($this->player()),
        ]);
        app(BitcraftPlayerData::class)->player('123');
        $this->assertSame(90, app(BitjuiceClient::class)->usage()['retryAfter']);
        $this->assertSame(0, app(BitjitaRequestBudget::class)->usage()['retryAfter']);
        app(BitcraftPlayerData::class)->player('123');
        Http::assertSentCount(2);
    }

    public function test_http_date_retry_after_recovers_after_the_cooldown(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::sequence()
                ->push([], 429, ['Retry-After' => now()->addSeconds(45)->toRfc7231String()])
                ->push($this->player('456')),
            'bitjita.com/api/players/123' => Http::response($this->player()),
        ]);
        app(BitcraftPlayerData::class)->player('123');
        $this->assertSame(45, app(BitjuiceClient::class)->usage()['retryAfter']);
        $this->travel(45)->seconds();
        $this->assertSame('bitjuice', app(BitcraftPlayerData::class)->player('456')['source']);
        Http::assertSentCount(3);
    }

    public function test_a_missing_player_only_cools_down_that_cache_key(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/players/123' => Http::response([], 404),
            'bitjuiceapi.deeznuts.chat/api/players/456' => Http::response($this->player('456')),
            'bitjita.com/api/players/123' => Http::response($this->player()),
        ]);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->player('123')['source']);
        $this->assertSame('bitjuice', app(BitcraftPlayerData::class)->player('456')['source']);
        $this->assertSame(0, app(BitjuiceClient::class)->usage()['retryAfter']);
        Http::assertSentCount(3);
    }

    public function test_bitjuice_budget_exhaustion_does_not_consume_bitjita_capacity_until_fallback(): void
    {
        config(['services.bitjuice.requests_per_minute' => 1]);
        Http::fake([
            'bitjuiceapi.deeznuts.chat/api/players/123' => Http::response($this->player()),
            'bitjita.com/api/players/456' => Http::response($this->player('456')),
        ]);
        $this->assertSame('bitjuice', app(BitcraftPlayerData::class)->player('123')['source']);
        $this->assertSame(0, app(BitjitaRequestBudget::class)->usage()['today']);
        $this->actor(2);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->player('456')['source']);
        Http::assertSentCount(2);
        $this->assertSame(1, app(BitjitaRequestBudget::class)->usage()['today']);
    }

    public function test_stale_bitjuice_data_survives_failure_of_both_providers(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::sequence()->push($this->player())->push([], 503),
            'bitjita.com/*' => Http::response([], 503),
        ]);
        $first = app(BitcraftPlayerData::class);
        $first->player('123');
        $sampledAt = $first->refreshStatus()['updatedAt'];
        $this->travel(30)->seconds();
        $second = app(BitcraftPlayerData::class);
        $this->assertSame('bitjuice', $second->player('123')['source']);
        $this->assertTrue($second->refreshStatus()['delayed']);
        $this->assertSame($sampledAt, $second->refreshStatus()['updatedAt']);
        Http::assertSentCount(3);
    }

    public function test_fresh_bitjita_fallback_is_used_instead_of_stale_bitjuice_data(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::sequence()->push($this->player())->push([], 503),
            'bitjita.com/*' => Http::response($this->player()),
        ]);
        app(BitcraftPlayerData::class)->player('123');
        $this->travel(30)->seconds();
        $second = app(BitcraftPlayerData::class);
        $this->assertSame('bitjita', $second->player('123')['source']);
        $this->assertFalse($second->refreshStatus()['delayed']);
        $this->assertSame(now()->toIso8601String(), $second->refreshStatus()['updatedAt']);
    }

    public function test_cache_lock_contention_does_not_duplicate_primary_requests(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response($this->player()),
            'bitjita.com/*' => Http::response([], 503),
        ]);
        app(BitcraftPlayerData::class)->player('123');
        $this->travel(30)->seconds();
        $key = 'bitjuice:responses.v1:'.md5(config('services.bitjuice.base_url').'|api/players/123|[]');
        $lock = Cache::lock($key.':lock', 15);
        $this->assertTrue($lock->get());

        try {
            $service = app(BitcraftPlayerData::class);
            $this->assertSame('bitjuice', $service->player('123')['source']);
            $this->assertSame(2, $service->refreshStatus()['retryAfter']);
            Http::assertSentCount(2);
        } finally {
            $lock->release();
        }
    }

    public function test_missing_local_skill_metadata_preserves_bitjita_player_contract(): void
    {
        $this->skills([]);
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response($this->player()),
            'bitjita.com/*' => Http::response($this->player()),
        ]);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->player('123')['source']);
        $this->assertSame(0, app(BitjuiceClient::class)->usage()['retryAfter']);
    }

    public function test_disabling_bitjuice_keeps_existing_bitjita_behavior(): void
    {
        config(['services.bitjuice.enabled' => false]);
        Http::fake(['bitjita.com/*' => Http::response($this->player())]);
        $service = app(BitcraftPlayerData::class);
        $this->assertSame('bitjita', $service->player('123')['source']);
        $this->assertFalse($service->refreshStatus()['fallback']);
        Http::assertSentCount(1);
    }

    public function test_both_providers_failing_without_cache_does_not_return_an_empty_success(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response([], 503),
            'bitjita.com/*' => Http::response([], 503),
        ]);
        $this->expectException(RequestException::class);
        app(BitcraftPlayerData::class)->player('123');
    }

    public function test_incomplete_inventory_entries_trigger_fallback(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response(['inventories' => [['entityId' => '123']], 'items' => [], 'cargos' => []]),
            'bitjita.com/*' => Http::response(['inventories' => [], 'items' => [], 'cargos' => []]),
        ]);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->playerInventories('123')['source']);
        Http::assertSentCount(2);
    }

    public function test_invalid_fallback_data_is_not_an_empty_success_and_has_shared_backoff(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::response([], 503),
            'bitjita.com/*' => Http::response([]),
        ]);
        $this->expectException(UnexpectedValueException::class);

        try {
            app(BitcraftPlayerData::class)->playerPassiveCrafts('123');
        } finally {
            $this->assertSame(15, app(BitjitaRequestBudget::class)->usage()['retryAfter']);
        }
    }

    public function test_newer_stale_bitjuice_data_is_preferred_to_older_stale_fallback_data(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::sequence()->push([], 503)->push($this->player())->push([], 503),
            'bitjita.com/*' => Http::sequence()->push($this->player())->push([], 503),
        ]);
        $this->assertSame('bitjita', app(BitcraftPlayerData::class)->player('123')['source']);
        $this->travel(30)->seconds();
        $fresh = app(BitcraftPlayerData::class);
        $this->assertSame('bitjuice', $fresh->player('123')['source']);
        $timestamp = $fresh->refreshStatus()['updatedAt'];
        $this->travel(30)->seconds();
        $stale = app(BitcraftPlayerData::class);
        $this->assertSame('bitjuice', $stale->player('123')['source']);
        $this->assertTrue($stale->refreshStatus()['delayed']);
        $this->assertSame($timestamp, $stale->refreshStatus()['updatedAt']);
        Http::assertSentCount(5);
    }

    public function test_expired_stale_data_does_not_hide_a_complete_provider_failure(): void
    {
        Http::fake([
            'bitjuiceapi.deeznuts.chat/*' => Http::sequence()->push($this->player())->push([], 503),
            'bitjita.com/*' => Http::response([], 503),
        ]);
        app(BitcraftPlayerData::class)->player('123');
        $this->travel(330)->seconds();
        $this->expectException(RequestException::class);
        app(BitcraftPlayerData::class)->player('123');
    }

    public function test_bitjuice_usage_command_reports_the_separate_request_budget(): void
    {
        Http::fake(['bitjuiceapi.deeznuts.chat/*' => Http::response($this->player())]);
        app(BitcraftPlayerData::class)->player('123');
        $this->artisan('bitcraft:bitjuice-usage')
            ->expectsOutput('Outgoing attempts in the last 60 seconds: 1/200')
            ->expectsOutput('Per-user allowance: 30/60 seconds; anonymous widgets are grouped by IP.')
            ->assertExitCode(0);
        $this->assertSame(0, app(BitjitaRequestBudget::class)->usage()['today']);
        Http::assertSentCount(1);
    }

    private function player(string $id = '123'): array
    {
        return ['player' => ['entityId' => $id, 'username' => 'Juice', 'signedIn' => false, 'experience' => [['skill_id' => 5, 'quantity' => 100]]]];
    }

    private function skills(?array $skills = null): void
    {
        $skills ??= [['id' => 5, 'name' => 'Mining', 'title' => 'Miner']];
        $this->mock(BitcraftSpacetimeStaticData::class, function (MockInterface $mock) use ($skills): void {
            $mock->shouldReceive('skillMap')->andReturn($skills);
        });
    }

    private function actor(int $id): void
    {
        $request = Request::create('/bitcraft-test');
        $request->setUserResolver(fn (): GenericUser => new GenericUser(['id' => $id]));
        $this->app->instance('request', $request);
    }
}
