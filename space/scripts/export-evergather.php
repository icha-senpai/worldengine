<?php

// One-time, read-only migration of public game definitions. PHP is not an app dependency.
require __DIR__.'/../../ichaa/vendor/autoload.php';
$app = require __DIR__.'/../../ichaa/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\ConnectedRealms\Services\EconomyAuditService;
use App\Domain\ConnectedRealms\Services\WorldEventService;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsPlayerService;
use App\Domain\ConnectedRealms\Services\ToolCatalogService;
use App\Domain\ConnectedRealms\Services\ItemCatalogService;
use App\Domain\ConnectedRealms\Services\ToolRarityUpgradeService;
use App\Domain\ConnectedRealms\Services\ToolEffectService;
use App\Domain\ConnectedRealms\Services\ProgressionService;

$content = $app->make(EconomyAuditService::class)->catalogs();
$content['world_events'] = WorldEventService::baseEvents();
$content['character_options'] = $app->make(ConnectedRealmsPlayerService::class)->characterOptions();
$content['rarities'] = $app->make(ToolCatalogService::class)->rarities();
$content['item_rules'] = $app->make(ItemCatalogService::class)->baseKeyRules();
$tools = $app->make(ToolCatalogService::class);
$skillCatalog = $app->make(App\Domain\ConnectedRealms\Services\SkillCatalogService::class);
$reference = ['experience' => [], 'durability' => []];
foreach (range(1, 100) as $level) {
    $reference['experience'][] = ['level' => $level, 'experience' => $skillCatalog->experienceForLevel($level)];
}
foreach ([0, ...range(1, 100)] as $level) {
    foreach ($tools->rarities() as $rarity) {
        $reference['durability'][] = ['level' => $level, 'rarity' => $rarity, 'maximum' => $tools->maxDurabilityFor($level, $rarity)];
    }
}
$fixtureDirectory = __DIR__.'/../tests/fixtures';
if (! is_dir($fixtureDirectory)) mkdir($fixtureDirectory, 0777, true);
file_put_contents($fixtureDirectory.'/legacy-rules.json', json_encode($reference, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
$players = $app->make(ConnectedRealmsPlayerService::class);
$starterMethod = new ReflectionMethod($players, 'starterEquipmentForFamily');
foreach ($content['tool_families'] as $skill => $family) {
    $content['starter_tools'][$skill] = $starterMethod->invoke($players, $family);
    foreach ($tools->tierPath() as $tier) {
        $content['tool_upgrades'][$skill.':'.$tier['level']] = [
            'label' => $tools->tierToolName($family, $tier),
            'skill' => $family['craft'],
            'equipment_skill' => $skill,
            'required_level' => $tier['level'],
            'gold_cost' => $tier['gold_cost'],
            'experience' => $tier['xp'],
            'ingredients' => $tools->tierIngredients($family, $tier, $tier['extra']),
            'output' => ['item_key' => $tools->tierToolKey($family, $tier), 'item_name' => $tools->tierToolName($family, $tier)],
            'bonuses' => ['experience' => $tier['experience_bonus'], 'yield' => $tier['yield_bonus']],
        ];
    }
}
$content['rarity_rules'] = (new ReflectionClass(ToolRarityUpgradeService::class))->getConstant('RULES');
$content['rarity_bonuses'] = (new ReflectionClass(ToolRarityUpgradeService::class))->getConstant('RARITY_BONUSES');
$content['rarity_effects'] = (new ReflectionClass(ToolEffectService::class))->getConstant('RARITY_EFFECTS');
foreach ($tools->rarities() as $rarity) {
    $content['rarity_materials'][$rarity] = $tools->rarityMaterials($rarity);
}
$items = $app->make(ItemCatalogService::class);
foreach (['gathering_actions', 'skill_activities', 'crafting_recipes', 'job_contracts', 'expeditions', 'shop_offers'] as $kind) {
    foreach ($content[$kind] as $definition) {
        $candidates = [];
        foreach (['loot', 'outputs', 'ingredients', 'requirements', 'supplies', 'rewards'] as $field) {
            $candidates = array_merge($candidates, $definition[$field] ?? []);
        }
        if (isset($definition['item_key'])) {
            $candidates[] = $definition;
        }
        foreach ($candidates as $item) {
            $key = $item['item_key'] ?? $item['key'] ?? null;
            if ($key === null) {
                continue;
            }
            foreach ($tools->rarities() as $rarity) {
                $content['item_metadata'][$key.':'.$rarity] = $items->enrich([
                    ...$item, 'item_key' => $key, 'item_name' => $item['item_name'] ?? $item['name'] ?? $key,
                    'rarity' => $rarity, 'quantity' => 1,
                ]);
            }
        }
    }
}
$progression = $app->make(ProgressionService::class);
$achievementMethod = new ReflectionMethod($progression, 'achievement');
$progressionSource = file_get_contents(__DIR__.'/../../ichaa/app/Domain/ConnectedRealms/Services/ProgressionService.php');
preg_match_all('/\$this->achievement\(\x27([^\x27]+)\x27, \x27([^\x27]+)\x27, \x27([^\x27]+)\x27, (.*?), \x27([^\x27]+)\x27\),/', $progressionSource, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    $content['achievements'][$match[1]] = [
        ...$achievementMethod->invoke($progression, $match[1], $match[2], $match[3], false, $match[5]),
        'legacy_condition' => $match[4],
    ];
}
foreach ((new ReflectionMethod($progression, 'accountMilestoneAchievements'))->invoke($progression, 1000) as $achievement) {
    $content['achievements'][$achievement['key']] = $achievement;
}
$allSkillLevels = collect($content['skills'])->mapWithKeys(fn ($skill, $key) => [$key => 100]);
foreach ((new ReflectionMethod($progression, 'skillMilestoneAchievements'))->invoke($progression, $allSkillLevels) as $achievement) {
    $content['achievements'][$achievement['key']] = $achievement;
}
$content['exported_at'] = gmdate('c');
$directory = __DIR__.'/../spacetimedb/src/content';
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
file_put_contents($directory.'/evergather.json', json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
foreach ($content as $kind => $rows) {
    if (is_array($rows)) {
        echo $kind.': '.count($rows).PHP_EOL;
    }
}
