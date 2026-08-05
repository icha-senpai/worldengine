<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GatheringActionService
{
    public function __construct(private ConnectedRealmsPlayerService $players, private WorldEventService $events, private ItemCatalogService $items, private ToolEffectService $toolEffects) {}

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $actionDefinitionCache = null;

    /**
     * @var array<string, array{
     *     label: string,
     *     skill: string,
     *     location: string,
     *     required_level?: int,
     *     cooldown_seconds: int,
     *     experience: array{min: int, max: int},
     *     gold: array{min: int, max: int},
     *     loot: list<array{key: string, name: string, rarity: string, min: int, max: int}>
     * }>
     */
    private const ACTIONS = [
        'fish' => [
            'label' => 'Fishing',
            'skill' => 'fishing',
            'location' => 'Moonwake Pier',
            'cooldown_seconds' => 75,
            'experience' => ['min' => 18, 'max' => 30],
            'gold' => ['min' => 2, 'max' => 6],
            'loot' => [
                ['key' => 'river_minnow', 'name' => 'River Minnow', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'silver_scale', 'name' => 'Silver Scale', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
                ['key' => 'brine_shrimp', 'name' => 'Brine Shrimp', 'rarity' => 'common', 'min' => 1, 'max' => 3],
            ],
        ],
        'mine' => [
            'label' => 'Mining',
            'skill' => 'mining',
            'location' => 'Emberdeep Quarry',
            'cooldown_seconds' => 90,
            'experience' => ['min' => 20, 'max' => 34],
            'gold' => ['min' => 1, 'max' => 5],
            'loot' => [
                ['key' => 'iron_ore', 'name' => 'Iron Ore', 'rarity' => 'common', 'min' => 2, 'max' => 6],
                ['key' => 'coal_chunk', 'name' => 'Coal Chunk', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'rough_gem', 'name' => 'Rough Gem', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'chop' => [
            'label' => 'Woodcutting',
            'skill' => 'woodcutting',
            'location' => 'Whisperbough Stand',
            'cooldown_seconds' => 80,
            'experience' => ['min' => 16, 'max' => 28],
            'gold' => ['min' => 1, 'max' => 4],
            'loot' => [
                ['key' => 'ashwood_log', 'name' => 'Ashwood Log', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'whisperbark', 'name' => 'Whisperbark', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'amber_sap', 'name' => 'Amber Sap', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'forage' => [
            'label' => 'Foraging',
            'skill' => 'foraging',
            'location' => 'Glimmerfen Trail',
            'cooldown_seconds' => 65,
            'experience' => ['min' => 14, 'max' => 24],
            'gold' => ['min' => 1, 'max' => 4],
            'loot' => [
                ['key' => 'mooncap_mushroom', 'name' => 'Mooncap Mushroom', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'bitterroot', 'name' => 'Bitterroot', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'sunspike_herb', 'name' => 'Sunspike Herb', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'hunt' => [
            'label' => 'Hunting',
            'skill' => 'hunting',
            'location' => 'Briarwake Run',
            'cooldown_seconds' => 95,
            'experience' => ['min' => 20, 'max' => 34],
            'gold' => ['min' => 2, 'max' => 5],
            'loot' => [
                ['key' => 'lean_game_meat', 'name' => 'Lean Game Meat', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'soft_hide', 'name' => 'Soft Hide', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'braided_sinew', 'name' => 'Braided Sinew', 'rarity' => 'common', 'min' => 1, 'max' => 2],
                ['key' => 'marked_trophy_bone', 'name' => 'Marked Trophy Bone', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'farm' => [
            'label' => 'Farming',
            'skill' => 'farming',
            'location' => 'Sunfield Plots',
            'cooldown_seconds' => 100,
            'experience' => ['min' => 18, 'max' => 32],
            'gold' => ['min' => 1, 'max' => 4],
            'loot' => [
                ['key' => 'sunfield_grain', 'name' => 'Sunfield Grain', 'rarity' => 'common', 'min' => 3, 'max' => 6],
                ['key' => 'wild_fiber', 'name' => 'Wild Fiber', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'flax_bundle', 'name' => 'Flax Bundle', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'glowseed', 'name' => 'Glowseed', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'dig' => [
            'label' => 'Excavation',
            'skill' => 'excavation',
            'location' => 'Old Gate Ruins',
            'cooldown_seconds' => 105,
            'experience' => ['min' => 22, 'max' => 36],
            'gold' => ['min' => 1, 'max' => 5],
            'loot' => [
                ['key' => 'relic_fragment', 'name' => 'Relic Fragment', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'ancient_bone', 'name' => 'Ancient Bone', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'pottery_shard', 'name' => 'Pottery Shard', 'rarity' => 'common', 'min' => 1, 'max' => 2],
                ['key' => 'sealed_rune_chip', 'name' => 'Sealed Rune Chip', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private const EARLY_ACTIONS = [
        'tidal_pools' => [
            'label' => 'Tidal Pools',
            'skill' => 'fishing',
            'location' => 'Moonwake Tide Pools',
            'required_level' => 3,
            'cooldown_seconds' => 70,
            'experience' => ['min' => 24, 'max' => 38],
            'gold' => ['min' => 2, 'max' => 7],
            'loot' => [
                ['key' => 'tide_snail', 'name' => 'Tide Snail', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'kelp_frond', 'name' => 'Kelp Frond', 'rarity' => 'common', 'min' => 1, 'max' => 4],
                ['key' => 'salt_shell', 'name' => 'Salt Shell', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'creek_traps' => [
            'label' => 'Creek Traps',
            'skill' => 'fishing',
            'location' => 'Glassrun Creek',
            'required_level' => 7,
            'cooldown_seconds' => 82,
            'experience' => ['min' => 30, 'max' => 46],
            'gold' => ['min' => 3, 'max' => 8],
            'loot' => [
                ['key' => 'creek_crayfish', 'name' => 'Creek Crayfish', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'reed_stem', 'name' => 'Reed Stem', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'glass_perch', 'name' => 'Glass Perch', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'shoal_cast' => [
            'label' => 'Shoal Cast',
            'skill' => 'fishing',
            'location' => 'Lantern Shoals',
            'required_level' => 11,
            'cooldown_seconds' => 94,
            'experience' => ['min' => 36, 'max' => 54],
            'gold' => ['min' => 4, 'max' => 9],
            'loot' => [
                ['key' => 'shoal_herring', 'name' => 'Shoal Herring', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'bright_scale', 'name' => 'Bright Scale', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'drift_pearl', 'name' => 'Drift Pearl', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'surface_coal' => [
            'label' => 'Surface Coal',
            'skill' => 'mining',
            'location' => 'Blackcap Slope',
            'required_level' => 3,
            'cooldown_seconds' => 84,
            'experience' => ['min' => 26, 'max' => 42],
            'gold' => ['min' => 2, 'max' => 7],
            'loot' => [
                ['key' => 'coal_chunk', 'name' => 'Coal Chunk', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'copper_ore', 'name' => 'Copper Ore', 'rarity' => 'common', 'min' => 1, 'max' => 4],
                ['key' => 'chalkstone', 'name' => 'Chalkstone', 'rarity' => 'common', 'min' => 1, 'max' => 2],
            ],
        ],
        'clay_pit' => [
            'label' => 'Clay Pit',
            'skill' => 'mining',
            'location' => 'Redbank Cut',
            'required_level' => 7,
            'cooldown_seconds' => 96,
            'experience' => ['min' => 34, 'max' => 50],
            'gold' => ['min' => 3, 'max' => 8],
            'loot' => [
                ['key' => 'clay_lump', 'name' => 'Clay Lump', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'flint_chip', 'name' => 'Flint Chip', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'rough_gem', 'name' => 'Rough Gem', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'quarry_survey' => [
            'label' => 'Quarry Survey',
            'skill' => 'mining',
            'location' => 'Emberdeep Bench',
            'required_level' => 11,
            'cooldown_seconds' => 108,
            'experience' => ['min' => 40, 'max' => 58],
            'gold' => ['min' => 4, 'max' => 10],
            'loot' => [
                ['key' => 'survey_marker', 'name' => 'Survey Marker', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'iron_ore', 'name' => 'Iron Ore', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'quartz_shard', 'name' => 'Quartz Shard', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'fallen_branches' => [
            'label' => 'Fallen Branches',
            'skill' => 'woodcutting',
            'location' => 'Whisperbough Edge',
            'required_level' => 3,
            'cooldown_seconds' => 74,
            'experience' => ['min' => 22, 'max' => 36],
            'gold' => ['min' => 2, 'max' => 6],
            'loot' => [
                ['key' => 'branch_bundle', 'name' => 'Branch Bundle', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'pinecone', 'name' => 'Pinecone', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'ashwood_log', 'name' => 'Ashwood Log', 'rarity' => 'common', 'min' => 1, 'max' => 3],
            ],
        ],
        'bark_strip' => [
            'label' => 'Bark Strip',
            'skill' => 'woodcutting',
            'location' => 'Old Trailfall',
            'required_level' => 7,
            'cooldown_seconds' => 88,
            'experience' => ['min' => 30, 'max' => 46],
            'gold' => ['min' => 3, 'max' => 8],
            'loot' => [
                ['key' => 'whisperbark', 'name' => 'Whisperbark', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'sapwood_stick', 'name' => 'Sapwood Stick', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'amber_sap', 'name' => 'Amber Sap', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'sap_tap' => [
            'label' => 'Sap Tap',
            'skill' => 'woodcutting',
            'location' => 'Amberroot Tapline',
            'required_level' => 11,
            'cooldown_seconds' => 102,
            'experience' => ['min' => 36, 'max' => 54],
            'gold' => ['min' => 4, 'max' => 9],
            'loot' => [
                ['key' => 'amber_sap', 'name' => 'Amber Sap', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'resin_drop', 'name' => 'Resin Drop', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'ashwood_log', 'name' => 'Ashwood Log', 'rarity' => 'common', 'min' => 1, 'max' => 3],
            ],
        ],
        'wild_seed_bed' => [
            'label' => 'Wild Seed Bed',
            'skill' => 'foraging',
            'location' => 'Glimmerfen Verge',
            'required_level' => 3,
            'cooldown_seconds' => 62,
            'experience' => ['min' => 20, 'max' => 34],
            'gold' => ['min' => 1, 'max' => 6],
            'loot' => [
                ['key' => 'common_seed', 'name' => 'Sunfield Seed Mix', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'nettle_leaf', 'name' => 'Nettle Leaf', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'bitterroot', 'name' => 'Bitterroot', 'rarity' => 'common', 'min' => 1, 'max' => 2],
            ],
        ],
        'mushroom_ring' => [
            'label' => 'Mushroom Ring',
            'skill' => 'foraging',
            'location' => 'Dewcap Hollow',
            'required_level' => 7,
            'cooldown_seconds' => 78,
            'experience' => ['min' => 28, 'max' => 44],
            'gold' => ['min' => 2, 'max' => 7],
            'loot' => [
                ['key' => 'mooncap_mushroom', 'name' => 'Mooncap Mushroom', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'dewcap_mushroom', 'name' => 'Dewcap Mushroom', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'brightcap_spore', 'name' => 'Brightcap Spore', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'root_patch' => [
            'label' => 'Root Patch',
            'skill' => 'foraging',
            'location' => 'Marrowfen Patch',
            'required_level' => 11,
            'cooldown_seconds' => 90,
            'experience' => ['min' => 34, 'max' => 52],
            'gold' => ['min' => 3, 'max' => 8],
            'loot' => [
                ['key' => 'marrowroot', 'name' => 'Marrowroot', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'sunspike_herb', 'name' => 'Sunspike Herb', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'reed_stem', 'name' => 'Reed Stem', 'rarity' => 'common', 'min' => 1, 'max' => 3],
            ],
        ],
        'snare_line' => [
            'label' => 'Snare Line',
            'skill' => 'hunting',
            'location' => 'Briarwake Verge',
            'required_level' => 3,
            'cooldown_seconds' => 88,
            'experience' => ['min' => 28, 'max' => 44],
            'gold' => ['min' => 2, 'max' => 7],
            'loot' => [
                ['key' => 'small_game_meat', 'name' => 'Small Game Meat', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'soft_hide', 'name' => 'Soft Hide', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'feather_bundle', 'name' => 'Feather Bundle', 'rarity' => 'common', 'min' => 1, 'max' => 2],
            ],
        ],
        'burrow_watch' => [
            'label' => 'Burrow Watch',
            'skill' => 'hunting',
            'location' => 'Redgrass Burrows',
            'required_level' => 7,
            'cooldown_seconds' => 104,
            'experience' => ['min' => 36, 'max' => 54],
            'gold' => ['min' => 3, 'max' => 9],
            'loot' => [
                ['key' => 'burrow_egg', 'name' => 'Burrow Egg', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'braided_sinew', 'name' => 'Braided Sinew', 'rarity' => 'common', 'min' => 1, 'max' => 2],
                ['key' => 'ancient_bone', 'name' => 'Ancient Bone', 'rarity' => 'common', 'min' => 0, 'max' => 1],
            ],
        ],
        'trail_tracking' => [
            'label' => 'Trail Tracking',
            'skill' => 'hunting',
            'location' => 'Briarwake Ridge',
            'required_level' => 11,
            'cooldown_seconds' => 116,
            'experience' => ['min' => 42, 'max' => 60],
            'gold' => ['min' => 4, 'max' => 11],
            'loot' => [
                ['key' => 'lean_game_meat', 'name' => 'Lean Game Meat', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'marked_trophy_bone', 'name' => 'Marked Trophy Bone', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
                ['key' => 'sharp_fang', 'name' => 'Sharp Fang', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'seed_sorting' => [
            'label' => 'Seed Sorting',
            'skill' => 'farming',
            'location' => 'Sunfield Shed',
            'required_level' => 3,
            'cooldown_seconds' => 86,
            'experience' => ['min' => 24, 'max' => 40],
            'gold' => ['min' => 1, 'max' => 6],
            'loot' => [
                ['key' => 'common_seed', 'name' => 'Sunfield Seed Mix', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'sunfield_grain', 'name' => 'Sunfield Grain', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'wild_fiber', 'name' => 'Wild Fiber', 'rarity' => 'common', 'min' => 1, 'max' => 3],
            ],
        ],
        'bean_rows' => [
            'label' => 'Bean Rows',
            'skill' => 'farming',
            'location' => 'East Sunfield',
            'required_level' => 7,
            'cooldown_seconds' => 100,
            'experience' => ['min' => 32, 'max' => 48],
            'gold' => ['min' => 2, 'max' => 8],
            'loot' => [
                ['key' => 'field_bean', 'name' => 'Field Bean', 'rarity' => 'common', 'min' => 2, 'max' => 5],
                ['key' => 'flax_bundle', 'name' => 'Flax Bundle', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'pressed_oil', 'name' => 'Pressed Oil', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'herb_bed' => [
            'label' => 'Herb Bed',
            'skill' => 'farming',
            'location' => 'Mooncap Furrows',
            'required_level' => 11,
            'cooldown_seconds' => 112,
            'experience' => ['min' => 38, 'max' => 56],
            'gold' => ['min' => 3, 'max' => 9],
            'loot' => [
                ['key' => 'bitterroot', 'name' => 'Bitterroot', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'mooncap_mushroom', 'name' => 'Mooncap Mushroom', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'glowseed', 'name' => 'Glowseed', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'clay_sifting' => [
            'label' => 'Clay Sifting',
            'skill' => 'excavation',
            'location' => 'Old Gate Wash',
            'required_level' => 3,
            'cooldown_seconds' => 92,
            'experience' => ['min' => 30, 'max' => 46],
            'gold' => ['min' => 2, 'max' => 7],
            'loot' => [
                ['key' => 'pottery_shard', 'name' => 'Pottery Shard', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'clay_lump', 'name' => 'Clay Lump', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'relic_fragment', 'name' => 'Relic Fragment', 'rarity' => 'common', 'min' => 1, 'max' => 2],
            ],
        ],
        'bone_bed' => [
            'label' => 'Bone Bed',
            'skill' => 'excavation',
            'location' => 'Mossgrave Shelf',
            'required_level' => 7,
            'cooldown_seconds' => 110,
            'experience' => ['min' => 38, 'max' => 56],
            'gold' => ['min' => 3, 'max' => 9],
            'loot' => [
                ['key' => 'ancient_bone', 'name' => 'Ancient Bone', 'rarity' => 'common', 'min' => 2, 'max' => 4],
                ['key' => 'chalkstone', 'name' => 'Chalkstone', 'rarity' => 'common', 'min' => 1, 'max' => 3],
                ['key' => 'amber_bead', 'name' => 'Amber Bead', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
        'marker_survey' => [
            'label' => 'Marker Survey',
            'skill' => 'excavation',
            'location' => 'Old Gate Transect',
            'required_level' => 11,
            'cooldown_seconds' => 124,
            'experience' => ['min' => 44, 'max' => 64],
            'gold' => ['min' => 4, 'max' => 11],
            'loot' => [
                ['key' => 'survey_marker', 'name' => 'Survey Marker', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'sealed_rune_chip', 'name' => 'Sealed Rune Chip', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
                ['key' => 'clockwork_spring', 'name' => 'Clockwork Spring', 'rarity' => 'uncommon', 'min' => 0, 'max' => 1],
            ],
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private const ADVANCED_ACTIONS = [
        'reef_net' => [
            'label' => 'Reef Net',
            'skill' => 'fishing',
            'location' => 'Pearlglass Reef',
            'required_level' => 15,
            'cooldown_seconds' => 115,
            'experience' => ['min' => 42, 'max' => 64],
            'gold' => ['min' => 5, 'max' => 11],
            'loot' => [
                ['key' => 'reef_eel', 'name' => 'Reef Eel', 'rarity' => 'uncommon', 'min' => 2, 'max' => 4],
                ['key' => 'pearl_cluster', 'name' => 'Pearl Cluster', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
                ['key' => 'tideglass_shard', 'name' => 'Tideglass Shard', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'abyssal_line' => [
            'label' => 'Abyssal Line',
            'skill' => 'fishing',
            'location' => 'Leviathan Drop',
            'required_level' => 45,
            'cooldown_seconds' => 180,
            'experience' => ['min' => 82, 'max' => 126],
            'gold' => ['min' => 10, 'max' => 22],
            'loot' => [
                ['key' => 'abyssal_ink', 'name' => 'Abyssal Ink', 'rarity' => 'rare', 'min' => 1, 'max' => 2],
                ['key' => 'leviathan_scale', 'name' => 'Leviathan Scale', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
            ],
        ],
        'crystal_vein' => [
            'label' => 'Crystal Vein',
            'skill' => 'mining',
            'location' => 'Azure Faultline',
            'required_level' => 15,
            'cooldown_seconds' => 125,
            'experience' => ['min' => 46, 'max' => 70],
            'gold' => ['min' => 5, 'max' => 12],
            'loot' => [
                ['key' => 'azure_crystal', 'name' => 'Azure Crystal', 'rarity' => 'uncommon', 'min' => 1, 'max' => 3],
                ['key' => 'cobalt_ore', 'name' => 'Cobalt Ore', 'rarity' => 'uncommon', 'min' => 2, 'max' => 4],
                ['key' => 'prism_geode', 'name' => 'Prism Geode', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'starfall_lode' => [
            'label' => 'Starfall Lode',
            'skill' => 'mining',
            'location' => 'Meteor Scar',
            'required_level' => 45,
            'cooldown_seconds' => 190,
            'experience' => ['min' => 88, 'max' => 134],
            'gold' => ['min' => 12, 'max' => 25],
            'loot' => [
                ['key' => 'highguild_ore', 'name' => 'Highguild Ore', 'rarity' => 'epic', 'min' => 1, 'max' => 2],
                ['key' => 'void_coal', 'name' => 'Void Coal', 'rarity' => 'rare', 'min' => 1, 'max' => 3],
            ],
        ],
        'resinwood_grove' => [
            'label' => 'Resinwood Grove',
            'skill' => 'woodcutting',
            'location' => 'Amberroot Slope',
            'required_level' => 15,
            'cooldown_seconds' => 120,
            'experience' => ['min' => 40, 'max' => 62],
            'gold' => ['min' => 4, 'max' => 10],
            'loot' => [
                ['key' => 'resinwood_log', 'name' => 'Resinwood Log', 'rarity' => 'uncommon', 'min' => 2, 'max' => 4],
                ['key' => 'amber_resin', 'name' => 'Amber Resin', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
                ['key' => 'dryad_seed', 'name' => 'Dryad Seed', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'heartwood_canopy' => [
            'label' => 'Heartwood Canopy',
            'skill' => 'woodcutting',
            'location' => 'Oldgreen Crown',
            'required_level' => 45,
            'cooldown_seconds' => 178,
            'experience' => ['min' => 80, 'max' => 122],
            'gold' => ['min' => 10, 'max' => 20],
            'loot' => [
                ['key' => 'heartwood_log', 'name' => 'Heartwood Log', 'rarity' => 'rare', 'min' => 1, 'max' => 3],
                ['key' => 'living_branch', 'name' => 'Living Branch', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
            ],
        ],
        'rare_herb_run' => [
            'label' => 'Glassmire Herb Survey',
            'skill' => 'foraging',
            'location' => 'Glassmire Basin',
            'required_level' => 15,
            'cooldown_seconds' => 110,
            'experience' => ['min' => 38, 'max' => 58],
            'gold' => ['min' => 4, 'max' => 10],
            'loot' => [
                ['key' => 'silk_moss', 'name' => 'Silk Moss', 'rarity' => 'uncommon', 'min' => 2, 'max' => 4],
                ['key' => 'brightcap_spore', 'name' => 'Brightcap Spore', 'rarity' => 'uncommon', 'min' => 1, 'max' => 3],
                ['key' => 'lunar_bloom', 'name' => 'Lunar Bloom', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'spirit_orchid_walk' => [
            'label' => 'Spirit Orchid Walk',
            'skill' => 'foraging',
            'location' => 'Moonlit Hollow',
            'required_level' => 45,
            'cooldown_seconds' => 170,
            'experience' => ['min' => 76, 'max' => 116],
            'gold' => ['min' => 9, 'max' => 18],
            'loot' => [
                ['key' => 'spirit_orchid', 'name' => 'Spirit Orchid', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
                ['key' => 'dreamleaf', 'name' => 'Dreamleaf', 'rarity' => 'rare', 'min' => 1, 'max' => 2],
            ],
        ],
        'monster_track' => [
            'label' => 'Monster Track',
            'skill' => 'hunting',
            'location' => 'Redfang Break',
            'required_level' => 15,
            'cooldown_seconds' => 135,
            'experience' => ['min' => 48, 'max' => 72],
            'gold' => ['min' => 6, 'max' => 14],
            'loot' => [
                ['key' => 'sharp_fang', 'name' => 'Sharp Fang', 'rarity' => 'uncommon', 'min' => 1, 'max' => 3],
                ['key' => 'monster_hide', 'name' => 'Monster Hide', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
                ['key' => 'battle_sinew', 'name' => 'Battle Sinew', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
            ],
        ],
        'apex_pursuit' => [
            'label' => 'Apex Pursuit',
            'skill' => 'hunting',
            'location' => 'Crownbeast Range',
            'required_level' => 45,
            'cooldown_seconds' => 195,
            'experience' => ['min' => 92, 'max' => 140],
            'gold' => ['min' => 14, 'max' => 30],
            'loot' => [
                ['key' => 'apex_claw', 'name' => 'Apex Claw', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
                ['key' => 'primal_hide', 'name' => 'Primal Hide', 'rarity' => 'rare', 'min' => 1, 'max' => 2],
            ],
        ],
        'greenhouse_cycle' => [
            'label' => 'Greenhouse Cycle',
            'skill' => 'farming',
            'location' => 'Verdant Glasshouse',
            'required_level' => 15,
            'cooldown_seconds' => 130,
            'experience' => ['min' => 44, 'max' => 66],
            'gold' => ['min' => 4, 'max' => 11],
            'loot' => [
                ['key' => 'dusk_wheat', 'name' => 'Dusk Wheat', 'rarity' => 'uncommon', 'min' => 2, 'max' => 5],
                ['key' => 'pressed_oil', 'name' => 'Pressed Oil', 'rarity' => 'uncommon', 'min' => 1, 'max' => 3],
                ['key' => 'sunheart_seed', 'name' => 'Sunheart Seed', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
            ],
        ],
        'spirit_fruit_harvest' => [
            'label' => 'Spirit Fruit Harvest',
            'skill' => 'farming',
            'location' => 'Seasonal Orchard',
            'required_level' => 45,
            'cooldown_seconds' => 185,
            'experience' => ['min' => 84, 'max' => 128],
            'gold' => ['min' => 10, 'max' => 22],
            'loot' => [
                ['key' => 'spirit_fruit', 'name' => 'Spirit Fruit', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
                ['key' => 'golden_grain', 'name' => 'Golden Grain', 'rarity' => 'rare', 'min' => 1, 'max' => 3],
            ],
        ],
        'relic_grid' => [
            'label' => 'Relic Grid',
            'skill' => 'excavation',
            'location' => 'Sunken Archive',
            'required_level' => 15,
            'cooldown_seconds' => 140,
            'experience' => ['min' => 50, 'max' => 76],
            'gold' => ['min' => 5, 'max' => 13],
            'loot' => [
                ['key' => 'clockwork_spring', 'name' => 'Clockwork Spring', 'rarity' => 'uncommon', 'min' => 1, 'max' => 3],
                ['key' => 'ancient_tablet', 'name' => 'Ancient Tablet', 'rarity' => 'rare', 'min' => 0, 'max' => 1],
                ['key' => 'survey_marker', 'name' => 'Survey Marker', 'rarity' => 'uncommon', 'min' => 1, 'max' => 2],
            ],
        ],
        'gate_sanctum' => [
            'label' => 'Gate Sanctum',
            'skill' => 'excavation',
            'location' => 'Buried Gate Core',
            'required_level' => 45,
            'cooldown_seconds' => 205,
            'experience' => ['min' => 96, 'max' => 146],
            'gold' => ['min' => 12, 'max' => 28],
            'loot' => [
                ['key' => 'gate_core', 'name' => 'Gate Core', 'rarity' => 'epic', 'min' => 0, 'max' => 1],
                ['key' => 'rune_slate', 'name' => 'Rune Slate', 'rarity' => 'rare', 'min' => 1, 'max' => 2],
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function actionKeys(): array
    {
        return array_keys(self::actionDefinitions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function availableActionsFor(ConnectedRealmsPlayer $player): array
    {
        return collect(self::actionDefinitions())
            ->map(function (array $action, string $key) use ($player): array {
                $requiredLevel = (int) ($action['required_level'] ?? 1);
                $skillLevel = $this->players->currentSkillLevel($player, $action['skill']);

                return [
                    'key' => $key,
                    'label' => $action['label'],
                    'skill' => $action['skill'],
                    'skill_label' => str($action['skill'])->headline()->toString(),
                    'location' => $action['location'],
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
                    'is_unlocked' => $skillLevel >= $requiredLevel,
                    'cooldown_seconds' => $this->cooldownSecondsFor($action),
                    'loot_preview' => collect($action['loot'])
                        ->map(fn (array $item): array => $this->items->enrich([
                            'item_key' => $item['key'],
                            'item_name' => $item['name'],
                            'rarity' => $item['rarity'],
                            'min_quantity' => $item['min'],
                            'max_quantity' => $item['max'],
                        ]))
                        ->all(),
                    'active_event' => $this->events->gatheringBonusForSkill($action['skill']),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function perform(User $user, string $actionKey, string $platform = 'website'): array
    {
        $definition = self::actionDefinitions()[$actionKey] ?? null;

        if ($definition === null) {
            throw ValidationException::withMessages([
                'action' => 'That Evergather action is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $actionKey, $platform, $definition): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($player->next_action_at !== null && $player->next_action_at->isFuture()) {
                throw ValidationException::withMessages([
                    'action' => 'Your next action is available '.$player->next_action_at->diffForHumans().'.',
                ]);
            }

            $requiredLevel = (int) ($definition['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $definition['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'action' => "You need level {$requiredLevel} ".str($definition['skill'])->headline()->toString().' for that action.',
                ]);
            }

            $tool = $this->players->equipmentForSkill($player, $definition['skill']);
            $toolModifiers = $this->toolEffects->actionModifiers($tool);
            $eventBonus = $this->events->gatheringBonusForSkill($definition['skill']);
            $experienceBonus = $toolModifiers['experience'];
            $yieldBonus = $toolModifiers['yield'];
            $experienceBonus += max(0, (int) ($eventBonus['experience'] ?? 0));
            $yieldBonus += max(0, (int) ($eventBonus['yield'] ?? 0));

            $experienceAwarded = random_int($definition['experience']['min'], $definition['experience']['max']) + $experienceBonus;
            $goldAwarded = random_int($definition['gold']['min'], $definition['gold']['max']) + $toolModifiers['gold'] + max(0, (int) ($eventBonus['gold'] ?? 0));
            $itemsAwarded = $this->rollLoot($definition['loot'], $yieldBonus);
            $availableAt = now()->addSeconds($this->cooldownSecondsFor($definition, $toolModifiers['cooldown_reduction']));

            $this->players->awardSkillExperience($player, $definition['skill'], $experienceAwarded);

            foreach ($itemsAwarded as $item) {
                $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                    'player_id' => $player->id,
                    'item_key' => $item['item_key'],
                ]);

                $stack->fill([
                    'item_name' => $item['item_name'],
                    'rarity' => $item['rarity'],
                    'quantity' => (int) $stack->quantity + $item['quantity'],
                ]);
                $stack->save();
            }

            $player->forceFill([
                'gold' => $player->gold + $goldAwarded,
                'last_action_at' => now(),
                'next_action_at' => $availableAt,
            ])->save();

            $log = ConnectedRealmsActionLog::create([
                'player_id' => $player->id,
                'action' => $actionKey,
                'skill' => $definition['skill'],
                'platform' => $platform,
                'result_label' => $definition['location'],
                'tool_item_key' => $tool?->item_key,
                'tool_item_name' => $tool?->item_name,
                'event_key' => $eventBonus['key'] ?? null,
                'event_label' => $eventBonus['label'] ?? null,
                'items_awarded' => $itemsAwarded,
                'experience_awarded' => $experienceAwarded,
                'gold_awarded' => $goldAwarded,
                'available_at' => $availableAt,
            ]);

            return [
                'id' => $log->id,
                'action' => $actionKey,
                'skill' => $definition['skill'],
                'label' => $definition['label'],
                'location' => $definition['location'],
                'tool' => $this->players->toolPayload($tool),
                'event' => $eventBonus,
                'items_awarded' => $itemsAwarded,
                'experience_awarded' => $experienceAwarded,
                'gold_awarded' => $goldAwarded,
                'next_action_at' => $availableAt->toIso8601String(),
            ];
        });
    }

    /**
     * @param  list<array{key: string, name: string, rarity: string, min: int, max: int}>  $loot
     * @return list<array{item_key: string, item_name: string, rarity: string, quantity: int}>
     */
    private function rollLoot(array $loot, int $yieldBonus): array
    {
        return collect($loot)
            ->map(function (array $item) use ($yieldBonus): ?array {
                $quantity = random_int($item['min'], $item['max']);

                if ($quantity < 1) {
                    return null;
                }

                if ($item['min'] > 0) {
                    $quantity += $yieldBonus;
                }

                return $this->items->enrich([
                    'item_key' => $item['key'],
                    'item_name' => $item['name'],
                    'rarity' => $item['rarity'],
                    'quantity' => $quantity,
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function cooldownSecondsFor(array $definition, int $cooldownReduction = 0): int
    {
        $override = config('connected_realms.action_cooldown_seconds');

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        $baseCooldown = (int) $definition['cooldown_seconds'];

        return max(1, (int) floor($baseCooldown * ((100 - min(80, max(0, $cooldownReduction))) / 100)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function actionDefinitions(): array
    {
        if (self::$actionDefinitionCache !== null) {
            return self::$actionDefinitionCache;
        }

        self::$actionDefinitionCache = [
            ...self::ACTIONS,
            ...self::EARLY_ACTIONS,
            ...self::ADVANCED_ACTIONS,
            ...self::midgameActions(),
            ...self::endgameActions(),
        ];

        return self::$actionDefinitionCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function midgameActions(): array
    {
        $families = [
            'fishing' => ['label' => 'Fishing', 'activities' => ['Hearthsign Harbor Shoal Cast', 'Runebound Crab Pot Lift', 'Stormglass Trawl', 'Highguild Ray Sounding'], 'traces' => ['Hearthsign Scale Reading', 'Runebound Shell Ledger', 'Stormglass Wake Note', 'Highguild Tide Rubbing'], 'resource' => 'fish'],
            'mining' => ['label' => 'Mining', 'activities' => ['Hearthsign Surface Assay', 'Runebound Iron Split', 'Stormglass Flux Tap', 'Highguild Core Cut'], 'traces' => ['Hearthsign Ore Stamp', 'Runebound Pick Mark', 'Stormglass Flux Glint', 'Highguild Core Dust'], 'resource' => 'ore'],
            'woodcutting' => ['label' => 'Woodcutting', 'activities' => ['Hearthsign Limb Mark', 'Runebound Branch Pull', 'Stormglass Ironwood Trim', 'Highguild Amberheart Notch'], 'traces' => ['Hearthsign Ring Slip', 'Runebound Branch Scar', 'Stormglass Grain Rubbing', 'Highguild Sap Map'], 'resource' => 'log'],
            'foraging' => ['label' => 'Foraging', 'activities' => ['Hearthsign Verge Pick', 'Runebound Bitterroot Pull', 'Stormglass Bloom Cut', 'Highguild Orchid Survey'], 'traces' => ['Hearthsign Herb Pressing', 'Runebound Root Rubbing', 'Stormglass Petal Press', 'Highguild Orchid Note'], 'resource' => 'herb'],
            'hunting' => ['label' => 'Hunting', 'activities' => ['Hearthsign Track Line', 'Runebound Sinew Field', 'Stormglass Bone Hunt', 'Highguild Trophy Pursuit'], 'traces' => ['Hearthsign Hoof Casting', 'Runebound Sinew Tag', 'Stormglass Tooth Score', 'Highguild Trophy Tag'], 'resource' => 'hide'],
            'farming' => ['label' => 'Farming', 'activities' => ['Hearthsign Row Harvest', 'Runebound Flax Pull', 'Stormglass Crop Turn', 'Highguild Fruit Watch'], 'traces' => ['Hearthsign Husk Note', 'Runebound Fiber Twist', 'Stormglass Soil Reading', 'Highguild Seed Press'], 'resource' => 'crop'],
            'excavation' => ['label' => 'Excavation', 'activities' => ['Hearthsign Field Relic Grid', 'Runebound Tablet Lift', 'Stormglass Gate Rubble Cut', 'Highguild Vault Dust Line'], 'traces' => ['Hearthsign Relic Rubbing', 'Runebound Tablet Dust', 'Stormglass Gate Mark', 'Highguild Vault Dust'], 'resource' => 'relic'],
        ];
        $tiers = [
            ['level' => 20, 'site' => 'Hearthsign', 'rarity' => 'uncommon', 'experience' => [52, 78], 'gold' => [6, 14], 'cooldown' => 130],
            ['level' => 30, 'site' => 'Runebound', 'rarity' => 'rare', 'experience' => [70, 104], 'gold' => [8, 18], 'cooldown' => 160],
            ['level' => 40, 'site' => 'Stormglass', 'rarity' => 'rare', 'experience' => [96, 144], 'gold' => [12, 26], 'cooldown' => 195],
            ['level' => 50, 'site' => 'Highguild', 'rarity' => 'rare', 'experience' => [118, 170], 'gold' => [16, 34], 'cooldown' => 230],
        ];
        $actions = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $index => $tier) {
                $level = $tier['level'];
                $itemName = GeneratedItemNameService::midgameGatheringResourceName($skill, $level);
                $actions["{$skill}_level_{$level}"] = [
                    'label' => $family['activities'][$index],
                    'skill' => $skill,
                    'location' => "{$tier['site']} {$family['label']} Grounds",
                    'required_level' => $level,
                    'cooldown_seconds' => $tier['cooldown'],
                    'experience' => ['min' => $tier['experience'][0], 'max' => $tier['experience'][1]],
                    'gold' => ['min' => $tier['gold'][0], 'max' => $tier['gold'][1]],
                    'loot' => [
                        ['key' => self::midgameResourceKey($skill, $level), 'name' => $itemName, 'rarity' => $tier['rarity'], 'min' => 2, 'max' => 5],
                        ['key' => self::midgameResourceKey($skill, $level).'_trace', 'name' => $family['traces'][$index], 'rarity' => $level >= 30 ? 'rare' : 'uncommon', 'min' => 0, 'max' => 1],
                    ],
                ];
            }
        }

        return $actions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function endgameActions(): array
    {
        $families = [
            'fishing' => [
                'label' => 'Fishing',
                'activities' => ['Elderwake Kelp Trench Haul', 'Mythgate Leviathan Drift', 'Crownmark Throne Drop'],
                'locations' => ['Elderwake Kelp Trench', 'Mythgate Leviathan Wake', 'Crownmark Leviathan Throne'],
                'loot' => [
                    ['key' => 'fish', 'name' => 'Deepcurrent Fish'],
                    ['key' => 'scale', 'name' => 'Leviathan Scale'],
                    ['key' => 'pearl', 'name' => 'Storm Pearl'],
                ],
            ],
            'mining' => [
                'label' => 'Mining',
                'activities' => ['Elderwake Geode Split', 'Mythgate Shelf Cut', 'Crownmark Coal Tap'],
                'locations' => ['Elderwake Geode Hollow', 'Mythgate Shelf', 'Crownmark Coal Face'],
                'loot' => [
                    ['key' => 'ore', 'name' => 'Mythgate Ore'],
                    ['key' => 'geode', 'name' => 'Elderwake Geode'],
                    ['key' => 'coal', 'name' => 'Crownmark Coal'],
                ],
            ],
            'woodcutting' => [
                'label' => 'Woodcutting',
                'activities' => ['Elderwake Resin Tapline', 'Mythgate Crown Cut', 'Crownmark Bough Claim'],
                'locations' => ['Elderwake Resinline', 'Mythgate Rise', 'Crownmark Bough'],
                'loot' => [
                    ['key' => 'log', 'name' => 'Mythgate Log'],
                    ['key' => 'resin', 'name' => 'Elderwake Resin'],
                    ['key' => 'branch', 'name' => 'Crownmark Branch'],
                ],
            ],
            'foraging' => [
                'label' => 'Foraging',
                'activities' => ['Elderwake Bloom Walk', 'Mythgate Spore Ring Cut', 'Crownmark Seed Verge'],
                'locations' => ['Elderwake Bloom Field', 'Mythgate Spore Ring', 'Crownmark Seed Verge'],
                'loot' => [
                    ['key' => 'bloom', 'name' => 'Elderwake Bloom'],
                    ['key' => 'root', 'name' => 'Runebound Root'],
                    ['key' => 'spore', 'name' => 'Mythgate Spore'],
                ],
            ],
            'hunting' => [
                'label' => 'Hunting',
                'activities' => ['Elderwake Greatbeast Trail', 'Mythgate Crownbeast Pursuit', 'Crownmark Apex Claim'],
                'locations' => ['Elderwake Greatbeast Trail', 'Mythgate Crownbeast Steppe', 'Crownmark Apex Range'],
                'loot' => [
                    ['key' => 'hide', 'name' => 'Primal Hide'],
                    ['key' => 'claw', 'name' => 'Apex Claw'],
                    ['key' => 'meat', 'name' => 'Greatbeast Meat'],
                ],
            ],
            'farming' => [
                'label' => 'Farming',
                'activities' => ['Elderwake Grain Terrace Cut', 'Mythgate Seed Row', 'Crownmark Fruit Conservatory'],
                'locations' => ['Elderwake Grain Terrace', 'Mythgate Seed Row', 'Crownmark Fruit Conservatory'],
                'loot' => [
                    ['key' => 'grain', 'name' => 'Moonwake Grain'],
                    ['key' => 'seed', 'name' => 'Crownmark Seed'],
                    ['key' => 'fruit', 'name' => 'Crownmark Fruit'],
                ],
            ],
            'excavation' => [
                'label' => 'Excavation',
                'activities' => ['Elderwake Reliquary Sift', 'Mythgate Tablet Reading', 'Crownmark Rune Vault Lift'],
                'locations' => ['Elderwake Reliquary', 'Mythgate Tablet Vault', 'Crownmark Rune Vault'],
                'loot' => [
                    ['key' => 'relic', 'name' => 'Elderwake Relic'],
                    ['key' => 'rune', 'name' => 'Crownmark Rune'],
                    ['key' => 'tablet', 'name' => 'Mythgate Tablet'],
                ],
            ],
        ];
        $tiers = [
            ['level' => 65, 'prefix' => 'Elderwake', 'rarity' => 'epic', 'experience' => [148, 212], 'gold' => [20, 42], 'cooldown' => 240],
            ['level' => 80, 'prefix' => 'Mythgate', 'rarity' => 'epic', 'experience' => [210, 300], 'gold' => [30, 60], 'cooldown' => 300],
            ['level' => 100, 'prefix' => 'Crownmark', 'rarity' => 'legendary', 'experience' => [360, 520], 'gold' => [58, 110], 'cooldown' => 420],
        ];
        $actions = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $index => $tier) {
                $level = $tier['level'];
                $prefix = $tier['prefix'];
                $location = $family['locations'][$index];
                $key = "{$skill}_level_{$level}";

                $actions[$key] = [
                    'label' => $family['activities'][$index],
                    'skill' => $skill,
                    'location' => $location,
                    'required_level' => $level,
                    'cooldown_seconds' => $tier['cooldown'],
                    'experience' => ['min' => $tier['experience'][0], 'max' => $tier['experience'][1]],
                    'gold' => ['min' => $tier['gold'][0], 'max' => $tier['gold'][1]],
                    'loot' => self::endgameLoot($skill, $prefix, $level, $tier['rarity'], $family['loot']),
                ];
            }
        }

        return $actions;
    }

    private static function midgameResourceKey(string $skill, int $level): string
    {
        return str("{$skill} midgame resource {$level}")->slug('_')->toString();
    }

    /**
     * @param  list<array{key: string, name: string}>  $loot
     * @return list<array{key: string, name: string, rarity: string, min: int, max: int}>
     */
    private static function endgameLoot(string $skill, string $prefix, int $level, string $rarity, array $loot): array
    {
        return collect($loot)
            ->map(function (array $item, int $index) use ($skill, $prefix, $level, $rarity): array {
                $itemKey = str("{$skill} {$prefix} {$item['key']} {$level}")->slug('_')->toString();

                return [
                    'key' => $itemKey,
                    'name' => GeneratedItemNameService::endgameGatheringResourceName($skill, $item['key'], $prefix),
                    'rarity' => $index === 0 ? $rarity : ($level >= 100 ? 'legendary' : 'epic'),
                    'min' => $index === 0 ? 2 : 0,
                    'max' => $index === 0 ? 5 : 1,
                ];
            })
            ->values()
            ->all();
    }
}
