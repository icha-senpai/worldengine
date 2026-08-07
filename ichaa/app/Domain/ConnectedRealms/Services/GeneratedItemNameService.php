<?php

namespace App\Domain\ConnectedRealms\Services;

class GeneratedItemNameService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const MIDGAME_GATHERING_RESOURCES = [
        'fishing' => [20 => 'Harbor Tuna', 30 => 'Blueclaw Crab', 40 => 'Glasswater Pike', 50 => 'Deep Ray'],
        'mining' => [20 => 'Bog Iron Ore', 30 => 'Hematite Ore', 40 => 'Fulgurite Flux', 50 => 'Deepvein Ore'],
        'woodcutting' => [20 => 'Coppice Log', 30 => 'Knotpine Branch', 40 => 'Thunderheart Timber', 50 => 'Amberheart Log'],
        'foraging' => [20 => 'Bitterleaf Herb', 30 => 'Redroot Bundle', 40 => 'Glasscap Bloom', 50 => 'High Meadow Orchid'],
        'hunting' => [20 => 'Brindle Hide', 30 => 'Bound Sinew Cord', 40 => 'Whitejaw Bone', 50 => 'Ridgeback Trophy'],
        'farming' => [20 => 'Barley Sheaf', 30 => 'Blue Flax', 40 => 'Rainbean Crop', 50 => 'Orchard Lanternfruit'],
        'excavation' => [20 => 'Kiln-Baked Pottery', 30 => 'Boundary Tablet', 40 => 'Gatehouse Fragment', 50 => 'Vault Tablet Plaque'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const MIDGAME_CRAFT_OUTPUTS = [
        'smelting' => [20 => 'Charcoal Iron Bloom', 30 => 'Hematite Bloom', 40 => 'Lightning-Fused Bloom', 50 => 'Deepvein Ingot'],
        'milling' => [20 => 'Pegged Pine Board', 30 => 'Knotpine Plank', 40 => 'Weather-Sealed Timber', 50 => 'Amberheart Beam'],
        'tanning' => [20 => 'Brindle Leather Panel', 30 => 'Boiled Sinew Backing', 40 => 'Whitejaw Scalehide', 50 => 'Ridgeback Harness Leather'],
        'cutting' => [20 => 'Clearcut Socket Gem', 30 => 'Hematite Lens', 40 => 'Fulgurite Crystal Prism', 50 => 'Deepvein Jewel Plate'],
        'weaving' => [20 => 'Linen Field Bolt', 30 => 'Blueflax Sailcloth', 40 => 'Rainthread Canvas', 50 => 'Lanternfruit Silk'],
        'smithing' => [20 => 'Gate Nail Blank', 30 => 'Hematite Weapon Blank', 40 => 'Storm-Forged Weapon Blank', 50 => 'Deepvein Guardplate'],
        'carpentry' => [20 => 'Pegged Tool Frame', 30 => 'Knotpine Crate', 40 => 'Weatherbow Stave', 50 => 'Amberheart Joinery'],
        'cooking' => [20 => 'Harbor Ration Pack', 30 => 'Blueflax Travel Cake', 40 => 'Rainbean Stew Kit', 50 => 'Lanternfruit Feast Box'],
        'alchemy' => [20 => 'Bitterleaf Tonic', 30 => 'Redroot Poultice', 40 => 'Glasscap Catalyst', 50 => 'Orchid Still Draught'],
        'tailoring' => [20 => 'Linen Field Pattern', 30 => 'Blueflax Satchel Kit', 40 => 'Rainthread Robe Panel', 50 => 'Lanternsilk Vestment Cut'],
        'leatherworking' => [20 => 'Brindle Strap Set', 30 => 'Sinew-Stitched Belt', 40 => 'Whitejaw Saddle Stock', 50 => 'Ridgeback Harness Set'],
        'engineering' => [20 => 'Brass Spring Assembly', 30 => 'Hematite Gearbox', 40 => 'Storm-Tuned Trigger', 50 => 'Deepvein Survey Device'],
        'enchanting' => [20 => 'Bitterleaf Ward Oil', 30 => 'Boundary Rune Wash', 40 => 'Glasscap Binding Ink', 50 => 'Vaultlight Oil Infusion'],
        'jewelcrafting' => [20 => 'Copper Setting Set', 30 => 'Hematite Ring Mount', 40 => 'Fulgurite Focus Lens', 50 => 'Deepvein Amulet Frame'],
        'boatbuilding' => [20 => 'Harbor Skiff Rib', 30 => 'Knotpine Cargo Keel', 40 => 'Weather-Sealed Hull Rib', 50 => 'Amberheart Expedition Hull'],
        'furniture' => [20 => 'Pegged Hall Stool', 30 => 'Knotpine Display Stand', 40 => 'Weathered Trophy Fitting', 50 => 'Amberheart Hall Fixture'],
        'construction' => [20 => 'Lime-Mortared Frame', 30 => 'Boundary Frame Brace', 40 => 'Fulgurite Watchtower Frame', 50 => 'Deepvein Frame Span'],
        'cartography' => [20 => 'Harbor Waymap', 30 => 'Boundary Crossing Map', 40 => 'Stormbreak Sea Chart', 50 => 'Lower Vault Atlas'],
        'trading' => [20 => 'Market Stall Charter', 30 => 'Crossroads Rate Sheet', 40 => 'Stormbreak Cargo Bond', 50 => 'Highroad Exchange Charter'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ENDGAME_CRAFT_OUTPUTS = [
        'smelting' => [65 => 'Star-Iron Bar', 80 => 'Gateglass Ingot', 100 => 'First Forge Steel'],
        'milling' => [65 => 'Elder Yew Beam', 80 => 'Gatewood Beam Spar', 100 => 'First Grove Timber'],
        'tanning' => [65 => 'Greatbeast Leather', 80 => 'Crownbeast Hideplate', 100 => 'First Hunt Harness Leather'],
        'cutting' => [65 => 'Starfacet Lens', 80 => 'Gateglass Gem Jewel', 100 => 'First Light Gem'],
        'weaving' => [65 => 'Starloom Cloth', 80 => 'Gate-Dyed Sailcloth', 100 => 'First Dawn Cloth Brocade'],
        'smithing' => [65 => 'Meteor Edge Blank', 80 => 'Gateforged Armament', 100 => 'First Anvil Warplate'],
        'carpentry' => [65 => 'Livingwood Frame', 80 => 'Gatewood Joinery', 100 => 'First Grove Joinery Masterwork'],
        'cooking' => [65 => 'Starfeast Platter', 80 => 'Gatehouse Feast Box', 100 => 'First Hearth Feast'],
        'alchemy' => [65 => 'Dreamroot Elixir', 80 => 'Gateglass Elixir Transmuter', 100 => 'First Still Elixir'],
        'tailoring' => [65 => 'Starthread Vestment', 80 => 'Gate-Dyed Regalia', 100 => 'First Needle Courtwear'],
        'leatherworking' => [65 => 'Greatbeast Harness', 80 => 'Crownbeast Saddle', 100 => 'First Hunt Kit'],
        'engineering' => [65 => 'Elder Gear Engine', 80 => 'Gatehouse Prototype', 100 => 'First Clockwork Engine'],
        'enchanting' => [65 => 'Awakened Rune Oil', 80 => 'Gate Script Oil Binding', 100 => 'First Ward Oil Infusion'],
        'jewelcrafting' => [65 => 'Starfacet Setting', 80 => 'Gateglass Crown Diadem', 100 => 'First Light Crown Circlet'],
        'boatbuilding' => [65 => 'Stormfleet Keel', 80 => 'Gatewater Hull', 100 => 'First Tide Flagship Hull'],
        'furniture' => [65 => 'Royal Suite Joinery', 80 => 'Gatehall Fixture Carving', 100 => 'First Hall Fixture Set'],
        'construction' => [65 => 'Citadel Wall Frame', 80 => 'Gatehouse Frame Foundation', 100 => 'First City Frame Span'],
        'cartography' => [65 => 'Navigator Archive Map', 80 => 'Hidden Gate Atlas', 100 => 'First Star Chart'],
        'trading' => [65 => 'Royal Exchange Ledger', 80 => 'Gate Market Warrant', 100 => 'First Concord Charter'],
    ];

    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private const ENDGAME_GATHERING_RESOURCES = [
        'fishing' => [
            'fish' => [65 => 'Kelp Trench Cod', 80 => 'Leviathan Wakefish', 100 => 'Thronewater Eel'],
            'scale' => [65 => 'Kelpbright Scale', 80 => 'Wake Leviathan Scale', 100 => 'Throneback Scale'],
            'pearl' => [65 => 'Greenwater Pearl', 80 => 'Blackwake Pearl', 100 => 'First Tide Pearl'],
        ],
        'mining' => [
            'ore' => [65 => 'Star-Iron Ore', 80 => 'Gateglass Ore', 100 => 'First Forge Ore'],
            'geode' => [65 => 'Deepvein Geode', 80 => 'Gatecracked Geode', 100 => 'First Light Geode'],
            'coal' => [65 => 'Bluefire Coal', 80 => 'Gatehouse Coal', 100 => 'First Hearth Coal'],
        ],
        'woodcutting' => [
            'log' => [65 => 'Elder Yew Log', 80 => 'Gatewood Log', 100 => 'First Grove Log'],
            'resin' => [65 => 'Oldgold Resin', 80 => 'Gate Amber', 100 => 'First Sap'],
            'branch' => [65 => 'Livingwood Branch', 80 => 'Gatewood Bough', 100 => 'First Grove Branch'],
        ],
        'foraging' => [
            'bloom' => [65 => 'Duskglass Bloom', 80 => 'Gatecap Flower', 100 => 'First Dawn Bloom'],
            'root' => [65 => 'Dreamroot', 80 => 'Gate-Twined Root', 100 => 'First Root'],
            'spore' => [65 => 'Bluecap Spore', 80 => 'Gatecap Spore', 100 => 'First Spore'],
        ],
        'hunting' => [
            'hide' => [65 => 'Greatbeast Hide', 80 => 'Crownbeast Hide', 100 => 'First Hunt Hide'],
            'claw' => [65 => 'Ridgebreaker Claw', 80 => 'Crownbeast Claw', 100 => 'First Hunt Claw'],
            'meat' => [65 => 'Greatbeast Haunch', 80 => 'Crownbeast Cut', 100 => 'First Hunt Roast'],
        ],
        'farming' => [
            'grain' => [65 => 'Terrace Grain', 80 => 'Gatefield Grain', 100 => 'First Harvest Grain'],
            'seed' => [65 => 'Glasshouse Seed', 80 => 'Gatefield Seed', 100 => 'First Seed'],
            'fruit' => [65 => 'Sunvault Fruit', 80 => 'Gate Orchard Fruit', 100 => 'First Orchard Fruit'],
        ],
        'excavation' => [
            'relic' => [65 => 'Reliquary Gearplate', 80 => 'Gatehouse Idol', 100 => 'First City Inscription'],
            'rune' => [65 => 'Old Ward Rune', 80 => 'Gate Script Rune', 100 => 'First Script Rune'],
            'tablet' => [65 => 'Lower Vault Tablet', 80 => 'Gate Archive Tablet', 100 => 'First Archive Tablet'],
        ],
    ];

    public static function midgameGatheringResourceName(string $skill, int $level): string
    {
        $effectiveLevel = self::canonicalLevelFor($level, 20, 50);

        return self::MIDGAME_GATHERING_RESOURCES[$skill][$effectiveLevel]
            ?? self::fallbackName($skill, $effectiveLevel, 'gathering resource');
    }

    public static function midgameCraftOutputName(string $skill, int $level): string
    {
        $effectiveLevel = self::canonicalLevelFor($level, 20, 50);

        return self::MIDGAME_CRAFT_OUTPUTS[$skill][$effectiveLevel]
            ?? self::fallbackName($skill, $effectiveLevel, 'crafted work');
    }

    public static function endgameCraftOutputName(string $skill, int $level): string
    {
        $effectiveLevel = self::canonicalLevelFor($level, 65, 100);

        return self::ENDGAME_CRAFT_OUTPUTS[$skill][$effectiveLevel]
            ?? self::fallbackName($skill, $effectiveLevel, 'masterwork');
    }

    public static function endgameGatheringResourceName(string $skill, string $resource, string $prefix): string
    {
        $level = self::levelForMark($prefix);

        return self::ENDGAME_GATHERING_RESOURCES[$skill][$resource][$level]
            ?? self::fallbackName($resource, $level, "{$skill} resource");
    }

    private static function levelForMark(string $mark): int
    {
        foreach (EvergatherTierCatalog::tiers() as $tier) {
            if (strcasecmp($tier['mark'], $mark) === 0 || strcasecmp($tier['key_slug'], $mark) === 0) {
                return (int) $tier['level'];
            }
        }

        return EvergatherTierCatalog::nextTierLevelFor(65);
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

    private static function fallbackName(string $subject, int $level, string $kind): string
    {
        return str("Custom {$subject} {$kind} {$level}")->headline()->toString();
    }
}
