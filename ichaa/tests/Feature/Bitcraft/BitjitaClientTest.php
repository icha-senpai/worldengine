<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Exceptions\BitjitaRefreshDelayed;
use App\Domain\Bitcraft\Services\BitjitaClient;
use App\Domain\Bitcraft\Services\BitjitaRequestBudget;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitjitaClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['services.bitjita.requests_per_minute' => 200, 'services.bitjita.user_requests_per_minute' => 150]);
        $this->actor(1);
        $this->freezeTime();
    }

    public function test_player_endpoints_share_fresh_cache_across_clients_and_users(): void
    {
        Http::fake(['bitjita.com/*' => Http::response(['value' => 1])]);
        $first = app(BitjitaClient::class);
        $first->players('icha');
        $first->player('123');
        $first->playerInventories('123');
        $first->playerPassiveCrafts('123');
        $this->actor(2);
        $second = app(BitjitaClient::class);
        $second->players('icha');
        $second->player('123');
        $second->playerInventories('123');
        $second->playerPassiveCrafts('123');
        Http::assertSentCount(4);
        $this->assertSame(4, app(BitjitaRequestBudget::class)->usage()['today']);

        $this->travel(15)->seconds();
        app(BitjitaClient::class)->player('123');
        Http::assertSentCount(5);
    }

    public function test_user_budget_leaves_capacity_for_another_user(): void
    {
        config(['services.bitjita.user_requests_per_minute' => 1]);
        Http::fake(['bitjita.com/*' => Http::response([])]);
        app(BitjitaClient::class)->player('1');
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('2'), 60);
        $this->actor(2);
        app(BitjitaClient::class)->player('2');
        Http::assertSentCount(2);
    }

    public function test_anonymous_widgets_share_an_ip_budget_and_cannot_change_it_with_user_query(): void
    {
        config(['services.bitjita.user_requests_per_minute' => 1]);
        Http::fake(['bitjita.com/*' => Http::response([])]);
        $this->actor(null, '192.0.2.1');
        app(BitjitaClient::class)->player('1');
        request()->merge(['user' => 999]);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('2'), 60);
        $this->actor(null, '192.0.2.2');
        app(BitjitaClient::class)->player('2');
        Http::assertSentCount(2);
    }

    public function test_global_budget_is_shared_across_users_and_uses_a_rolling_window(): void
    {
        config(['services.bitjita.requests_per_minute' => 2]);
        Http::fake(['bitjita.com/*' => Http::response([])]);
        app(BitjitaClient::class)->player('1');
        $this->travel(30)->seconds();
        $this->actor(2);
        app(BitjitaClient::class)->player('2');
        $this->actor(3);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('3'), 30);
        $this->travel(30)->seconds();
        app(BitjitaClient::class)->player('3');
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('4'), 30);
        Http::assertSentCount(3);
    }

    public function test_building_pool_respects_budget_and_retains_successful_work_for_retry(): void
    {
        config(['services.bitjita.requests_per_minute' => 2]);
        Http::fake(['bitjita.com/*' => Http::response(['buildings' => []])]);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->claimBuildingsMany(['1', '2', '3']), 60);
        Http::assertSentCount(2);
        $this->travel(60)->seconds();
        $this->assertCount(3, app(BitjitaClient::class)->claimBuildingsMany(['1', '2', '3']));
        Http::assertSentCount(3);
    }

    public function test_listing_pages_and_stall_pages_all_consume_budget(): void
    {
        Http::fake([
            'bitjita.com/api/claims/*/market/listings*' => Http::response(['listings' => [], 'totalPages' => 3]),
            'bitjita.com/api/stalls*' => Http::response(['stalls' => [], 'totalPages' => 3]),
        ]);
        app(BitjitaClient::class)->claimMarketListings('1');
        app(BitjitaClient::class)->stalls();
        Http::assertSentCount(6);
        $this->assertSame(6, app(BitjitaRequestBudget::class)->usage()['lastMinute']);
    }

    public function test_large_listing_search_can_finish_using_successful_pages_from_the_previous_attempt(): void
    {
        config(['services.bitjita.requests_per_minute' => 2]);
        Http::fake(['bitjita.com/*' => Http::response(['listings' => [], 'totalPages' => 3])]);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->claimMarketListings('1'), 60);
        Http::assertSentCount(2);
        $this->travel(60)->seconds();
        $client = app(BitjitaClient::class);
        $this->assertSame(3, $client->claimMarketListings('1')['totalPages']);
        $this->assertTrue($client->refreshStatus()['delayed']);
        Http::assertSentCount(4);
    }

    public function test_connection_failures_use_stale_data_and_release_locks(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            return match (++$attempt) {
                1 => Http::response(['player' => ['entityId' => '1']]),
                2 => Http::failedConnection(),
                default => Http::response(['player' => ['entityId' => '1', 'xp' => 50]]),
            };
        });
        $payload = app(BitjitaClient::class)->player('1');
        $this->travel(15)->seconds();
        $client = app(BitjitaClient::class);
        $this->assertSame($payload, $client->player('1'));
        $this->assertTrue($client->refreshStatus()['delayed']);
        $this->assertSame(50, app(BitjitaClient::class)->player('1')['player']['xp']);
    }

    public function test_cached_data_remains_accessible_when_budget_is_exhausted(): void
    {
        config(['services.bitjita.requests_per_minute' => 1]);
        Http::fake(['bitjita.com/*' => Http::response(['player' => ['entityId' => '1']])]);
        $payload = app(BitjitaClient::class)->player('1');
        $updatedAt = now()->toIso8601String();
        $this->travel(15)->seconds();
        $client = app(BitjitaClient::class);
        $this->assertSame($payload, $client->player('1'));
        $this->assertSame(['delayed' => true, 'retryAfter' => 45, 'updatedAt' => $updatedAt], $client->refreshStatus());
        Http::assertSentCount(1);
    }

    public function test_429_sets_shared_cooldown_honors_retry_after_and_recovers(): void
    {
        Http::fakeSequence()->push(['player' => ['entityId' => '1']])->push([], 429, ['Retry-After' => '90'])->push(['player' => ['entityId' => '2']]);
        $payload = app(BitjitaClient::class)->player('1');
        $this->travel(15)->seconds();
        $client = app(BitjitaClient::class);
        $this->assertSame($payload, $client->player('1'));
        $this->assertSame(90, $client->refreshStatus()['retryAfter']);
        $this->actor(2);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('2'), 90);
        Http::assertSentCount(2);
        $this->travel(90)->seconds();
        app(BitjitaClient::class)->player('2');
        Http::assertSentCount(3);
    }

    public function test_retry_after_accepts_http_dates(): void
    {
        Http::fake(['bitjita.com/*' => Http::response([], 429, ['Retry-After' => now()->addSeconds(120)->toRfc7231String()])]);
        $this->assertDelayed(fn () => app(BitjitaClient::class)->player('1'), 120);
    }

    public function test_held_response_lock_prevents_duplicate_cold_requests(): void
    {
        Http::fake(['bitjita.com/*' => Http::response([])]);
        $client = app(BitjitaClient::class);
        $lock = Cache::lock('bitjita:responses.v1:'.$client->applicationCacheKey('get.api/players/1.'.md5('')).':lock', 30);
        $this->assertTrue($lock->get());

        try {
            $this->assertDelayed(fn () => $client->player('1'), 2);
            Http::assertNothingSent();
            $this->assertSame(0, app(BitjitaRequestBudget::class)->usage()['today']);
        } finally {
            $lock->release();
        }

        app(BitjitaClient::class)->player('1');
        Http::assertSentCount(1);
    }

    public function test_transient_failure_uses_stale_data_but_404_does_not(): void
    {
        Http::fakeSequence()->push(['player' => ['entityId' => '1']])->push([], 503)->push([], 404);
        $payload = app(BitjitaClient::class)->player('1');
        $this->travel(15)->seconds();
        $this->assertSame($payload, app(BitjitaClient::class)->player('1'));
        $this->expectException(RequestException::class);
        app(BitjitaClient::class)->player('1');
    }

    public function test_stale_data_is_not_kept_indefinitely(): void
    {
        config(['services.bitjita.stale_cache_seconds' => 30]);
        Http::fakeSequence()->push(['player' => ['entityId' => '1']])->push([], 503);
        app(BitjitaClient::class)->player('1');
        $this->travel(46)->seconds();
        $this->expectException(RequestException::class);
        app(BitjitaClient::class)->player('1');
    }

    public function test_identity_headers_and_cache_separation_are_preserved(): void
    {
        config(['services.bitjita.identity' => 'first', 'services.bitjita.token' => 'test-token']);
        Http::fake(['bitjita.com/*' => Http::response([])]);
        app(BitjitaClient::class)->regions();
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('x-bitjita-identity', 'first') && $request->hasHeader('Authorization', 'Bearer test-token'));
        config(['services.bitjita.identity' => 'second']);
        app(BitjitaClient::class)->regions();
        Http::assertSentCount(2);
        $this->assertSame(2, app(BitjitaRequestBudget::class)->usage()['today']);
    }

    public function test_usage_is_grouped_by_tool_and_command_reports_it(): void
    {
        request()->setRouteResolver(fn () => (new Route('GET', 'activity/snapshot', fn () => null))->name('bitcraft.activity.snapshot'));
        Http::fake(['bitjita.com/*' => Http::response([])]);
        app(BitjitaClient::class)->player('1');
        $this->assertSame(['bitcraft.activity.snapshot' => 1], app(BitjitaRequestBudget::class)->usage()['byTool']);
        $this->artisan('bitcraft:bitjita-usage')->expectsOutputToContain('1/200')->assertSuccessful();
    }

    private function actor(?int $id, string $ip = '192.0.2.1'): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $id === null ? null : new GenericUser(['id' => $id]));
    }

    private function assertDelayed(callable $callback, int $retryAfter): void
    {
        try {
            $callback();
            $this->fail('Expected a delayed refresh.');
        } catch (BitjitaRefreshDelayed $exception) {
            $this->assertSame($retryAfter, $exception->retryAfter);
        }
    }
}
