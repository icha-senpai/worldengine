<?php

namespace App\Domain\ConnectedRealms\Services;

class SkillCatalogService
{
    public const MAX_LEVEL = 100;

    public const LEVEL_100_EXPERIENCE = 170000;

    public const CALIBRATED_EXPERIENCE_PER_HOUR = 1600;

    /**
     * @var array<int, int>
     */
    private const LEVEL_EXPERIENCE_ANCHORS = [
        1 => 0,
        2 => 200,
        5 => 450,
        10 => 1100,
        20 => 2500,
        30 => 5200,
        40 => 9000,
        50 => 15500,
        65 => 35000,
        80 => 70000,
        100 => self::LEVEL_100_EXPERIENCE,
    ];

    /**
     * @var list<array{from_level: int, to_level: int, target_hours_range: array{int, int}}>
     */
    private const LEVEL_BAND_TARGETS = [
        ['from_level' => 1, 'to_level' => 5, 'target_hours_range' => [1, 2]],
        ['from_level' => 5, 'to_level' => 10, 'target_hours_range' => [2, 4]],
        ['from_level' => 10, 'to_level' => 20, 'target_hours_range' => [4, 7]],
        ['from_level' => 20, 'to_level' => 30, 'target_hours_range' => [6, 10]],
        ['from_level' => 30, 'to_level' => 40, 'target_hours_range' => [8, 12]],
        ['from_level' => 40, 'to_level' => 50, 'target_hours_range' => [10, 16]],
        ['from_level' => 50, 'to_level' => 65, 'target_hours_range' => [15, 24]],
        ['from_level' => 65, 'to_level' => 80, 'target_hours_range' => [22, 34]],
        ['from_level' => 80, 'to_level' => 100, 'target_hours_range' => [35, 55]],
    ];

    /**
     * @var array<string, array{label: string, target_hours_range: array{int, int}}>
     */
    private const CATEGORY_TARGETS = [
        'Utility' => ['label' => 'Minor or Utility', 'target_hours_range' => [25, 50]],
        'Gathering' => ['label' => 'Gathering', 'target_hours_range' => [50, 90]],
        'Processing' => ['label' => 'Crafting', 'target_hours_range' => [50, 100]],
        'Crafting' => ['label' => 'Crafting', 'target_hours_range' => [50, 100]],
        'Combat' => ['label' => 'Combat or Slayer', 'target_hours_range' => [75, 150]],
        'World' => ['label' => 'Major Prestige', 'target_hours_range' => [100, 200]],
        'Social' => ['label' => 'Major Prestige', 'target_hours_range' => [100, 200]],
    ];

    /**
     * @var array<string, array{
     *     label: string,
     *     type: string,
     *     category: string,
     *     role: string,
     *     description: string,
     *     unlocks: array<int, string>
     * }>
     */
    private const DEFINITIONS = [
        'fishing' => [
            'label' => 'Fishing',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Food, aquatic materials, coastal events',
            'description' => 'Catch fish, scales, shells, and rare tideborn resources.',
            'unlocks' => [1 => 'River casts', 5 => 'Fishing material sorting', 10 => 'Coastal shoals', 20 => 'Fishing regional board', 30 => 'Treasure nets', 40 => 'Fishing storm routes', 50 => 'Deepwater routes', 65 => 'Fishing elder claims', 80 => 'Leviathan tide pools', 100 => 'Leviathan angler'],
        ],
        'mining' => [
            'label' => 'Mining',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Ore, gems, coal, relic stone',
            'description' => 'Extract ore, gems, fossils, coal, and meteor metals.',
            'unlocks' => [1 => 'Surface ore', 5 => 'Mining material sorting', 10 => 'Coal seams', 20 => 'Mining regional board', 30 => 'Gem pockets', 40 => 'Mining storm routes', 50 => 'Deep quarry shafts', 65 => 'Mining elder claims', 80 => 'Meteor deposits', 100 => 'Mountain breaker'],
        ],
        'woodcutting' => [
            'label' => 'Woodcutting',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Logs, bark, sap, construction timber',
            'description' => 'Harvest common logs, rare woods, bark, resin, and living timber.',
            'unlocks' => [1 => 'Trail trees', 5 => 'Woodcutting material sorting', 10 => 'Hardwood stands', 20 => 'Woodcutting regional board', 30 => 'Resin taps', 40 => 'Woodcutting storm routes', 50 => 'Ancient groves', 65 => 'Woodcutting elder claims', 80 => 'Heartwood cuts', 100 => 'Grove warden'],
        ],
        'foraging' => [
            'label' => 'Foraging',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Herbs, mushrooms, seeds, reagents',
            'description' => 'Gather herbs, mushrooms, wild seeds, roots, and alchemical reagents.',
            'unlocks' => [1 => 'Trail herbs', 5 => 'Foraging material sorting', 10 => 'Mushroom rings', 20 => 'Foraging regional board', 30 => 'Seed caches', 40 => 'Foraging storm routes', 50 => 'Rare reagents', 65 => 'Foraging elder claims', 80 => 'Moonlit blooms', 100 => 'Wilds keeper'],
        ],
        'hunting' => [
            'label' => 'Hunting',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Meat, hides, bones, trophies',
            'description' => 'Track wildlife, set traps, recover hides, meat, bones, and trophies.',
            'unlocks' => [1 => 'Small traps', 5 => 'Hunting material sorting', 10 => 'Trail tracking', 20 => 'Hunting regional board', 30 => 'Hide curing', 40 => 'Hunting storm routes', 50 => 'Rare spoor', 65 => 'Hunting elder claims', 80 => 'Great beasts', 100 => 'Apex tracker'],
        ],
        'farming' => [
            'label' => 'Farming',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Crops, fibers, oils, stable reagents',
            'description' => 'Raise crops, herbs, fibers, cooking staples, and long-term reagents.',
            'unlocks' => [1 => 'Garden plots', 5 => 'Farming material sorting', 10 => 'Herb beds', 20 => 'Farming regional board', 30 => 'Fiber fields', 40 => 'Farming storm routes', 50 => 'Greenhouse yields', 65 => 'Farming elder claims', 80 => 'Seasonal breeds', 100 => 'Harvest master'],
        ],
        'excavation' => [
            'label' => 'Excavation',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Relics, bones, artifacts, lost maps',
            'description' => 'Recover buried relics, bones, fragments, maps, and ancient mechanisms.',
            'unlocks' => [1 => 'Old mounds', 5 => 'Excavation material sorting', 10 => 'Bone beds', 20 => 'Excavation regional board', 30 => 'Relic grids', 40 => 'Excavation storm routes', 50 => 'Ruin chambers', 65 => 'Excavation elder claims', 80 => 'Buried sanctums', 100 => 'Archive delver'],
        ],
        'smelting' => [
            'label' => 'Smelting',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Ore into bars',
            'description' => 'Refine ore, coal, and flux into bars and ingots.',
            'unlocks' => [1 => 'Copper and iron bars', 5 => 'Smelting waste reduction', 10 => 'Coal efficiency', 20 => 'Smelting batch commissions', 30 => 'Alloy batches', 40 => 'Smelting stormglass quotas', 50 => 'Rare ingots', 65 => 'Smelting elder refinements', 80 => 'Meteor refinement', 100 => 'Forge chemist'],
        ],
        'milling' => [
            'label' => 'Milling',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Logs into planks',
            'description' => 'Turn logs, bark, resin, and timber into planks and construction stock.',
            'unlocks' => [1 => 'Rough planks', 5 => 'Milling waste reduction', 10 => 'Bark sheets', 20 => 'Milling batch commissions', 30 => 'Resin-treated boards', 40 => 'Milling stormglass quotas', 50 => 'Precision beams', 65 => 'Milling elder refinements', 80 => 'Heartwood stock', 100 => 'Master sawyer'],
        ],
        'tanning' => [
            'label' => 'Tanning',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Hides into leather',
            'description' => 'Process hides, scales, and sinew into leather, straps, and armor stock.',
            'unlocks' => [1 => 'Rawhide leather', 5 => 'Tanning waste reduction', 10 => 'Cured hides', 20 => 'Tanning batch commissions', 30 => 'Scale backing', 40 => 'Tanning stormglass quotas', 50 => 'Reinforced leather', 65 => 'Tanning elder refinements', 80 => 'Monster hide', 100 => 'Hide master'],
        ],
        'cutting' => [
            'label' => 'Gem Cutting',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Gems into jewels',
            'description' => 'Cut gems, fossils, lenses, and magical stones for crafting and enchantment.',
            'unlocks' => [1 => 'Rough cuts', 5 => 'Gem Cutting waste reduction', 10 => 'Gem polishing', 20 => 'Gem Cutting batch commissions', 30 => 'Socket stones', 40 => 'Gem Cutting stormglass quotas', 50 => 'Highguild cuts', 65 => 'Gem Cutting elder refinements', 80 => 'Starfacet work', 100 => 'Facet savant'],
        ],
        'weaving' => [
            'label' => 'Weaving',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Fibers into cloth',
            'description' => 'Spin fibers, reeds, silk, and magical threads into cloth and bindings.',
            'unlocks' => [1 => 'Rough cloth', 5 => 'Weaving waste reduction', 10 => 'Thread bundles', 20 => 'Weaving batch commissions', 30 => 'Reinforced canvas', 40 => 'Weaving stormglass quotas', 50 => 'Silkwork', 65 => 'Weaving elder refinements', 80 => 'Spellthread', 100 => 'Loom keeper'],
        ],
        'smithing' => [
            'label' => 'Smithing',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Metal tools, armor, weapons',
            'description' => 'Craft tools, fittings, armor, blades, and heavy metal equipment.',
            'unlocks' => [1 => 'Field fittings', 5 => 'Smithing component fitting', 10 => 'Tool blanks', 20 => 'Smithing oathhall patterns', 30 => 'Steel equipment', 40 => 'Smithing stormglass patterns', 50 => 'Masterwork frames', 65 => 'Smithing elder masterworks', 80 => 'Meteor gear', 100 => 'Anvil saint'],
        ],
        'carpentry' => [
            'label' => 'Carpentry',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Wood tools, structures, bows',
            'description' => 'Craft handles, bows, structures, furniture frames, and ship timber.',
            'unlocks' => [1 => 'Tool handles', 5 => 'Carpentry component fitting', 10 => 'Simple furniture', 20 => 'Carpentry oathhall patterns', 30 => 'Strong frames', 40 => 'Carpentry stormglass patterns', 50 => 'Expedition crates', 65 => 'Carpentry elder masterworks', 80 => 'Living woodwork', 100 => 'Master carpenter'],
        ],
        'cooking' => [
            'label' => 'Cooking',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Food, buffs, raid supplies',
            'description' => 'Prepare food, stews, feasts, field rations, and event provisions.',
            'unlocks' => [1 => 'Simple meals', 5 => 'Cooking component fitting', 10 => 'Rations', 20 => 'Cooking oathhall patterns', 30 => 'Skill foods', 40 => 'Cooking stormglass patterns', 50 => 'Party feasts', 65 => 'Cooking elder masterworks', 80 => 'Starfeast cuisine', 100 => 'Realm chef'],
        ],
        'alchemy' => [
            'label' => 'Alchemy',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Potions, tonics, reagents',
            'description' => 'Brew tonics, oils, potions, catalysts, and strange reagents.',
            'unlocks' => [1 => 'Field tonics', 5 => 'Alchemy component fitting', 10 => 'Gathering oils', 20 => 'Alchemy oathhall patterns', 30 => 'Combat potions', 40 => 'Alchemy stormglass patterns', 50 => 'Catalysts', 65 => 'Alchemy elder masterworks', 80 => 'Transmutations', 100 => 'Grand alchemist'],
        ],
        'tailoring' => [
            'label' => 'Tailoring',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Cloth gear, bags, cosmetics',
            'description' => 'Craft clothing, bags, robes, sails, banners, and appearance pieces.',
            'unlocks' => [1 => 'Cloth wraps', 5 => 'Tailoring component fitting', 10 => 'Bags', 20 => 'Tailoring oathhall patterns', 30 => 'Robes', 40 => 'Tailoring stormglass patterns', 50 => 'Banners', 65 => 'Tailoring elder masterworks', 80 => 'Spellcloth outfits', 100 => 'Couture master'],
        ],
        'leatherworking' => [
            'label' => 'Leatherworking',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Leather gear, straps, saddles',
            'description' => 'Craft leather armor, pouches, straps, saddles, and rugged travel gear.',
            'unlocks' => [1 => 'Pouches', 5 => 'Leatherworking component fitting', 10 => 'Tool belts', 20 => 'Leatherworking oathhall patterns', 30 => 'Leather armor', 40 => 'Leatherworking stormglass patterns', 50 => 'Travel kits', 65 => 'Leatherworking elder masterworks', 80 => 'Monster gear', 100 => 'Hide artisan'],
        ],
        'engineering' => [
            'label' => 'Engineering',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Devices, traps, siege support',
            'description' => 'Build devices, traps, mechanisms, siege tools, and expedition machines.',
            'unlocks' => [1 => 'Simple mechanisms', 5 => 'Engineering component fitting', 10 => 'Traps', 20 => 'Engineering oathhall patterns', 30 => 'Gadgets', 40 => 'Engineering stormglass patterns', 50 => 'Siege parts', 65 => 'Engineering elder masterworks', 80 => 'Arcane engines', 100 => 'Chief engineer'],
        ],
        'enchanting' => [
            'label' => 'Enchanting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Magical traits and enhancements',
            'description' => 'Infuse equipment, jewels, charms, and relics with magical traits.',
            'unlocks' => [1 => 'Minor charms', 5 => 'Enchanting component fitting', 10 => 'Trait oils', 20 => 'Enchanting oathhall patterns', 30 => 'Socket infusions', 40 => 'Enchanting stormglass patterns', 50 => 'Major enchantments', 65 => 'Enchanting elder masterworks', 80 => 'Relic awakenings', 100 => 'Arcane binder'],
        ],
        'jewelcrafting' => [
            'label' => 'Jewelcrafting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Jewels, rings, trinkets',
            'description' => 'Craft rings, amulets, settings, trinkets, lenses, and focus stones.',
            'unlocks' => [1 => 'Copper settings', 5 => 'Jewelcrafting component fitting', 10 => 'Gem rings', 20 => 'Jewelcrafting oathhall patterns', 30 => 'Socket trinkets', 40 => 'Jewelcrafting stormglass patterns', 50 => 'Focus lenses', 65 => 'Jewelcrafting elder masterworks', 80 => 'Starfacet jewelry', 100 => 'Gem sovereign'],
        ],
        'boatbuilding' => [
            'label' => 'Boatbuilding',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Boats, sails, fleets',
            'description' => 'Build boats, hulls, sails, fleet cargo, and water expedition upgrades.',
            'unlocks' => [1 => 'Rafts', 5 => 'Boatbuilding component fitting', 10 => 'Skiffs', 20 => 'Boatbuilding oathhall patterns', 30 => 'Cargo boats', 40 => 'Boatbuilding stormglass patterns', 50 => 'Expedition hulls', 65 => 'Boatbuilding elder masterworks', 80 => 'Fleet vessels', 100 => 'Shipwright'],
        ],
        'furniture' => [
            'label' => 'Furniture Crafting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Housing, oathhalls, cosmetics',
            'description' => 'Craft furniture, trophies, displays, housing upgrades, and oathhall fixtures.',
            'unlocks' => [1 => 'Stools and crates', 5 => 'Furniture Crafting component fitting', 10 => 'Tables', 20 => 'Furniture Crafting oathhall patterns', 30 => 'Displays', 40 => 'Furniture Crafting stormglass patterns', 50 => 'Oathhall fixtures', 65 => 'Furniture Crafting elder masterworks', 80 => 'Prestige sets', 100 => 'Hall architect'],
        ],
        'construction' => [
            'label' => 'Construction',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Buildings, projects, settlements',
            'description' => 'Build settlement projects, fortifications, stations, and world-event structures.',
            'unlocks' => [1 => 'Repair tasks', 5 => 'Construction component fitting', 10 => 'Small stations', 20 => 'Construction oathhall patterns', 30 => 'Workshops', 40 => 'Construction stormglass patterns', 50 => 'Oathhall frames', 65 => 'Construction elder masterworks', 80 => 'Fortifications', 100 => 'Realm builder'],
        ],
        'combat' => [
            'label' => 'Combat',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'General fighting capability',
            'description' => 'Improve overall combat output, tactical reliability, and encounter readiness.',
            'unlocks' => [1 => 'Guard cuts', 5 => 'Combat role drills', 10 => 'Combat stances', 20 => 'Combat encounter board', 30 => 'Role focus', 40 => 'Combat stormfront tactics', 50 => 'Vanguard tactics', 65 => 'Combat elder trials', 80 => 'Champion trials', 100 => 'Realm champion'],
        ],
        'slayer' => [
            'label' => 'Slayer',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Monster marks and weaknesses',
            'description' => 'Take dangerous marks, exploit weaknesses, and recover monster trophies.',
            'unlocks' => [1 => 'Trail marks', 5 => 'Slayer role drills', 10 => 'Weakness study', 20 => 'Slayer encounter board', 30 => 'Special gear', 40 => 'Slayer stormfront tactics', 50 => 'Nightfang marks', 65 => 'Slayer elder trials', 80 => 'Crownbeast hunts', 100 => 'Monster bane'],
        ],
        'defense' => [
            'label' => 'Defense',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Armor, mitigation, protection',
            'description' => 'Improve protection, guard tactics, armor use, and dungeon survival.',
            'unlocks' => [1 => 'Guard basics', 5 => 'Defense role drills', 10 => 'Shield work', 20 => 'Defense encounter board', 30 => 'Armor mastery', 40 => 'Defense stormfront tactics', 50 => 'Party guard', 65 => 'Defense elder trials', 80 => 'Bulwark stance', 100 => 'Unbroken wall'],
        ],
        'healing' => [
            'label' => 'Healing',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Recovery and support',
            'description' => 'Restore allies, improve supplies, stabilize injuries, and support expeditions.',
            'unlocks' => [1 => 'First aid', 5 => 'Healing role drills', 10 => 'Tonics', 20 => 'Healing encounter board', 30 => 'Group recovery', 40 => 'Healing stormfront tactics', 50 => 'Expedition medic', 65 => 'Healing elder trials', 80 => 'Revival rites', 100 => 'Life warden'],
        ],
        'magic' => [
            'label' => 'Magic',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Spells, rituals, utility',
            'description' => 'Use spells, wards, rituals, and arcane utility in encounters and crafting.',
            'unlocks' => [1 => 'Sparks', 5 => 'Magic role drills', 10 => 'Wards', 20 => 'Magic encounter board', 30 => 'Elemental work', 40 => 'Magic stormfront tactics', 50 => 'Ritual magic', 65 => 'Magic elder trials', 80 => 'Arcane storms', 100 => 'Archmage'],
        ],
        'ranged' => [
            'label' => 'Ranged',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Bows, thrown weapons, siege aim',
            'description' => 'Improve bows, thrown tools, artillery support, and distance combat.',
            'unlocks' => [1 => 'Simple shots', 5 => 'Ranged role drills', 10 => 'Steady aim', 20 => 'Ranged encounter board', 30 => 'Special arrows', 40 => 'Ranged stormfront tactics', 50 => 'Siege marksmanship', 65 => 'Ranged elder trials', 80 => 'Trick shots', 100 => 'Sky archer'],
        ],
        'exploration' => [
            'label' => 'Exploration',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Expeditions, ruins, travel',
            'description' => 'Scout routes, resolve expeditions, uncover ruins, and reveal world opportunities.',
            'unlocks' => [1 => 'Local paths', 5 => 'Exploration field notes', 10 => 'Regional routes', 20 => 'Exploration wayfinder commissions', 30 => 'Hidden rooms', 40 => 'Exploration stormglass routes', 50 => 'Distant expeditions', 65 => 'Exploration elder waymarks', 80 => 'Ancient gates', 100 => 'Worldwalker'],
        ],
        'dungeoneering' => [
            'label' => 'Dungeoneering',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Dungeon routing and room checks',
            'description' => 'Handle traps, branching routes, boss rooms, and dungeon resource planning.',
            'unlocks' => [1 => 'Room checks', 5 => 'Dungeoneering field notes', 10 => 'Trap reads', 20 => 'Dungeoneering wayfinder commissions', 30 => 'Party routing', 40 => 'Dungeoneering stormglass routes', 50 => 'Boss prep', 65 => 'Dungeoneering elder waymarks', 80 => 'Deep chambers', 100 => 'Deep warden'],
        ],
        'sailing' => [
            'label' => 'Sailing',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Boats, coasts, sea expeditions',
            'description' => 'Navigate coasts, move cargo, support fleets, and unlock waterborne expeditions.',
            'unlocks' => [1 => 'Dock work', 5 => 'Sailing field notes', 10 => 'Coastal trips', 20 => 'Sailing wayfinder commissions', 30 => 'Cargo manifests', 40 => 'Sailing stormglass routes', 50 => 'Fleet support', 65 => 'Sailing elder waymarks', 80 => 'Stormglass sea charts', 100 => 'Tide captain'],
        ],
        'survival' => [
            'label' => 'Survival',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Long expeditions and dangerous regions',
            'description' => 'Improve campcraft, supplies, hazard resistance, and wild-region travel.',
            'unlocks' => [1 => 'Camp basics', 5 => 'Survival field notes', 10 => 'Weather reads', 20 => 'Survival wayfinder commissions', 30 => 'Long trips', 40 => 'Survival stormglass routes', 50 => 'Hazard kits', 65 => 'Survival elder waymarks', 80 => 'Hostile regions', 100 => 'Last light'],
        ],
        'cartography' => [
            'label' => 'Cartography',
            'type' => 'profession',
            'category' => 'World',
            'role' => 'Maps, routes, resource discovery',
            'description' => 'Map regions, annotate resource routes, chart dungeons, and sell navigation data.',
            'unlocks' => [1 => 'Sketch maps', 5 => 'Cartography field notes', 10 => 'Resource marks', 20 => 'Cartography wayfinder commissions', 30 => 'Route maps', 40 => 'Cartography stormglass routes', 50 => 'Dungeon charts', 65 => 'Cartography elder waymarks', 80 => 'Secret atlases', 100 => 'Star mapper'],
        ],
        'reputation' => [
            'label' => 'Reputation',
            'type' => 'skill',
            'category' => 'Social',
            'role' => 'Factions, regional privileges, titles',
            'description' => 'Earn trust, unlock faction privileges, regional rates, and titles.',
            'unlocks' => [1 => 'Local notices', 5 => 'Reputation notice access', 10 => 'Faction errands', 20 => 'Reputation regional contacts', 30 => 'Regional rates', 40 => 'Reputation stormglass writs', 50 => 'Trusted access', 65 => 'Reputation elder privileges', 80 => 'Council work', 100 => 'Realm envoy'],
        ],
        'leadership' => [
            'label' => 'Leadership',
            'type' => 'skill',
            'category' => 'Social',
            'role' => 'Crews, parties, raids',
            'description' => 'Coordinate parties, crew projects, raid supplies, and shared objectives.',
            'unlocks' => [1 => 'Party calls', 5 => 'Leadership notice access', 10 => 'Small teams', 20 => 'Leadership regional contacts', 30 => 'Crew tasks', 40 => 'Leadership stormglass writs', 50 => 'Raid planning', 65 => 'Leadership elder privileges', 80 => 'Regional campaigns', 100 => 'Bannerlord'],
        ],
        'trading' => [
            'label' => 'Trading',
            'type' => 'profession',
            'category' => 'Social',
            'role' => 'Marketplace, work orders, storefronts',
            'description' => 'Improve market access, storefronts, commissions, logistics, and economic play.',
            'unlocks' => [1 => 'Market listings', 5 => 'Trading notice access', 10 => 'Bulk listings', 20 => 'Trading regional contacts', 30 => 'Work orders', 40 => 'Trading stormglass writs', 50 => 'Storefronts', 65 => 'Trading elder privileges', 80 => 'Regional arbitrage', 100 => 'Market sovereign'],
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $baseDefinitionsCache = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $definitionsCache = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitionCache = [];

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $allCache = null;

    /**
     * @var list<string>|null
     */
    private static ?array $keysCache = null;

    public static function forgetCache(): void
    {
        self::$keysCache = null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        if (self::$keysCache !== null) {
            return self::$keysCache;
        }

        self::$keysCache = array_keys(app(self::class)->definitions());

        return self::$keysCache;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $skill): array
    {
        if (array_key_exists($skill, $this->definitionCache)) {
            return $this->definitionCache[$skill];
        }

        $definition = $this->definitions()[$skill] ?? [
            'label' => str($skill)->headline()->toString(),
            'type' => 'skill',
            'category' => 'Unmapped',
            'role' => 'Unmapped progression',
            'description' => 'Progression record awaiting catalog definition.',
            'unlocks' => [1 => 'Known record', 100 => 'Mastery'],
        ];
        $definition = $this->withTierUnlocks($skill, $definition);
        $definition['unlocks'] = $this->markedUnlocksFor($skill, $definition);
        $definition['target_hours_range'] = $this->targetHoursRangeFor($definition['category']);

        $this->definitionCache[$skill] = [
            'key' => $skill,
            ...$definition,
        ];

        return $this->definitionCache[$skill];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $this->allCache = collect(array_keys($this->definitions()))
            ->map(fn (string $key): array => [
                ...$this->definition($key),
                'max_level' => self::MAX_LEVEL,
            ])
            ->values()
            ->all();

        return $this->allCache;
    }

    /**
     * @return array<string, mixed>
     */
    public function pacing(): array
    {
        return [
            'max_level' => self::MAX_LEVEL,
            'level_100_experience' => self::LEVEL_100_EXPERIENCE,
            'calibrated_experience_per_hour' => self::CALIBRATED_EXPERIENCE_PER_HOUR,
            'estimated_hours_to_level_100' => round(self::LEVEL_100_EXPERIENCE / self::CALIBRATED_EXPERIENCE_PER_HOUR, 1),
            'target_hours_range' => [25, 200],
            'level_band_targets' => self::LEVEL_BAND_TARGETS,
            'category_targets' => self::CATEGORY_TARGETS,
            'major_action_goal_range' => [3000, 5000],
            'brutal_repetition_threshold' => 20000,
        ];
    }

    public function levelForExperience(int $experience): int
    {
        for ($level = self::MAX_LEVEL; $level >= 1; $level--) {
            if ($experience >= $this->experienceForLevel($level)) {
                return $level;
            }
        }

        return 1;
    }

    public function experienceForLevel(int $level): int
    {
        $level = max(1, min(self::MAX_LEVEL, $level));

        if ($level === 1) {
            return 0;
        }

        $anchors = self::LEVEL_EXPERIENCE_ANCHORS;
        $anchorLevels = array_keys($anchors);

        if (isset($anchors[$level])) {
            return $anchors[$level];
        }

        for ($index = 0; $index < count($anchorLevels) - 1; $index++) {
            $lowerLevel = $anchorLevels[$index];
            $upperLevel = $anchorLevels[$index + 1];

            if ($level > $lowerLevel && $level < $upperLevel) {
                $lowerExperience = $anchors[$lowerLevel];
                $upperExperience = $anchors[$upperLevel];
                $ratio = ($level - $lowerLevel) / ($upperLevel - $lowerLevel);

                return (int) round($lowerExperience + (($upperExperience - $lowerExperience) * $ratio));
            }
        }

        return self::LEVEL_100_EXPERIENCE;
    }

    public function nextLevelExperience(int $level): ?int
    {
        if ($level >= self::MAX_LEVEL) {
            return null;
        }

        return $this->experienceForLevel($level + 1);
    }

    /**
     * @return array<int, array{level: int, label: string}>
     */
    public function milestoneUnlocks(string $skill): array
    {
        $definition = $this->definition($skill);

        return collect($definition['unlocks'])
            ->map(fn (string $label, int $level): array => [
                'level' => $level,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupedCatalog(): array
    {
        return collect($this->all())
            ->groupBy('category')
            ->map(fn ($entries, string $category): array => [
                'category' => $category,
                'entries' => $entries->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function baseDefinitions(): array
    {
        if ($this->baseDefinitionsCache !== null) {
            return $this->baseDefinitionsCache;
        }

        $this->baseDefinitionsCache = collect(self::DEFINITIONS)
            ->mapWithKeys(fn (array $definition, string $skill): array => [
                $skill => $this->withTierUnlocks($skill, $definition),
            ])
            ->all();

        return $this->baseDefinitionsCache;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function withTierUnlocks(string $skill, array $definition): array
    {
        return [
            ...$definition,
            'unlocks' => $this->rawUnlocksFor($skill, $definition),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        if ($this->definitionsCache !== null) {
            return $this->definitionsCache;
        }

        $this->definitionsCache = app(ConnectedRealmsContentService::class)->apply('skill_definitions', $this->baseDefinitions());

        return $this->definitionsCache;
    }

    /**
     * @param  array{label: string, type: string, category: string, unlocks: array<int, string>}  $definition
     * @return array<int, string>
     */
    private function markedUnlocksFor(string $skill, array $definition): array
    {
        $unlocks = [];
        $rawUnlocks = $this->rawUnlocksFor($skill, $definition);

        foreach (EvergatherTierCatalog::tiers() as $tier) {
            $level = $tier['level'];
            $unlocks[$level] = "{$tier['mark']}: {$rawUnlocks[$level]}";
        }

        return $unlocks;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function rawUnlocksFor(string $skill, array $definition): array
    {
        $unlocks = [];
        $definedUnlocks = $this->definedUnlocks($definition['unlocks'] ?? []);

        foreach (EvergatherTierCatalog::tiers() as $tier) {
            $level = $tier['level'];
            $legacyLevel = $this->legacyLevelForTierLevel($level);
            $label = $definedUnlocks[$level]
                ?? ($legacyLevel === null ? null : ($definedUnlocks[$legacyLevel] ?? null))
                ?? $this->tierUnlocksFor($skill, $definition)[$level]
                ?? $this->canonicalUnlockLabel($definition, $tier);

            $unlocks[$level] = $this->withoutTierMark((string) $label);
        }

        return $unlocks;
    }

    /**
     * @return array<int, string>
     */
    private function definedUnlocks(mixed $unlocks): array
    {
        if (! is_array($unlocks)) {
            return [];
        }

        return collect($unlocks)
            ->filter(fn (mixed $label): bool => trim((string) $label) !== '')
            ->mapWithKeys(fn (mixed $label, int|string $level): array => [(int) $level => (string) $label])
            ->all();
    }

    private function legacyLevelForTierLevel(int $level): ?int
    {
        return match ($level) {
            30 => 25,
            80 => 75,
            default => null,
        };
    }

    private function withoutTierMark(string $label): string
    {
        foreach (EvergatherTierCatalog::tiers() as $tier) {
            $prefix = "{$tier['mark']}: ";

            if (str_starts_with($label, $prefix)) {
                return substr($label, strlen($prefix));
            }
        }

        return $label;
    }

    /**
     * @param  array{label: string, type: string, category: string}  $definition
     * @return array<int, string>
     */
    private function tierUnlocksFor(string $skill, array $definition): array
    {
        $label = $definition['label'] ?? str($skill)->headline()->toString();

        return match ($definition['category']) {
            'Gathering' => [
                5 => "{$label} material sorting",
                20 => "{$label} regional board",
                40 => "{$label} storm routes",
                65 => "{$label} elder claims",
            ],
            'Processing' => [
                5 => "{$label} waste reduction",
                20 => "{$label} batch commissions",
                40 => "{$label} stormglass quotas",
                65 => "{$label} elder refinements",
            ],
            'Crafting' => [
                5 => "{$label} component fitting",
                20 => "{$label} oathhall patterns",
                40 => "{$label} stormglass patterns",
                65 => "{$label} elder masterworks",
            ],
            'Combat' => [
                5 => "{$label} role drills",
                20 => "{$label} encounter board",
                40 => "{$label} stormfront tactics",
                65 => "{$label} elder trials",
            ],
            'World' => [
                5 => "{$label} field notes",
                20 => "{$label} wayfinder commissions",
                40 => "{$label} stormglass routes",
                65 => "{$label} elder waymarks",
            ],
            'Social' => [
                5 => "{$label} notice access",
                20 => "{$label} regional contacts",
                40 => "{$label} stormglass writs",
                65 => "{$label} elder privileges",
            ],
            default => $definition['type'] === 'profession'
                ? [
                    5 => "{$label} component prep",
                    20 => "{$label} board commissions",
                    40 => "{$label} stormglass orders",
                    65 => "{$label} elder commissions",
                ]
                : [
                    5 => "{$label} local requests",
                    20 => "{$label} board commissions",
                    40 => "{$label} stormglass tasks",
                    65 => "{$label} elder requests",
                ],
        };
    }

    /**
     * @param  array{label: string, type: string, category: string}  $definition
     * @param  array{mark: string, key_slug: string}  $tier
     */
    private function canonicalUnlockLabel(array $definition, array $tier): string
    {
        $label = $definition['label'] ?? 'Skill';

        return match ($tier['key_slug']) {
            'local' => "{$label} local board",
            'guild' => "{$label} guild board",
            'runed' => "{$label} runed orders",
            'storm' => "{$label} storm requests",
            'elder' => "{$label} elder writs",
            'mythic' => "{$label} mythic claims",
            default => "{$label} ".str($tier['key_slug'])->headline()->lower()->toString(),
        };
    }

    /**
     * @return array{int, int}
     */
    private function targetHoursRangeFor(string $category): array
    {
        return self::CATEGORY_TARGETS[$category]['target_hours_range'] ?? self::CATEGORY_TARGETS['Utility']['target_hours_range'];
    }
}
