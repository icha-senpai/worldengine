<?php

namespace App\Domain\ConnectedRealms\Services;

class ItemCatalogService
{
    private const RARITY_PROFILES = [
        'common' => ['quality' => 'standard', 'quality_score' => 40, 'value_multiplier' => 1, 'stack_limit' => 99],
        'uncommon' => ['quality' => 'fine', 'quality_score' => 55, 'value_multiplier' => 2, 'stack_limit' => 80],
        'rare' => ['quality' => 'superior', 'quality_score' => 70, 'value_multiplier' => 4, 'stack_limit' => 50],
        'epic' => ['quality' => 'exceptional', 'quality_score' => 85, 'value_multiplier' => 8, 'stack_limit' => 25],
        'legendary' => ['quality' => 'masterwork', 'quality_score' => 100, 'value_multiplier' => 16, 'stack_limit' => 10],
    ];

    /**
     * @var array<string, array{item_class: string, material_family: string, weight: float, base_value: int, tags: list<string>}>
     */
    private const KEY_RULES = [
        'shrimp' => ['item_class' => 'resource', 'material_family' => 'Shellfish', 'weight' => 0.08, 'base_value' => 2, 'tags' => ['food', 'shellfish']],
        'crab' => ['item_class' => 'resource', 'material_family' => 'Shellfish', 'weight' => 0.3, 'base_value' => 4, 'tags' => ['food', 'shellfish']],
        'minnow' => ['item_class' => 'resource', 'material_family' => 'Fish', 'weight' => 0.2, 'base_value' => 2, 'tags' => ['food', 'fish']],
        'snail' => ['item_class' => 'resource', 'material_family' => 'Shellfish', 'weight' => 0.12, 'base_value' => 2, 'tags' => ['food', 'shellfish']],
        'crayfish' => ['item_class' => 'resource', 'material_family' => 'Shellfish', 'weight' => 0.22, 'base_value' => 3, 'tags' => ['food', 'shellfish']],
        'fish' => ['item_class' => 'resource', 'material_family' => 'Fish', 'weight' => 0.45, 'base_value' => 3, 'tags' => ['food', 'fish']],
        'perch' => ['item_class' => 'resource', 'material_family' => 'Fish', 'weight' => 0.35, 'base_value' => 4, 'tags' => ['food', 'fish']],
        'herring' => ['item_class' => 'resource', 'material_family' => 'Fish', 'weight' => 0.3, 'base_value' => 4, 'tags' => ['food', 'fish']],
        'eel' => ['item_class' => 'resource', 'material_family' => 'Fish', 'weight' => 0.9, 'base_value' => 7, 'tags' => ['food', 'fish']],
        'kelp' => ['item_class' => 'resource', 'material_family' => 'Aquatic Plant', 'weight' => 0.1, 'base_value' => 2, 'tags' => ['plant', 'cooking']],
        'shell' => ['item_class' => 'resource', 'material_family' => 'Shell', 'weight' => 0.08, 'base_value' => 5, 'tags' => ['shell', 'jewelcrafting']],
        'meat' => ['item_class' => 'resource', 'material_family' => 'Meat', 'weight' => 0.6, 'base_value' => 4, 'tags' => ['food', 'meat']],
        'ration' => ['item_class' => 'consumable', 'material_family' => 'Prepared Food', 'weight' => 0.4, 'base_value' => 14, 'tags' => ['food', 'supply']],
        'soup' => ['item_class' => 'consumable', 'material_family' => 'Prepared Food', 'weight' => 0.45, 'base_value' => 10, 'tags' => ['food', 'meal']],
        'flatbread' => ['item_class' => 'consumable', 'material_family' => 'Prepared Food', 'weight' => 0.25, 'base_value' => 9, 'tags' => ['food', 'meal']],
        'feast' => ['item_class' => 'consumable', 'material_family' => 'Prepared Food', 'weight' => 1.8, 'base_value' => 45, 'tags' => ['food', 'party']],
        'grain' => ['item_class' => 'resource', 'material_family' => 'Crop', 'weight' => 0.15, 'base_value' => 2, 'tags' => ['crop', 'food']],
        'wheat' => ['item_class' => 'resource', 'material_family' => 'Crop', 'weight' => 0.18, 'base_value' => 3, 'tags' => ['crop', 'food']],
        'bean' => ['item_class' => 'resource', 'material_family' => 'Crop', 'weight' => 0.12, 'base_value' => 2, 'tags' => ['crop', 'food']],
        'fruit' => ['item_class' => 'resource', 'material_family' => 'Crop', 'weight' => 0.35, 'base_value' => 8, 'tags' => ['crop', 'rare-food']],
        'seed' => ['item_class' => 'resource', 'material_family' => 'Seed', 'weight' => 0.05, 'base_value' => 5, 'tags' => ['seed', 'farming']],
        'ore' => ['item_class' => 'resource', 'material_family' => 'Ore', 'weight' => 1.25, 'base_value' => 5, 'tags' => ['ore', 'metal']],
        'coal' => ['item_class' => 'resource', 'material_family' => 'Fuel', 'weight' => 0.8, 'base_value' => 3, 'tags' => ['fuel', 'smelting']],
        'clay' => ['item_class' => 'resource', 'material_family' => 'Clay', 'weight' => 0.7, 'base_value' => 3, 'tags' => ['clay', 'excavation']],
        'chalkstone' => ['item_class' => 'resource', 'material_family' => 'Stone', 'weight' => 0.6, 'base_value' => 2, 'tags' => ['stone', 'flux']],
        'flint' => ['item_class' => 'resource', 'material_family' => 'Stone', 'weight' => 0.18, 'base_value' => 4, 'tags' => ['stone', 'tooling']],
        'quartz' => ['item_class' => 'resource', 'material_family' => 'Crystal', 'weight' => 0.16, 'base_value' => 10, 'tags' => ['crystal', 'cutting']],
        'bar' => ['item_class' => 'material', 'material_family' => 'Metal Bar', 'weight' => 1.0, 'base_value' => 12, 'tags' => ['metal', 'processed']],
        'ingot' => ['item_class' => 'material', 'material_family' => 'Metal Bar', 'weight' => 1.3, 'base_value' => 24, 'tags' => ['metal', 'processed']],
        'nails' => ['item_class' => 'material', 'material_family' => 'Metal Fitting', 'weight' => 0.05, 'base_value' => 4, 'tags' => ['metal', 'fitting']],
        'fittings' => ['item_class' => 'material', 'material_family' => 'Metal Fitting', 'weight' => 0.35, 'base_value' => 14, 'tags' => ['metal', 'fitting']],
        'gem' => ['item_class' => 'resource', 'material_family' => 'Gem', 'weight' => 0.12, 'base_value' => 18, 'tags' => ['gem', 'jewelcrafting']],
        'crystal' => ['item_class' => 'resource', 'material_family' => 'Crystal', 'weight' => 0.22, 'base_value' => 16, 'tags' => ['crystal', 'arcane']],
        'geode' => ['item_class' => 'resource', 'material_family' => 'Gem', 'weight' => 0.55, 'base_value' => 25, 'tags' => ['gem', 'mining']],
        'lens' => ['item_class' => 'material', 'material_family' => 'Lens', 'weight' => 0.18, 'base_value' => 38, 'tags' => ['lens', 'precision']],
        'scale' => ['item_class' => 'resource', 'material_family' => 'Scale', 'weight' => 0.08, 'base_value' => 8, 'tags' => ['scale', 'fish']],
        'log' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 1.8, 'base_value' => 4, 'tags' => ['wood', 'raw']],
        'branch' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.8, 'base_value' => 2, 'tags' => ['wood', 'raw']],
        'pinecone' => ['item_class' => 'resource', 'material_family' => 'Seed', 'weight' => 0.06, 'base_value' => 2, 'tags' => ['seed', 'wood']],
        'stick' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.25, 'base_value' => 2, 'tags' => ['wood', 'tooling']],
        'plank' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 1.1, 'base_value' => 9, 'tags' => ['wood', 'processed']],
        'dowel' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 0.35, 'base_value' => 6, 'tags' => ['wood', 'processed']],
        'sheet' => ['item_class' => 'material', 'material_family' => 'Sheet', 'weight' => 0.18, 'base_value' => 7, 'tags' => ['processed', 'crafting']],
        'beam' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 2.4, 'base_value' => 22, 'tags' => ['wood', 'construction']],
        'bark' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.35, 'base_value' => 3, 'tags' => ['wood', 'fiber']],
        'sap' => ['item_class' => 'resource', 'material_family' => 'Resin', 'weight' => 0.25, 'base_value' => 7, 'tags' => ['resin', 'alchemy']],
        'resin' => ['item_class' => 'resource', 'material_family' => 'Resin', 'weight' => 0.35, 'base_value' => 10, 'tags' => ['resin', 'crafting']],
        'hide' => ['item_class' => 'resource', 'material_family' => 'Hide', 'weight' => 0.9, 'base_value' => 6, 'tags' => ['hide', 'leather']],
        'leather' => ['item_class' => 'material', 'material_family' => 'Leather', 'weight' => 0.65, 'base_value' => 13, 'tags' => ['leather', 'processed']],
        'sinew' => ['item_class' => 'resource', 'material_family' => 'Sinew', 'weight' => 0.18, 'base_value' => 5, 'tags' => ['sinew', 'binding']],
        'bone' => ['item_class' => 'resource', 'material_family' => 'Bone', 'weight' => 0.45, 'base_value' => 7, 'tags' => ['bone', 'trophy']],
        'egg' => ['item_class' => 'resource', 'material_family' => 'Egg', 'weight' => 0.2, 'base_value' => 3, 'tags' => ['food', 'hunting']],
        'feather' => ['item_class' => 'resource', 'material_family' => 'Feather', 'weight' => 0.02, 'base_value' => 3, 'tags' => ['feather', 'ranged']],
        'fang' => ['item_class' => 'resource', 'material_family' => 'Trophy', 'weight' => 0.22, 'base_value' => 12, 'tags' => ['trophy', 'monster']],
        'claw' => ['item_class' => 'resource', 'material_family' => 'Trophy', 'weight' => 0.3, 'base_value' => 18, 'tags' => ['trophy', 'monster']],
        'mushroom' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.12, 'base_value' => 3, 'tags' => ['herb', 'alchemy']],
        'herb' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.08, 'base_value' => 4, 'tags' => ['herb', 'alchemy']],
        'leaf' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.04, 'base_value' => 2, 'tags' => ['herb', 'alchemy']],
        'root' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.18, 'base_value' => 3, 'tags' => ['herb', 'root']],
        'spore' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.03, 'base_value' => 6, 'tags' => ['herb', 'alchemy']],
        'bloom' => ['item_class' => 'resource', 'material_family' => 'Flower', 'weight' => 0.05, 'base_value' => 15, 'tags' => ['flower', 'rare-herb']],
        'orchid' => ['item_class' => 'resource', 'material_family' => 'Flower', 'weight' => 0.05, 'base_value' => 28, 'tags' => ['flower', 'epic-herb']],
        'tonic' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.3, 'base_value' => 18, 'tags' => ['potion', 'support']],
        'salve' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.28, 'base_value' => 44, 'tags' => ['potion', 'healing']],
        'paste' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.18, 'base_value' => 9, 'tags' => ['potion', 'support']],
        'oil' => ['item_class' => 'consumable', 'material_family' => 'Oil', 'weight' => 0.2, 'base_value' => 12, 'tags' => ['oil', 'crafting']],
        'fiber' => ['item_class' => 'resource', 'material_family' => 'Fiber', 'weight' => 0.08, 'base_value' => 2, 'tags' => ['fiber', 'weaving']],
        'thread' => ['item_class' => 'material', 'material_family' => 'Thread', 'weight' => 0.04, 'base_value' => 5, 'tags' => ['thread', 'weaving']],
        'cloth' => ['item_class' => 'material', 'material_family' => 'Cloth', 'weight' => 0.35, 'base_value' => 12, 'tags' => ['cloth', 'tailoring']],
        'cord' => ['item_class' => 'material', 'material_family' => 'Cord', 'weight' => 0.08, 'base_value' => 5, 'tags' => ['cord', 'weaving']],
        'wraps' => ['item_class' => 'consumable', 'material_family' => 'Medical Supply', 'weight' => 0.16, 'base_value' => 10, 'tags' => ['cloth', 'healing']],
        'pouch' => ['item_class' => 'tooling', 'material_family' => 'Bag', 'weight' => 0.3, 'base_value' => 16, 'tags' => ['bag', 'tailoring']],
        'bolt' => ['item_class' => 'material', 'material_family' => 'Cloth', 'weight' => 0.55, 'base_value' => 24, 'tags' => ['cloth', 'tailoring']],
        'spellthread' => ['item_class' => 'material', 'material_family' => 'Thread', 'weight' => 0.04, 'base_value' => 45, 'tags' => ['thread', 'arcane']],
        'trace' => ['item_class' => 'material', 'material_family' => 'Attunement Trace', 'weight' => 0.02, 'base_value' => 10, 'tags' => ['trace', 'attunement', 'refinement']],
        'fragment' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 0.35, 'base_value' => 8, 'tags' => ['relic', 'excavation']],
        'tablet' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 1.1, 'base_value' => 35, 'tags' => ['relic', 'lore']],
        'rune' => ['item_class' => 'resource', 'material_family' => 'Rune', 'weight' => 0.18, 'base_value' => 20, 'tags' => ['rune', 'arcane']],
        'relic' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 0.75, 'base_value' => 32, 'tags' => ['relic', 'rare']],
        'map' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 14, 'tags' => ['map', 'route']],
        'chart' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.12, 'base_value' => 30, 'tags' => ['map', 'dungeon']],
        'manifest' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 12, 'tags' => ['trade', 'commission']],
        'note' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.04, 'base_value' => 8, 'tags' => ['document', 'commission']],
        'token' => ['item_class' => 'tooling', 'material_family' => 'Trade Good', 'weight' => 0.05, 'base_value' => 16, 'tags' => ['trade', 'currency']],
        'seal' => ['item_class' => 'tooling', 'material_family' => 'Trade Good', 'weight' => 0.12, 'base_value' => 36, 'tags' => ['trade', 'social']],
        'charm' => ['item_class' => 'trinket', 'material_family' => 'Trinket', 'weight' => 0.08, 'base_value' => 24, 'tags' => ['trinket', 'magic']],
        'ring' => ['item_class' => 'trinket', 'material_family' => 'Jewelry', 'weight' => 0.04, 'base_value' => 32, 'tags' => ['jewelry', 'trade']],
        'amulet' => ['item_class' => 'trinket', 'material_family' => 'Jewelry', 'weight' => 0.06, 'base_value' => 55, 'tags' => ['jewelry', 'magic']],
        'setting' => ['item_class' => 'material', 'material_family' => 'Jewelry Setting', 'weight' => 0.04, 'base_value' => 16, 'tags' => ['jewelry', 'component']],
        'bead' => ['item_class' => 'material', 'material_family' => 'Jewelry Setting', 'weight' => 0.03, 'base_value' => 12, 'tags' => ['jewelry', 'component']],
        'knife' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 0.8, 'base_value' => 20, 'tags' => ['weapon', 'combat']],
        'blade' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 1.0, 'base_value' => 22, 'tags' => ['weapon', 'combat']],
        'bow' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 1.2, 'base_value' => 24, 'tags' => ['weapon', 'ranged']],
        'armor' => ['item_class' => 'equipment', 'material_family' => 'Armor', 'weight' => 4.5, 'base_value' => 70, 'tags' => ['armor', 'defense']],
        'rod' => ['item_class' => 'tool', 'material_family' => 'Fishing Tool', 'weight' => 0.8, 'base_value' => 28, 'tags' => ['tool', 'fishing']],
        'pickaxe' => ['item_class' => 'tool', 'material_family' => 'Mining Tool', 'weight' => 2.2, 'base_value' => 34, 'tags' => ['tool', 'mining']],
        'hatchet' => ['item_class' => 'tool', 'material_family' => 'Woodcutting Tool', 'weight' => 1.6, 'base_value' => 30, 'tags' => ['tool', 'woodcutting']],
        'satchel' => ['item_class' => 'tool', 'material_family' => 'Foraging Tool', 'weight' => 0.7, 'base_value' => 26, 'tags' => ['tool', 'foraging']],
        'trap' => ['item_class' => 'tool', 'material_family' => 'Hunting Tool', 'weight' => 1.0, 'base_value' => 32, 'tags' => ['tool', 'hunting']],
        'cultivator' => ['item_class' => 'tool', 'material_family' => 'Farming Tool', 'weight' => 1.4, 'base_value' => 32, 'tags' => ['tool', 'farming']],
        'spade' => ['item_class' => 'tool', 'material_family' => 'Farming Tool', 'weight' => 1.4, 'base_value' => 24, 'tags' => ['tool', 'farming']],
        'trowel' => ['item_class' => 'tool', 'material_family' => 'Excavation Tool', 'weight' => 0.9, 'base_value' => 28, 'tags' => ['tool', 'excavation']],
        'compass' => ['item_class' => 'tooling', 'material_family' => 'Device', 'weight' => 0.35, 'base_value' => 48, 'tags' => ['device', 'navigation']],
        'spring' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 0.15, 'base_value' => 10, 'tags' => ['mechanism', 'engineering']],
        'trigger' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 0.18, 'base_value' => 18, 'tags' => ['mechanism', 'trap']],
        'handle' => ['item_class' => 'material', 'material_family' => 'Tool Component', 'weight' => 0.35, 'base_value' => 10, 'tags' => ['tooling', 'component']],
        'stake' => ['item_class' => 'tooling', 'material_family' => 'Survey Tool', 'weight' => 0.45, 'base_value' => 8, 'tags' => ['survey', 'construction']],
        'scaffold' => ['item_class' => 'structure', 'material_family' => 'Construction', 'weight' => 6.0, 'base_value' => 28, 'tags' => ['construction', 'settlement']],
        'frame' => ['item_class' => 'structure', 'material_family' => 'Construction', 'weight' => 8.0, 'base_value' => 55, 'tags' => ['construction', 'settlement']],
        'kit' => ['item_class' => 'tooling', 'material_family' => 'Kit', 'weight' => 1.2, 'base_value' => 20, 'tags' => ['kit', 'support']],
        'signpost' => ['item_class' => 'structure', 'material_family' => 'Construction', 'weight' => 2.2, 'base_value' => 18, 'tags' => ['construction', 'route']],
        'skiff' => ['item_class' => 'structure', 'material_family' => 'Boat Part', 'weight' => 5.0, 'base_value' => 30, 'tags' => ['boat', 'sailing']],
        'float' => ['item_class' => 'structure', 'material_family' => 'Boat Part', 'weight' => 1.0, 'base_value' => 14, 'tags' => ['boat', 'sailing']],
        'rope' => ['item_class' => 'material', 'material_family' => 'Cord', 'weight' => 0.9, 'base_value' => 12, 'tags' => ['boat', 'cord']],
        'sail' => ['item_class' => 'structure', 'material_family' => 'Boat Part', 'weight' => 2.0, 'base_value' => 42, 'tags' => ['boat', 'tailoring']],
        'table' => ['item_class' => 'housing', 'material_family' => 'Furniture', 'weight' => 7.0, 'base_value' => 50, 'tags' => ['furniture', 'housing']],
        'stool' => ['item_class' => 'housing', 'material_family' => 'Furniture', 'weight' => 3.0, 'base_value' => 18, 'tags' => ['furniture', 'housing']],
        'crate' => ['item_class' => 'housing', 'material_family' => 'Furniture', 'weight' => 4.0, 'base_value' => 20, 'tags' => ['furniture', 'storage']],
        'stand' => ['item_class' => 'housing', 'material_family' => 'Furniture', 'weight' => 3.5, 'base_value' => 24, 'tags' => ['furniture', 'housing']],
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function enrich(array $item): array
    {
        $itemKey = (string) ($item['item_key'] ?? $item['key'] ?? '');
        $itemName = (string) ($item['item_name'] ?? $item['name'] ?? str($itemKey)->headline()->toString());
        $rarity = (string) ($item['rarity'] ?? 'common');
        $quantity = (int) ($item['quantity'] ?? 1);
        $profile = self::RARITY_PROFILES[$rarity] ?? self::RARITY_PROFILES['common'];
        $rule = $this->ruleFor($itemKey, $itemName);
        $weight = (float) ($item['weight'] ?? $rule['weight']);
        $unitValue = (int) round(((int) ($item['vendor_value'] ?? $rule['base_value'])) * $profile['value_multiplier']);

        return [
            ...$item,
            'item_key' => $itemKey,
            'item_name' => $itemName,
            'rarity' => $rarity,
            'quality' => $item['quality'] ?? $profile['quality'],
            'quality_score' => (int) ($item['quality_score'] ?? $profile['quality_score']),
            'item_class' => $item['item_class'] ?? $rule['item_class'],
            'material_family' => $item['material_family'] ?? $rule['material_family'],
            'weight' => round($weight, 2),
            'total_weight' => round($weight * max(1, $quantity), 2),
            'vendor_value' => $unitValue,
            'total_vendor_value' => $unitValue * max(1, $quantity),
            'npc_buy_price' => $this->npcBuyPrice($unitValue),
            'total_npc_buy_price' => $this->npcBuyPrice($unitValue) * max(1, $quantity),
            'market_floor_price' => $this->npcBuyPrice($unitValue),
            'market_ceiling_price' => $this->marketCeilingPrice($unitValue, $item['item_class'] ?? $rule['item_class']),
            'market_price_band' => $this->npcBuyPrice($unitValue).'-'.$this->marketCeilingPrice($unitValue, $item['item_class'] ?? $rule['item_class']).'g',
            'stack_limit' => (int) ($item['stack_limit'] ?? $profile['stack_limit']),
            'tradeable' => (bool) ($item['tradeable'] ?? true),
            'tags' => array_values(array_unique([
                ...($rule['tags'] ?? []),
                $rarity,
                $item['item_class'] ?? $rule['item_class'],
            ])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function enrichMany(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => $this->enrich($item))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rarityProfiles(): array
    {
        return self::RARITY_PROFILES;
    }

    /**
     * @return array{item_class: string, material_family: string, weight: float, base_value: int, tags: list<string>}
     */
    private function ruleFor(string $itemKey, string $itemName): array
    {
        $needle = str($itemKey.' '.$itemName)->lower()->toString();

        foreach (self::KEY_RULES as $fragment => $rule) {
            if (str_contains($needle, $fragment)) {
                return $rule;
            }
        }

        return [
            'item_class' => 'misc',
            'material_family' => 'General',
            'weight' => 0.5,
            'base_value' => 5,
            'tags' => ['general'],
        ];
    }

    private function npcBuyPrice(int $unitValue): int
    {
        return max(1, (int) floor($unitValue * 0.35));
    }

    private function marketCeilingPrice(int $unitValue, string $itemClass): int
    {
        $multiplier = match ($itemClass) {
            'armor', 'equipment', 'tool', 'trinket' => 12,
            'consumable', 'housing', 'structure', 'tooling' => 10,
            'material' => 8,
            default => 6,
        };

        return max($this->npcBuyPrice($unitValue), $unitValue * $multiplier);
    }
}
