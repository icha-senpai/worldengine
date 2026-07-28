<?php

namespace Tests\Feature\Bitcraft;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BitcraftSettlementProjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_settlement_projects_tracks_ba_sing_se_donations_and_craft_contributors(): void
    {
        Http::fake([
            'https://bitjita.com/api/empires/720575940379279361/claims' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363891',
                    'name' => 'Ba Sing Se',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'tier' => 5,
                ]],
            ]),
            'https://bitjita.com/api/empires?*' => Http::response([
                'empires' => [[
                    'entityId' => '720575940379279361',
                    'name' => 'Earth Kingdom',
                ]],
            ]),
            'https://bitjita.com/api/claims/288230376165363891/buildings' => Http::response([
                [
                    'entityId' => '864691128500984069',
                    'buildingName' => 'Sturdy Barter Stall',
                    'buildingNickname' => 'Donations',
                    'functions' => [[
                        'storage_slots' => 20,
                        'cargo_slots' => 4,
                    ]],
                ],
                [
                    'entityId' => '864691128500984070',
                    'buildingName' => 'Basic Workbench',
                    'buildingNickname' => 'Workshop',
                ],
            ]),
            'https://bitjita.com/api/claims/288230376165363891/inventories' => Http::response([
                'buildings' => [[
                    'entityId' => '864691128500984069',
                    'buildingName' => 'Sturdy Barter Stall',
                    'buildingNickname' => 'Donations',
                    'inventory' => [[
                        'locked' => false,
                        'volume' => 10000,
                        'contents' => [
                            'item_id' => 2020003,
                            'item_type' => 'item',
                            'quantity' => 200,
                        ],
                    ]],
                ]],
            ]),
            'https://bitjita.com/api/claims/288230376165363891/construction' => Http::response([
                'projects' => [[
                    'entityId' => 'construction-1',
                    'buildingName' => 'Guild Hall',
                    'progressPercent' => 42,
                ]],
                'items' => [[
                    'itemId' => 2020003,
                    'itemName' => 'Simple Plank',
                    'quantity' => 200,
                    'currentQuantity' => 120,
                ]],
                'cargos' => [[
                    'cargoId' => 1201,
                    'cargoName' => 'Simple Timber',
                    'quantity' => 10,
                    'currentQuantity' => 4,
                ]],
            ]),
            'https://bitjita.com/api/crafts/craft-1/contributions' => Http::response([
                'contributors' => [
                    [
                        'playerEntityId' => 'player-1',
                        'playerName' => 'Suki',
                        'progress' => 40,
                        'percentage' => 40,
                        'contributionCount' => 2,
                    ],
                    [
                        'playerEntityId' => 'player-2',
                        'playerName' => 'Bumi',
                        'progress' => 60,
                        'percentage' => 60,
                        'contributionCount' => 3,
                    ],
                ],
            ]),
            'https://bitjita.com/api/crafts?*' => Http::response([
                'craftResults' => [[
                    'entityId' => 'craft-1',
                    'recipeName' => 'Saw Simple Planks',
                    'buildingName' => 'Basic Workbench',
                    'buildingNickname' => 'Workshop',
                    'completed' => false,
                ]],
            ]),
            'https://bitjita.com/api/logs/storage*' => Http::response([
                'logs' => [[
                    'id' => 'storage-log-1',
                    'objectEntityId' => '864691128500984069',
                    'subjectEntityId' => 'player-1',
                    'subjectName' => 'Suki',
                    'subjectType' => 'player',
                    'data' => [
                        'type' => 'deposit_item',
                        'item_id' => 2020003,
                        'quantity' => 200,
                        'item_type' => 'item',
                    ],
                    'timestamp' => '2026-07-27T10:00:00Z',
                    'building' => [
                        'entityId' => '864691128500984069',
                        'buildingName' => 'Sturdy Barter Stall',
                        'buildingNickname' => 'Donations',
                    ],
                ]],
                'items' => [[
                    'id' => 2020003,
                    'name' => 'Simple Plank',
                ]],
                'cargos' => [],
            ]),
        ]);

        $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.settlement-projects'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/SettlementProjects')
                ->where('filters.empire', 'Earth Kingdom')
                ->where('filters.claimQ', 'Ba Sing Se')
                ->where('state.empire.name', 'Earth Kingdom')
                ->where('state.claim.name', 'Ba Sing Se')
                ->where('state.buildings.1.buildingNickname', 'Workshop')
                ->where('state.donationBuildings.0.buildingNickname', 'Donations')
                ->where('state.selectedBuildings.0.buildingNickname', 'Donations')
                ->where('state.storageLogs.0.playerName', 'Suki')
                ->where('state.storageLogs.0.playerEntityId', 'player-1')
                ->where('state.storageLogs.0.itemName', 'Simple Plank')
                ->where('state.storageLogs.0.buildingNickname', 'Donations')
                ->where('state.construction.requirements.0.name', 'Simple Plank')
                ->where('state.crafts.0.name', 'Saw Simple Planks')
                ->where('state.crafts.0.contributors.0.playerName', 'Bumi')
                ->where('state.contributors.0.playerName', 'Suki')
                ->where('state.metrics.buildingCount', 2)
                ->where('state.metrics.donationBuildingCount', 1)
                ->where('state.metrics.selectedBuildingCount', 1)
                ->where('error', null)
            );

        $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.settlement-projects', [
                'buildingEntityIds' => '864691128500984070',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.buildingEntityIds.0', '864691128500984070')
                ->where('state.selectedBuildings.0.buildingNickname', 'Workshop')
                ->where('state.metrics.selectedBuildingCount', 1)
            );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/logs/storage')
            && $request['buildingEntityId'] === '864691128500984070');
    }
}
