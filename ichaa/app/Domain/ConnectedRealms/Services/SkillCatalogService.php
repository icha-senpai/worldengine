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
        20 => 2500,
        40 => 9000,
        60 => 26000,
        75 => 52000,
        90 => 95000,
        99 => 150000,
        100 => self::LEVEL_100_EXPERIENCE,
    ];

    /**
     * @var list<array{from_level: int, to_level: int, target_hours_range: array{int, int}}>
     */
    private const LEVEL_BAND_TARGETS = [
        ['from_level' => 1, 'to_level' => 20, 'target_hours_range' => [1, 2]],
        ['from_level' => 20, 'to_level' => 40, 'target_hours_range' => [3, 5]],
        ['from_level' => 40, 'to_level' => 60, 'target_hours_range' => [8, 12]],
        ['from_level' => 60, 'to_level' => 75, 'target_hours_range' => [12, 18]],
        ['from_level' => 75, 'to_level' => 90, 'target_hours_range' => [20, 30]],
        ['from_level' => 90, 'to_level' => 99, 'target_hours_range' => [20, 35]],
        ['from_level' => 99, 'to_level' => 100, 'target_hours_range' => [5, 15]],
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
            'unlocks' => [1 => 'River casts', 10 => 'Coastal shoals', 25 => 'Treasure nets', 50 => 'Deepwater routes', 75 => 'Leviathan tide pools', 100 => 'Leviathan angler'],
        ],
        'mining' => [
            'label' => 'Mining',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Ore, gems, coal, relic stone',
            'description' => 'Extract ore, gems, fossils, coal, and meteor metals.',
            'unlocks' => [1 => 'Surface ore', 10 => 'Coal seams', 25 => 'Gem pockets', 50 => 'Deep quarry shafts', 75 => 'Star-metal deposits', 100 => 'Mountain breaker'],
        ],
        'woodcutting' => [
            'label' => 'Woodcutting',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Logs, bark, sap, construction timber',
            'description' => 'Harvest common logs, rare woods, bark, resin, and living timber.',
            'unlocks' => [1 => 'Trail trees', 10 => 'Hardwood stands', 25 => 'Resin taps', 50 => 'Ancient groves', 75 => 'Heartwood cuts', 100 => 'Grove warden'],
        ],
        'foraging' => [
            'label' => 'Foraging',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Herbs, mushrooms, seeds, reagents',
            'description' => 'Gather herbs, mushrooms, wild seeds, roots, and alchemical reagents.',
            'unlocks' => [1 => 'Trail herbs', 10 => 'Mushroom rings', 25 => 'Seed caches', 50 => 'Rare reagents', 75 => 'Moonlit blooms', 100 => 'Wilds keeper'],
        ],
        'hunting' => [
            'label' => 'Hunting',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Meat, hides, bones, trophies',
            'description' => 'Track wildlife, set traps, recover hides, meat, bones, and trophies.',
            'unlocks' => [1 => 'Small traps', 10 => 'Trail tracking', 25 => 'Hide curing', 50 => 'Rare spoor', 75 => 'Great beasts', 100 => 'Apex tracker'],
        ],
        'farming' => [
            'label' => 'Farming',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Crops, fibers, oils, stable reagents',
            'description' => 'Raise crops, herbs, fibers, cooking staples, and long-term reagents.',
            'unlocks' => [1 => 'Garden plots', 10 => 'Herb beds', 25 => 'Fiber fields', 50 => 'Greenhouse yields', 75 => 'Seasonal breeds', 100 => 'Harvest master'],
        ],
        'excavation' => [
            'label' => 'Excavation',
            'type' => 'skill',
            'category' => 'Gathering',
            'role' => 'Relics, bones, artifacts, lost maps',
            'description' => 'Recover buried relics, bones, fragments, maps, and ancient mechanisms.',
            'unlocks' => [1 => 'Old mounds', 10 => 'Bone beds', 25 => 'Relic grids', 50 => 'Ruin chambers', 75 => 'Buried sanctums', 100 => 'Archive delver'],
        ],
        'smelting' => [
            'label' => 'Smelting',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Ore into bars',
            'description' => 'Refine ore, coal, and flux into bars and ingots.',
            'unlocks' => [1 => 'Copper and iron bars', 10 => 'Coal efficiency', 25 => 'Alloy batches', 50 => 'Rare ingots', 75 => 'Star-metal refinement', 100 => 'Forge chemist'],
        ],
        'milling' => [
            'label' => 'Milling',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Logs into planks',
            'description' => 'Turn logs, bark, resin, and timber into planks and construction stock.',
            'unlocks' => [1 => 'Rough planks', 10 => 'Bark sheets', 25 => 'Resin-treated boards', 50 => 'Precision beams', 75 => 'Heartwood stock', 100 => 'Master sawyer'],
        ],
        'tanning' => [
            'label' => 'Tanning',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Hides into leather',
            'description' => 'Process hides, scales, and sinew into leather, straps, and armor stock.',
            'unlocks' => [1 => 'Rawhide leather', 10 => 'Cured hides', 25 => 'Scale backing', 50 => 'Reinforced leather', 75 => 'Monster hide', 100 => 'Hide master'],
        ],
        'cutting' => [
            'label' => 'Gem Cutting',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Gems into jewels',
            'description' => 'Cut gems, fossils, lenses, and magical stones for crafting and enchantment.',
            'unlocks' => [1 => 'Rough cuts', 10 => 'Gem polishing', 25 => 'Socket stones', 50 => 'Prismatic cuts', 75 => 'Starfacet work', 100 => 'Facet savant'],
        ],
        'weaving' => [
            'label' => 'Weaving',
            'type' => 'profession',
            'category' => 'Processing',
            'role' => 'Fibers into cloth',
            'description' => 'Spin fibers, reeds, silk, and magical threads into cloth and bindings.',
            'unlocks' => [1 => 'Rough cloth', 10 => 'Thread bundles', 25 => 'Reinforced canvas', 50 => 'Silkwork', 75 => 'Spellthread', 100 => 'Loom keeper'],
        ],
        'smithing' => [
            'label' => 'Smithing',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Metal tools, armor, weapons',
            'description' => 'Craft tools, fittings, armor, blades, and heavy metal equipment.',
            'unlocks' => [1 => 'Field fittings', 10 => 'Tool blanks', 25 => 'Steel equipment', 50 => 'Masterwork frames', 75 => 'Meteor gear', 100 => 'Anvil saint'],
        ],
        'carpentry' => [
            'label' => 'Carpentry',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Wood tools, structures, bows',
            'description' => 'Craft handles, bows, structures, furniture frames, and ship timber.',
            'unlocks' => [1 => 'Tool handles', 10 => 'Simple furniture', 25 => 'Strong frames', 50 => 'Expedition crates', 75 => 'Living woodwork', 100 => 'Master carpenter'],
        ],
        'cooking' => [
            'label' => 'Cooking',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Food, buffs, raid supplies',
            'description' => 'Prepare food, stews, feasts, field rations, and event provisions.',
            'unlocks' => [1 => 'Simple meals', 10 => 'Rations', 25 => 'Skill foods', 50 => 'Party feasts', 75 => 'Starfeast cuisine', 100 => 'Realm chef'],
        ],
        'alchemy' => [
            'label' => 'Alchemy',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Potions, tonics, reagents',
            'description' => 'Brew tonics, oils, potions, catalysts, and strange reagents.',
            'unlocks' => [1 => 'Field tonics', 10 => 'Gathering oils', 25 => 'Combat potions', 50 => 'Catalysts', 75 => 'Transmutations', 100 => 'Grand alchemist'],
        ],
        'tailoring' => [
            'label' => 'Tailoring',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Cloth gear, bags, cosmetics',
            'description' => 'Craft clothing, bags, robes, sails, banners, and appearance pieces.',
            'unlocks' => [1 => 'Cloth wraps', 10 => 'Bags', 25 => 'Robes', 50 => 'Banners', 75 => 'Spellcloth outfits', 100 => 'Couture master'],
        ],
        'leatherworking' => [
            'label' => 'Leatherworking',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Leather gear, straps, saddles',
            'description' => 'Craft leather armor, pouches, straps, saddles, and rugged travel gear.',
            'unlocks' => [1 => 'Pouches', 10 => 'Tool belts', 25 => 'Leather armor', 50 => 'Travel kits', 75 => 'Monster gear', 100 => 'Hide artisan'],
        ],
        'engineering' => [
            'label' => 'Engineering',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Devices, traps, siege support',
            'description' => 'Build devices, traps, mechanisms, siege tools, and expedition machines.',
            'unlocks' => [1 => 'Simple mechanisms', 10 => 'Traps', 25 => 'Gadgets', 50 => 'Siege parts', 75 => 'Arcane engines', 100 => 'Chief engineer'],
        ],
        'enchanting' => [
            'label' => 'Enchanting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Magical traits and enhancements',
            'description' => 'Infuse equipment, jewels, charms, and relics with magical traits.',
            'unlocks' => [1 => 'Minor charms', 10 => 'Trait oils', 25 => 'Socket infusions', 50 => 'Major enchantments', 75 => 'Relic awakenings', 100 => 'Arcane binder'],
        ],
        'jewelcrafting' => [
            'label' => 'Jewelcrafting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Jewels, rings, trinkets',
            'description' => 'Craft rings, amulets, settings, trinkets, lenses, and focus stones.',
            'unlocks' => [1 => 'Copper settings', 10 => 'Gem rings', 25 => 'Socket trinkets', 50 => 'Focus lenses', 75 => 'Starfacet jewelry', 100 => 'Gem sovereign'],
        ],
        'boatbuilding' => [
            'label' => 'Boatbuilding',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Boats, sails, fleets',
            'description' => 'Build boats, hulls, sails, fleet cargo, and water expedition upgrades.',
            'unlocks' => [1 => 'Rafts', 10 => 'Skiffs', 25 => 'Cargo boats', 50 => 'Expedition hulls', 75 => 'Fleet vessels', 100 => 'Shipwright'],
        ],
        'furniture' => [
            'label' => 'Furniture Crafting',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Housing, oathhalls, cosmetics',
            'description' => 'Craft furniture, trophies, displays, housing upgrades, and oathhall fixtures.',
            'unlocks' => [1 => 'Stools and crates', 10 => 'Tables', 25 => 'Displays', 50 => 'Oathhall fixtures', 75 => 'Prestige sets', 100 => 'Hall architect'],
        ],
        'construction' => [
            'label' => 'Construction',
            'type' => 'profession',
            'category' => 'Crafting',
            'role' => 'Buildings, projects, settlements',
            'description' => 'Build settlement projects, fortifications, stations, and world-event structures.',
            'unlocks' => [1 => 'Repair tasks', 10 => 'Small stations', 25 => 'Workshops', 50 => 'Oathhall frames', 75 => 'Fortifications', 100 => 'Realm builder'],
        ],
        'combat' => [
            'label' => 'Combat',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'General fighting capability',
            'description' => 'Improve overall combat output, tactical reliability, and encounter readiness.',
            'unlocks' => [1 => 'Guard cuts', 10 => 'Combat stances', 25 => 'Role focus', 50 => 'Vanguard tactics', 75 => 'Champion trials', 100 => 'Realm champion'],
        ],
        'slayer' => [
            'label' => 'Slayer',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Monster marks and weaknesses',
            'description' => 'Take dangerous marks, exploit weaknesses, and recover monster trophies.',
            'unlocks' => [1 => 'Trail marks', 10 => 'Weakness study', 25 => 'Special gear', 50 => 'Nightfang marks', 75 => 'Crownbeast hunts', 100 => 'Monster bane'],
        ],
        'defense' => [
            'label' => 'Defense',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Armor, mitigation, protection',
            'description' => 'Improve protection, guard tactics, armor use, and dungeon survival.',
            'unlocks' => [1 => 'Guard basics', 10 => 'Shield work', 25 => 'Armor mastery', 50 => 'Party guard', 75 => 'Bulwark stance', 100 => 'Unbroken wall'],
        ],
        'healing' => [
            'label' => 'Healing',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Recovery and support',
            'description' => 'Restore allies, improve supplies, stabilize injuries, and support expeditions.',
            'unlocks' => [1 => 'First aid', 10 => 'Tonics', 25 => 'Group recovery', 50 => 'Expedition medic', 75 => 'Revival rites', 100 => 'Life warden'],
        ],
        'magic' => [
            'label' => 'Magic',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Spells, rituals, utility',
            'description' => 'Use spells, wards, rituals, and arcane utility in encounters and crafting.',
            'unlocks' => [1 => 'Sparks', 10 => 'Wards', 25 => 'Elemental work', 50 => 'Ritual magic', 75 => 'Arcane storms', 100 => 'Archmage'],
        ],
        'ranged' => [
            'label' => 'Ranged',
            'type' => 'skill',
            'category' => 'Combat',
            'role' => 'Bows, thrown weapons, siege aim',
            'description' => 'Improve bows, thrown tools, artillery support, and distance combat.',
            'unlocks' => [1 => 'Simple shots', 10 => 'Steady aim', 25 => 'Special arrows', 50 => 'Siege marksmanship', 75 => 'Trick shots', 100 => 'Sky archer'],
        ],
        'exploration' => [
            'label' => 'Exploration',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Expeditions, ruins, travel',
            'description' => 'Scout routes, resolve expeditions, uncover ruins, and reveal world opportunities.',
            'unlocks' => [1 => 'Local paths', 10 => 'Regional routes', 25 => 'Hidden rooms', 50 => 'Distant expeditions', 75 => 'Ancient gates', 100 => 'Worldwalker'],
        ],
        'dungeoneering' => [
            'label' => 'Dungeoneering',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Dungeon routing and room checks',
            'description' => 'Handle traps, branching routes, boss rooms, and dungeon resource planning.',
            'unlocks' => [1 => 'Room checks', 10 => 'Trap reads', 25 => 'Party routing', 50 => 'Boss prep', 75 => 'Deep chambers', 100 => 'Deep warden'],
        ],
        'sailing' => [
            'label' => 'Sailing',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Boats, coasts, sea expeditions',
            'description' => 'Navigate coasts, move cargo, support fleets, and unlock waterborne expeditions.',
            'unlocks' => [1 => 'Dock work', 10 => 'Coastal trips', 25 => 'Cargo manifests', 50 => 'Fleet support', 75 => 'Stormglass sea charts', 100 => 'Tide captain'],
        ],
        'survival' => [
            'label' => 'Survival',
            'type' => 'skill',
            'category' => 'World',
            'role' => 'Long expeditions and dangerous regions',
            'description' => 'Improve campcraft, supplies, hazard resistance, and wild-region travel.',
            'unlocks' => [1 => 'Camp basics', 10 => 'Weather reads', 25 => 'Long trips', 50 => 'Hazard kits', 75 => 'Hostile regions', 100 => 'Last light'],
        ],
        'cartography' => [
            'label' => 'Cartography',
            'type' => 'profession',
            'category' => 'World',
            'role' => 'Maps, routes, resource discovery',
            'description' => 'Map regions, annotate resource routes, chart dungeons, and sell navigation data.',
            'unlocks' => [1 => 'Sketch maps', 10 => 'Resource marks', 25 => 'Route maps', 50 => 'Dungeon charts', 75 => 'Secret atlases', 100 => 'Star mapper'],
        ],
        'reputation' => [
            'label' => 'Reputation',
            'type' => 'skill',
            'category' => 'Social',
            'role' => 'Factions, regional privileges, titles',
            'description' => 'Earn trust, unlock faction privileges, regional rates, and titles.',
            'unlocks' => [1 => 'Local notices', 10 => 'Faction errands', 25 => 'Regional rates', 50 => 'Trusted access', 75 => 'Council work', 100 => 'Realm envoy'],
        ],
        'leadership' => [
            'label' => 'Leadership',
            'type' => 'skill',
            'category' => 'Social',
            'role' => 'Crews, parties, raids',
            'description' => 'Coordinate parties, crew projects, raid supplies, and shared objectives.',
            'unlocks' => [1 => 'Party calls', 10 => 'Small teams', 25 => 'Crew tasks', 50 => 'Raid planning', 75 => 'Regional campaigns', 100 => 'Bannerlord'],
        ],
        'trading' => [
            'label' => 'Trading',
            'type' => 'profession',
            'category' => 'Social',
            'role' => 'Marketplace, work orders, storefronts',
            'description' => 'Improve market access, storefronts, commissions, logistics, and economic play.',
            'unlocks' => [1 => 'Market listings', 10 => 'Bulk listings', 25 => 'Work orders', 50 => 'Storefronts', 75 => 'Regional arbitrage', 100 => 'Market sovereign'],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $skill): array
    {
        $definition = self::DEFINITIONS[$skill] ?? [
            'label' => str($skill)->headline()->toString(),
            'type' => 'skill',
            'category' => 'Unmapped',
            'role' => 'Unmapped progression',
            'description' => 'Progression record awaiting catalog definition.',
            'unlocks' => [1 => 'Known record', 100 => 'Mastery'],
        ];
        $definition['unlocks'] = $this->unlocksFor($skill, $definition);
        $definition['target_hours_range'] = $this->targetHoursRangeFor($definition['category']);

        return [
            'key' => $skill,
            ...$definition,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return collect(array_keys(self::DEFINITIONS))
            ->map(fn (string $key): array => [
                ...$this->definition($key),
                'max_level' => self::MAX_LEVEL,
            ])
            ->values()
            ->all();
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
     * @param  array{label: string, type: string, category: string, unlocks: array<int, string>}  $definition
     * @return array<int, string>
     */
    private function unlocksFor(string $skill, array $definition): array
    {
        $unlocks = $definition['unlocks'];

        foreach ($this->earlyUnlocksFor($skill, $definition) as $level => $label) {
            if (! array_key_exists($level, $unlocks)) {
                $unlocks[$level] = $label;
            }
        }

        ksort($unlocks);

        return $unlocks;
    }

    /**
     * @param  array{label: string, type: string, category: string}  $definition
     * @return array<int, string>
     */
    private function earlyUnlocksFor(string $skill, array $definition): array
    {
        $label = $definition['label'] ?? str($skill)->headline()->toString();

        return match ($definition['category']) {
            'Gathering' => [
                3 => "{$label} side routes",
                5 => "{$label} material sorting",
                8 => "{$label} yield targeting",
                12 => "{$label} supplier requests",
                15 => "{$label} route prep",
                20 => "{$label} regional board",
            ],
            'Processing' => [
                3 => "{$label} first batches",
                5 => "{$label} waste reduction",
                8 => "{$label} component prep",
                12 => "{$label} workshop quotas",
                15 => "{$label} refined orders",
                20 => "{$label} batch commissions",
            ],
            'Crafting' => [
                3 => "{$label} bench commissions",
                5 => "{$label} component fitting",
                8 => "{$label} fitted gear",
                12 => "{$label} request board",
                15 => "{$label} specialist patterns",
                20 => "{$label} oathhall patterns",
            ],
            'Combat' => [
                3 => "{$label} training rounds",
                5 => "{$label} role drills",
                8 => "{$label} field assignments",
                12 => "{$label} party support",
                15 => "{$label} threat study",
                20 => "{$label} encounter board",
            ],
            'World' => [
                3 => "{$label} route checks",
                5 => "{$label} field notes",
                8 => "{$label} supply caches",
                12 => "{$label} regional scouting",
                15 => "{$label} hazard prep",
                20 => "{$label} wayfinder commissions",
            ],
            'Social' => [
                3 => "{$label} local errands",
                5 => "{$label} notice access",
                8 => "{$label} small commissions",
                12 => "{$label} introductions",
                15 => "{$label} request board",
                20 => "{$label} regional contacts",
            ],
            default => $definition['type'] === 'profession'
                ? [
                    3 => "{$label} first orders",
                    5 => "{$label} component prep",
                    8 => "{$label} bench commissions",
                    12 => "{$label} workshop board",
                    15 => "{$label} specialist patterns",
                    20 => "{$label} board commissions",
                ]
                : [
                    3 => "{$label} first activities",
                    5 => "{$label} local requests",
                    8 => "{$label} field tasks",
                    12 => "{$label} field board",
                    15 => "{$label} specialist routes",
                    20 => "{$label} board commissions",
                ],
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
