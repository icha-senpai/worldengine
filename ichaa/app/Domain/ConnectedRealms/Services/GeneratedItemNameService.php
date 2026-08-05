<?php

namespace App\Domain\ConnectedRealms\Services;

class GeneratedItemNameService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const MIDGAME_GATHERING_RESOURCES = [
        'fishing' => [20 => 'Hearthsign Tuna', 30 => 'Runebound Crab', 40 => 'Stormglass Pike', 50 => 'Highguild Ray'],
        'mining' => [20 => 'Hearthsign Ore', 30 => 'Runebound Iron Ore', 40 => 'Stormglass Flux', 50 => 'Highguild Core'],
        'woodcutting' => [20 => 'Hearthsign Log', 30 => 'Runebound Branch', 40 => 'Stormglass Ironwood', 50 => 'Highguild Amberheart Log'],
        'foraging' => [20 => 'Hearthsign Herb', 30 => 'Runebound Bitterroot', 40 => 'Stormglass Bloom', 50 => 'Highguild Orchid'],
        'hunting' => [20 => 'Hearthsign Hide', 30 => 'Runebound Sinew', 40 => 'Stormglass Bone', 50 => 'Highguild Trophy'],
        'farming' => [20 => 'Hearthsign Grain', 30 => 'Runebound Flax', 40 => 'Stormglass Crop', 50 => 'Highguild Fruit'],
        'excavation' => [20 => 'Hearthsign Relic Shard', 30 => 'Runebound Tablet', 40 => 'Stormglass Gate Fragment', 50 => 'Highguild Vault Relic'],
    ];

    /**
     * @var array<string, string>
     */
    private const MIDGAME_CRAFT_OUTPUTS = [
        'smelting' => 'Alloy Bloom',
        'milling' => 'Worked Timber',
        'tanning' => 'Leather Panel',
        'cutting' => 'Faceted Gem',
        'weaving' => 'Cloth Bolt',
        'smithing' => 'Weapon Blank',
        'carpentry' => 'Joinery Frame',
        'cooking' => 'Provision Pack',
        'alchemy' => 'Field Draught',
        'tailoring' => 'Pattern Kit',
        'leatherworking' => 'Harness Set',
        'engineering' => 'Clockwork Assembly',
        'enchanting' => 'Ward Oil',
        'jewelcrafting' => 'Gem Setting',
        'boatbuilding' => 'Hull Rib',
        'furniture' => 'Hall Fixture',
        'construction' => 'Mason Frame',
        'cartography' => 'Waymap',
        'trading' => 'Trade Charter',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const MIDGAME_CRAFT_OUTPUT_LEVELS = [
        'cartography' => [
            20 => 'Hearthsign Waymap',
            30 => 'Runebound Crossing Map',
            40 => 'Stormglass Sea Chart',
            50 => 'Highguild Expedition Atlas',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const ENDGAME_CRAFT_OUTPUTS = [
        'smelting' => 'Ingot',
        'milling' => 'Beam',
        'tanning' => 'Leather',
        'cutting' => 'Facet',
        'weaving' => 'Bolt',
        'smithing' => 'Armament',
        'carpentry' => 'Frame',
        'cooking' => 'Feast',
        'alchemy' => 'Elixir',
        'tailoring' => 'Vestment',
        'leatherworking' => 'Harness',
        'engineering' => 'Engine',
        'enchanting' => 'Sigil',
        'jewelcrafting' => 'Crown',
        'boatbuilding' => 'Hull',
        'furniture' => 'Hall Set',
        'construction' => 'Citadel Frame',
        'cartography' => 'Atlas',
        'trading' => 'Charter',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const ENDGAME_GATHERING_RESOURCES = [
        'fishing' => ['fish' => 'Deepcurrent Fish', 'scale' => 'Leviathan Scale', 'pearl' => 'Storm Pearl'],
        'mining' => ['ore' => 'Mythgate Ore', 'geode' => 'Elderwake Geode', 'coal' => 'Crownmark Coal'],
        'woodcutting' => ['log' => 'Mythgate Log', 'resin' => 'Elderwake Resin', 'branch' => 'Crownmark Branch'],
        'foraging' => ['bloom' => 'Elderwake Bloom', 'root' => 'Runebound Root', 'spore' => 'Mythgate Spore'],
        'hunting' => ['hide' => 'Primal Hide', 'claw' => 'Apex Claw', 'meat' => 'Greatbeast Meat'],
        'farming' => ['grain' => 'Moonwake Grain', 'seed' => 'Crownmark Seed', 'fruit' => 'Crownmark Fruit'],
        'excavation' => ['relic' => 'Elderwake Relic', 'rune' => 'Crownmark Rune', 'tablet' => 'Mythgate Tablet'],
    ];

    public static function midgameGatheringResourceName(string $skill, int $level): string
    {
        $effectiveLevel = self::canonicalLevelFor($level, 20, 50);

        return self::MIDGAME_GATHERING_RESOURCES[$skill][$effectiveLevel]
            ?? self::prefixedName(self::midgamePrefix($effectiveLevel), str($skill)->headline()->toString().' Resource');
    }

    public static function midgameCraftOutputName(string $skill, int $level): string
    {
        $effectiveLevel = self::canonicalLevelFor($level, 20, 50);

        if (isset(self::MIDGAME_CRAFT_OUTPUT_LEVELS[$skill][$effectiveLevel])) {
            return self::MIDGAME_CRAFT_OUTPUT_LEVELS[$skill][$effectiveLevel];
        }

        return self::prefixedName(
            self::midgamePrefix($effectiveLevel),
            self::MIDGAME_CRAFT_OUTPUTS[$skill] ?? str($skill)->headline()->toString().' Commission',
        );
    }

    public static function endgameCraftOutputName(string $skill, int $level): string
    {
        return self::prefixedName(
            self::endgamePrefix($level),
            self::ENDGAME_CRAFT_OUTPUTS[$skill] ?? str($skill)->headline()->toString().' Commission',
        );
    }

    public static function endgameGatheringResourceName(string $skill, string $resource, string $prefix): string
    {
        return self::prefixedName(
            $prefix,
            self::ENDGAME_GATHERING_RESOURCES[$skill][$resource] ?? str($resource)->headline()->toString(),
        );
    }

    private static function midgamePrefix(int $level): string
    {
        return EvergatherTierCatalog::markForLevel($level);
    }

    private static function endgamePrefix(int $level): string
    {
        return EvergatherTierCatalog::markForLevel($level);
    }

    private static function canonicalLevelFor(int $level, int $minimumLevel, int $maximumLevel): int
    {
        foreach (EvergatherTierCatalog::tiersBetween($minimumLevel, $maximumLevel) as $tier) {
            if ($tier['level'] >= $level) {
                return $tier['level'];
            }
        }

        return $maximumLevel;
    }

    private static function prefixedName(string $prefix, string $name): string
    {
        $prefixRoot = str($prefix)->lower()->limit(4, '')->toString();
        $normalizedName = str($name)->lower()->toString();

        if (str_starts_with($normalizedName, str($prefix)->lower()->toString())
            || ($prefixRoot !== '' && str_starts_with($normalizedName, $prefixRoot))) {
            return $name;
        }

        return "{$prefix} {$name}";
    }
}
