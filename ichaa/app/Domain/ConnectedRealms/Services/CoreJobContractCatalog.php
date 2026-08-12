<?php

namespace App\Domain\ConnectedRealms\Services;

class CoreJobContractCatalog
{
    /**
     * @var list<int>
     */
    private const GATHER_QUANTITIES = [6, 8, 10, 12, 14, 16, 18, 20, 24, 30];

    /**
     * @var list<int>
     */
    private const PROCESS_QUANTITIES = [2, 3, 4, 5, 6, 7, 8, 9, 10, 12];

    /**
     * @var list<int>
     */
    private const CRAFT_QUANTITIES = [1, 1, 1, 2, 2, 2, 2, 3, 3, 4];

    /**
     * @var list<int>
     */
    private const MENU_ACTION_QUANTITIES = [3, 3, 4, 4, 5, 5, 6, 6, 7, 8];

    /**
     * @var list<int>
     */
    private const MENU_RUN_QUANTITIES = [1, 1, 1, 2, 2, 2, 2, 3, 3, 3];

    /**
     * @var list<int>
     */
    private const CIVIC_QUANTITIES = [2, 2, 3, 3, 4, 4, 5, 5, 6, 6];

    /**
     * @var array<string, string>
     */
    private const SUPPORT_SUPPLY_SKILLS = [
        'combat' => 'smithing',
        'slayer' => 'leatherworking',
        'defense' => 'construction',
        'healing' => 'alchemy',
        'magic' => 'enchanting',
        'ranged' => 'carpentry',
        'exploration' => 'cartography',
        'dungeoneering' => 'cartography',
        'sailing' => 'boatbuilding',
        'survival' => 'cooking',
        'reputation' => 'trading',
        'leadership' => 'construction',
        'trading' => 'trading',
    ];

    /**
     * @var array<string, array{category: string, archetype: string, objective_type: string, line_label: string, consumer: string, purpose: string, tool_material_profile: string, schedule: string}>
     */
    private const PROFILES = [
        'fishing' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Fishing Catch', 'consumer' => 'Saltmere Kitchens', 'purpose' => 'Feeds dockside kitchens and preserves aquatic stock for settlements.', 'tool_material_profile' => 'Lumber/Textile', 'schedule' => 'gather'],
        'mining' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Mining Assay', 'consumer' => 'West Gate Smiths', 'purpose' => 'Routes ore and stone into forge reserves.', 'tool_material_profile' => 'Metal/Lumber', 'schedule' => 'gather'],
        'woodcutting' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Woodcutting Timber', 'consumer' => 'Drydock Yard', 'purpose' => 'Supplies lumber lines for docks, halls, and tool hafts.', 'tool_material_profile' => 'Metal/Lumber', 'schedule' => 'gather'],
        'foraging' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Foraging Cache', 'consumer' => 'Briarwatch Herbalists', 'purpose' => 'Stocks herbs, fibers, and wild reagents for support work.', 'tool_material_profile' => 'Textile/Leather', 'schedule' => 'gather'],
        'hunting' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Hunting Tally', 'consumer' => 'Trailwarden Lodge', 'purpose' => 'Turns meat, hides, and trophies into practical camp demand.', 'tool_material_profile' => 'Leather/Metal/Creature', 'schedule' => 'gather'],
        'farming' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Farming Harvest', 'consumer' => 'Sunfield Granary', 'purpose' => 'Feeds granaries, kitchens, cloth lines, and provisions.', 'tool_material_profile' => 'Metal/Lumber/Mechanism', 'schedule' => 'gather'],
        'excavation' => ['category' => 'Gathering', 'archetype' => 'Gathering Contract', 'objective_type' => 'gather', 'line_label' => 'Excavation Find', 'consumer' => 'Lower Vault Office', 'purpose' => 'Moves relics, tablets, and survey pieces into civic records.', 'tool_material_profile' => 'Metal/Lumber/Cut Gem', 'schedule' => 'gather'],
        'smelting' => ['category' => 'Processing', 'archetype' => 'Processing Order', 'objective_type' => 'process', 'line_label' => 'Smelting Batch', 'consumer' => 'Emberdeep Forge', 'purpose' => 'Converts mined stock into metal demand for every workshop tier.', 'tool_material_profile' => 'Stone/Metal', 'schedule' => 'process'],
        'milling' => ['category' => 'Processing', 'archetype' => 'Processing Order', 'objective_type' => 'process', 'line_label' => 'Milling Batch', 'consumer' => 'Whisperbough Mill', 'purpose' => 'Turns timber into lumber demand for shipwrights and builders.', 'tool_material_profile' => 'Metal/Lumber', 'schedule' => 'process'],
        'tanning' => ['category' => 'Processing', 'archetype' => 'Processing Order', 'objective_type' => 'process', 'line_label' => 'Tanning Batch', 'consumer' => 'Briarwake Tannery', 'purpose' => 'Converts hides into leather demand for armor, packs, and repairs.', 'tool_material_profile' => 'Lumber/Textile', 'schedule' => 'process'],
        'cutting' => ['category' => 'Processing', 'archetype' => 'Processing Order', 'objective_type' => 'process', 'line_label' => 'Gem Cutting Batch', 'consumer' => 'Gemcutter Row', 'purpose' => 'Shapes gems and lenses for arcane, jewelry, and tool work.', 'tool_material_profile' => 'Metal/Stone/Mechanism', 'schedule' => 'process'],
        'weaving' => ['category' => 'Processing', 'archetype' => 'Processing Order', 'objective_type' => 'process', 'line_label' => 'Weaving Batch', 'consumer' => 'Sunfield Loomhall', 'purpose' => 'Turns fiber into textile demand for sails, robes, and banners.', 'tool_material_profile' => 'Lumber/Metal', 'schedule' => 'process'],
        'smithing' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Smithing Commission', 'consumer' => 'Moonwake Anvil Yard', 'purpose' => 'Creates metal fittings, weapons, and structural hardware.', 'tool_material_profile' => 'Metal/Lumber', 'schedule' => 'craft'],
        'carpentry' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Carpentry Commission', 'consumer' => 'Oathhall Joiners', 'purpose' => 'Creates wood frames, bows, crates, and handles.', 'tool_material_profile' => 'Metal/Lumber', 'schedule' => 'craft'],
        'cooking' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Cooking Commission', 'consumer' => 'Hearthline Cooks', 'purpose' => 'Creates provisions and meals consumed by camps and expeditions.', 'tool_material_profile' => 'Metal/Stone', 'schedule' => 'craft'],
        'alchemy' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Alchemy Commission', 'consumer' => 'Glimmerfen Stillroom', 'purpose' => 'Creates tonics, reagents, and support consumables.', 'tool_material_profile' => 'Cut Gem/Metal/Stone', 'schedule' => 'craft'],
        'tailoring' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Tailoring Commission', 'consumer' => 'Sunfield Stitchery', 'purpose' => 'Creates cloth goods, field wraps, sails, and vestments.', 'tool_material_profile' => 'Metal/Textile', 'schedule' => 'craft'],
        'leatherworking' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Leatherworking Commission', 'consumer' => 'Strap Bench', 'purpose' => 'Creates leather gear, harnesses, and armor pieces.', 'tool_material_profile' => 'Metal/Leather/Lumber', 'schedule' => 'craft'],
        'engineering' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Engineering Commission', 'consumer' => 'Clockwork Yard', 'purpose' => 'Creates mechanisms, gauges, lures, and engine parts.', 'tool_material_profile' => 'Metal/Cut Gem/Mechanism', 'schedule' => 'craft'],
        'enchanting' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Enchanting Commission', 'consumer' => 'Moon Ward Annex', 'purpose' => 'Creates wards, foci, sigils, and arcane seals.', 'tool_material_profile' => 'Cut Gem/Relic/Arcane', 'schedule' => 'craft'],
        'jewelcrafting' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Jewelcrafting Commission', 'consumer' => 'Gem Setting Office', 'purpose' => 'Creates settings, rings, amulets, crowns, and lenses.', 'tool_material_profile' => 'Metal/Cut Gem/Mechanism', 'schedule' => 'craft'],
        'boatbuilding' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Boatbuilding Commission', 'consumer' => 'Moonwake Drydock', 'purpose' => 'Creates ribs, floats, hulls, and expedition vessels.', 'tool_material_profile' => 'Lumber/Metal/Textile', 'schedule' => 'craft'],
        'furniture' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Furniture Commission', 'consumer' => 'Hallwright Table', 'purpose' => 'Creates hall goods, fixtures, stands, and prestige furniture.', 'tool_material_profile' => 'Lumber/Metal/Textile', 'schedule' => 'craft'],
        'construction' => ['category' => 'Crafting', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Construction Commission', 'consumer' => 'Settlement Frame Crew', 'purpose' => 'Creates frames, scaffolds, walls, and public works.', 'tool_material_profile' => 'Metal/Lumber/Stone', 'schedule' => 'craft'],
        'combat' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Combat Operation', 'consumer' => 'Training Ring', 'purpose' => 'Rewards resolved combat practice, not purchased weapons.', 'tool_material_profile' => 'Metal/Leather', 'schedule' => 'menu'],
        'slayer' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Slayer Operation', 'consumer' => 'Bounty Board', 'purpose' => 'Rewards resolved bounty work and monster study.', 'tool_material_profile' => 'Leather/Creature/Arcane', 'schedule' => 'menu'],
        'defense' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Defense Operation', 'consumer' => 'Old Gate Shieldline', 'purpose' => 'Rewards resolved protection and guard rotation work.', 'tool_material_profile' => 'Metal/Lumber/Leather', 'schedule' => 'menu'],
        'healing' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Healing Operation', 'consumer' => 'Moonwake Infirmary', 'purpose' => 'Rewards resolved support and triage work.', 'tool_material_profile' => 'Textile/Herb/Arcane', 'schedule' => 'menu'],
        'magic' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Magic Operation', 'consumer' => 'Moon Ward Circle', 'purpose' => 'Rewards resolved spellwork and arcane trials.', 'tool_material_profile' => 'Cut Gem/Relic/Arcane', 'schedule' => 'menu'],
        'ranged' => ['category' => 'Combat & Support', 'archetype' => 'Combat Operation', 'objective_type' => 'deliver', 'line_label' => 'Ranged Operation', 'consumer' => 'High Perch Range', 'purpose' => 'Rewards resolved ranged drills and marksmanship.', 'tool_material_profile' => 'Lumber/Textile/Creature', 'schedule' => 'menu'],
        'exploration' => ['category' => 'Adventure', 'archetype' => 'Adventure Contract', 'objective_type' => 'deliver', 'line_label' => 'Exploration Route', 'consumer' => 'Hidden Mile Scouts', 'purpose' => 'Rewards resolved route scouting and survey work.', 'tool_material_profile' => 'Leather/Textile/Map', 'schedule' => 'menu'],
        'dungeoneering' => ['category' => 'Adventure', 'archetype' => 'Adventure Contract', 'objective_type' => 'deliver', 'line_label' => 'Dungeoneering Run', 'consumer' => 'Lower Vault Delvers', 'purpose' => 'Rewards resolved dungeon runs and vault route work.', 'tool_material_profile' => 'Metal/Leather/Map', 'schedule' => 'run'],
        'sailing' => ['category' => 'Adventure', 'archetype' => 'Adventure Contract', 'objective_type' => 'deliver', 'line_label' => 'Sailing Voyage', 'consumer' => 'Stormbreak Harbor', 'purpose' => 'Rewards resolved voyages and sea-route operations.', 'tool_material_profile' => 'Lumber/Textile/Map', 'schedule' => 'run'],
        'survival' => ['category' => 'Adventure', 'archetype' => 'Adventure Contract', 'objective_type' => 'deliver', 'line_label' => 'Survival Circuit', 'consumer' => 'Cold Camp Quartermaster', 'purpose' => 'Rewards resolved survival and campcraft work.', 'tool_material_profile' => 'Leather/Lumber/Provision', 'schedule' => 'menu'],
        'cartography' => ['category' => 'Adventure', 'archetype' => 'Craft Commission', 'objective_type' => 'craft', 'line_label' => 'Cartography Commission', 'consumer' => 'Surveyor Ridge', 'purpose' => 'Creates maps and charts for routes, expeditions, and archives.', 'tool_material_profile' => 'Leather/Textile/Map', 'schedule' => 'craft'],
        'reputation' => ['category' => 'Civic & Economy', 'archetype' => 'Civic Contract', 'objective_type' => 'deliver', 'line_label' => 'Reputation Request', 'consumer' => 'Regional Council', 'purpose' => 'Rewards server-resolved faction requests without live-player dependency.', 'tool_material_profile' => 'Trade Doc/Leather/Arcane', 'schedule' => 'civic'],
        'leadership' => ['category' => 'Civic & Economy', 'archetype' => 'Civic Contract', 'objective_type' => 'deliver', 'line_label' => 'Leadership Operation', 'consumer' => 'Muster Yard', 'purpose' => 'Rewards resolved management operations, not live-player command loops.', 'tool_material_profile' => 'Textile/Lumber/Trade Doc', 'schedule' => 'civic'],
        'trading' => ['category' => 'Civic & Economy', 'archetype' => 'Civic Contract', 'objective_type' => 'deliver', 'line_label' => 'Trading Operation', 'consumer' => 'Crossroads Brokerage', 'purpose' => 'Creates barter notes, market tokens, and trade paperwork for settlement demand.', 'tool_material_profile' => 'Trade Doc/Leather/Arcane', 'schedule' => 'civic'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function contracts(): array
    {
        $gatheringOutputs = self::gatheringOutputsBySkillAndLevel();
        $recipeOutputs = self::recipeOutputsBySkillAndLevel();
        $activityLabels = self::activityLabelsBySkillAndLevel();
        $contracts = [];

        foreach (self::PROFILES as $skill => $profile) {
            foreach (EvergatherTierCatalog::tiers() as $index => $tier) {
                $level = (int) $tier['level'];
                $quantity = self::quantityFor($profile['schedule'], $index);
                $requirement = self::requirementFor($skill, $profile, $level, $quantity, $gatheringOutputs, $recipeOutputs);
                $objectiveType = self::runtimeObjectiveTypeFor($profile);
                $objective = self::objectiveFor($skill, $profile, $tier, $quantity, $requirement, $objectiveType, $activityLabels[$skill][$level] ?? null);
                $gold = self::goldFor($profile['schedule'], $level, $quantity);
                $experience = self::experienceFor($profile['schedule'], $level, $quantity);
                $key = "{$skill}_{$tier['key_slug']}_contract";

                $contracts[$key] = [
                    'label' => "{$tier['mark']} {$profile['line_label']}",
                    'category' => $profile['category'],
                    'skill' => $skill,
                    'required_level' => $level,
                    'tier' => (int) $tier['item_tier'],
                    'tier_mark' => $tier['mark'],
                    'archetype' => $profile['archetype'],
                    'objective_type' => $objectiveType,
                    'objective' => $objective,
                    'demand_channel' => 'profession_turn_in',
                    'demand_pool' => 'profession_contracts',
                    'world_consumer' => $profile['consumer'],
                    'purpose' => $profile['purpose'],
                    'sink' => [
                        'type' => $profile['archetype'],
                        'label' => "{$profile['consumer']} {$tier['mark']} demand",
                        'required_level' => $level,
                        'context' => $profile['category'],
                    ],
                    'tool_material_profile' => $profile['tool_material_profile'],
                    'rotation' => 'daily',
                    'completion_cap' => 3,
                    'experience' => $experience,
                    'gold' => $gold,
                    'requirements' => $requirement === null ? [] : [$requirement],
                    'rewards' => [
                        ['type' => 'gold', 'label' => 'Gold', 'quantity' => $gold],
                        ['type' => 'experience', 'label' => str($skill)->headline()->toString().' XP', 'quantity' => $experience],
                    ],
                ];
            }
        }

        return $contracts;
    }

    /**
     * @param  array{objective_type: string, schedule: string}  $profile
     * @param  array<string, array<int, array{item_key: string, item_name: string}>>  $gatheringOutputs
     * @param  array<string, array<int, array{item_key: string, item_name: string}>>  $recipeOutputs
     * @return array{item_key: string, item_name: string, quantity: int}|null
     */
    private static function requirementFor(string $skill, array $profile, int $level, int $quantity, array $gatheringOutputs, array $recipeOutputs): ?array
    {
        $output = match ($profile['objective_type']) {
            'gather' => $gatheringOutputs[$skill][$level] ?? self::nearestOutput($skill, $level, $gatheringOutputs),
            'process', 'craft' => $recipeOutputs[$skill][$level] ?? self::nearestOutput($skill, $level, $recipeOutputs),
            default => self::supportSupplyFor($skill, $level, $recipeOutputs),
        };

        if ($output === null) {
            return null;
        }

        return [
            'item_key' => $output['item_key'],
            'item_name' => $output['item_name'],
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  array{objective_type: string, schedule: string}  $profile
     * @param  array{level: int, item_tier: int, mark: string}  $tier
     * @param  array{item_key: string, item_name: string, quantity: int}|null  $requirement
     * @return array<string, mixed>
     */
    private static function objectiveFor(string $skill, array $profile, array $tier, int $quantity, ?array $requirement, string $objectiveType, ?string $activityLabel): array
    {
        $objective = [
            'skill' => $skill,
            'type' => $objectiveType,
            'target_count' => $quantity,
            'tier_level' => (int) $tier['level'],
            'item_tier' => (int) $tier['item_tier'],
            'tier_mark' => $tier['mark'],
            'credit_rule' => 'Deliver the listed item from inventory.',
        ];

        if ($requirement !== null) {
            return [
                ...$objective,
                'item_key' => $requirement['item_key'],
                'item_name' => $requirement['item_name'],
            ];
        }

        return [
            ...$objective,
            'activity_label' => $activityLabel,
        ];
    }

    /**
     * @param  array{objective_type: string}  $profile
     */
    private static function runtimeObjectiveTypeFor(array $profile): string
    {
        return in_array($profile['objective_type'], ['gather', 'process', 'craft'], true)
            ? $profile['objective_type']
            : 'deliver';
    }

    /**
     * @param  array<string, array<int, array{item_key: string, item_name: string}>>  $recipeOutputs
     * @return array{item_key: string, item_name: string}|null
     */
    private static function supportSupplyFor(string $skill, int $level, array $recipeOutputs): ?array
    {
        $supplySkill = self::SUPPORT_SUPPLY_SKILLS[$skill] ?? null;

        if ($supplySkill === null) {
            return null;
        }

        return $recipeOutputs[$supplySkill][$level]
            ?? self::nearestRecipeOutput($supplySkill, $level, $recipeOutputs);
    }

    /**
     * @param  array<string, array<int, array{item_key: string, item_name: string}>>  $recipeOutputs
     * @return array{item_key: string, item_name: string}|null
     */
    private static function nearestRecipeOutput(string $skill, int $level, array $recipeOutputs): ?array
    {
        return self::nearestOutput($skill, $level, $recipeOutputs);
    }

    /**
     * @param  array<string, array<int, array{item_key: string, item_name: string}>>  $outputsBySkill
     * @return array{item_key: string, item_name: string}|null
     */
    private static function nearestOutput(string $skill, int $level, array $outputsBySkill): ?array
    {
        $outputs = $outputsBySkill[$skill] ?? [];

        if ($outputs === []) {
            return null;
        }

        ksort($outputs);
        $selected = reset($outputs);

        foreach ($outputs as $requiredLevel => $output) {
            if ($requiredLevel > $level) {
                break;
            }

            $selected = $output;
        }

        return $selected;
    }

    /**
     * @return array<string, array<int, array{item_key: string, item_name: string}>>
     */
    private static function gatheringOutputsBySkillAndLevel(): array
    {
        $outputs = [];

        foreach (GatheringActionService::baseActionDefinitions() as $action) {
            $skill = (string) $action['skill'];
            $level = (int) ($action['required_level'] ?? 1);
            $loot = $action['loot'][0] ?? null;

            if ($loot === null || isset($outputs[$skill][$level])) {
                continue;
            }

            $outputs[$skill][$level] = [
                'item_key' => (string) ($loot['item_key'] ?? $loot['key']),
                'item_name' => (string) ($loot['item_name'] ?? $loot['name']),
            ];
        }

        return $outputs;
    }

    /**
     * @return array<string, array<int, array{item_key: string, item_name: string}>>
     */
    private static function recipeOutputsBySkillAndLevel(): array
    {
        $outputs = [];

        foreach (CraftingService::baseRecipes() as $recipe) {
            $skill = (string) $recipe['skill'];
            $level = (int) ($recipe['required_level'] ?? 1);
            $output = collect($recipe['outputs'] ?? [])
                ->first(fn (array $candidate): bool => ! isset($candidate['equipment_skill']));

            if ($output === null || isset($outputs[$skill][$level])) {
                continue;
            }

            $outputs[$skill][$level] = [
                'item_key' => (string) $output['item_key'],
                'item_name' => (string) $output['item_name'],
            ];
        }

        return $outputs;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function activityLabelsBySkillAndLevel(): array
    {
        $labels = [];

        foreach (SkillActivityService::baseActivities() as $activity) {
            $labels[(string) $activity['skill']][(int) $activity['required_level']] = (string) $activity['label'];
        }

        return $labels;
    }

    private static function quantityFor(string $schedule, int $index): int
    {
        return match ($schedule) {
            'gather' => self::GATHER_QUANTITIES[$index],
            'process' => self::PROCESS_QUANTITIES[$index],
            'craft' => self::CRAFT_QUANTITIES[$index],
            'run' => self::MENU_RUN_QUANTITIES[$index],
            'civic' => self::CIVIC_QUANTITIES[$index],
            default => self::MENU_ACTION_QUANTITIES[$index],
        };
    }

    private static function goldFor(string $schedule, int $level, int $quantity): int
    {
        $base = match ($schedule) {
            'gather' => 18,
            'process' => 26,
            'craft' => 34,
            'run' => 48,
            'civic' => 42,
            default => 38,
        };

        return $base + ($level * 3) + ($quantity * 2);
    }

    private static function experienceFor(string $schedule, int $level, int $quantity): int
    {
        $base = match ($schedule) {
            'gather' => 30,
            'process' => 42,
            'craft' => 52,
            'run' => 78,
            'civic' => 66,
            default => 62,
        };

        return $base + ($level * 5) + ($quantity * 3);
    }
}
