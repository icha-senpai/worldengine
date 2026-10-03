<?php

// Read-only reference values from ICHAA. Unsaved models never write player data.
require __DIR__.'/../../ichaa/vendor/autoload.php';
$app = require __DIR__.'/../../ichaa/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsPlayerService;
use App\Domain\ConnectedRealms\Services\ItemCatalogService;
use App\Domain\ConnectedRealms\Services\ToolEffectService;

$content = json_decode(file_get_contents(__DIR__.'/../spacetimedb/src/content/evergather.json'), true, flags: JSON_THROW_ON_ERROR);
$players = $app->make(ConnectedRealmsPlayerService::class);
$items = $app->make(ItemCatalogService::class);
$effects = (new ReflectionClass(ToolEffectService::class))->getConstant('RARITY_EFFECTS');
$samples = [];
foreach (['fishing', 'smithing', 'magic'] as $skill) {
    foreach ([1, 5, 65, 100] as $level) {
        $upgrade = $content['tool_upgrades'][$skill.':'.$level];
        foreach ($content['rarities'] as $rarity) {
            foreach ([0, 100] as $durability) {
                foreach ([0, 3] as $history) {
                    $tool = new ConnectedRealmsTool([
                        'skill' => $skill, 'slot' => 'tool_'.$skill,
                        'item_key' => $upgrade['output']['item_key'],
                        'item_name' => $upgrade['output']['item_name'],
                        'rarity' => $rarity, 'durability' => $durability,
                        'tier_level' => $level, 'bonuses' => $upgrade['bonuses'],
                        'upgrade_count' => $history, 'tier_upgrade_count' => $history,
                        'rarity_upgrade_attempts' => $history, 'rarity_progress' => 0,
                        'origin' => 'crafted', 'status' => 'inventory', 'maker_name' => 'Reference crafter',
                    ]);
                    $tool->id = count($samples) + 1;
                    $payload = $players->toolInstancePayload($tool);
                    $metadata = $items->enrich([
                        'item_key' => $tool->item_key, 'item_name' => $tool->item_name,
                        'rarity' => $rarity, 'quantity' => 1,
                    ]);
                    $samples[] = [
                        'skill' => $skill, 'rarity' => $rarity,
                        'tool' => [
                            'tierLevel' => $level, 'durability' => $durability,
                            'upgrades' => $history, 'tierUpgrades' => $history,
                            'bonuses' => json_encode($upgrade['bonuses'], JSON_THROW_ON_ERROR),
                        ],
                        'metadata' => [
                            'vendor_value' => $metadata['vendor_value'],
                            'market_floor_price' => $metadata['market_floor_price'],
                        ],
                        'rarityMarketPremium' => $effects[$rarity]['market'],
                        'prices' => [
                            'marketFloorPrice' => $payload['market_floor_price'],
                            'marketCeilingPrice' => $payload['market_ceiling_price'],
                            'npcBuyPrice' => $payload['npc_buy_price'],
                        ],
                    ];
                }
            }
        }
    }
}
file_put_contents(__DIR__.'/../tests/fixtures/legacy-tool-values.json', json_encode($samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo 'Exported '.count($samples).' tool valuation references.'.PHP_EOL;
