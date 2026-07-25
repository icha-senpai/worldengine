<?php

namespace Tests\Feature\Bitcraft;

use App\Mcp\Servers\DataverseServer;
use App\Mcp\Tools\GetBitcraftRecipeTool;
use App\Mcp\Tools\SearchBitcraftBarterStallsTool;
use App\Mcp\Tools\SearchBitcraftMarketTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitcraftApiMcpToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.bitcraft_spacetime.enabled', false);
    }

    public function test_bitcraft_api_requires_read_token(): void
    {
        $this->getJson('/api/v1/bitcraft/market?q=Pickaxe')
            ->assertUnauthorized();

        $this->withToken($this->assistantToken(['write:*']))
            ->getJson('/api/v1/bitcraft/market?q=Pickaxe')
            ->assertForbidden();
    }

    public function test_bitcraft_market_api_returns_order_book(): void
    {
        $this->fakeMarketSearch();

        $this->withToken($this->assistantToken(['read:*']))
            ->getJson('/api/v1/bitcraft/market?q=Pickaxe&itemId=1421716234&itemKind=item&hasOrders=1')
            ->assertOk()
            ->assertJsonPath('data.tool.key', 'market')
            ->assertJsonPath('data.filters.q', 'Pickaxe')
            ->assertJsonPath('data.market.items.0.name', 'Astralite Pickaxe')
            ->assertJsonPath('data.market.orderBook.item.name', 'Astralite Pickaxe')
            ->assertJsonPath('meta.tool', 'market');
    }

    public function test_bitcraft_barter_stalls_api_returns_stall_listings(): void
    {
        $this->fakeBarterStallSearch();

        $this->withToken($this->assistantToken(['read:*']))
            ->getJson('/api/v1/bitcraft/barter-stalls?q=Vibrant&region=Solmere&side=sell')
            ->assertOk()
            ->assertJsonPath('data.tool.key', 'barter-stalls')
            ->assertJsonPath('data.market.listings.0.itemName', 'Vibrant Janus')
            ->assertJsonPath('data.market.listings.0.priceCurrency', 'Hex Coin')
            ->assertJsonPath('data.market.tradeBuildings.0.buildingNickname', 'Icha Cart')
            ->assertJsonPath('meta.tool', 'barter-stalls');
    }

    public function test_bitcraft_crafting_api_returns_recipe_tree(): void
    {
        $this->fakeCraftingLookup();

        $this->withToken($this->assistantToken(['read:*']))
            ->getJson('/api/v1/bitcraft/crafting?q=Pickaxe&itemId=1421716234&itemKind=item&quantity=2')
            ->assertOk()
            ->assertJsonPath('data.filters.q', 'Pickaxe')
            ->assertJsonPath('data.detail.item.name', 'Astralite Pickaxe')
            ->assertJsonPath('data.detail.recipeTree.0.ingredients.0.name', 'Astralite Ingot')
            ->assertJsonPath('data.detail.recipeTree.0.ingredients.0.rarity', 'Rare')
            ->assertJsonPath('meta.tool', 'crafting');
    }

    public function test_bitcraft_mcp_tools_proxy_to_the_authenticated_api(): void
    {
        config()->set('services.dataverse_mcp.token', $this->assistantToken(['read:*']));

        $this->fakeMarketSearch();

        DataverseServer::tool(SearchBitcraftMarketTool::class, [
            'q' => 'Pickaxe',
            'itemId' => 1421716234,
            'itemKind' => 'item',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.market.orderBook.item.name', 'Astralite Pickaxe')
                ->etc();
        });

        Cache::flush();
        $this->fakeBarterStallSearch();

        DataverseServer::tool(SearchBitcraftBarterStallsTool::class, [
            'q' => 'Vibrant',
            'region' => 'Solmere',
            'side' => 'sell',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.market.listings.0.itemName', 'Vibrant Janus')
                ->etc();
        });

        Cache::flush();
        $this->fakeCraftingLookup();

        DataverseServer::tool(GetBitcraftRecipeTool::class, [
            'q' => 'Pickaxe',
            'itemId' => 1421716234,
            'itemKind' => 'item',
            'quantity' => 2,
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.detail.recipeTree.0.ingredients.0.name', 'Astralite Ingot')
                ->etc();
        });
    }

    private function fakeMarketSearch(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([
                ['regionId' => 8, 'regionName' => 'Solmere'],
            ]),
            'https://bitjita.com/api/market/item/1421716234*' => Http::response([
                'item' => [
                    'id' => 1421716234,
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
                    'regionName' => 'Solmere',
                ]],
                'buyOrders' => [],
                'stats' => [],
            ]),
            'https://bitjita.com/api/market*' => Http::response([
                'data' => [
                    'items' => [[
                        'id' => 1421716234,
                        'name' => 'Astralite Pickaxe',
                        'category' => 'Miner Tool',
                        'tier' => 5,
                        'rarityStr' => 'Rare',
                        'sellOrders' => 1,
                        'buyOrders' => 0,
                    ]],
                    'categories' => ['Miner Tool'],
                    'metrics' => ['totalItems' => 1],
                ],
            ]),
        ]);
    }

    private function fakeBarterStallSearch(): void
    {
        Http::fake([
            'https://bitjita.com/api/regions' => Http::response([
                ['regionId' => 8, 'regionName' => 'Solmere'],
            ]),
            'https://bitjita.com/api/stalls?page=1&limit=100' => Http::response([
                'stalls' => [[
                    'entityId' => 'stall-1',
                    'ownerName' => 'Icha',
                    'regionId' => 8,
                    'regionName' => 'Solmere',
                    'nickname' => 'Icha Cart',
                    'claimName' => 'Omashu',
                    'orderCount' => 1,
                    'orders' => [[
                        'entityId' => 'barter-order-1',
                        'remainingStock' => 3,
                        'offerItems' => [[
                            'itemId' => 1516591189,
                            'itemName' => 'Vibrant Janus',
                            'quantity' => 5,
                            'iconAssetName' => 'GeneratedIcons/Items/Fish',
                        ]],
                        'requiredItems' => [[
                            'itemId' => 1,
                            'itemName' => 'Hex Coin',
                            'quantity' => 500,
                            'iconAssetName' => 'GeneratedIcons/Items/HexCoin',
                        ]],
                    ]],
                ]],
                'totalPages' => 1,
            ]),
        ]);
    }

    private function fakeCraftingLookup(): void
    {
        Http::fake([
            'https://bitjita.com/api/items?q=Pickaxe' => Http::response([
                'items' => [[
                    'id' => 1421716234,
                    'name' => 'Astralite Pickaxe',
                    'tag' => 'Miner Tool',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                    'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                ]],
            ]),
            'https://bitjita.com/api/cargo?q=Pickaxe' => Http::response([
                'cargos' => [],
            ]),
            'https://bitjita.com/api/items/1421716234' => Http::response([
                'item' => [
                    'id' => 1421716234,
                    'name' => 'Astralite Pickaxe',
                    'tag' => 'Miner Tool',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                    'iconAssetName' => 'GeneratedIcons/Items/Pickaxe',
                ],
                'craftingRecipes' => [[
                    'id' => 55,
                    'recipeName' => 'Forge Astralite Pickaxe',
                    'craftingStation' => 'Smithy',
                    'ingredients' => [[
                        'itemId' => 111,
                        'itemName' => 'Astralite Ingot',
                        'quantity' => 3,
                        'iconAssetName' => 'GeneratedIcons/Items/Ingot',
                        'tier' => 5,
                        'rarityStr' => 'Rare',
                    ]],
                ]],
                'extractionRecipes' => [],
            ]),
            'https://bitjita.com/api/items/111' => Http::response([
                'item' => [
                    'id' => 111,
                    'name' => 'Astralite Ingot',
                    'tag' => 'Ingot',
                    'tier' => 5,
                    'rarityStr' => 'Rare',
                    'iconAssetName' => 'GeneratedIcons/Items/Ingot',
                ],
                'craftingRecipes' => [],
                'extractionRecipes' => [],
            ]),
        ]);
    }

    private function assistantToken(array $abilities): string
    {
        return User::factory()->create()->createToken('assistant', $abilities)->plainTextToken;
    }
}
