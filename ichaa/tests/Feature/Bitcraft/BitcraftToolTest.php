<?php

namespace Tests\Feature\Bitcraft;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BitcraftToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_market_finder_uses_market_api_for_selected_claim_trades(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([
                [
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                ],
                [
                    'regionId' => 12,
                    'regionName' => 'Elyndor',
                ],
            ]),
            'https://bitjita.com/api/claims?q=Jita*' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363891',
                    'name' => 'Jita',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'tier' => 5,
                    'locationX' => 1200,
                    'locationZ' => 1300,
                ]],
                'count' => 1,
            ]),
            'https://bitjita.com/api/market/item/1421716234*' => Http::response([
                'item' => [
                    'id' => '1421716234',
                    'name' => 'Astralite Pickaxe',
                    'tag' => 'Miner Tool',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                ],
                'sellOrders' => [[
                    'entityId' => 'order-1',
                    'ownerUsername' => 'Astra',
                    'claimEntityId' => '288230376165363891',
                    'claimName' => 'Jita',
                    'priceThreshold' => '1200',
                    'quantity' => '4',
                    'regionName' => 'Solace',
                ]],
                'buyOrders' => [],
                'stats' => [
                    'lowestSell' => 1200,
                    'highestBuy' => null,
                    'sellOrderCount' => 1,
                    'buyOrderCount' => 0,
                ],
            ]),
            'https://bitjita.com/api/claims/288230376165363891/buildings' => Http::response([
                'buildings' => [
                    [
                        'entityId' => '864691128500984069',
                        'buildingDescriptionId' => 3210,
                        'buildingName' => 'Sturdy Barter Stall',
                        'buildingNickname' => 'Astra Tools',
                        'level' => 3,
                        'functions' => [[
                            'trade_orders' => 30,
                            'storage_slots' => 20,
                        ]],
                    ],
                    [
                        'entityId' => '864691128500984070',
                        'buildingDescriptionId' => 100,
                        'buildingName' => 'Basic Workbench',
                        'functions' => [[
                            'crafting' => true,
                        ]],
                    ],
                    [
                        'entityId' => '864691128500984071',
                        'buildingDescriptionId' => 934683282,
                        'buildingName' => 'Town Market',
                        'functions' => [[
                            'trade_orders' => 50,
                        ]],
                    ],
                ],
            ]),
            'https://bitjita.com/api/claims/288230376165363892/buildings' => Http::response([
                'buildings' => [[
                    'entityId' => '864691128500984099',
                    'buildingDescriptionId' => 100,
                    'buildingName' => 'Basic Workbench',
                    'functions' => [[
                        'crafting' => true,
                    ]],
                ]],
            ]),
            'https://bitjita.com/api/claims/288230376165363891/inventories' => Http::response([
                'buildings' => [[
                    'entityId' => '864691128500984069',
                    'buildingDescriptionId' => 3210,
                    'buildingName' => 'Sturdy Barter Stall',
                    'buildingNickname' => 'Astra Tools Custom',
                    'inventory' => [],
                ]],
                'items' => [],
                'cargos' => [],
            ]),
            'https://bitjita.com/api/market*' => Http::response([
                'data' => [
                    'items' => [[
                        'id' => 1421716234,
                        'name' => 'Astralite Pickaxe',
                        'category' => 'Miner Tool',
                        'tier' => 5,
                        'rarityStr' => 'Rare',
                        'sellOrders' => 3,
                        'buyOrders' => 2,
                        'stats' => [
                            'lowestSellPrice' => 1200,
                            'highestBuyPrice' => 900,
                        ],
                    ]],
                    'categories' => ['Miner Tool'],
                    'metrics' => ['totalItems' => 1],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.market', [
                'q' => 'Astralite',
                'claimQ' => 'Jita',
                'claimEntityId' => '288230376165363891',
                'side' => 'sell',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'market')
                ->where('tool.title', 'Market Finder')
                ->where('filters.q', 'Astralite')
                ->where('filters.claimQ', 'Jita')
                ->where('filters.side', 'sell')
                ->where('filters.hasOrders', true)
                ->has('market.claims', 1)
                ->where('market.claims.0.name', 'Jita')
                ->where('market.claims.0.entityId', '288230376165363891')
                ->has('market.items', 1)
                ->where('market.items.0.name', 'Astralite Pickaxe')
                ->where('market.items.0.lowestSellPrice', 1200)
                ->where('market.items.0.sellOrderCount', 3)
                ->has('market.tradeBuildings', 0)
                ->where('market.categories.0', 'Miner Tool')
                ->where('market.orderBook', null)
                ->where('market.claim.name', 'Jita')
                ->has('market.listings', 0)
            );

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.market', [
                'q' => 'Astralite',
                'claimQ' => 'Jita',
                'claimEntityId' => '288230376165363891',
                'itemId' => 1421716234,
                'itemKind' => 'item',
                'side' => 'sell',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('market.orderBook.item.name', 'Astralite Pickaxe')
                ->where('market.orderBook.sellOrders.0.claimName', 'Jita')
                ->where('market.orderBook.sellOrders.0.price', '1200')
            );

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market?')
            && str_contains($request->url(), 'claimEntityId=288230376165363891')
            && str_contains($request->url(), 'hasOrders=1'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims')
            && str_contains($request->url(), 'q=Jita'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market/item/1421716234')
            && str_contains($request->url(), 'claimEntityId=288230376165363891')
            && $request->hasHeader('x-app-identifier'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims/288230376165363891/market/listings'));
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/claims/288230376165363891/buildings');
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/claims/288230376165363891/inventories');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/logs/storage'));
    }

    public function test_barter_stall_finder_filters_region_claim_search_to_claims_with_barter_stations(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([
                [
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                ],
                [
                    'regionId' => 12,
                    'regionName' => 'Elyndor',
                ],
            ]),
            'https://bitjita.com/api/claims?page=1*regionId=8*' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363892',
                    'name' => 'Workshop Only',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'tier' => 3,
                ]],
                'count' => 101,
            ]),
            'https://bitjita.com/api/claims?page=2*regionId=8*' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363891',
                    'name' => 'Jita',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'tier' => 5,
                ]],
                'count' => 101,
            ]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => '864691128500984069',
                    'ownerName' => 'Astra',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Omashu Tools',
                    'claimName' => 'Omashu',
                    'orderCount' => 30,
                    'orders' => [],
                ], [
                    'entityId' => '864691128500984099',
                    'ownerName' => 'Cabbage Man',
                    'regionId' => 12,
                    'regionName' => 'Elyndor',
                    'nickname' => 'Cabbage Cart',
                    'claimName' => 'Ba Sing Se',
                    'orderCount' => 3,
                    'orders' => [],
                ]],
                'totalStalls' => 2,
                'totalOrders' => 33,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'region' => 'Solmere',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->where('tool.title', 'Barter Stall Finder')
                ->where('filters.region', 'Solmere')
                ->where('filters.regionId', '8')
                ->where('filters.regionName', 'Solmere')
                ->has('market.claims', 1)
                ->where('market.claims.0.name', 'Omashu')
                ->where('market.claims.0.tradeBuildingCount', 1)
                ->where('market.claims.0.tradeOrderCount', 30)
                ->has('market.tradeBuildings', 1)
                ->where('market.tradeBuildings.0.buildingNickname', 'Omashu Tools')
                ->has('market.items', 0)
                ->has('market.listings', 0)
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market?'));
    }

    public function test_barter_stall_finder_item_search_uses_stalls_api_without_market_search(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => '864691128500984069',
                    'ownerName' => 'Astra',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Astra Tools',
                    'claimName' => 'Omashu',
                    'locationX' => 1200,
                    'locationZ' => 1500,
                    'orderCount' => 2,
                    'orders' => [[
                        'entityId' => 'stall-order-1',
                        'remainingStock' => 4,
                        'offerItems' => [[
                            'itemId' => 1421716234,
                            'itemName' => 'Astralite Pickaxe',
                            'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                            'quantity' => 1,
                        ]],
                        'requiredItems' => [[
                            'itemId' => 1,
                            'itemName' => 'Hex Coin',
                            'iconAssetName' => 'Items/HexCoin[,3,10,500]',
                            'quantity' => 1200,
                        ]],
                        'offerCargo' => [],
                        'requiredCargo' => [],
                    ], [
                        'entityId' => 'stall-order-2',
                        'remainingStock' => 8,
                        'offerItems' => [[
                            'itemId' => 1421716234,
                            'itemName' => 'Astralite Pickaxe',
                            'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                            'quantity' => 1,
                        ]],
                        'requiredItems' => [[
                            'itemId' => 999,
                            'itemName' => 'Jar of Dirt',
                            'iconAssetName' => 'GeneratedIcons/Items/JarDirt',
                            'quantity' => 3,
                        ]],
                        'offerCargo' => [],
                        'requiredCargo' => [],
                    ]],
                ]],
                'totalStalls' => 1,
                'totalOrders' => 2,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
            'https://bitjita.com/api/market*' => Http::response([
                'data' => [
                    'items' => [[
                        'id' => 1421716234,
                        'name' => 'Astralite Pickaxe',
                        'category' => 'Miner Tool',
                        'tier' => 5,
                        'sellOrders' => 3,
                        'buyOrders' => 2,
                    ]],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'q' => 'Astralite',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->where('filters.q', 'Astralite')
                ->has('market.items', 1)
                ->where('market.items.0.name', 'Astralite Pickaxe')
                ->where('market.items.0.lowestSellPrice', 1200)
                ->where('market.items.0.sellOrderCount', 1)
                ->has('market.listings', 1)
                ->where('market.listings.0.source', 'stall-order')
                ->where('market.listings.0.side', 'sell')
                ->where('market.listings.0.stall.name', 'Astra Tools')
                ->where('market.listings.0.price', 1200)
                ->where('market.listings.0.bundlePrice', 1200)
                ->where('market.listings.0.requiredSummary', '1,200x Hex Coin')
                ->has('market.claims', 1)
                ->where('market.claims.0.name', 'Omashu')
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
    }

    public function test_barter_stall_finder_region_item_search_groups_stall_orders(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([[
                'regionId' => 8,
                'regionName' => 'Solmere',
            ]]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [
                    [
                        'entityId' => '864691128500984069',
                        'ownerName' => 'Astra',
                        'regionId' => 8,
                        'regionName' => 'Solmere',
                        'nickname' => 'Astra Tools',
                        'claimName' => 'Omashu',
                        'locationX' => 1200,
                        'locationZ' => 1500,
                        'orderCount' => 1,
                        'orders' => [[
                            'entityId' => 'stall-order-1',
                            'remainingStock' => 4,
                            'offerItems' => [[
                                'itemId' => 1421716234,
                                'itemName' => 'Astralite Pickaxe',
                                'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                                'quantity' => 1,
                            ]],
                            'requiredItems' => [[
                                'itemId' => 1,
                                'itemName' => 'Hex Coin',
                                'iconAssetName' => 'Items/HexCoin[,3,10,500]',
                                'quantity' => 1200,
                            ]],
                            'offerCargo' => [],
                            'requiredCargo' => [],
                        ]],
                    ],
                    [
                        'entityId' => '864691128500984070',
                        'ownerName' => 'Astra',
                        'regionId' => 12,
                        'regionName' => 'Elyndor',
                        'nickname' => 'Wrong Region',
                        'claimName' => 'Ba Sing Se',
                        'orderCount' => 1,
                        'orders' => [[
                            'entityId' => 'stall-order-2',
                            'remainingStock' => 1,
                            'offerItems' => [[
                                'itemId' => 1421716234,
                                'itemName' => 'Astralite Pickaxe',
                                'quantity' => 1,
                            ]],
                            'requiredItems' => [],
                            'offerCargo' => [],
                            'requiredCargo' => [],
                        ]],
                    ],
                ],
                'totalStalls' => 2,
                'totalOrders' => 2,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'q' => 'Astralite',
                'region' => 'Solmere',
                'side' => 'sell',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->where('filters.q', 'Astralite')
                ->where('filters.region', 'Solmere')
                ->where('filters.regionId', '8')
                ->has('market.claims', 1)
                ->where('market.claims.0.name', 'Omashu')
                ->has('market.items', 1)
                ->where('market.items.0.name', 'Astralite Pickaxe')
                ->where('market.items.0.sellOrderCount', 1)
                ->has('market.listings', 1)
                ->where('market.listings.0.claimName', 'Omashu')
                ->where('market.listings.0.source', 'stall-order')
                ->where('market.listings.0.stall.name', 'Astra Tools')
                ->where('market.listings.0.price', 1200)
                ->where('market.listings.0.quantity', 4)
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/inventories'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims/288230376165363891/market/listings'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market'));
    }

    public function test_barter_stall_claim_listings_come_from_stall_orders_not_marketplace_orders(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([]),
            'https://bitjita.com/api/claims/288230376165363891' => Http::response([
                'claim' => [
                    'entityId' => '288230376165363891',
                    'name' => 'Jita',
                    'regionName' => 'Solmere',
                ],
            ]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => '864691128500984069',
                    'ownerName' => 'Astra',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Astra Tools',
                    'claimName' => 'Jita',
                    'orderCount' => 2,
                    'orders' => [[
                        'entityId' => 'stall-order-1',
                        'remainingStock' => 4,
                        'offerItems' => [[
                            'itemId' => 1421716234,
                            'itemName' => 'Astralite Pickaxe',
                            'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                            'quantity' => 1,
                        ]],
                        'requiredItems' => [[
                            'itemId' => 1,
                            'itemName' => 'Hex Coin',
                            'iconAssetName' => 'Items/HexCoin[,3,10,500]',
                            'quantity' => 1200,
                        ]],
                        'offerCargo' => [],
                        'requiredCargo' => [],
                    ], [
                        'entityId' => 'stall-order-2',
                        'remainingStock' => 6,
                        'offerItems' => [[
                            'itemId' => 999,
                            'itemName' => 'Jar of Dirt',
                            'iconAssetName' => 'GeneratedIcons/Items/JarDirt',
                            'quantity' => 3,
                        ]],
                        'requiredItems' => [[
                            'itemId' => 1421716234,
                            'itemName' => 'Astralite Pickaxe',
                            'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                            'quantity' => 1,
                        ]],
                        'offerCargo' => [],
                        'requiredCargo' => [],
                    ]],
                ]],
                'totalStalls' => 1,
                'totalOrders' => 2,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'claimEntityId' => '288230376165363891',
                'side' => 'sell',
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->has('market.items', 1)
                ->where('market.items.0.name', 'Astralite Pickaxe')
                ->has('market.listings', 1)
                ->where('market.listings.0.itemName', 'Astralite Pickaxe')
                ->where('market.listings.0.source', 'stall-order')
                ->where('market.listings.0.stall.name', 'Astra Tools')
                ->where('market.listings.0.price', 1200)
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/inventories'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims/288230376165363891/market/listings'));
    }

    public function test_barter_stall_finder_filters_empire_claims_to_claims_with_barter_stations(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([]),
            'https://bitjita.com/api/empires?q=*' => Http::response([
                'empires' => [[
                    'entityId' => '1807',
                    'name' => 'Earth Kingdom',
                    'claimCount' => 2,
                    'memberCount' => 12,
                ]],
                'count' => 1,
            ]),
            'https://bitjita.com/api/empires/1807/claims' => Http::response([
                'claims' => [
                    [
                        'entityId' => '288230376165363891',
                        'name' => 'Omashu',
                        'empireEntityId' => '1807',
                        'empireName' => 'Earth Kingdom',
                        'regionId' => 8,
                        'regionName' => 'Solmere',
                        'tier' => 5,
                    ],
                    [
                        'entityId' => '288230376165363892',
                        'name' => 'Cabbage Farm',
                        'empireEntityId' => '1807',
                        'empireName' => 'Earth Kingdom',
                        'regionId' => 8,
                        'regionName' => 'Solmere',
                        'tier' => 2,
                    ],
                ],
                'count' => 2,
            ]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => '864691128500984069',
                    'ownerName' => 'Astra',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Astra Tools',
                    'claimName' => 'Omashu',
                    'orderCount' => 30,
                    'orders' => [],
                ], [
                    'entityId' => '864691128500984099',
                    'ownerName' => 'Sokka',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Not Earth',
                    'claimName' => 'Not In Empire',
                    'orderCount' => 15,
                    'orders' => [],
                ]],
                'totalStalls' => 2,
                'totalOrders' => 45,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'empire' => 'Earth Kingdom',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->where('filters.empire', 'Earth Kingdom')
                ->where('filters.empireEntityId', '1807')
                ->where('filters.empireName', 'Earth Kingdom')
                ->has('market.empires', 1)
                ->where('market.empires.0.name', 'Earth Kingdom')
                ->has('market.claims', 1)
                ->where('market.claims.0.name', 'Omashu')
                ->where('market.claims.0.empireName', 'Earth Kingdom')
                ->where('market.claims.0.tradeBuildingCount', 1)
                ->where('market.claims.0.tradeOrderCount', 30)
                ->has('market.items', 0)
                ->has('market.listings', 0)
            );

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/empires?')
            && str_contains($request->url(), 'q=Earth'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/empires/1807/claims');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market?'));
    }

    public function test_market_finder_filters_claim_search_by_region_without_building_lookup(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([
                [
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                ],
            ]),
            'https://bitjita.com/api/claims?page=1*regionId=8*' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363892',
                    'name' => 'Workshop Only',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'tier' => 3,
                ]],
                'count' => 101,
            ]),
            'https://bitjita.com/api/claims?page=2*regionId=8*' => Http::response([
                'claims' => [[
                    'entityId' => '288230376165363891',
                    'name' => 'Jita',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'tier' => 5,
                ]],
                'count' => 101,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.market', [
                'region' => 'Solmere',
                'hasOrders' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'market')
                ->where('filters.region', 'Solmere')
                ->where('filters.regionId', '8')
                ->where('filters.regionName', 'Solmere')
                ->has('market.claims', 2)
                ->where('market.claims.0.name', 'Workshop Only')
                ->where('market.claims.1.name', 'Jita')
                ->has('market.items', 0)
                ->has('market.listings', 0)
            );

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?')
            && str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'limit=100')
            && str_contains($request->url(), 'regionId=8'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims?')
            && str_contains($request->url(), 'page=2')
            && str_contains($request->url(), 'limit=100')
            && str_contains($request->url(), 'regionId=8'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/market?'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
    }

    public function test_barter_stall_listings_show_buy_orders_when_item_is_required(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([]),
            'https://bitjita.com/api/claims/288230376165363891' => Http::response([
                'claim' => [
                    'entityId' => '288230376165363891',
                    'name' => 'Jita',
                    'regionName' => 'Solace',
                ],
            ]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => '864691128500984069',
                    'ownerName' => 'Astra',
                    'regionId' => 8,
                    'regionName' => 'Solace',
                    'nickname' => 'Astra Tools',
                    'claimName' => 'Jita',
                    'orderCount' => 1,
                    'orders' => [[
                        'entityId' => 'stall-order-1',
                        'remainingStock' => 4,
                        'offerItems' => [[
                            'itemId' => 1,
                            'itemName' => 'Hex Coin',
                            'iconAssetName' => 'Items/HexCoin[,3,10,500]',
                            'quantity' => 900,
                        ]],
                        'requiredItems' => [[
                            'itemId' => 1421716234,
                            'itemName' => 'Astralite Pickaxe',
                            'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                            'quantity' => 1,
                        ]],
                        'offerCargo' => [],
                        'requiredCargo' => [],
                    ]],
                ]],
                'totalStalls' => 1,
                'totalOrders' => 1,
                'page' => 1,
                'totalPages' => 1,
                'limit' => 100,
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.barter-stalls', [
                'claimEntityId' => '288230376165363891',
                'q' => 'Astralite',
                'side' => 'buy',
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Market')
                ->where('tool.key', 'barter-stalls')
                ->where('market.listings.0.stallMatchStatus', 'matched')
                ->where('market.listings.0.source', 'stall-order')
                ->where('market.listings.0.side', 'buy')
                ->where('market.listings.0.stall.name', 'Astra Tools')
                ->where('market.listings.0.stall.buildingName', 'Barter Stall')
                ->where('market.listings.0.stall.entityId', '864691128500984069')
                ->where('market.listings.0.price', 900)
                ->where('market.listings.0.bundlePrice', 900)
                ->where('market.tradeBuildings.0.inventoryItems.0.name', 'Hex Coin')
                ->where('market.tradeBuildings.0.inventoryItems.0.category', 'Item')
                ->where('market.tradeBuildings.0.inventoryItems.0.quantity', 900)
                ->where('market.tradeBuildings.0.inventoryItems.0.kind', 'item')
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/stalls?page=1&limit=100');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/buildings'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/inventories'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/claims/288230376165363891/market/listings'));
    }

    public function test_crafting_tool_searches_items_and_loads_recipe_detail(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Pickaxe' => Http::response([
                'items' => [[
                    'id' => 1421716234,
                    'name' => 'Astralite Pickaxe',
                    'tag' => 'Miner Tool',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                ]],
            ]),
            'https://bitjita.com/api/items/1421716234' => Http::response([
                'item' => [
                    'id' => 1421716234,
                    'name' => 'Astralite Pickaxe',
                    'tag' => 'Miner Tool',
                    'tier' => 5,
                ],
                'craftingRecipes' => [[
                    'id' => 55,
                    'recipeName' => 'Forge Astralite Pickaxe',
                    'craftingStation' => 'Smithy',
                    'ingredients' => [[
                        'itemId' => 111,
                        'itemName' => 'Astralite Ingot',
                        'quantity' => 3,
                    ]],
                ]],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Pickaxe',
                'itemId' => 1421716234,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('items.0.name', 'Astralite Pickaxe')
                ->where('detail.item.name', 'Astralite Pickaxe')
                ->where('detail.craftingRecipes.0.name', 'Forge Astralite Pickaxe')
                ->where('detail.craftingRecipes.0.ingredients.0.name', 'Astralite Ingot')
            );
    }
}
