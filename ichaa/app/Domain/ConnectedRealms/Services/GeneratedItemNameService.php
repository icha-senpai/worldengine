<?php

namespace App\Domain\ConnectedRealms\Services;

class GeneratedItemNameService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const MIDGAME_GATHERING_RESOURCES = [
        'fishing' => [20 => 'Lantern Tuna', 25 => 'Sable Crab', 30 => 'Moonwake Cod', 35 => 'Stormfin Pike', 40 => 'Glassfin Ray'],
        'mining' => [20 => 'Silver Ore', 25 => 'Sable Cobalt Veinstone', 30 => 'Runevein Iron Ore', 35 => 'Basalt Core', 40 => 'Crystalized Flux'],
        'woodcutting' => [20 => 'Silverbough Log', 25 => 'Cedarheart Log', 30 => 'Runevein Branch', 35 => 'Ironwood Log', 40 => 'Amberheart Log'],
        'foraging' => [20 => 'Silverleaf Herb', 25 => 'Sablecap Mushroom', 30 => 'Runebound Bitterroot', 35 => 'Moonspike Herb', 40 => 'Stormbloom'],
        'hunting' => [20 => 'Stag Hide', 25 => 'Ridgeback Meat', 30 => 'Runebound Sinew', 35 => 'Direwolf Hide', 40 => 'Stormclaw Bone'],
        'farming' => [20 => 'Silvergrain', 25 => 'Sable Bean', 30 => 'Runeweft Flax', 35 => 'Moonroot Crop', 40 => 'Stormfruit'],
        'excavation' => [20 => 'Silver Relic Shard', 25 => 'Sable Pottery', 30 => 'Rune-Etched Tablet', 35 => 'Moon Gate Fragment', 40 => 'Storm Vault Relic'],
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
            20 => 'Silverbank Waymap',
            25 => 'Sablecross Road Atlas',
            30 => 'Runebound Crossing Map',
            35 => 'Moonwake Path Atlas',
            40 => 'Stormglass Sea Chart',
            45 => 'Starline Expedition Atlas',
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
        'mining' => ['ore' => 'Mythrite Ore', 'geode' => 'Astral Geode', 'coal' => 'Worldcore Coal'],
        'woodcutting' => ['log' => 'Mythwood Log', 'resin' => 'Astral Resin', 'branch' => 'Worldtree Branch'],
        'foraging' => ['bloom' => 'Astral Bloom', 'root' => 'Dreamroot', 'spore' => 'Prismatic Spore'],
        'hunting' => ['hide' => 'Primal Hide', 'claw' => 'Apex Claw', 'meat' => 'Greatbeast Meat'],
        'farming' => ['grain' => 'Moon Grain', 'seed' => 'Worldseed', 'fruit' => 'Everdawn Fruit'],
        'excavation' => ['relic' => 'Elder Relic', 'rune' => 'First Realm Rune', 'tablet' => 'Prismatic Tablet'],
    ];

    public static function midgameGatheringResourceName(string $skill, int $level): string
    {
        $effectiveLevel = max(20, min(40, $level));

        return self::MIDGAME_GATHERING_RESOURCES[$skill][$effectiveLevel]
            ?? self::prefixedName(self::midgamePrefix($effectiveLevel), str($skill)->headline()->toString().' Resource');
    }

    public static function midgameCraftOutputName(string $skill, int $level): string
    {
        $effectiveLevel = max(20, min(45, $level));

        if (isset(self::MIDGAME_CRAFT_OUTPUT_LEVELS[$skill][$effectiveLevel])) {
            return self::MIDGAME_CRAFT_OUTPUT_LEVELS[$skill][$effectiveLevel];
        }

        return self::prefixedName(
            self::midgamePrefix($level),
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
        return match (true) {
            $level >= 45 => 'Starline',
            $level >= 40 => 'Stormglass',
            $level >= 35 => 'Moonwake',
            $level >= 30 => 'Runebound',
            $level >= 25 => 'Sablecross',
            default => 'Silverbank',
        };
    }

    private static function endgamePrefix(int $level): string
    {
        return match (true) {
            $level >= 100 => 'Crownmark',
            $level >= 95 => 'Prismatic',
            $level >= 85 => 'Astral',
            $level >= 75 => 'Mythgate',
            $level >= 65 => 'Elderwake',
            default => 'Runebound',
        };
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
