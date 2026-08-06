<?php

namespace App\Domain\ConnectedRealms\Services;

class ItemCatalogService
{
    private const RARITY_PROFILES = [
        'common' => ['quality' => 'standard', 'quality_score' => 40, 'value_multiplier' => 1, 'stack_limit' => 99],
        'uncommon' => ['quality' => 'fine', 'quality_score' => 55, 'value_multiplier' => 2, 'stack_limit' => 80],
        'rare' => ['quality' => 'superior', 'quality_score' => 70, 'value_multiplier' => 4, 'stack_limit' => 50],
        'epic' => ['quality' => 'exceptional', 'quality_score' => 85, 'value_multiplier' => 8, 'stack_limit' => 25],
        'legendary' => ['quality' => 'peerless', 'quality_score' => 100, 'value_multiplier' => 16, 'stack_limit' => 10],
        'mythic' => ['quality' => 'masterwork', 'quality_score' => 120, 'value_multiplier' => 32, 'stack_limit' => 5],
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
        'crop' => ['item_class' => 'resource', 'material_family' => 'Crop', 'weight' => 0.22, 'base_value' => 6, 'tags' => ['crop', 'farming']],
        'seed' => ['item_class' => 'resource', 'material_family' => 'Seed', 'weight' => 0.05, 'base_value' => 5, 'tags' => ['seed', 'farming']],
        'flax' => ['item_class' => 'resource', 'material_family' => 'Fiber', 'weight' => 0.1, 'base_value' => 3, 'tags' => ['fiber', 'weaving', 'farming']],
        'reed' => ['item_class' => 'resource', 'material_family' => 'Fiber', 'weight' => 0.12, 'base_value' => 3, 'tags' => ['fiber', 'boatbuilding']],
        'veinstone' => ['item_class' => 'resource', 'material_family' => 'Ore', 'weight' => 1.15, 'base_value' => 8, 'tags' => ['ore', 'metal', 'mining']],
        'ore' => ['item_class' => 'resource', 'material_family' => 'Ore', 'weight' => 1.25, 'base_value' => 5, 'tags' => ['ore', 'metal']],
        'coal' => ['item_class' => 'resource', 'material_family' => 'Fuel', 'weight' => 0.8, 'base_value' => 3, 'tags' => ['fuel', 'smelting']],
        'flux' => ['item_class' => 'resource', 'material_family' => 'Flux', 'weight' => 0.25, 'base_value' => 12, 'tags' => ['flux', 'smelting']],
        'slag_glass' => ['item_class' => 'material', 'material_family' => 'Forge Glass', 'weight' => 0.18, 'base_value' => 14, 'tags' => ['forge', 'smelting', 'byproduct']],
        'pottery' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 0.35, 'base_value' => 7, 'tags' => ['relic', 'excavation']],
        'clay' => ['item_class' => 'resource', 'material_family' => 'Clay', 'weight' => 0.7, 'base_value' => 3, 'tags' => ['clay', 'excavation']],
        'chalkstone' => ['item_class' => 'resource', 'material_family' => 'Stone', 'weight' => 0.6, 'base_value' => 2, 'tags' => ['stone', 'flux']],
        'flint' => ['item_class' => 'resource', 'material_family' => 'Stone', 'weight' => 0.18, 'base_value' => 4, 'tags' => ['stone', 'tooling']],
        'quartz' => ['item_class' => 'resource', 'material_family' => 'Crystal', 'weight' => 0.16, 'base_value' => 10, 'tags' => ['crystal', 'cutting']],
        'shard' => ['item_class' => 'resource', 'material_family' => 'Crystal', 'weight' => 0.12, 'base_value' => 9, 'tags' => ['crystal', 'relic']],
        'bar' => ['item_class' => 'material', 'material_family' => 'Metal Bar', 'weight' => 1.0, 'base_value' => 12, 'tags' => ['metal', 'processed']],
        'ingot' => ['item_class' => 'material', 'material_family' => 'Metal Bar', 'weight' => 1.3, 'base_value' => 24, 'tags' => ['metal', 'processed']],
        'alloy' => ['item_class' => 'material', 'material_family' => 'Metal Bar', 'weight' => 1.1, 'base_value' => 20, 'tags' => ['metal', 'processed']],
        'blank' => ['item_class' => 'material', 'material_family' => 'Weapon Component', 'weight' => 1.0, 'base_value' => 18, 'tags' => ['weapon', 'component']],
        'armament' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 2.2, 'base_value' => 80, 'tags' => ['weapon', 'combat']],
        'nails' => ['item_class' => 'material', 'material_family' => 'Metal Fitting', 'weight' => 0.05, 'base_value' => 4, 'tags' => ['metal', 'fitting']],
        'fittings' => ['item_class' => 'material', 'material_family' => 'Metal Fitting', 'weight' => 0.35, 'base_value' => 14, 'tags' => ['metal', 'fitting']],
        'gem' => ['item_class' => 'resource', 'material_family' => 'Gem', 'weight' => 0.12, 'base_value' => 18, 'tags' => ['gem', 'jewelcrafting']],
        'crystal' => ['item_class' => 'resource', 'material_family' => 'Crystal', 'weight' => 0.22, 'base_value' => 16, 'tags' => ['crystal', 'arcane']],
        'geode' => ['item_class' => 'resource', 'material_family' => 'Gem', 'weight' => 0.55, 'base_value' => 25, 'tags' => ['gem', 'mining']],
        'lens' => ['item_class' => 'material', 'material_family' => 'Lens', 'weight' => 0.18, 'base_value' => 38, 'tags' => ['lens', 'precision']],
        'facet' => ['item_class' => 'material', 'material_family' => 'Gem', 'weight' => 0.08, 'base_value' => 28, 'tags' => ['gem', 'cutting']],
        'pearl' => ['item_class' => 'resource', 'material_family' => 'Gem', 'weight' => 0.08, 'base_value' => 22, 'tags' => ['gem', 'fishing']],
        'scale' => ['item_class' => 'resource', 'material_family' => 'Scale', 'weight' => 0.08, 'base_value' => 8, 'tags' => ['scale', 'fish']],
        'log' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 1.8, 'base_value' => 4, 'tags' => ['wood', 'raw']],
        'ironwood' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 2.0, 'base_value' => 18, 'tags' => ['wood', 'rare']],
        'branch' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.8, 'base_value' => 2, 'tags' => ['wood', 'raw']],
        'pinecone' => ['item_class' => 'resource', 'material_family' => 'Seed', 'weight' => 0.06, 'base_value' => 2, 'tags' => ['seed', 'wood']],
        'stick' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.25, 'base_value' => 2, 'tags' => ['wood', 'tooling']],
        'plank' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 1.1, 'base_value' => 9, 'tags' => ['wood', 'processed']],
        'dowel' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 0.35, 'base_value' => 6, 'tags' => ['wood', 'processed']],
        'sheet' => ['item_class' => 'material', 'material_family' => 'Sheet', 'weight' => 0.18, 'base_value' => 7, 'tags' => ['processed', 'crafting']],
        'timber' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 1.6, 'base_value' => 18, 'tags' => ['wood', 'processed']],
        'beam' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 2.4, 'base_value' => 22, 'tags' => ['wood', 'construction']],
        'joinery' => ['item_class' => 'material', 'material_family' => 'Lumber', 'weight' => 0.45, 'base_value' => 16, 'tags' => ['wood', 'component']],
        'bark' => ['item_class' => 'resource', 'material_family' => 'Wood', 'weight' => 0.35, 'base_value' => 3, 'tags' => ['wood', 'fiber']],
        'sweetdust' => ['item_class' => 'material', 'material_family' => 'Wood Byproduct', 'weight' => 0.08, 'base_value' => 5, 'tags' => ['wood', 'milling']],
        'sawdust' => ['item_class' => 'material', 'material_family' => 'Wood Byproduct', 'weight' => 0.08, 'base_value' => 4, 'tags' => ['wood', 'milling']],
        'sap' => ['item_class' => 'resource', 'material_family' => 'Resin', 'weight' => 0.25, 'base_value' => 7, 'tags' => ['resin', 'alchemy']],
        'resin' => ['item_class' => 'resource', 'material_family' => 'Resin', 'weight' => 0.35, 'base_value' => 10, 'tags' => ['resin', 'crafting']],
        'varnish' => ['item_class' => 'material', 'material_family' => 'Resin Finish', 'weight' => 0.25, 'base_value' => 15, 'tags' => ['resin', 'furniture']],
        'tannin' => ['item_class' => 'material', 'material_family' => 'Tanning Reagent', 'weight' => 0.2, 'base_value' => 9, 'tags' => ['leather', 'alchemy']],
        'hide' => ['item_class' => 'resource', 'material_family' => 'Hide', 'weight' => 0.9, 'base_value' => 6, 'tags' => ['hide', 'leather']],
        'leather' => ['item_class' => 'material', 'material_family' => 'Leather', 'weight' => 0.65, 'base_value' => 13, 'tags' => ['leather', 'processed']],
        'sinew' => ['item_class' => 'resource', 'material_family' => 'Sinew', 'weight' => 0.18, 'base_value' => 5, 'tags' => ['sinew', 'binding']],
        'bone' => ['item_class' => 'resource', 'material_family' => 'Bone', 'weight' => 0.45, 'base_value' => 7, 'tags' => ['bone', 'trophy']],
        'trophy' => ['item_class' => 'resource', 'material_family' => 'Trophy', 'weight' => 0.35, 'base_value' => 18, 'tags' => ['trophy', 'monster']],
        'crest' => ['item_class' => 'trinket', 'material_family' => 'Commendation', 'weight' => 0.12, 'base_value' => 42, 'tags' => ['commendation', 'combat']],
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
        'moss' => ['item_class' => 'resource', 'material_family' => 'Herb', 'weight' => 0.07, 'base_value' => 6, 'tags' => ['herb', 'fiber']],
        'catalyst' => ['item_class' => 'material', 'material_family' => 'Alchemy Reagent', 'weight' => 0.06, 'base_value' => 14, 'tags' => ['alchemy', 'reagent']],
        'elixir' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.32, 'base_value' => 60, 'tags' => ['potion', 'alchemy']],
        'draught' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.28, 'base_value' => 22, 'tags' => ['potion', 'alchemy']],
        'tonic' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.3, 'base_value' => 18, 'tags' => ['potion', 'support']],
        'salve' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.28, 'base_value' => 44, 'tags' => ['potion', 'healing']],
        'paste' => ['item_class' => 'consumable', 'material_family' => 'Potion', 'weight' => 0.18, 'base_value' => 9, 'tags' => ['potion', 'support']],
        'oil' => ['item_class' => 'consumable', 'material_family' => 'Oil', 'weight' => 0.2, 'base_value' => 12, 'tags' => ['oil', 'crafting']],
        'fiber' => ['item_class' => 'resource', 'material_family' => 'Fiber', 'weight' => 0.08, 'base_value' => 2, 'tags' => ['fiber', 'weaving']],
        'thread' => ['item_class' => 'material', 'material_family' => 'Thread', 'weight' => 0.04, 'base_value' => 5, 'tags' => ['thread', 'weaving']],
        'fletching' => ['item_class' => 'material', 'material_family' => 'Fletching', 'weight' => 0.06, 'base_value' => 8, 'tags' => ['ranged', 'feather']],
        'vestment' => ['item_class' => 'equipment', 'material_family' => 'Cloth Gear', 'weight' => 0.8, 'base_value' => 65, 'tags' => ['cloth', 'tailoring', 'equipment']],
        'cloth' => ['item_class' => 'material', 'material_family' => 'Cloth', 'weight' => 0.35, 'base_value' => 12, 'tags' => ['cloth', 'tailoring']],
        'cord' => ['item_class' => 'material', 'material_family' => 'Cord', 'weight' => 0.08, 'base_value' => 5, 'tags' => ['cord', 'weaving']],
        'wraps' => ['item_class' => 'consumable', 'material_family' => 'Medical Supply', 'weight' => 0.16, 'base_value' => 10, 'tags' => ['cloth', 'healing']],
        'pouch' => ['item_class' => 'tooling', 'material_family' => 'Bag', 'weight' => 0.3, 'base_value' => 16, 'tags' => ['bag', 'tailoring']],
        'pack' => ['item_class' => 'tooling', 'material_family' => 'Bag', 'weight' => 0.6, 'base_value' => 18, 'tags' => ['bag', 'supply']],
        'bolt' => ['item_class' => 'material', 'material_family' => 'Cloth', 'weight' => 0.55, 'base_value' => 24, 'tags' => ['cloth', 'tailoring']],
        'spellthread' => ['item_class' => 'material', 'material_family' => 'Thread', 'weight' => 0.04, 'base_value' => 45, 'tags' => ['thread', 'arcane']],
        'trace' => ['item_class' => 'material', 'material_family' => 'Attunement Trace', 'weight' => 0.02, 'base_value' => 10, 'tags' => ['trace', 'attunement', 'refinement']],
        'chip' => ['item_class' => 'material', 'material_family' => 'Small Component', 'weight' => 0.03, 'base_value' => 9, 'tags' => ['component', 'crafting']],
        'fragment' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 0.35, 'base_value' => 8, 'tags' => ['relic', 'excavation']],
        'tablet' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 1.1, 'base_value' => 35, 'tags' => ['relic', 'lore']],
        'rune' => ['item_class' => 'resource', 'material_family' => 'Rune', 'weight' => 0.18, 'base_value' => 20, 'tags' => ['rune', 'arcane']],
        'relic' => ['item_class' => 'resource', 'material_family' => 'Relic', 'weight' => 0.75, 'base_value' => 32, 'tags' => ['relic', 'rare']],
        'ink' => ['item_class' => 'material', 'material_family' => 'Ink', 'weight' => 0.08, 'base_value' => 18, 'tags' => ['ink', 'cartography']],
        'order_sheet' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.04, 'base_value' => 10, 'tags' => ['document', 'leadership']],
        'ledger' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.1, 'base_value' => 18, 'tags' => ['document', 'trade']],
        'writ' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.05, 'base_value' => 18, 'tags' => ['document', 'commission']],
        'atlas' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.35, 'base_value' => 55, 'tags' => ['map', 'prestige']],
        'charter' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 32, 'tags' => ['document', 'trade']],
        'diagram' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.06, 'base_value' => 16, 'tags' => ['document', 'engineering']],
        'page' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.03, 'base_value' => 8, 'tags' => ['document', 'trade']],
        'map' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 14, 'tags' => ['map', 'route']],
        'chart' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.12, 'base_value' => 30, 'tags' => ['map', 'dungeon']],
        'manifest' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 12, 'tags' => ['trade', 'commission']],
        'note' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.04, 'base_value' => 8, 'tags' => ['document', 'commission']],
        'commission' => ['item_class' => 'tooling', 'material_family' => 'Document', 'weight' => 0.08, 'base_value' => 20, 'tags' => ['document', 'leadership']],
        'favor' => ['item_class' => 'tooling', 'material_family' => 'Favor', 'weight' => 0.03, 'base_value' => 18, 'tags' => ['social', 'reputation']],
        'key' => ['item_class' => 'tooling', 'material_family' => 'Key', 'weight' => 0.1, 'base_value' => 18, 'tags' => ['key', 'dungeoneering']],
        'token' => ['item_class' => 'tooling', 'material_family' => 'Trade Good', 'weight' => 0.05, 'base_value' => 16, 'tags' => ['trade', 'currency']],
        'seal' => ['item_class' => 'tooling', 'material_family' => 'Trade Good', 'weight' => 0.12, 'base_value' => 36, 'tags' => ['trade', 'social']],
        'credit' => ['item_class' => 'tooling', 'material_family' => 'Oathhall Credit', 'weight' => 0.04, 'base_value' => 14, 'tags' => ['guild', 'trade']],
        'chit' => ['item_class' => 'tooling', 'material_family' => 'Oathhall Credit', 'weight' => 0.04, 'base_value' => 12, 'tags' => ['guild', 'trade']],
        'marker' => ['item_class' => 'tooling', 'material_family' => 'Survey Tool', 'weight' => 0.25, 'base_value' => 12, 'tags' => ['survey', 'cartography']],
        'mark' => ['item_class' => 'tooling', 'material_family' => 'Oathhall Mark', 'weight' => 0.04, 'base_value' => 14, 'tags' => ['guild', 'standing']],
        'badge' => ['item_class' => 'trinket', 'material_family' => 'Commendation', 'weight' => 0.08, 'base_value' => 24, 'tags' => ['commendation', 'combat']],
        'banner' => ['item_class' => 'tooling', 'material_family' => 'Banner', 'weight' => 0.7, 'base_value' => 28, 'tags' => ['banner', 'leadership']],
        'standard' => ['item_class' => 'tooling', 'material_family' => 'Banner', 'weight' => 1.0, 'base_value' => 60, 'tags' => ['banner', 'leadership']],
        'brand' => ['item_class' => 'trinket', 'material_family' => 'Survival Token', 'weight' => 0.08, 'base_value' => 30, 'tags' => ['survival', 'prestige']],
        'oath' => ['item_class' => 'trinket', 'material_family' => 'Oath Token', 'weight' => 0.06, 'base_value' => 38, 'tags' => ['prestige', 'defense']],
        'vow' => ['item_class' => 'trinket', 'material_family' => 'Oath Token', 'weight' => 0.06, 'base_value' => 38, 'tags' => ['prestige', 'magic']],
        'ward' => ['item_class' => 'trinket', 'material_family' => 'Ward Token', 'weight' => 0.08, 'base_value' => 36, 'tags' => ['magic', 'healing']],
        'sigil' => ['item_class' => 'trinket', 'material_family' => 'Arcane Token', 'weight' => 0.06, 'base_value' => 30, 'tags' => ['magic', 'enchanting']],
        'charm' => ['item_class' => 'trinket', 'material_family' => 'Trinket', 'weight' => 0.08, 'base_value' => 24, 'tags' => ['trinket', 'magic']],
        'ring' => ['item_class' => 'trinket', 'material_family' => 'Jewelry', 'weight' => 0.04, 'base_value' => 32, 'tags' => ['jewelry', 'trade']],
        'amulet' => ['item_class' => 'trinket', 'material_family' => 'Jewelry', 'weight' => 0.06, 'base_value' => 55, 'tags' => ['jewelry', 'magic']],
        'setting' => ['item_class' => 'material', 'material_family' => 'Jewelry Setting', 'weight' => 0.04, 'base_value' => 16, 'tags' => ['jewelry', 'component']],
        'bead' => ['item_class' => 'material', 'material_family' => 'Jewelry Setting', 'weight' => 0.03, 'base_value' => 12, 'tags' => ['jewelry', 'component']],
        'knife' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 0.8, 'base_value' => 20, 'tags' => ['weapon', 'combat']],
        'blade' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 1.0, 'base_value' => 22, 'tags' => ['weapon', 'combat']],
        'bow' => ['item_class' => 'equipment', 'material_family' => 'Weapon', 'weight' => 1.2, 'base_value' => 24, 'tags' => ['weapon', 'ranged']],
        'armor' => ['item_class' => 'equipment', 'material_family' => 'Armor', 'weight' => 4.5, 'base_value' => 70, 'tags' => ['armor', 'defense']],
        'plate' => ['item_class' => 'material', 'material_family' => 'Armor Component', 'weight' => 1.0, 'base_value' => 22, 'tags' => ['armor', 'defense']],
        'boots' => ['item_class' => 'equipment', 'material_family' => 'Armor', 'weight' => 0.9, 'base_value' => 28, 'tags' => ['armor', 'travel']],
        'rod' => ['item_class' => 'tool', 'material_family' => 'Fishing Tool', 'weight' => 0.8, 'base_value' => 28, 'tags' => ['tool', 'fishing']],
        'pickaxe' => ['item_class' => 'tool', 'material_family' => 'Mining Tool', 'weight' => 2.2, 'base_value' => 34, 'tags' => ['tool', 'mining']],
        'hatchet' => ['item_class' => 'tool', 'material_family' => 'Woodcutting Tool', 'weight' => 1.6, 'base_value' => 30, 'tags' => ['tool', 'woodcutting']],
        'satchel' => ['item_class' => 'tool', 'material_family' => 'Foraging Tool', 'weight' => 0.7, 'base_value' => 26, 'tags' => ['tool', 'foraging']],
        'trap' => ['item_class' => 'tool', 'material_family' => 'Hunting Tool', 'weight' => 1.0, 'base_value' => 32, 'tags' => ['tool', 'hunting']],
        'cultivator' => ['item_class' => 'tool', 'material_family' => 'Farming Tool', 'weight' => 1.4, 'base_value' => 32, 'tags' => ['tool', 'farming']],
        'spade' => ['item_class' => 'tool', 'material_family' => 'Farming Tool', 'weight' => 1.4, 'base_value' => 24, 'tags' => ['tool', 'farming']],
        'trowel' => ['item_class' => 'tool', 'material_family' => 'Excavation Tool', 'weight' => 0.9, 'base_value' => 28, 'tags' => ['tool', 'excavation']],
        'compass' => ['item_class' => 'tooling', 'material_family' => 'Device', 'weight' => 0.35, 'base_value' => 48, 'tags' => ['device', 'navigation']],
        'focus' => ['item_class' => 'tooling', 'material_family' => 'Arcane Focus', 'weight' => 0.22, 'base_value' => 42, 'tags' => ['magic', 'device']],
        'crucible' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 2.0, 'base_value' => 48, 'tags' => ['tooling', 'smelting']],
        'plane' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 0.9, 'base_value' => 36, 'tags' => ['tooling', 'carpentry']],
        'shuttle' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 0.25, 'base_value' => 30, 'tags' => ['tooling', 'weaving']],
        'hammer' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 1.5, 'base_value' => 38, 'tags' => ['tooling', 'smithing']],
        'alembic' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 1.4, 'base_value' => 48, 'tags' => ['tooling', 'alchemy']],
        'caliper' => ['item_class' => 'tooling', 'material_family' => 'Workshop Tool', 'weight' => 0.35, 'base_value' => 34, 'tags' => ['tooling', 'engineering']],
        'crown' => ['item_class' => 'trinket', 'material_family' => 'Jewelry', 'weight' => 0.3, 'base_value' => 90, 'tags' => ['jewelry', 'prestige']],
        'spring' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 0.15, 'base_value' => 10, 'tags' => ['mechanism', 'engineering']],
        'trigger' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 0.18, 'base_value' => 18, 'tags' => ['mechanism', 'trap']],
        'assembly' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 0.75, 'base_value' => 36, 'tags' => ['mechanism', 'engineering']],
        'engine' => ['item_class' => 'material', 'material_family' => 'Mechanism', 'weight' => 2.4, 'base_value' => 90, 'tags' => ['mechanism', 'engineering']],
        'lure' => ['item_class' => 'tooling', 'material_family' => 'Fishing Tool', 'weight' => 0.15, 'base_value' => 20, 'tags' => ['tooling', 'fishing']],
        'handle' => ['item_class' => 'material', 'material_family' => 'Tool Component', 'weight' => 0.35, 'base_value' => 10, 'tags' => ['tooling', 'component']],
        'stake' => ['item_class' => 'tooling', 'material_family' => 'Survey Tool', 'weight' => 0.45, 'base_value' => 8, 'tags' => ['survey', 'construction']],
        'scaffold' => ['item_class' => 'settlement_good', 'material_family' => 'Construction', 'weight' => 6.0, 'base_value' => 28, 'tags' => ['construction', 'settlement']],
        'frame' => ['item_class' => 'settlement_good', 'material_family' => 'Construction', 'weight' => 8.0, 'base_value' => 55, 'tags' => ['construction', 'settlement']],
        'kit' => ['item_class' => 'tooling', 'material_family' => 'Kit', 'weight' => 1.2, 'base_value' => 20, 'tags' => ['kit', 'support']],
        'signpost' => ['item_class' => 'settlement_good', 'material_family' => 'Construction', 'weight' => 2.2, 'base_value' => 18, 'tags' => ['construction', 'route']],
        'skiff' => ['item_class' => 'settlement_good', 'material_family' => 'Boat Part', 'weight' => 5.0, 'base_value' => 30, 'tags' => ['boat', 'sailing']],
        'hull' => ['item_class' => 'settlement_good', 'material_family' => 'Boat Part', 'weight' => 5.0, 'base_value' => 55, 'tags' => ['boat', 'sailing']],
        'rib' => ['item_class' => 'settlement_good', 'material_family' => 'Boat Part', 'weight' => 1.2, 'base_value' => 20, 'tags' => ['boat', 'sailing']],
        'float' => ['item_class' => 'settlement_good', 'material_family' => 'Boat Part', 'weight' => 1.0, 'base_value' => 14, 'tags' => ['boat', 'sailing']],
        'rope' => ['item_class' => 'material', 'material_family' => 'Cord', 'weight' => 0.9, 'base_value' => 12, 'tags' => ['boat', 'cord']],
        'sail' => ['item_class' => 'settlement_good', 'material_family' => 'Boat Part', 'weight' => 2.0, 'base_value' => 42, 'tags' => ['boat', 'tailoring']],
        'table' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 7.0, 'base_value' => 50, 'tags' => ['furniture', 'housing']],
        'hall set' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 9.0, 'base_value' => 80, 'tags' => ['furniture', 'housing', 'prestige']],
        'fixture' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 2.0, 'base_value' => 32, 'tags' => ['furniture', 'housing']],
        'stool' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 3.0, 'base_value' => 18, 'tags' => ['furniture', 'housing']],
        'crate' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 4.0, 'base_value' => 20, 'tags' => ['furniture', 'storage']],
        'stand' => ['item_class' => 'settlement_good', 'material_family' => 'Furniture', 'weight' => 3.5, 'base_value' => 24, 'tags' => ['furniture', 'housing']],
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $keyRulesCache = null;

    /**
     * @var list<array{needle: string, rule: array<string, mixed>}>|null
     */
    private ?array $normalizedKeyRulesCache = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $ruleMatchCache = [];

    /**
     * @var list<array{needle: string, item_tier: int}>|null
     */
    private ?array $tierMarksCache = null;

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
        $itemTier = $this->itemTierFor($item, $itemName, $rarity);
        $itemClass = (string) ($item['item_class'] ?? $rule['item_class']);
        $npcBuyPrice = $this->npcBuyPrice($unitValue);
        $marketCeilingPrice = $this->marketCeilingPrice($unitValue, $itemClass);

        return [
            ...$item,
            'item_key' => $itemKey,
            'item_name' => $itemName,
            'rarity' => $rarity,
            'item_tier' => $itemTier,
            'tier_label' => "T{$itemTier}",
            'quality' => $item['quality'] ?? $profile['quality'],
            'quality_score' => (int) ($item['quality_score'] ?? $profile['quality_score']),
            'item_class' => $itemClass,
            'material_family' => $item['material_family'] ?? $rule['material_family'],
            'weight' => round($weight, 2),
            'total_weight' => round($weight * max(1, $quantity), 2),
            'vendor_value' => $unitValue,
            'total_vendor_value' => $unitValue * max(1, $quantity),
            'npc_buy_price' => $npcBuyPrice,
            'total_npc_buy_price' => $npcBuyPrice * max(1, $quantity),
            'market_floor_price' => $npcBuyPrice,
            'market_ceiling_price' => $marketCeilingPrice,
            'market_price_band' => $npcBuyPrice.'-'.$marketCeilingPrice.'g',
            'stack_limit' => (int) ($item['stack_limit'] ?? $profile['stack_limit']),
            'tradeable' => (bool) ($item['tradeable'] ?? true),
            'tags' => array_values(array_unique([
                ...($rule['tags'] ?? []),
                $rarity,
                $itemClass,
                "tier_{$itemTier}",
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
        $cacheKey = $itemKey."\0".$itemName;

        if (array_key_exists($cacheKey, $this->ruleMatchCache)) {
            return $this->ruleMatchCache[$cacheKey];
        }

        $keyNeedle = $this->normalizeRuleNeedle($itemKey);
        $nameNeedle = $this->normalizeRuleNeedle($itemName);
        $needle = trim($keyNeedle.' '.$nameNeedle);

        if (preg_match('/\b(cargo|package|shipment|consignment|supply crate|goods cache|expedition cache)\b/', $needle) === 1) {
            return $this->ruleMatchCache[$cacheKey] = [
                'item_class' => 'cargo',
                'material_family' => 'Cargo',
                'weight' => 3.0,
                'base_value' => 28,
                'tags' => ['cargo', 'package', 'trade'],
            ];
        }

        if (str_contains($keyNeedle, 'slag glass') || str_contains($nameNeedle, 'slagglass')) {
            return $this->ruleMatchCache[$cacheKey] = $this->keyRules()['slag_glass'] ?? self::KEY_RULES['slag_glass'];
        }

        foreach ($this->normalizedKeyRules() as $entry) {
            $ruleNeedle = $entry['needle'];

            if ($this->matchesRuleSuffix($keyNeedle, $ruleNeedle) || $this->matchesRuleSuffix($nameNeedle, $ruleNeedle)) {
                return $this->ruleMatchCache[$cacheKey] = $entry['rule'];
            }
        }

        foreach ($this->normalizedKeyRules() as $entry) {
            if (str_contains($needle, $entry['needle'])) {
                return $this->ruleMatchCache[$cacheKey] = $entry['rule'];
            }
        }

        return $this->ruleMatchCache[$cacheKey] = [
            'item_class' => 'misc',
            'material_family' => 'General',
            'weight' => 0.5,
            'base_value' => 5,
            'tags' => ['general'],
        ];
    }

    private function normalizeRuleNeedle(string $value): string
    {
        return (string) str($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function baseKeyRules(): array
    {
        return self::KEY_RULES;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keyRules(): array
    {
        if ($this->keyRulesCache !== null) {
            return $this->keyRulesCache;
        }

        $this->keyRulesCache = app(ConnectedRealmsContentService::class)->apply('item_rules', self::KEY_RULES);

        return $this->keyRulesCache;
    }

    /**
     * @return list<array{needle: string, rule: array<string, mixed>}>
     */
    private function normalizedKeyRules(): array
    {
        if ($this->normalizedKeyRulesCache !== null) {
            return $this->normalizedKeyRulesCache;
        }

        $this->normalizedKeyRulesCache = collect($this->keyRules())
            ->map(fn (array $rule, string $fragment): array => [
                'needle' => $this->normalizeRuleNeedle($fragment),
                'rule' => $rule,
            ])
            ->values()
            ->all();

        return $this->normalizedKeyRulesCache;
    }

    private function matchesRuleSuffix(string $needle, string $ruleNeedle): bool
    {
        return $needle === $ruleNeedle || str_ends_with($needle, ' '.$ruleNeedle);
    }

    private function npcBuyPrice(int $unitValue): int
    {
        return max(1, (int) floor($unitValue * 0.35));
    }

    private function marketCeilingPrice(int $unitValue, string $itemClass): int
    {
        $multiplier = match ($itemClass) {
            'armor', 'equipment', 'tool', 'trinket' => 12,
            'cargo', 'consumable', 'housing', 'settlement_good', 'structure', 'tooling' => 10,
            'material' => 8,
            default => 6,
        };

        return max($this->npcBuyPrice($unitValue), $unitValue * $multiplier);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemTierFor(array $item, string $itemName, string $rarity): int
    {
        foreach (['item_tier', 'craft_tier'] as $key) {
            if (is_numeric($item[$key] ?? null)) {
                return max(1, min(10, (int) $item[$key]));
            }
        }

        foreach (['required_level', 'tier_level'] as $key) {
            if (is_numeric($item[$key] ?? null)) {
                return EvergatherTierCatalog::itemTierForLevel((int) $item[$key]);
            }
        }

        $itemNameNeedle = $this->normalizeRuleNeedle($itemName);

        foreach ($this->tierMarks() as $tier) {
            if (str_contains($itemNameNeedle, $tier['needle'])) {
                return $tier['item_tier'];
            }
        }

        return match ($rarity) {
            'mythic' => 10,
            'legendary' => 9,
            'epic' => 8,
            'rare' => 5,
            'uncommon' => 3,
            default => 1,
        };
    }

    /**
     * @return list<array{needle: string, item_tier: int}>
     */
    private function tierMarks(): array
    {
        if ($this->tierMarksCache !== null) {
            return $this->tierMarksCache;
        }

        $this->tierMarksCache = collect(EvergatherTierCatalog::tiers())
            ->map(fn (array $tier): array => [
                'needle' => $this->normalizeRuleNeedle((string) $tier['mark']),
                'item_tier' => (int) $tier['item_tier'],
            ])
            ->values()
            ->all();

        return $this->tierMarksCache;
    }
}
