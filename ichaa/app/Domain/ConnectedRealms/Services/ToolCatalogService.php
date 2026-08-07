<?php

namespace App\Domain\ConnectedRealms\Services;

class ToolCatalogService
{
    /**
     * @var list<string>
     */
    private const RARITY_ORDER = ['common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic'];

    /**
     * @var array<string, string>
     */
    private const TOOL_MODEL_PREFIXES = [
        'starter' => 'Workshop',
        'local' => 'Roadside',
        'apprentice' => 'Bench-Made',
        'guild' => 'Guildhall',
        'runed' => 'Inscribed',
        'storm' => 'Weatherproof',
        'elite' => 'Highroad',
        'elder' => 'Oldhall',
        'mythic' => 'Gatewright',
        'evergather' => 'Firsthall',
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $familiesCache = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $tierPathCache = null;

    /**
     * @return array<string, array{label: string, noun: string, line: string, slot: string, skill: string, craft: string, base: string, base_name: string, starter_item_key?: string, starter_item_name?: string}>
     */
    public function families(): array
    {
        if ($this->familiesCache !== null) {
            return $this->familiesCache;
        }

        $this->familiesCache = app(ConnectedRealmsContentService::class)->apply('tool_families', $this->baseFamilies());

        return $this->familiesCache;
    }

    /**
     * @return array<string, array{label: string, noun: string, line: string, slot: string, skill: string, craft: string, base: string, base_name: string, starter_item_key?: string, starter_item_name?: string}>
     */
    public function baseFamilies(): array
    {
        $families = [
            'fishing' => ['label' => 'Fishing', 'noun' => 'Rod', 'line' => 'Tidehook', 'slot' => 'tool_fishing', 'skill' => 'fishing', 'craft' => 'carpentry', 'base' => 'ashwood_plank', 'base_name' => 'Ashwood Plank', 'starter_item_key' => 'reed_rod', 'starter_item_name' => 'Reed Rod'],
            'mining' => ['label' => 'Mining', 'noun' => 'Pickaxe', 'line' => 'Stonebite', 'slot' => 'tool_mining', 'skill' => 'mining', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar', 'starter_item_key' => 'worn_pickaxe', 'starter_item_name' => 'Worn Pickaxe'],
            'woodcutting' => ['label' => 'Woodcutting', 'noun' => 'Hatchet', 'line' => 'Boughsplitter', 'slot' => 'tool_woodcutting', 'skill' => 'woodcutting', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar', 'starter_item_key' => 'trail_hatchet', 'starter_item_name' => 'Trail Hatchet'],
            'foraging' => ['label' => 'Foraging', 'noun' => 'Satchel', 'line' => 'Mosskeeper', 'slot' => 'tool_foraging', 'skill' => 'foraging', 'craft' => 'tailoring', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread', 'starter_item_key' => 'woven_satchel', 'starter_item_name' => 'Woven Satchel'],
            'hunting' => ['label' => 'Hunting', 'noun' => 'Trap Kit', 'line' => 'Snarefang', 'slot' => 'tool_hunting', 'skill' => 'hunting', 'craft' => 'leatherworking', 'base' => 'cured_leather', 'base_name' => 'Cured Leather', 'starter_item_key' => 'snare_kit', 'starter_item_name' => 'Snare Kit'],
            'farming' => ['label' => 'Farming', 'noun' => 'Cultivator', 'line' => 'Seedwake', 'slot' => 'tool_farming', 'skill' => 'farming', 'craft' => 'engineering', 'base' => 'clockwork_spring', 'base_name' => 'Clockwork Spring', 'starter_item_key' => 'seed_spade', 'starter_item_name' => 'Seed Spade'],
            'excavation' => ['label' => 'Excavation', 'noun' => 'Survey Trowel', 'line' => 'Relicprobe', 'slot' => 'tool_excavation', 'skill' => 'excavation', 'craft' => 'engineering', 'base' => 'relic_fragment', 'base_name' => 'Relic Fragment', 'starter_item_key' => 'field_trowel', 'starter_item_name' => 'Field Trowel'],
        ];

        foreach ($this->professionalFamilies() as $skill => $family) {
            $families[$skill] = [
                'label' => $family['label'],
                'noun' => $family['noun'],
                'line' => $family['line'],
                'slot' => "tool_{$skill}",
                'skill' => $skill,
                'craft' => $family['craft'],
                'base' => $family['base'],
                'base_name' => $family['base_name'],
                'starter_item_key' => str($family['starter_item_name'])->slug('_')->toString(),
                'starter_item_name' => $family['starter_item_name'],
            ];
        }

        return $families;
    }

    /**
     * @return list<array{name_mark: string, rarity: string, level: int, item_tier: int, xp: int, experience_bonus: int, yield_bonus: int, gold_cost: int, extra: array{item_key: string, item_name: string, quantity: int}|null}>
     */
    public function tierPath(): array
    {
        if ($this->tierPathCache !== null) {
            return $this->tierPathCache;
        }

        $this->tierPathCache = app(ConnectedRealmsContentService::class)->applyList(
            'tool_tiers',
            $this->tierPathFor(EvergatherTierCatalog::tiers()),
            'name_mark',
        );

        return $this->tierPathCache;
    }

    /**
     * @return list<array{name_mark: string, rarity: string, level: int, item_tier: int, xp: int, experience_bonus: int, yield_bonus: int, gold_cost: int, extra: array{item_key: string, item_name: string, quantity: int}|null}>
     */
    public function baseTierPath(): array
    {
        return $this->tierPathFor(EvergatherTierCatalog::baseTiers());
    }

    /**
     * @param  list<array{level: int, item_tier: int, key_slug: string, mark: string, rarity: string}>  $tiers
     * @return list<array{name_mark: string, rarity: string, level: int, item_tier: int, xp: int, experience_bonus: int, yield_bonus: int, gold_cost: int, extra: array{item_key: string, item_name: string, quantity: int}|null}>
     */
    private function tierPathFor(array $tiers): array
    {
        $stats = [
            'starter' => ['xp' => 44, 'experience_bonus' => 9, 'yield_bonus' => 2, 'gold_cost' => 35, 'extra' => ['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1]],
            'local' => ['xp' => 58, 'experience_bonus' => 12, 'yield_bonus' => 2, 'gold_cost' => 55, 'extra' => ['item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'quantity' => 2]],
            'apprentice' => ['xp' => 72, 'experience_bonus' => 15, 'yield_bonus' => 3, 'gold_cost' => 75, 'extra' => ['item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'quantity' => 1]],
            'guild' => ['xp' => 86, 'experience_bonus' => 17, 'yield_bonus' => 3, 'gold_cost' => 90, 'extra' => ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1]],
            'runed' => ['xp' => 112, 'experience_bonus' => 22, 'yield_bonus' => 4, 'gold_cost' => 160, 'extra' => null],
            'storm' => ['xp' => 132, 'experience_bonus' => 26, 'yield_bonus' => 5, 'gold_cost' => 260, 'extra' => null],
            'elite' => ['xp' => 146, 'experience_bonus' => 28, 'yield_bonus' => 5, 'gold_cost' => 420, 'extra' => null],
            'elder' => ['xp' => 180, 'experience_bonus' => 35, 'yield_bonus' => 6, 'gold_cost' => 600, 'extra' => null],
            'mythic' => ['xp' => 240, 'experience_bonus' => 46, 'yield_bonus' => 8, 'gold_cost' => 900, 'extra' => null],
            'evergather' => ['xp' => 340, 'experience_bonus' => 65, 'yield_bonus' => 10, 'gold_cost' => 1400, 'extra' => null],
        ];

        return collect($tiers)
            ->map(fn (array $tier): array => [
                'name_mark' => $tier['mark'],
                'rarity' => $tier['rarity'],
                'level' => $tier['level'],
                'item_tier' => (int) $tier['item_tier'],
                ...$stats[$tier['key_slug']],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $family
     * @param  array<string, mixed>  $tier
     */
    public function tierToolName(array $family, array $tier): string
    {
        $model = $this->toolModelPrefixFor((string) $tier['name_mark']);

        return "{$model} {$family['line']} {$family['noun']}";
    }

    /**
     * @param  array<string, mixed>  $family
     * @param  array<string, mixed>  $tier
     */
    public function tierToolKey(array $family, array $tier): string
    {
        return str($this->legacyTierToolName($family, $tier))->slug('_')->toString();
    }

    /**
     * @param  array<string, mixed>  $family
     * @param  array<string, mixed>  $tier
     */
    private function legacyTierToolName(array $family, array $tier): string
    {
        return "{$tier['name_mark']} {$family['line']} {$family['noun']}";
    }

    private function toolModelPrefixFor(string $mark): string
    {
        foreach (EvergatherTierCatalog::tiers() as $tier) {
            if (strcasecmp((string) $tier['mark'], $mark) === 0) {
                return self::TOOL_MODEL_PREFIXES[$tier['key_slug']] ?? self::TOOL_MODEL_PREFIXES['starter'];
            }
        }

        return self::TOOL_MODEL_PREFIXES['starter'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function nextTierFor(string $skill, int $currentTierLevel): ?array
    {
        $effectiveLevel = max(0, $currentTierLevel);

        return collect($this->tierPath())
            ->first(fn (array $tier): bool => $tier['level'] > $effectiveLevel);
    }

    /**
     * @return list<string>
     */
    public function rarities(): array
    {
        return self::RARITY_ORDER;
    }

    public function nextRarity(string $rarity): ?string
    {
        $rank = $this->rarityRank($rarity);

        return self::RARITY_ORDER[$rank + 1] ?? null;
    }

    public function rarityRank(string $rarity): int
    {
        $rank = array_search($rarity, self::RARITY_ORDER, true);

        return $rank === false ? 0 : (int) $rank;
    }

    public function maxRarityForTierLevel(int $tierLevel): string
    {
        $itemTier = $tierLevel <= 0 ? 1 : EvergatherTierCatalog::itemTierForLevel($tierLevel);
        $rank = max(0, min(count(self::RARITY_ORDER) - 1, $itemTier - 1));

        return self::RARITY_ORDER[$rank];
    }

    public function rarityAllowedAtTier(string $rarity, int $tierLevel): bool
    {
        return $this->rarityRank($rarity) <= $this->rarityRank($this->maxRarityForTierLevel($tierLevel));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function familyForSkill(string $skill): ?array
    {
        return $this->families()[$skill] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function familyForSlot(string $slot): ?array
    {
        return collect($this->families())->first(fn (array $family): bool => $family['slot'] === $slot);
    }

    /**
     * @param  array{item_key: string, item_name: string, quantity: int}|null  $extra
     * @return list<array{item_key: string, item_name: string, quantity: int}>
     */
    public function tierIngredients(array $family, array $tier, ?array $extra): array
    {
        return array_values(array_filter([
            [
                'item_key' => $family['base'],
                'item_name' => $family['base_name'],
                'quantity' => $tier['level'] >= 50 ? 3 : 2,
            ],
            $extra,
        ]));
    }

    /**
     * @return list<array{item_key: string, item_name: string, quantity: int}>
     */
    public function rarityMaterials(string $currentRarity): array
    {
        return match ($currentRarity) {
            'common' => [['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1]],
            'uncommon' => [['item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'quantity' => 1]],
            'rare' => [
                ['item_key' => 'rune_thread', 'item_name' => 'Rune Thread', 'quantity' => 1],
                ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1],
            ],
            'epic' => [
                ['item_key' => 'highguild_ingot', 'item_name' => 'Highguild Ingot', 'quantity' => 1],
                ['item_key' => 'arcane_focus', 'item_name' => 'Arcane Focus', 'quantity' => 1],
            ],
            'legendary' => [
                ['item_key' => 'gate_core', 'item_name' => 'Gate Core', 'quantity' => 1],
                ['item_key' => 'secret_atlas_leaf', 'item_name' => 'Secret Atlas Leaf', 'quantity' => 1],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array{label: string, noun: string, line: string, craft: string, base: string, base_name: string, starter_item_name: string}>
     */
    private function professionalFamilies(): array
    {
        return [
            'smelting' => ['label' => 'Smelting', 'noun' => 'Crucible', 'line' => 'Coalbed', 'craft' => 'smelting', 'base' => 'coal_chunk', 'base_name' => 'Coal Chunk', 'starter_item_name' => 'Coalbed Crucible'],
            'milling' => ['label' => 'Milling', 'noun' => 'Hand Plane', 'line' => 'Whisperplane', 'craft' => 'milling', 'base' => 'ashwood_log', 'base_name' => 'Ashwood Log', 'starter_item_name' => 'Ashwood Hand Plane'],
            'tanning' => ['label' => 'Tanning', 'noun' => 'Curing Rack', 'line' => 'Briarhide', 'craft' => 'tanning', 'base' => 'soft_hide', 'base_name' => 'Soft Hide', 'starter_item_name' => 'Briarhide Curing Rack'],
            'cutting' => ['label' => 'Gem Cutting', 'noun' => 'Lapidary Kit', 'line' => 'Prismfacet', 'craft' => 'cutting', 'base' => 'rough_gem', 'base_name' => 'Rough Gem', 'starter_item_name' => 'Prismdust Lapidary Kit'],
            'weaving' => ['label' => 'Weaving', 'noun' => 'Loom Shuttle', 'line' => 'Sunshuttle', 'craft' => 'weaving', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread', 'starter_item_name' => 'Sunfield Loom Shuttle'],
            'smithing' => ['label' => 'Smithing', 'noun' => 'Hammer', 'line' => 'Ironhand', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar', 'starter_item_name' => 'Ironhand Hammer'],
            'carpentry' => ['label' => 'Carpentry', 'noun' => 'Carving Kit', 'line' => 'Dovetail', 'craft' => 'carpentry', 'base' => 'ashwood_plank', 'base_name' => 'Ashwood Plank', 'starter_item_name' => 'Ashwood Carving Kit'],
            'cooking' => ['label' => 'Cooking', 'noun' => 'Cook Kit', 'line' => 'Hearthgrain', 'craft' => 'cooking', 'base' => 'sunfield_grain', 'base_name' => 'Sunfield Grain', 'starter_item_name' => 'Hearthgrain Cook Kit'],
            'alchemy' => ['label' => 'Alchemy', 'noun' => 'Alembic', 'line' => 'Mooncap', 'craft' => 'alchemy', 'base' => 'mooncap_mushroom', 'base_name' => 'Mooncap Mushroom', 'starter_item_name' => 'Mooncap Alembic'],
            'tailoring' => ['label' => 'Tailoring', 'noun' => 'Needle Kit', 'line' => 'Threadneedle', 'craft' => 'tailoring', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread', 'starter_item_name' => 'Threadneedle Kit'],
            'leatherworking' => ['label' => 'Leatherworking', 'noun' => 'Awl Kit', 'line' => 'Hideworn', 'craft' => 'leatherworking', 'base' => 'cured_leather', 'base_name' => 'Cured Leather', 'starter_item_name' => 'Hideworn Awl Kit'],
            'engineering' => ['label' => 'Engineering', 'noun' => 'Caliper Set', 'line' => 'Clockwork', 'craft' => 'engineering', 'base' => 'clockwork_spring', 'base_name' => 'Clockwork Spring', 'starter_item_name' => 'Clockwork Caliper Set'],
            'enchanting' => ['label' => 'Enchanting', 'noun' => 'Rune Focus', 'line' => 'Emberrune', 'craft' => 'enchanting', 'base' => 'minor_ward_oil', 'base_name' => 'Minor Ward Oil', 'starter_item_name' => 'Ember Rune Focus'],
            'jewelcrafting' => ['label' => 'Jewelcrafting', 'noun' => 'Setting Kit', 'line' => 'Beadlight', 'craft' => 'jewelcrafting', 'base' => 'polished_gem', 'base_name' => 'Polished Gem', 'starter_item_name' => 'Beadlight Setting Kit'],
            'boatbuilding' => ['label' => 'Boatbuilding', 'noun' => 'Shipwright Kit', 'line' => 'Skiffwright', 'craft' => 'boatbuilding', 'base' => 'skiff_rib', 'base_name' => 'Skiff Rib', 'starter_item_name' => 'Skiffwright Ship Kit'],
            'furniture' => ['label' => 'Furniture Crafting', 'noun' => 'Finishing Kit', 'line' => 'Hearthwood', 'craft' => 'furniture', 'base' => 'trophy_stand', 'base_name' => 'Trophy Stand', 'starter_item_name' => 'Hearthwood Finishing Kit'],
            'construction' => ['label' => 'Construction', 'noun' => 'Builder Kit', 'line' => 'Plumbline', 'craft' => 'construction', 'base' => 'repair_scaffold', 'base_name' => 'Repair Scaffold', 'starter_item_name' => 'Plumbline Builder Kit'],
            'combat' => ['label' => 'Combat', 'noun' => 'Blade', 'line' => 'Ironmark', 'craft' => 'smithing', 'base' => 'iron_knife', 'base_name' => 'Iron Knife', 'starter_item_name' => 'Ironmark Blade'],
            'slayer' => ['label' => 'Slayer', 'noun' => 'Bounty Kit', 'line' => 'Redfang', 'craft' => 'leatherworking', 'base' => 'marked_trophy_bone', 'base_name' => 'Marked Trophy Bone', 'starter_item_name' => 'Redfang Bounty Kit'],
            'defense' => ['label' => 'Defense', 'noun' => 'Shield Kit', 'line' => 'Rivetguard', 'craft' => 'smithing', 'base' => 'iron_fittings', 'base_name' => 'Iron Fittings', 'starter_item_name' => 'Rivetguard Shield Kit'],
            'healing' => ['label' => 'Healing', 'noun' => 'Medic Kit', 'line' => 'Fieldglass', 'craft' => 'alchemy', 'base' => 'field_tonic', 'base_name' => 'Field Tonic', 'starter_item_name' => 'Fieldglass Medic Kit'],
            'magic' => ['label' => 'Magic', 'noun' => 'Spell Focus', 'line' => 'Emberglow', 'craft' => 'enchanting', 'base' => 'ember_charm', 'base_name' => 'Ember Charm', 'starter_item_name' => 'Emberglow Spell Focus'],
            'ranged' => ['label' => 'Ranged', 'noun' => 'Bow Kit', 'line' => 'Trailstring', 'craft' => 'carpentry', 'base' => 'trail_bow', 'base_name' => 'Trail Bow', 'starter_item_name' => 'Trailstring Bow Kit'],
            'exploration' => ['label' => 'Exploration', 'noun' => 'Scout Kit', 'line' => 'Wayfinder', 'craft' => 'cartography', 'base' => 'route_map', 'base_name' => 'Route Map', 'starter_item_name' => 'Wayfinder Scout Kit'],
            'dungeoneering' => ['label' => 'Dungeoneering', 'noun' => 'Delver Kit', 'line' => 'Deepmark', 'craft' => 'cartography', 'base' => 'dungeon_chart', 'base_name' => 'Dungeon Chart', 'starter_item_name' => 'Deepmark Delver Kit'],
            'sailing' => ['label' => 'Sailing', 'noun' => 'Navigator Kit', 'line' => 'Tidechart', 'craft' => 'boatbuilding', 'base' => 'skiff_rib', 'base_name' => 'Skiff Rib', 'starter_item_name' => 'Tidechart Navigator Kit'],
            'survival' => ['label' => 'Survival', 'noun' => 'Camp Kit', 'line' => 'Ashcamp', 'craft' => 'cooking', 'base' => 'hunter_ration', 'base_name' => 'Hunter Ration', 'starter_item_name' => 'Ashcamp Camp Kit'],
            'cartography' => ['label' => 'Cartography', 'noun' => 'Map Case', 'line' => 'Ridgepath', 'craft' => 'cartography', 'base' => 'route_map', 'base_name' => 'Route Map', 'starter_item_name' => 'Ridgepath Map Case'],
            'reputation' => ['label' => 'Reputation', 'noun' => 'Envoy Ledger', 'line' => 'Councilmark', 'craft' => 'trading', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest', 'starter_item_name' => 'Council Envoy Ledger'],
            'leadership' => ['label' => 'Leadership', 'noun' => 'Command Banner', 'line' => 'Warcall', 'craft' => 'tailoring', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest', 'starter_item_name' => 'Warcall Command Banner'],
            'trading' => ['label' => 'Trading', 'noun' => 'Ledger', 'line' => 'Marketseal', 'craft' => 'trading', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest', 'starter_item_name' => 'Marketseal Ledger'],
        ];
    }
}
