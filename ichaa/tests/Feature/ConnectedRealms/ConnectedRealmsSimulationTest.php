<?php

namespace Tests\Feature\ConnectedRealms;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketListing;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayerSkill;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsSimulationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedRealmsSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rico_and_kye_simulation_creates_specialized_market_sellers(): void
    {
        $summary = app(ConnectedRealmsSimulationService::class)->simulateRicoAndKye(2, true);

        $this->assertArrayHasKey('rico', $summary['personas']);
        $this->assertArrayHasKey('kye', $summary['personas']);
        $this->assertGreaterThan(20, $summary['personas']['rico']['actions_completed']);
        $this->assertGreaterThan(20, $summary['personas']['kye']['actions_completed']);
        $this->assertContains('Candlemark Tidehook Rod', $summary['personas']['rico']['tools_bought']);
        $this->assertContains('Candlemark Seedwake Cultivator', $summary['personas']['rico']['tools_bought']);
        $this->assertContains('Candlemark Snarefang Trap Kit', $summary['personas']['kye']['tools_bought']);
        $this->assertContains('Candlemark Mosskeeper Satchel', $summary['personas']['kye']['tools_bought']);
        $this->assertGreaterThanOrEqual(3, count($summary['personas']['rico']['active_listings']));
        $this->assertGreaterThanOrEqual(3, count($summary['personas']['kye']['active_listings']));

        $this->assertTrue(User::query()->where('email', 'rico@evergather.local')->firstOrFail()->canAccessConnectedRealms());
        $this->assertTrue(User::query()->where('email', 'kye@evergather.local')->firstOrFail()->canAccessConnectedRealms());

        $rico = ConnectedRealmsPlayer::query()->where('display_name', 'Rico')->firstOrFail();
        $kye = ConnectedRealmsPlayer::query()->where('display_name', 'Kye')->firstOrFail();

        foreach (['farming', 'fishing', 'cooking'] as $skill) {
            $this->assertDatabaseHas('connected_realms_player_skills', [
                'player_id' => $rico->id,
                'skill' => $skill,
            ]);
            $this->assertGreaterThan(0, ConnectedRealmsPlayerSkill::query()
                ->where('player_id', $rico->id)
                ->where('skill', $skill)
                ->value('experience'));
        }

        foreach (['hunting', 'foraging', 'combat', 'slayer'] as $skill) {
            $this->assertDatabaseHas('connected_realms_player_skills', [
                'player_id' => $kye->id,
                'skill' => $skill,
            ]);
            $this->assertGreaterThan(0, ConnectedRealmsPlayerSkill::query()
                ->where('player_id', $kye->id)
                ->where('skill', $skill)
                ->value('experience'));
        }

        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $rico->id,
            'slot' => 'tool_fishing',
            'item_key' => 'candlemark_tidehook_rod',
        ]);
        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $rico->id,
            'slot' => 'tool_farming',
            'item_key' => 'candlemark_seedwake_cultivator',
        ]);
        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $kye->id,
            'slot' => 'tool_hunting',
            'item_key' => 'candlemark_snarefang_trap_kit',
        ]);
        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $kye->id,
            'slot' => 'tool_foraging',
            'item_key' => 'candlemark_mosskeeper_satchel',
        ]);

        $this->assertGreaterThanOrEqual(3, ConnectedRealmsMarketListing::query()
            ->where('seller_player_id', $rico->id)
            ->where('status', ConnectedRealmsMarketListing::STATUS_ACTIVE)
            ->count());
        $this->assertGreaterThanOrEqual(3, ConnectedRealmsMarketListing::query()
            ->where('seller_player_id', $kye->id)
            ->where('status', ConnectedRealmsMarketListing::STATUS_ACTIVE)
            ->count());
    }
}
