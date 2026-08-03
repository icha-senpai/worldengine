<?php

namespace App\Domain\ConnectedRealms\Services;

class ToolCatalogService
{
    /**
     * @return array<string, array{label: string, noun: string, slot: string, skill: string, craft: string, base: string, base_name: string, starter_item_key?: string, starter_item_name?: string}>
     */
    public function families(): array
    {
        $families = [
            'fishing' => ['label' => 'Fishing', 'noun' => 'Rod', 'slot' => 'tool_fishing', 'skill' => 'fishing', 'craft' => 'carpentry', 'base' => 'ashwood_plank', 'base_name' => 'Ashwood Plank'],
            'mining' => ['label' => 'Mining', 'noun' => 'Pickaxe', 'slot' => 'tool_mining', 'skill' => 'mining', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar'],
            'woodcutting' => ['label' => 'Woodcutting', 'noun' => 'Hatchet', 'slot' => 'tool_woodcutting', 'skill' => 'woodcutting', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar'],
            'foraging' => ['label' => 'Foraging', 'noun' => 'Satchel', 'slot' => 'tool_foraging', 'skill' => 'foraging', 'craft' => 'tailoring', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread'],
            'hunting' => ['label' => 'Hunting', 'noun' => 'Trap Kit', 'slot' => 'tool_hunting', 'skill' => 'hunting', 'craft' => 'leatherworking', 'base' => 'cured_leather', 'base_name' => 'Cured Leather'],
            'farming' => ['label' => 'Farming', 'noun' => 'Cultivator', 'slot' => 'tool_farming', 'skill' => 'farming', 'craft' => 'engineering', 'base' => 'clockwork_spring', 'base_name' => 'Clockwork Spring'],
            'excavation' => ['label' => 'Excavation', 'noun' => 'Survey Trowel', 'slot' => 'tool_excavation', 'skill' => 'excavation', 'craft' => 'engineering', 'base' => 'relic_fragment', 'base_name' => 'Relic Fragment'],
        ];

        foreach ($this->professionalFamilies() as $skill => $family) {
            $families[$skill] = [
                'label' => $family['label'],
                'noun' => $family['noun'],
                'slot' => "tool_{$skill}",
                'skill' => $skill,
                'craft' => $family['craft'],
                'base' => $family['base'],
                'base_name' => $family['base_name'],
            ];
        }

        return $families;
    }

    /**
     * @return list<array{prefix: string, rarity: string, level: int, xp: int, experience_bonus: int, yield_bonus: int, gold_cost: int, extra: array{item_key: string, item_name: string, quantity: int}|null}>
     */
    public function tierPath(): array
    {
        return [
            ['prefix' => 'Apprentice', 'rarity' => 'uncommon', 'level' => 1, 'xp' => 44, 'experience_bonus' => 9, 'yield_bonus' => 2, 'gold_cost' => 35, 'extra' => ['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1]],
            ['prefix' => 'Guild', 'rarity' => 'rare', 'level' => 20, 'xp' => 86, 'experience_bonus' => 17, 'yield_bonus' => 3, 'gold_cost' => 90, 'extra' => ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1]],
            ['prefix' => 'Journeyman', 'rarity' => 'rare', 'level' => 25, 'xp' => 98, 'experience_bonus' => 19, 'yield_bonus' => 3, 'gold_cost' => 120, 'extra' => null],
            ['prefix' => 'Artisan', 'rarity' => 'rare', 'level' => 30, 'xp' => 112, 'experience_bonus' => 22, 'yield_bonus' => 4, 'gold_cost' => 160, 'extra' => null],
            ['prefix' => 'Expert', 'rarity' => 'epic', 'level' => 35, 'xp' => 124, 'experience_bonus' => 24, 'yield_bonus' => 4, 'gold_cost' => 210, 'extra' => null],
            ['prefix' => 'Runed', 'rarity' => 'epic', 'level' => 40, 'xp' => 132, 'experience_bonus' => 26, 'yield_bonus' => 5, 'gold_cost' => 260, 'extra' => null],
            ['prefix' => 'Crown', 'rarity' => 'epic', 'level' => 45, 'xp' => 140, 'experience_bonus' => 27, 'yield_bonus' => 5, 'gold_cost' => 330, 'extra' => null],
            ['prefix' => 'Masterwork', 'rarity' => 'epic', 'level' => 50, 'xp' => 146, 'experience_bonus' => 28, 'yield_bonus' => 5, 'gold_cost' => 420, 'extra' => ['item_key' => 'star_metal_ingot', 'item_name' => 'Star Metal Ingot', 'quantity' => 1]],
            ['prefix' => 'Mythic', 'rarity' => 'epic', 'level' => 75, 'xp' => 220, 'experience_bonus' => 42, 'yield_bonus' => 7, 'gold_cost' => 780, 'extra' => null],
            ['prefix' => 'Ascendant', 'rarity' => 'legendary', 'level' => 100, 'xp' => 340, 'experience_bonus' => 65, 'yield_bonus' => 10, 'gold_cost' => 1400, 'extra' => null],
        ];
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
                ['item_key' => 'star_metal_ingot', 'item_name' => 'Star Metal Ingot', 'quantity' => 1],
                ['item_key' => 'arcane_focus', 'item_name' => 'Arcane Focus', 'quantity' => 1],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array{label: string, noun: string, craft: string, base: string, base_name: string}>
     */
    private function professionalFamilies(): array
    {
        return [
            'smelting' => ['label' => 'Smelting', 'noun' => 'Crucible', 'craft' => 'smelting', 'base' => 'coal_chunk', 'base_name' => 'Coal Chunk'],
            'milling' => ['label' => 'Milling', 'noun' => 'Plane', 'craft' => 'milling', 'base' => 'ashwood_log', 'base_name' => 'Ashwood Log'],
            'tanning' => ['label' => 'Tanning', 'noun' => 'Curing Rack', 'craft' => 'tanning', 'base' => 'soft_hide', 'base_name' => 'Soft Hide'],
            'cutting' => ['label' => 'Gem Cutting', 'noun' => 'Lapidary Kit', 'craft' => 'cutting', 'base' => 'rough_gem', 'base_name' => 'Rough Gem'],
            'weaving' => ['label' => 'Weaving', 'noun' => 'Loom Shuttle', 'craft' => 'weaving', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread'],
            'smithing' => ['label' => 'Smithing', 'noun' => 'Hammer', 'craft' => 'smithing', 'base' => 'iron_bar', 'base_name' => 'Iron Bar'],
            'carpentry' => ['label' => 'Carpentry', 'noun' => 'Carving Kit', 'craft' => 'carpentry', 'base' => 'ashwood_plank', 'base_name' => 'Ashwood Plank'],
            'cooking' => ['label' => 'Cooking', 'noun' => 'Cook Kit', 'craft' => 'cooking', 'base' => 'sunfield_grain', 'base_name' => 'Sunfield Grain'],
            'alchemy' => ['label' => 'Alchemy', 'noun' => 'Alembic', 'craft' => 'alchemy', 'base' => 'mooncap_mushroom', 'base_name' => 'Mooncap Mushroom'],
            'tailoring' => ['label' => 'Tailoring', 'noun' => 'Needle Kit', 'craft' => 'tailoring', 'base' => 'fiber_thread', 'base_name' => 'Fiber Thread'],
            'leatherworking' => ['label' => 'Leatherworking', 'noun' => 'Awl Kit', 'craft' => 'leatherworking', 'base' => 'cured_leather', 'base_name' => 'Cured Leather'],
            'engineering' => ['label' => 'Engineering', 'noun' => 'Caliper Set', 'craft' => 'engineering', 'base' => 'clockwork_spring', 'base_name' => 'Clockwork Spring'],
            'enchanting' => ['label' => 'Enchanting', 'noun' => 'Rune Focus', 'craft' => 'enchanting', 'base' => 'minor_ward_oil', 'base_name' => 'Minor Ward Oil'],
            'jewelcrafting' => ['label' => 'Jewelcrafting', 'noun' => 'Setting Kit', 'craft' => 'jewelcrafting', 'base' => 'polished_gem', 'base_name' => 'Polished Gem'],
            'boatbuilding' => ['label' => 'Boatbuilding', 'noun' => 'Shipwright Kit', 'craft' => 'boatbuilding', 'base' => 'skiff_rib', 'base_name' => 'Skiff Rib'],
            'furniture' => ['label' => 'Furniture Crafting', 'noun' => 'Finishing Kit', 'craft' => 'furniture', 'base' => 'trophy_stand', 'base_name' => 'Trophy Stand'],
            'construction' => ['label' => 'Construction', 'noun' => 'Builder Kit', 'craft' => 'construction', 'base' => 'repair_scaffold', 'base_name' => 'Repair Scaffold'],
            'combat' => ['label' => 'Combat', 'noun' => 'Blade', 'craft' => 'smithing', 'base' => 'iron_knife', 'base_name' => 'Iron Knife'],
            'slayer' => ['label' => 'Slayer', 'noun' => 'Bounty Kit', 'craft' => 'leatherworking', 'base' => 'marked_trophy_bone', 'base_name' => 'Marked Trophy Bone'],
            'defense' => ['label' => 'Defense', 'noun' => 'Shield Kit', 'craft' => 'smithing', 'base' => 'iron_fittings', 'base_name' => 'Iron Fittings'],
            'healing' => ['label' => 'Healing', 'noun' => 'Medic Kit', 'craft' => 'alchemy', 'base' => 'field_tonic', 'base_name' => 'Field Tonic'],
            'magic' => ['label' => 'Magic', 'noun' => 'Spell Focus', 'craft' => 'enchanting', 'base' => 'ember_charm', 'base_name' => 'Ember Charm'],
            'ranged' => ['label' => 'Ranged', 'noun' => 'Bow Kit', 'craft' => 'carpentry', 'base' => 'trail_bow', 'base_name' => 'Trail Bow'],
            'exploration' => ['label' => 'Exploration', 'noun' => 'Scout Kit', 'craft' => 'cartography', 'base' => 'route_map', 'base_name' => 'Route Map'],
            'dungeoneering' => ['label' => 'Dungeoneering', 'noun' => 'Delver Kit', 'craft' => 'cartography', 'base' => 'dungeon_chart', 'base_name' => 'Dungeon Chart'],
            'sailing' => ['label' => 'Sailing', 'noun' => 'Navigator Kit', 'craft' => 'boatbuilding', 'base' => 'skiff_rib', 'base_name' => 'Skiff Rib'],
            'survival' => ['label' => 'Survival', 'noun' => 'Camp Kit', 'craft' => 'cooking', 'base' => 'hunter_ration', 'base_name' => 'Hunter Ration'],
            'cartography' => ['label' => 'Cartography', 'noun' => 'Map Case', 'craft' => 'cartography', 'base' => 'route_map', 'base_name' => 'Route Map'],
            'reputation' => ['label' => 'Reputation', 'noun' => 'Envoy Ledger', 'craft' => 'trading', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest'],
            'leadership' => ['label' => 'Leadership', 'noun' => 'Command Banner', 'craft' => 'tailoring', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest'],
            'trading' => ['label' => 'Trading', 'noun' => 'Ledger', 'craft' => 'trading', 'base' => 'trade_manifest', 'base_name' => 'Trade Manifest'],
        ];
    }
}
