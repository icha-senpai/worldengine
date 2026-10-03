<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BitcraftHuntingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_authorized_users_receive_the_level_thresholds(): void
    {
        $levels = [['level' => 37, 'xp' => 227130], ['level' => 38, 'xp' => 253930]];
        $this->mock(BitcraftPlayerData::class, fn (MockInterface $mock) => $mock->shouldReceive('experienceLevels')->once()->andReturn($levels));

        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.hunting-calculator'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bitcraft/HuntingCalculator')
            ->where('levels', $levels)->where('error', null));
    }

    public function test_unavailable_thresholds_render_a_recoverable_error(): void
    {
        $this->mock(BitcraftPlayerData::class, fn (MockInterface $mock) => $mock->shouldReceive('experienceLevels')->once()->andThrow(new RuntimeException('Private upstream details')));

        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.hunting-calculator'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bitcraft/HuntingCalculator')
            ->where('levels', [])->where('error', 'Level thresholds are unavailable. Reload to try again.'));
    }

    public function test_empty_thresholds_are_reported(): void
    {
        $this->mock(BitcraftPlayerData::class, fn (MockInterface $mock) => $mock->shouldReceive('experienceLevels')->once()->andReturn([]));

        $this->actingAs($this->createVerifiedAdminUser())->get(route('bitcraft.hunting-calculator'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('levels', [])->where('error', 'Level thresholds are unavailable. Reload to try again.'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('bitcraft.hunting-calculator'))->assertRedirect(route('login'));
    }

    public function test_calculator_inherits_the_existing_auth_and_verified_middleware(): void
    {
        $middleware = Route::getRoutes()->getByName('bitcraft.hunting-calculator')->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
    }

    public function test_users_without_bitcraft_access_are_redirected(): void
    {
        $this->actingAs(User::factory()->create())->get(route('bitcraft.hunting-calculator'))->assertRedirect();
    }
}
