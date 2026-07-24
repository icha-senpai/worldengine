<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftWidgetProfile;
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
            'https://bitjita.com/api/cargo?q=Pickaxe' => Http::response([
                'cargos' => [],
                'count' => 0,
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
            'https://bitjita.com/api/items/111' => Http::response([
                'item' => [
                    'id' => 111,
                    'name' => 'Astralite Ingot',
                    'tag' => 'Ingot',
                    'tier' => 5,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
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
                ->where('items.0.kind', 'item')
                ->where('detail.item.name', 'Astralite Pickaxe')
                ->where('detail.item.kind', 'item')
                ->where('detail.craftingRecipes.0.name', 'Forge Astralite Pickaxe')
                ->where('detail.craftingRecipes.0.station', 'Smithy')
                ->where('detail.craftingRecipes.0.ingredients.0.name', 'Astralite Ingot')
                ->where('detail.recipeTree.0.name', 'Forge Astralite Pickaxe')
                ->where('detail.recipeTree.0.ingredients.0.name', 'Astralite Ingot')
            );
    }

    public function test_crafting_tool_uses_spacetime_snapshot_when_available(): void
    {
        $snapshotPath = storage_path('framework/testing/bitcraft-spacetime-static.json');

        if (! is_dir(dirname($snapshotPath))) {
            mkdir(dirname($snapshotPath), 0777, true);
        }

        file_put_contents($snapshotPath, json_encode([
            'source' => 'bitcraft-spacetimedb',
            'generatedAt' => now()->toISOString(),
            'host' => 'wss://bitcraft-early-access.spacetimedb.com',
            'database' => 'bitcraft-live-19',
            'tables' => [
                'item_desc' => [
                    'count' => 5,
                    'rows' => [[
                        'id' => 100,
                        'name' => 'Simple Timber',
                        'description' => 'A workable timber.',
                        'tier' => 2,
                        'tag' => 'Timber',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/SimpleTimber',
                    ], [
                        'id' => 101,
                        'name' => 'Simple Plank',
                        'description' => 'A simple plank.',
                        'tier' => 2,
                        'tag' => 'Plank',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/SimplePlank',
                    ], [
                        'id' => 102,
                        'name' => 'Simple Stripped Wood',
                        'description' => 'Simple stripped wood.',
                        'tier' => 2,
                        'tag' => 'Stripped Wood',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/SimpleStrippedWood',
                    ], [
                        'id' => 103,
                        'name' => 'Simple Wood Log',
                        'description' => 'A simple log.',
                        'tier' => 2,
                        'tag' => 'Wood Log',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/SimpleWoodLog',
                    ], [
                        'id' => 104,
                        'name' => 'Hexite Wood Fragment',
                        'description' => 'A special fragment.',
                        'tier' => 2,
                        'tag' => 'Wood Fragment',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/HexiteWoodFragment',
                    ]],
                ],
                'cargo_desc' => [
                    'count' => 1,
                    'rows' => [[
                        'id' => 200,
                        'name' => 'Simple Timber Package',
                        'description' => 'Bulk simple timber.',
                        'tier' => 2,
                        'tag' => 'Package',
                        'rarity' => [1, []],
                        'icon_asset_name' => 'GeneratedIcons/Items/SimpleTimberPackage',
                    ]],
                ],
                'crafting_recipe_desc' => [
                    'count' => 4,
                    'rows' => [[
                        'id' => 500,
                        'name' => 'Craft {0}',
                        'time_requirement' => 1.6,
                        'building_requirement' => [0, [
                            'building_type' => 9,
                            'tier' => 2,
                        ]],
                        'level_requirements' => [[8, 5]],
                        'crafted_item_stacks' => [[100, 1, [0, []], [1, []]]],
                        'consumed_item_stacks' => [[101, 20, [0, []], 1, 1]],
                    ], [
                        'id' => 501,
                        'name' => 'Package {1} into {0}',
                        'time_requirement' => 1.6,
                        'building_requirement' => [0, [
                            'building_type' => 9,
                            'tier' => 2,
                        ]],
                        'level_requirements' => [[8, 5]],
                        'crafted_item_stacks' => [[200, 1, [1, []], [1, []]]],
                        'consumed_item_stacks' => [[100, 100, [0, []], 1, 1]],
                    ], [
                        'id' => 502,
                        'name' => 'Treat {1} Into {0}',
                        'time_requirement' => 1.6,
                        'building_requirement' => [0, [
                            'building_type' => 9,
                            'tier' => 2,
                        ]],
                        'level_requirements' => [[8, 5]],
                        'crafted_item_stacks' => [[101, 1, [0, []], [1, []]]],
                        'consumed_item_stacks' => [[102, 1, [0, []], 1, 1]],
                    ], [
                        'id' => 503,
                        'name' => 'Treat {1} Into {0}',
                        'time_requirement' => 1.6,
                        'building_requirement' => [0, [
                            'building_type' => 9,
                            'tier' => 2,
                        ]],
                        'level_requirements' => [[8, 5]],
                        'crafted_item_stacks' => [[101, 2, [0, []], [1, []]]],
                        'consumed_item_stacks' => [[102, 1, [0, []], 1, 1], [104, 3, [0, []], 1, 1]],
                    ], [
                        'id' => 504,
                        'name' => 'Saw {0}',
                        'time_requirement' => 1.6,
                        'building_requirement' => [0, [
                            'building_type' => 9,
                            'tier' => 2,
                        ]],
                        'level_requirements' => [[8, 5]],
                        'crafted_item_stacks' => [[102, 1, [0, []], [1, []]]],
                        'consumed_item_stacks' => [[103, 3, [0, []], 1, 1]],
                    ]],
                ],
                'extraction_recipe_desc' => ['count' => 0, 'rows' => []],
                'building_type_desc' => [
                    'count' => 1,
                    'rows' => [[
                        'id' => 9,
                        'name' => 'Carpentry Station',
                    ]],
                ],
                'skill_desc' => [
                    'count' => 1,
                    'rows' => [[
                        'id' => 8,
                        'name' => 'Carpentry',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        config([
            'services.bitcraft_spacetime.enabled' => true,
            'services.bitcraft_spacetime.enabled_in_tests' => true,
            'services.bitcraft_spacetime.static_snapshot_path' => $snapshotPath,
        ]);

        Http::fake([
            'https://bitjita.com/*' => Http::response([], 500),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Timber',
                'itemId' => 100,
                'itemKind' => 'item',
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->has('items', 1)
                ->where('items.0.name', 'Simple Timber')
                ->where('items.0.kind', 'item')
                ->where('detail.item.name', 'Simple Timber')
                ->where('detail.craftingRecipes.0.name', 'Craft Simple Timber')
                ->where('detail.craftingRecipes.0.station', 'Carpentry Station')
                ->where('detail.craftingRecipes.0.skill', 'Carpentry')
                ->where('detail.craftingRecipes.0.ingredients.0.name', 'Simple Plank')
                ->where('detail.craftingRecipes.0.ingredients.0.quantity', 20)
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.name', 'Treat Simple Stripped Wood Into Simple Plank')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.ingredients.0.name', 'Simple Stripped Wood')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.ingredients.0.recipes.0.name', 'Saw Simple Stripped Wood')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.ingredients.0.recipes.0.ingredients.0.name', 'Simple Wood Log')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.alternatives.1.ingredients.1.name', 'Hexite Wood Fragment')
            );

        Http::assertNothingSent();
    }

    public function test_crafting_tool_searches_cargo_and_filters_to_recipe_targets(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Timber' => Http::response([
                'items' => [[
                    'id' => 9001,
                    'name' => 'Timber Token',
                    'tag' => 'Collectible',
                    'tier' => 1,
                    'rarityStr' => 'Common',
                ]],
            ]),
            'https://bitjita.com/api/items/9001' => Http::response([
                'item' => [
                    'id' => 9001,
                    'name' => 'Timber Token',
                    'tag' => 'Collectible',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/cargo?q=Timber' => Http::response([
                'cargos' => [[
                    'id' => 1201,
                    'name' => 'Simple Timber',
                    'tag' => 'Timber',
                    'tier' => 2,
                    'rarityStr' => 'Common',
                ]],
                'count' => 1,
            ]),
            'https://bitjita.com/api/cargo/1201' => Http::response([
                'cargo' => [
                    'id' => 1201,
                    'name' => 'Simple Timber',
                    'tag' => 'Timber',
                    'tier' => 2,
                ],
                'craftingRecipes' => [[
                    'id' => 202002,
                    'name' => 'Craft {0}',
                    'buildingName' => 'Ancient Carpentry Station',
                    'outputQuantity' => 1,
                    'craftedItems' => [[
                        'id' => 1201,
                        'name' => 'Simple Timber',
                        'quantity' => 1,
                        'itemType' => 1,
                    ]],
                    'consumedItems' => [[
                        'id' => 2020003,
                        'name' => 'Simple Plank',
                        'quantity' => 20,
                        'itemType' => 0,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/2020003' => Http::response([
                'item' => [
                    'id' => 2020003,
                    'name' => 'Simple Plank',
                    'tag' => 'Plank',
                    'tier' => 2,
                ],
                'craftingRecipes' => [[
                    'id' => 202009,
                    'name' => 'Treat Simple Stripped Wood Into Simple Plank',
                    'buildingName' => 'Ancient Carpentry Station',
                    'outputQuantity' => 1,
                    'consumedItemStacks' => [[
                        'item_id' => 362614434,
                        'quantity' => 1,
                        'item_type' => 'item',
                    ]],
                    'consumedItems' => [[
                        'id' => 362614434,
                        'name' => 'Simple Stripped Wood',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ], [
                    'id' => 1117318721,
                    'name' => 'Treat Simple Stripped Wood Into Simple Plank',
                    'buildingName' => 'Ancient Carpentry Station',
                    'outputQuantity' => 2,
                    'consumedItemStacks' => [[
                        'item_id' => 362614434,
                        'quantity' => 1,
                        'item_type' => 'item',
                    ], [
                        'item_id' => 1939049017,
                        'quantity' => 3,
                        'item_type' => 'item',
                    ]],
                    'consumedItems' => [[
                        'id' => 362614434,
                        'name' => 'Simple Stripped Wood',
                        'quantity' => 1,
                        'itemType' => 0,
                    ], [
                        'id' => 1939049017,
                        'name' => 'Hexite Wood Fragment',
                        'quantity' => 3,
                        'itemType' => 0,
                    ]],
                ], [
                    'id' => 1117209372,
                    'name' => 'Unpack Simple Wood Plank Package',
                    'buildingName' => 'Rough Carpentry Station',
                    'outputQuantity' => 100,
                    'consumedItemStacks' => [[
                        'item_id' => 260001,
                        'quantity' => 1,
                        'item_type' => 'cargo',
                    ]],
                    'consumedItems' => [[
                        'id' => 4295227296,
                        'name' => 'Simple Wood Plank Package',
                        'quantity' => 1,
                        'itemType' => 1,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/362614434' => Http::response([
                'item' => [
                    'id' => 362614434,
                    'name' => 'Simple Stripped Wood',
                    'tag' => 'Stripped Wood',
                    'tier' => 2,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1939049017' => Http::response([
                'item' => [
                    'id' => 1939049017,
                    'name' => 'Hexite Wood Fragment',
                    'tag' => 'Wood Fragment',
                    'tier' => 2,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Timber',
                'itemId' => 1201,
                'itemKind' => 'cargo',
                'quantity' => 25,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('filters.itemKind', 'cargo')
                ->where('filters.quantity', 25)
                ->has('items', 1)
                ->where('items.0.name', 'Simple Timber')
                ->where('items.0.kind', 'cargo')
                ->where('detail.item.name', 'Simple Timber')
                ->where('detail.item.kind', 'cargo')
                ->where('detail.craftingRecipes.0.name', 'Craft Simple Timber')
                ->where('detail.craftingRecipes.0.station', 'Carpentry Station')
                ->where('detail.craftingRecipes.0.ingredients.0.name', 'Simple Plank')
                ->where('detail.craftingRecipes.0.ingredients.0.quantity', 20)
                ->where('detail.recipeTree.0.name', 'Craft Simple Timber')
                ->where('detail.recipeTree.0.station', 'Carpentry Station')
                ->where('detail.recipeTree.0.ingredients.0.name', 'Simple Plank')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.name', 'Treat Simple Stripped Wood Into Simple Plank')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.station', 'Carpentry Station')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.ingredients.0.name', 'Simple Stripped Wood')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.alternatives.1.ingredients.1.name', 'Hexite Wood Fragment')
                ->has('detail.recipeTree.0.ingredients.0.recipes', 1)
                ->has('detail.recipeTree.0.ingredients.0.recipes.0.ingredients', 1)
                ->has('detail.recipeTree.0.ingredients.0.recipes.0.alternatives', 2)
                ->has('detail.recipeTree.0.ingredients.0.recipes.0.alternatives.1.ingredients', 2)
            );

        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/cargo/260001');
    }

    public function test_crafting_tool_offers_mortar_route_as_alternative_and_filters_construction_material_pack_routes(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Refined%20Simple%20Brick' => Http::response([
                'items' => [[
                    'id' => 529266245,
                    'name' => 'Refined Simple Brick',
                    'tag' => 'Refined Brick',
                    'tier' => 2,
                    'rarityStr' => 'Epic',
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Refined%20Simple%20Brick' => Http::response([
                'cargos' => [],
                'count' => 0,
            ]),
            'https://bitjita.com/api/items/529266245' => Http::response([
                'item' => [
                    'id' => 529266245,
                    'name' => 'Refined Simple Brick',
                    'tag' => 'Refined Brick',
                    'tier' => 2,
                ],
                'craftingRecipes' => [[
                    'id' => 10,
                    'name' => 'Use Ancient Mortar For Refined Simple Brick',
                    'buildingName' => 'Ancient Masonry Station',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 1903879412,
                        'name' => 'Ancient Mortar',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ], [
                    'id' => 11,
                    'name' => 'Break Down Simple Construction Materials Pack',
                    'buildingName' => 'Ancient Masonry Station',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 1020001,
                        'name' => 'Simple Construction Materials Pack',
                        'quantity' => 1,
                        'itemType' => 1,
                    ]],
                ], [
                    'id' => 12,
                    'name' => 'Refine Refined Simple Brick',
                    'buildingName' => 'Simple Masonry Station',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 2030002,
                        'name' => 'Simple Brick',
                        'quantity' => 5,
                        'itemType' => 0,
                    ], [
                        'id' => 666637937,
                        'name' => 'Simple Firesand',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/2030002' => Http::response([
                'item' => [
                    'id' => 2030002,
                    'name' => 'Simple Brick',
                    'tag' => 'Brick',
                    'tier' => 2,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/666637937' => Http::response([
                'item' => [
                    'id' => 666637937,
                    'name' => 'Simple Firesand',
                    'tag' => 'Firesand',
                    'tier' => 2,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1903879412' => Http::response([
                'item' => [
                    'id' => 1903879412,
                    'name' => 'Ancient Mortar',
                    'tag' => 'Currency',
                    'tier' => -1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Refined Simple Brick',
                'itemId' => 529266245,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('detail.recipeTree.0.name', 'Refine Refined Simple Brick')
                ->where('detail.recipeTree.0.station', 'Masonry Station')
                ->where('detail.recipeTree.0.ingredients.0.name', 'Simple Brick')
                ->where('detail.recipeTree.0.ingredients.1.name', 'Simple Firesand')
                ->where('detail.recipeTree.0.alternatives.1.name', 'Use Ancient Mortar For Refined Simple Brick')
                ->where('detail.recipeTree.0.alternatives.1.ingredients.0.name', 'Ancient Mortar')
                ->has('detail.recipeTree.0.ingredients', 2)
                ->has('detail.recipeTree.0.alternatives', 2)
            );

        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/cargo/1020001');
    }

    public function test_crafting_tool_uses_same_tier_wood_log_for_stale_construction_material_pack_ingredients(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Rough%20Brick' => Http::response([
                'items' => [[
                    'id' => 1030002,
                    'name' => 'Rough Brick',
                    'tag' => 'Brick',
                    'tier' => 1,
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Rough%20Brick' => Http::response([
                'cargos' => [],
                'count' => 0,
            ]),
            'https://bitjita.com/api/items/1030002' => Http::response([
                'item' => [
                    'id' => 1030002,
                    'name' => 'Rough Brick',
                    'tag' => 'Brick',
                    'tier' => 1,
                ],
                'craftingRecipes' => [[
                    'id' => 1577688923,
                    'name' => 'Bake Rough Brick',
                    'buildingName' => 'Ancient Kiln',
                    'outputQuantity' => 2,
                    'consumedItems' => [[
                        'id' => 2044934693,
                        'name' => 'Unfired Rough Brick',
                        'quantity' => 1,
                        'itemType' => 0,
                    ], [
                        'id' => 1010001,
                        'name' => 'Rough Construction Materials Pack',
                        'quantity' => 1,
                        'itemType' => 0,
                    ], [
                        'id' => 1903879412,
                        'name' => 'Ancient Mortar',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ], [
                    'id' => 103001,
                    'name' => 'Bake Rough Brick',
                    'buildingName' => 'Rough Kiln',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 2044934693,
                        'name' => 'Unfired Rough Brick',
                        'quantity' => 1,
                        'itemType' => 0,
                    ], [
                        'id' => 1010001,
                        'name' => 'Rough Construction Materials Pack',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ], [
                    'id' => 103002,
                    'name' => 'Unpack Rough Brick Package',
                    'buildingName' => 'Rough Masonry Station',
                    'outputQuantity' => 100,
                    'consumedItems' => [[
                        'id' => 683661636,
                        'name' => 'Rough Brick Package',
                        'quantity' => 1,
                        'itemType' => 1,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/2044934693' => Http::response([
                'item' => [
                    'id' => 2044934693,
                    'name' => 'Unfired Rough Brick',
                    'tag' => 'Brick',
                    'tier' => 1,
                ],
                'craftingRecipes' => [[
                    'id' => 2044934694,
                    'name' => 'Shape Unfired Rough Brick',
                    'buildingName' => 'Rough Masonry Station',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 894623392,
                        'name' => "Basic Potter's Mix",
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1010001' => Http::response([
                'item' => [
                    'id' => 1010001,
                    'name' => 'Rough Wood Log',
                    'tag' => 'Wood Log',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/894623392' => Http::response([
                'item' => [
                    'id' => 894623392,
                    'name' => "Basic Potter's Mix",
                    'tag' => 'Potter Mix',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1903879412' => Http::response([
                'item' => [
                    'id' => 1903879412,
                    'name' => 'Ancient Mortar',
                    'tag' => 'Currency',
                    'tier' => -1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Rough Brick',
                'itemId' => 1030002,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('detail.item.name', 'Rough Brick')
                ->has('detail.craftingRecipes', 3)
                ->where('detail.craftingRecipes.1.ingredients.1.name', 'Rough Wood Log')
                ->where('detail.recipeTree.0.name', 'Bake Rough Brick')
                ->where('detail.recipeTree.0.station', 'Kiln')
                ->where('detail.recipeTree.0.outputQuantity', 1)
                ->where('detail.recipeTree.0.ingredients.0.name', 'Unfired Rough Brick')
                ->where('detail.recipeTree.0.ingredients.1.name', 'Rough Wood Log')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.name', 'Shape Unfired Rough Brick')
                ->where('detail.recipeTree.0.ingredients.0.recipes.0.station', 'Masonry Station')
                ->where('detail.recipeTree.0.alternatives.1.ingredients.2.name', 'Ancient Mortar')
                ->has('detail.recipeTree.0.ingredients', 2)
                ->has('detail.recipeTree.0.alternatives', 2)
            );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/items/2044934693');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/items/1010001');
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/cargo/683661636');
    }

    public function test_crafting_tool_uses_pebbles_for_stale_potters_mix_construction_material_pack_ingredients(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Basic%20Potter%27s%20Mix' => Http::response([
                'items' => [[
                    'id' => 894623392,
                    'name' => "Basic Potter's Mix",
                    'tag' => "Potter's Mix",
                    'tier' => 1,
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Basic%20Potter%27s%20Mix' => Http::response([
                'cargos' => [],
                'count' => 0,
            ]),
            'https://bitjita.com/api/items/894623392' => Http::response([
                'item' => [
                    'id' => 894623392,
                    'name' => "Basic Potter's Mix",
                    'tag' => "Potter's Mix",
                    'tier' => 1,
                ],
                'craftingRecipes' => [[
                    'id' => 1613691572,
                    'name' => "Mix Basic Potter's Mix",
                    'buildingName' => 'Rough Masonry Station',
                    'outputQuantity' => 1,
                    'consumedItemStacks' => [[
                        'item_id' => 1030001,
                        'quantity' => 5,
                        'item_type' => 'item',
                    ], [
                        'item_id' => 1130003,
                        'quantity' => 2,
                        'item_type' => 'item',
                    ]],
                    'consumedItems' => [[
                        'id' => 1030001,
                        'quantity' => 5,
                        'itemType' => 0,
                        'name' => 'Sturdy Construction Materials Pack',
                    ], [
                        'id' => 1130003,
                        'quantity' => 2,
                        'itemType' => 0,
                        'name' => 'Basic Clay Lump',
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1030001' => Http::response([
                'item' => [
                    'id' => 1030001,
                    'name' => 'Rough Pebbles',
                    'tag' => 'Pebbles',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1130003' => Http::response([
                'item' => [
                    'id' => 1130003,
                    'name' => 'Basic Clay Lump',
                    'tag' => 'Clay',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => "Basic Potter's Mix",
                'itemId' => 894623392,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('detail.recipeTree.0.name', "Mix Basic Potter's Mix")
                ->where('detail.recipeTree.0.station', 'Masonry Station')
                ->where('detail.recipeTree.0.ingredients.0.name', 'Rough Pebbles')
                ->where('detail.recipeTree.0.ingredients.0.quantity', 5)
                ->where('detail.recipeTree.0.ingredients.1.name', 'Basic Clay Lump')
                ->where('detail.recipeTree.0.ingredients.1.quantity', 2)
                ->has('detail.recipeTree.0.ingredients', 2)
            );
    }

    public function test_crafting_tool_uses_ferralith_ingot_for_stale_exquisite_construction_material_pack_names(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Ferralith%20Ingot' => Http::response([
                'items' => [[
                    'id' => 1050001,
                    'name' => 'Ferralith Ingot',
                    'tag' => 'Ingot',
                    'tier' => 1,
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Ferralith%20Ingot' => Http::response([
                'cargos' => [],
                'count' => 0,
            ]),
            'https://bitjita.com/api/items/1050001' => Http::response([
                'item' => [
                    'id' => 1050001,
                    'name' => 'Ferralith Ingot',
                    'tag' => 'Ingot',
                    'tier' => 1,
                ],
                'craftingRecipes' => [[
                    'id' => 105001,
                    'name' => 'Forge Exquisite Construction Materials Pack',
                    'buildingName' => 'Rough Smithy',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => 1050003,
                        'name' => 'Molten Ferralith',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                    'craftedItems' => [[
                        'id' => 1050001,
                        'name' => 'Exquisite Construction Materials Pack',
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/1050003' => Http::response([
                'item' => [
                    'id' => 1050003,
                    'name' => 'Molten Ferralith',
                    'tag' => 'Molten Metal',
                    'tier' => 1,
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Ferralith Ingot',
                'itemId' => 1050001,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where('detail.craftingRecipes.0.name', 'Forge Ferralith Ingot')
                ->where('detail.recipeTree.0.name', 'Forge Ferralith Ingot')
                ->where('detail.recipeTree.0.ingredients.0.name', 'Molten Ferralith')
                ->has('detail.recipeTree.0.ingredients', 1)
            );
    }

    public function test_crafting_tool_loads_deep_tiered_recipe_chains(): void
    {
        $responses = [
            'https://bitjita.com/api/items?q=Layered%20Codex' => Http::response([
                'items' => [[
                    'id' => 9100,
                    'name' => 'Layered Codex',
                    'tag' => 'Codex',
                    'tier' => 10,
                    'rarityStr' => 'Epic',
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Layered%20Codex' => Http::response([
                'cargos' => [],
                'count' => 0,
            ]),
        ];

        foreach (range(0, 8) as $index) {
            $itemId = 9100 + $index;
            $nextId = $itemId + 1;
            $name = $index === 0 ? 'Layered Codex' : "Layer {$index}";

            $responses["https://bitjita.com/api/items/{$itemId}"] = Http::response([
                'item' => [
                    'id' => $itemId,
                    'name' => $name,
                    'tag' => $index === 0 ? 'Codex' : 'Component',
                    'tier' => max(1, 10 - $index),
                ],
                'craftingRecipes' => $index < 8 ? [[
                    'id' => $itemId,
                    'name' => "Craft {$name}",
                    'buildingName' => 'Scholar Station',
                    'outputQuantity' => 1,
                    'consumedItems' => [[
                        'id' => $nextId,
                        'name' => 'Layer '.($index + 1),
                        'quantity' => 1,
                        'itemType' => 0,
                    ]],
                ]] : [],
                'extractionRecipes' => [],
            ]);
        }

        Http::fake($responses);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.crafting', [
                'q' => 'Layered Codex',
                'itemId' => 9100,
            ]));

        $deepestIngredientPath = 'detail.recipeTree.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0'
            .'.recipes.0.ingredients.0.name';

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Crafting')
                ->where($deepestIngredientPath, 'Layer 8')
            );
    }

    public function test_activity_tracker_page_resolves_player_skill_and_level_progress(): void
    {
        $this->fakeActivityTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.activity', [
                'character' => 'icha',
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Activity')
                ->where('filters.character', 'icha')
                ->where('filters.skill', 'all')
                ->where('snapshot.error', null)
                ->where('snapshot.tracker.player.username', 'Icha')
                ->where('snapshot.tracker.scope', 'all')
                ->where('snapshot.tracker.skill.name', 'Sailing')
                ->has('snapshot.tracker.skills', 2)
                ->where('snapshot.tracker.skills.0.name', 'Fishing')
                ->where('snapshot.tracker.skills.1.name', 'Sailing')
                ->where('snapshot.tracker.levels.2.level', 40)
                ->where('snapshot.tracker.xp', 285390)
                ->where('snapshot.tracker.level', 39)
                ->where('snapshot.tracker.nextLevel', 40)
                ->where('snapshot.tracker.xpRemaining', 31830)
                ->where('snapshot.tracker.progressPercent', 4.6)
                ->where('pollUrl', route('bitcraft.activity.snapshot', [
                    'character' => '1224979098725428189',
                    'skill' => 'all',
                ], false))
            );
    }

    public function test_activity_tracker_setup_accepts_xp_and_level_goals(): void
    {
        $this->fakeActivityTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.activity', [
                'character' => 'icha',
                'title' => 'Stream Goals',
                'icons' => '',
                'skillKeys' => '12,21',
                'skillGoalLevels' => '12=40',
                'skillGoalXp' => '21=500000',
                'setup' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Activity')
                ->where('filters.title', 'Stream Goals')
                ->where('filters.icons', '')
                ->where('filters.skillKeys.0', '12')
                ->where('filters.skillKeys.1', '21')
                ->where('filters.skillGoalLevels.12', 40)
                ->where('filters.skillGoalXp.21', 500000)
                ->where('filters.setup', true)
                ->where('snapshot.tracker.skills.0.name', 'Fishing')
                ->where('snapshot.tracker.skills.1.name', 'Sailing')
                ->where('pollUrl', route('bitcraft.activity.snapshot', [
                    'character' => '1224979098725428189',
                    'skill' => 'all',
                ], false))
            );
    }

    public function test_activity_tracker_snapshot_returns_live_json_payload(): void
    {
        $this->fakeActivityTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->getJson(route('bitcraft.activity.snapshot', [
                'character' => 'icha',
                'skill' => 'all',
                '_' => '1784649999999',
            ]));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('error', null)
            ->assertJsonPath('tracker.player.entityId', '1224979098725428189')
            ->assertJsonPath('tracker.scope', 'all')
            ->assertJsonPath('tracker.skill.id', 21)
            ->assertJsonPath('tracker.skills.0.name', 'Fishing')
            ->assertJsonPath('tracker.skills.1.name', 'Sailing')
            ->assertJsonPath('tracker.level', 39)
            ->assertJsonPath('tracker.nextLevelXp', 317220)
            ->assertJsonPath('tracker.xpRemaining', 31830);
    }

    public function test_activity_tracker_widget_is_public_for_obs(): void
    {
        $this->fakeActivityTrackerResponses();

        $response = $this->get(route('bitcraft.activity', [
            'character' => 'icha',
            'skillKeys' => '21',
        ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Activity')
                ->where('snapshot.error', null)
                ->where('snapshot.tracker.player.username', 'Icha')
            );
    }

    public function test_activity_tracker_source_profile_keeps_widget_url_stable(): void
    {
        $this->get(route('bitcraft.activity', [
            'source' => 'stream',
            'character' => 'icha',
            'skillKeys' => '21',
            'skillGoalLevels' => '21=40',
            'theme' => 'ember',
            'accentColor' => '#fb923c',
            'highlightColor' => '#facc15',
            'panelColor' => '#431407',
            'textColor' => '#fff7ed',
            'mutedColor' => '#fdba74',
            'borderColor' => '#f97316',
            'fontScale' => 115,
            'width' => 520,
            'radius' => 12,
            'panelOpacity' => 84,
        ]))->assertRedirect(route('bitcraft.activity', ['source' => 'stream']));

        $settings = BitcraftWidgetProfile::query()
            ->where('widget', 'activity')
            ->where('source', 'stream')
            ->firstOrFail()
            ->settings;

        $this->assertSame(['21'], $settings['skillKeys']);
        $this->assertSame('ember', $settings['theme']);
        $this->assertSame('#fb923c', $settings['accentColor']);
        $this->assertSame(115, $settings['fontScale']);
        $this->assertSame(520, $settings['width']);
        $this->assertSame(84, $settings['panelOpacity']);

        $this->fakeActivityTrackerResponses();

        $this->get(route('bitcraft.activity', ['source' => 'stream']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/Activity')
                ->where('filters.source', 'stream')
                ->where('filters.character', 'icha')
                ->where('filters.skillKeys.0', '21')
                ->where('filters.skillGoalLevels.21', 40)
                ->where('filters.theme', 'ember')
                ->where('filters.accentColor', '#fb923c')
                ->where('filters.fontScale', 115)
                ->where('filters.width', 520)
                ->where('filters.panelOpacity', 84)
            );
    }

    public function test_inventory_tracker_setup_lists_inventory_item_and_cargo_options(): void
    {
        $this->fakeInventoryTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.inventory-tracker', [
                'character' => 'icha',
                'icons' => '',
                'setup' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/InventoryTracker')
                ->where('filters.character', 'icha')
                ->where('filters.icons', '')
                ->where('filters.setup', true)
                ->where('snapshot.tracker', null)
                ->where('snapshot.error', null)
                ->where('snapshot.options.0.name', 'Astralite Pickaxe')
                ->where('snapshot.options.0.kind', 'item')
                ->where('snapshot.options.0.quantity', 0)
                ->where('snapshot.options.1.name', 'Briny Linus')
                ->where('snapshot.options.1.kind', 'cargo')
                ->where('snapshot.options.1.quantity', 3)
                ->where('snapshot.options.2.name', 'Vibrant Janus')
                ->where('snapshot.options.2.kind', 'item')
                ->where('snapshot.options.2.quantity', 29)
            );
    }

    public function test_inventory_tracker_snapshot_aggregates_selected_item_from_tracked_sources(): void
    {
        $this->fakeInventoryTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->getJson(route('bitcraft.inventory-tracker.snapshot', [
                'character' => 'icha',
                'title' => 'Fishing Day!',
                'icons' => 'fish',
                'itemSearch' => 'Vibrant Janus',
                'itemKey' => 'item:1516591189',
                'need' => 1057,
            ]));

        $response->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('tracker.player.username', 'Icha')
            ->assertJsonPath('tracker.item.name', 'Vibrant Janus')
            ->assertJsonPath('tracker.items.0.name', 'Vibrant Janus')
            ->assertJsonPath('tracker.quantity', 29)
            ->assertJsonPath('tracker.need', 1057)
            ->assertJsonPath('tracker.remaining', 1028)
            ->assertJsonPath('tracker.progressPercent', 2.7)
            ->assertJsonPath('tracker.sources.0.name', 'Icha\'s Cart (II)')
            ->assertJsonPath('tracker.sources.0.quantity', 14)
            ->assertJsonPath('tracker.sources.1.name', 'Inventory')
            ->assertJsonPath('tracker.sources.1.quantity', 10)
            ->assertJsonPath('tracker.sources.2.name', 'Icha\'s Personal Cache (I)')
            ->assertJsonPath('tracker.sources.2.quantity', 5);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://bitjita.com/api/players/1224979098725428189/inventories');
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://bitjita.com/api/players/1224979098725428189/inventories?'));
    }

    public function test_inventory_tracker_snapshot_aggregates_multiple_selected_items(): void
    {
        $this->fakeInventoryTrackerResponses();

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->getJson(route('bitcraft.inventory-tracker.snapshot', [
                'character' => 'icha',
                'itemKeys' => 'item:1516591189,cargo:6000',
                'itemNeeds' => 'item:1516591189=40,cargo:6000=9',
                'need' => 30,
            ]));

        $response->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('tracker.items.0.name', 'Vibrant Janus')
            ->assertJsonPath('tracker.items.0.quantity', 29)
            ->assertJsonPath('tracker.items.0.need', 40)
            ->assertJsonPath('tracker.items.0.remaining', 11)
            ->assertJsonPath('tracker.items.1.name', 'Briny Linus')
            ->assertJsonPath('tracker.items.1.kind', 'cargo')
            ->assertJsonPath('tracker.items.1.quantity', 3)
            ->assertJsonPath('tracker.items.1.need', 9)
            ->assertJsonPath('tracker.items.1.remaining', 6);
    }

    public function test_inventory_tracker_widget_is_public_for_obs(): void
    {
        $this->fakeInventoryTrackerResponses();

        $response = $this->get(route('bitcraft.inventory-tracker', [
            'character' => 'icha',
            'itemKeys' => 'item:1516591189',
            'itemNeeds' => 'item:1516591189=40',
        ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/InventoryTracker')
                ->where('snapshot.error', null)
                ->where('snapshot.tracker.player.username', 'Icha')
                ->where('snapshot.tracker.items.0.name', 'Vibrant Janus')
                ->where('snapshotUrl', route('bitcraft.inventory-tracker.snapshot', [
                    'character' => '1224979098725428189',
                    'title' => 'Fishing Day!',
                    'icons' => '🐟 🎣',
                    'itemKey' => 'item:1516591189',
                    'itemKeys' => 'item:1516591189',
                    'itemNeeds' => 'item:1516591189=40',
                ], false))
            );
    }

    public function test_inventory_tracker_source_profile_keeps_widget_url_stable(): void
    {
        $this->get(route('bitcraft.inventory-tracker', [
            'source' => 'stream',
            'character' => 'icha',
            'itemKeys' => 'item:1516591189',
            'itemNeeds' => 'item:1516591189=40',
            'theme' => 'harbor',
            'accentColor' => '#38bdf8',
            'highlightColor' => '#22c55e',
            'panelColor' => '#082f49',
            'textColor' => '#f0f9ff',
            'mutedColor' => '#bae6fd',
            'borderColor' => '#0ea5e9',
            'fontScale' => 105,
            'width' => 480,
            'radius' => 20,
            'panelOpacity' => 90,
        ]))->assertRedirect(route('bitcraft.inventory-tracker', ['source' => 'stream']));

        $settings = BitcraftWidgetProfile::query()
            ->where('widget', 'inventory-tracker')
            ->where('source', 'stream')
            ->firstOrFail()
            ->settings;

        $this->assertSame(['item:1516591189'], $settings['itemKeys']);
        $this->assertSame('harbor', $settings['theme']);
        $this->assertSame('#38bdf8', $settings['accentColor']);
        $this->assertSame(105, $settings['fontScale']);
        $this->assertSame(480, $settings['width']);
        $this->assertSame(90, $settings['panelOpacity']);

        $this->fakeInventoryTrackerResponses();

        $this->get(route('bitcraft.inventory-tracker', ['source' => 'stream']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/InventoryTracker')
                ->where('filters.source', 'stream')
                ->where('filters.character', 'icha')
                ->where('filters.itemKeys.0', 'item:1516591189')
                ->where('filters.itemNeeds.item:1516591189', 40)
                ->where('filters.theme', 'harbor')
                ->where('filters.accentColor', '#38bdf8')
                ->where('filters.fontScale', 105)
                ->where('filters.width', 480)
                ->where('filters.panelOpacity', 90)
                ->where('snapshot.tracker.items.0.name', 'Vibrant Janus')
            );
    }

    public function test_task_tracker_setup_accepts_tasks(): void
    {
        $tasks = json_encode([
            ['id' => 'task-one', 'text' => 'Gather fish', 'done' => false],
            ['id' => 'task-two', 'text' => 'Return to town', 'done' => true],
        ], JSON_THROW_ON_ERROR);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.task-tracker', [
                'title' => 'Fishing Run',
                'icons' => '',
                'tasks' => $tasks,
                'setup' => 1,
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/TaskTracker')
                ->where('filters.title', 'Fishing Run')
                ->where('filters.icons', '')
                ->where('filters.tasks.0.id', 'task-one')
                ->where('filters.tasks.0.text', 'Gather fish')
                ->where('filters.tasks.0.done', false)
                ->where('filters.tasks.1.id', 'task-two')
                ->where('filters.tasks.1.text', 'Return to town')
                ->where('filters.tasks.1.done', true)
                ->where('filters.setup', true)
            );
    }

    public function test_task_tracker_widget_is_public_for_obs(): void
    {
        $tasks = json_encode([
            ['id' => 'task-one', 'text' => 'Gather fish', 'done' => false],
        ], JSON_THROW_ON_ERROR);

        $response = $this->get(route('bitcraft.task-tracker', [
            'title' => 'Fishing Run',
            'tasks' => $tasks,
        ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/TaskTracker')
                ->where('filters.title', 'Fishing Run')
                ->where('filters.tasks.0.text', 'Gather fish')
            );
    }

    public function test_task_tracker_source_profile_keeps_widget_url_stable(): void
    {
        $tasks = json_encode([
            ['id' => 'task-one', 'text' => 'Gather fish', 'done' => false],
            ['id' => 'task-two', 'text' => 'Return to town', 'done' => true],
        ], JSON_THROW_ON_ERROR);

        $this->get(route('bitcraft.task-tracker', [
            'source' => 'stream',
            'title' => 'Fishing Run',
            'icons' => '',
            'tasks' => $tasks,
            'theme' => 'grove',
            'accentColor' => '#a3e635',
            'highlightColor' => '#2dd4bf',
            'panelColor' => '#1a2e05',
            'textColor' => '#f7fee7',
            'mutedColor' => '#bef264',
            'borderColor' => '#65a30d',
            'fontScale' => 110,
            'width' => 500,
            'radius' => 10,
            'panelOpacity' => 88,
        ]))->assertRedirect(route('bitcraft.task-tracker', ['source' => 'stream']));

        $settings = BitcraftWidgetProfile::query()
            ->where('widget', 'task-tracker')
            ->where('source', 'stream')
            ->firstOrFail()
            ->settings;

        $this->assertSame('Fishing Run', $settings['title']);
        $this->assertSame('', $settings['icons']);
        $this->assertSame('Gather fish', $settings['tasks'][0]['text']);
        $this->assertTrue($settings['tasks'][1]['done']);
        $this->assertSame('grove', $settings['theme']);
        $this->assertSame('#a3e635', $settings['accentColor']);
        $this->assertSame(110, $settings['fontScale']);
        $this->assertSame(500, $settings['width']);
        $this->assertSame(88, $settings['panelOpacity']);

        $this->get(route('bitcraft.task-tracker', ['source' => 'stream']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/TaskTracker')
                ->where('filters.source', 'stream')
                ->where('filters.title', 'Fishing Run')
                ->where('filters.icons', '')
                ->where('filters.tasks.0.text', 'Gather fish')
                ->where('filters.tasks.1.done', true)
                ->where('filters.theme', 'grove')
                ->where('filters.accentColor', '#a3e635')
                ->where('filters.fontScale', 110)
                ->where('filters.width', 500)
                ->where('filters.panelOpacity', 88)
            );
    }

    public function test_dataverse_widget_theme_ignores_stale_saved_custom_colors(): void
    {
        BitcraftWidgetProfile::query()->create([
            'widget' => 'task-tracker',
            'source' => 'stream',
            'settings' => [
                'title' => 'Fishing Run',
                'icons' => '',
                'tasks' => [
                    ['id' => 'task-one', 'text' => 'Gather fish', 'done' => false],
                ],
                'theme' => 'dataverse',
                'accentColor' => '#67e8f9',
                'highlightColor' => '#34d399',
                'panelColor' => '#111827',
                'textColor' => '#f8fafc',
                'mutedColor' => '#94a3b8',
                'borderColor' => '#2dd4bf',
            ],
        ]);

        $this->get(route('bitcraft.task-tracker', ['source' => 'stream']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/TaskTracker')
                ->where('filters.theme', 'dataverse')
                ->where('filters.accentColor', '#d4a44a')
                ->where('filters.highlightColor', '#6fb08d')
                ->where('filters.panelColor', '#110a18')
                ->where('filters.textColor', '#fff6e6')
                ->where('filters.mutedColor', '#b99456')
                ->where('filters.borderColor', '#8c6531')
            );
    }

    private function fakeActivityTrackerResponses(): void
    {
        Http::fake([
            'https://bitjita.com/api/players?q=icha' => Http::response([
                'players' => [[
                    'entityId' => '1224979098725428189',
                    'username' => 'Icha',
                    'signedIn' => true,
                    'updatedAt' => '2026-07-21 09:00:00+00',
                ]],
                'total' => 1,
            ]),
            'https://bitjita.com/api/players/1224979098725428189' => Http::response([
                'player' => [
                    'entityId' => '1224979098725428189',
                    'username' => 'Icha',
                    'signedIn' => true,
                    'updatedAt' => '2026-07-21 09:00:00+00',
                    'experience' => [[
                        'quantity' => 133354,
                        'skill_id' => 12,
                    ], [
                        'quantity' => 285390,
                        'skill_id' => 21,
                    ]],
                    'skillMap' => [
                        '12' => [
                            'id' => 12,
                            'name' => 'Fishing',
                            'title' => 'Fisher',
                            'skillCategoryStr' => 'Profession',
                        ],
                        '21' => [
                            'id' => 21,
                            'name' => 'Sailing',
                            'title' => 'Sailor',
                            'skillCategoryStr' => 'Adventure',
                        ],
                    ],
                ],
            ]),
            'https://bitjita.com/static/experience/levels.json' => Http::response([
                ['level' => 38, 'xp' => 253930],
                ['level' => 39, 'xp' => 283840],
                ['level' => 40, 'xp' => 317220],
            ]),
        ]);
    }

    private function fakeInventoryTrackerResponses(): void
    {
        Http::fake([
            'https://bitjita.com/api/players?q=icha' => Http::response([
                'players' => [[
                    'entityId' => '1224979098725428189',
                    'username' => 'Icha',
                ]],
                'total' => 1,
            ]),
            'https://bitjita.com/api/players/1224979098725428189' => Http::response([
                'player' => [
                    'entityId' => '1224979098725428189',
                    'username' => 'Icha',
                ],
            ]),
            'https://bitjita.com/api/items' => Http::response([
                'items' => [[
                    'id' => 1421716234,
                    'name' => 'Astralite Pickaxe',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                    'tag' => 'Miner Tool',
                ], [
                    'id' => 1516591189,
                    'name' => 'Vibrant Janus',
                    'tier' => 6,
                    'rarityStr' => 'Common',
                    'tag' => 'Ocean Fish',
                ]],
            ]),
            'https://bitjita.com/api/cargo' => Http::response([
                'cargos' => [[
                    'id' => 6000,
                    'name' => 'Briny Linus',
                    'tier' => 1,
                    'rarityStr' => 'Common',
                    'tag' => 'Ocean Fish',
                ]],
            ]),
            'https://bitjita.com/api/players/1224979098725428189/inventories' => Http::response([
                'inventories' => [
                    [
                        'entityId' => 'inventory-1',
                        'inventoryName' => 'Inventory',
                        'pockets' => [[
                            'contents' => [
                                'itemId' => 1516591189,
                                'itemType' => 0,
                                'quantity' => 10,
                            ],
                        ], [
                            'contents' => [
                                'itemId' => 6000,
                                'itemType' => 1,
                                'quantity' => 3,
                            ],
                        ]],
                    ],
                    [
                        'entityId' => 'cart-1',
                        'inventoryName' => 'Icha\'s Cart (II)',
                        'pockets' => [[
                            'contents' => [
                                'itemId' => 1516591189,
                                'itemType' => 0,
                                'quantity' => 14,
                            ],
                        ]],
                    ],
                    [
                        'entityId' => 'cache-1',
                        'inventoryName' => 'Icha\'s Personal Cache (I)',
                        'pockets' => [[
                            'contents' => [
                                'itemId' => 1516591189,
                                'itemType' => 0,
                                'quantity' => 5,
                            ],
                        ]],
                    ],
                    [
                        'entityId' => 'bank-1',
                        'inventoryName' => 'Town Bank',
                        'pockets' => [[
                            'contents' => [
                                'itemId' => 1516591189,
                                'itemType' => 0,
                                'quantity' => 999,
                            ],
                        ]],
                    ],
                ],
                'items' => [
                    '1516591189' => [
                        'name' => 'Vibrant Janus',
                        'tier' => 6,
                        'rarityStr' => 'Common',
                        'tag' => 'Ocean Fish',
                    ],
                ],
                'cargos' => [
                    '6000' => [
                        'name' => 'Briny Linus',
                        'tier' => 1,
                        'rarityStr' => 'Common',
                        'tag' => 'Ocean Fish',
                    ],
                ],
            ]),
        ]);
    }
}
