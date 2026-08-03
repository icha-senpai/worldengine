<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobContractService
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $jobCache = null;

    /**
     * @var array<string, array{
     *     label: string,
     *     category: string,
     *     skill: string,
     *     required_level?: int,
     *     experience: int,
     *     gold: int,
     *     requirements: list<array{item_key: string, item_name: string, quantity: int}>,
     *     rewards: list<array{type: string, label: string, quantity: int}>
     * }>
     */
    private const JOBS = [
        'pier_provisions' => [
            'label' => 'Pier Provisions',
            'category' => 'Provisioning',
            'skill' => 'cooking',
            'experience' => 35,
            'gold' => 35,
            'requirements' => [
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'quantity' => 1],
            ],
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => 35],
                ['type' => 'experience', 'label' => 'Cooking XP', 'quantity' => 35],
            ],
        ],
        'quarry_smelter' => [
            'label' => 'Quarry Smelter',
            'category' => 'Commission',
            'skill' => 'smithing',
            'experience' => 38,
            'gold' => 40,
            'requirements' => [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
            ],
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => 40],
                ['type' => 'experience', 'label' => 'Smithing XP', 'quantity' => 38],
            ],
        ],
        'trail_repair' => [
            'label' => 'Trail Repair',
            'category' => 'Settlement',
            'skill' => 'carpentry',
            'experience' => 30,
            'gold' => 32,
            'requirements' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
            ],
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => 32],
                ['type' => 'experience', 'label' => 'Carpentry XP', 'quantity' => 30],
            ],
        ],
        'field_medic' => [
            'label' => 'Field Medic',
            'category' => 'Support',
            'skill' => 'alchemy',
            'experience' => 42,
            'gold' => 48,
            'requirements' => [
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
            ],
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => 48],
                ['type' => 'experience', 'label' => 'Alchemy XP', 'quantity' => 42],
            ],
        ],
    ];

    /**
     * @var array<string, array{label: string, category: string, skill: string, level: int, experience: int, gold: int, item_key: string, item_name: string, quantity: int}>
     */
    private const STARTER_JOB_LINES = [
        'bait_bucket_order' => ['label' => 'Bait Bucket Order', 'category' => 'Gathering', 'skill' => 'fishing', 'level' => 3, 'experience' => 34, 'gold' => 24, 'item_key' => 'tide_snail', 'item_name' => 'Tide Snail', 'quantity' => 3],
        'copper_assay' => ['label' => 'Copper Assay', 'category' => 'Gathering', 'skill' => 'mining', 'level' => 3, 'experience' => 36, 'gold' => 26, 'item_key' => 'copper_ore', 'item_name' => 'Copper Ore', 'quantity' => 3],
        'kindling_quota' => ['label' => 'Kindling Quota', 'category' => 'Gathering', 'skill' => 'woodcutting', 'level' => 3, 'experience' => 32, 'gold' => 24, 'item_key' => 'branch_bundle', 'item_name' => 'Branch Bundle', 'quantity' => 3],
        'seed_cache_sort' => ['label' => 'Seed Cache Sort', 'category' => 'Gathering', 'skill' => 'foraging', 'level' => 3, 'experience' => 30, 'gold' => 22, 'item_key' => 'common_seed', 'item_name' => 'Common Seed', 'quantity' => 3],
        'camp_meat_delivery' => ['label' => 'Camp Meat Delivery', 'category' => 'Gathering', 'skill' => 'hunting', 'level' => 3, 'experience' => 38, 'gold' => 28, 'item_key' => 'small_game_meat', 'item_name' => 'Small Game Meat', 'quantity' => 3],
        'bean_row_sample' => ['label' => 'Bean Row Sample', 'category' => 'Gathering', 'skill' => 'farming', 'level' => 7, 'experience' => 42, 'gold' => 30, 'item_key' => 'field_bean', 'item_name' => 'Field Bean', 'quantity' => 3],
        'pottery_sort' => ['label' => 'Pottery Sort', 'category' => 'Gathering', 'skill' => 'excavation', 'level' => 3, 'experience' => 38, 'gold' => 28, 'item_key' => 'pottery_shard', 'item_name' => 'Pottery Shard', 'quantity' => 3],
        'banked_forge_shift' => ['label' => 'Banked Forge Shift', 'category' => 'Processing', 'skill' => 'smelting', 'level' => 3, 'experience' => 42, 'gold' => 32, 'item_key' => 'banked_coal_blend', 'item_name' => 'Banked Coal Blend', 'quantity' => 1],
        'bark_sheet_bundle' => ['label' => 'Bark Sheet Bundle', 'category' => 'Processing', 'skill' => 'milling', 'level' => 3, 'experience' => 38, 'gold' => 30, 'item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
        'leather_strip_order' => ['label' => 'Leather Strip Order', 'category' => 'Processing', 'skill' => 'tanning', 'level' => 3, 'experience' => 42, 'gold' => 32, 'item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
        'gem_chip_packet' => ['label' => 'Gem Chip Packet', 'category' => 'Processing', 'skill' => 'cutting', 'level' => 3, 'experience' => 44, 'gold' => 34, 'item_key' => 'chipped_gemstone', 'item_name' => 'Chipped Gemstone', 'quantity' => 1],
        'reed_cloth_roll' => ['label' => 'Reed Cloth Roll', 'category' => 'Processing', 'skill' => 'weaving', 'level' => 3, 'experience' => 38, 'gold' => 30, 'item_key' => 'reed_cloth', 'item_name' => 'Reed Cloth', 'quantity' => 1],
        'fittings_batch' => ['label' => 'Fittings Batch', 'category' => 'Workshop', 'skill' => 'smithing', 'level' => 3, 'experience' => 46, 'gold' => 36, 'item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'quantity' => 1],
        'handle_lot' => ['label' => 'Handle Lot', 'category' => 'Workshop', 'skill' => 'carpentry', 'level' => 3, 'experience' => 42, 'gold' => 34, 'item_key' => 'ashwood_handle', 'item_name' => 'Ashwood Handle', 'quantity' => 1],
        'soup_kettle' => ['label' => 'Soup Kettle', 'category' => 'Provisioning', 'skill' => 'cooking', 'level' => 3, 'experience' => 44, 'gold' => 34, 'item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'quantity' => 1],
        'paste_vials' => ['label' => 'Paste Vials', 'category' => 'Support', 'skill' => 'alchemy', 'level' => 3, 'experience' => 46, 'gold' => 36, 'item_key' => 'bitterroot_paste', 'item_name' => 'Bitterroot Paste', 'quantity' => 1],
        'wrap_bundle' => ['label' => 'Wrap Bundle', 'category' => 'Workshop', 'skill' => 'tailoring', 'level' => 3, 'experience' => 42, 'gold' => 34, 'item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'quantity' => 1],
        'binding_order' => ['label' => 'Binding Order', 'category' => 'Workshop', 'skill' => 'leatherworking', 'level' => 3, 'experience' => 44, 'gold' => 34, 'item_key' => 'sinew_binding', 'item_name' => 'Sinew Binding', 'quantity' => 1],
        'spring_calibration' => ['label' => 'Spring Calibration', 'category' => 'Workshop', 'skill' => 'engineering', 'level' => 3, 'experience' => 48, 'gold' => 38, 'item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'quantity' => 1],
        'ward_oil_request' => ['label' => 'Ward Oil Request', 'category' => 'Arcane', 'skill' => 'enchanting', 'level' => 3, 'experience' => 50, 'gold' => 40, 'item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'quantity' => 1],
        'copper_setting_lot' => ['label' => 'Copper Setting Lot', 'category' => 'Luxury', 'skill' => 'jewelcrafting', 'level' => 3, 'experience' => 46, 'gold' => 36, 'item_key' => 'copper_setting', 'item_name' => 'Copper Setting', 'quantity' => 1],
        'reed_float_bundle' => ['label' => 'Reed Float Bundle', 'category' => 'Settlement', 'skill' => 'boatbuilding', 'level' => 3, 'experience' => 44, 'gold' => 34, 'item_key' => 'reed_float', 'item_name' => 'Reed Float', 'quantity' => 1],
        'stool_delivery' => ['label' => 'Stool Delivery', 'category' => 'Settlement', 'skill' => 'furniture', 'level' => 3, 'experience' => 44, 'gold' => 34, 'item_key' => 'ashwood_stool', 'item_name' => 'Ashwood Stool', 'quantity' => 1],
        'signpost_crew' => ['label' => 'Signpost Crew', 'category' => 'Settlement', 'skill' => 'construction', 'level' => 3, 'experience' => 50, 'gold' => 40, 'item_key' => 'trail_signpost', 'item_name' => 'Trail Signpost', 'quantity' => 1],
        'blade_drill' => ['label' => 'Blade Drill', 'category' => 'Combat', 'skill' => 'combat', 'level' => 8, 'experience' => 60, 'gold' => 48, 'item_key' => 'training_blade', 'item_name' => 'Training Blade', 'quantity' => 1],
        'fang_study' => ['label' => 'Fang Study', 'category' => 'Combat', 'skill' => 'slayer', 'level' => 8, 'experience' => 62, 'gold' => 50, 'item_key' => 'sharp_fang', 'item_name' => 'Sharp Fang', 'quantity' => 1],
        'repair_line' => ['label' => 'Repair Line', 'category' => 'Combat', 'skill' => 'defense', 'level' => 8, 'experience' => 60, 'gold' => 48, 'item_key' => 'field_repair_kit', 'item_name' => 'Field Repair Kit', 'quantity' => 1],
        'sap_rounds' => ['label' => 'Sap Rounds', 'category' => 'Support', 'skill' => 'healing', 'level' => 8, 'experience' => 60, 'gold' => 48, 'item_key' => 'sap_tonic', 'item_name' => 'Sap Tonic', 'quantity' => 1],
        'rune_thread_watch' => ['label' => 'Rune Thread Watch', 'category' => 'Arcane', 'skill' => 'magic', 'level' => 8, 'experience' => 64, 'gold' => 52, 'item_key' => 'rune_thread', 'item_name' => 'Rune Thread', 'quantity' => 1],
        'bow_sighting' => ['label' => 'Bow Sighting', 'category' => 'Combat', 'skill' => 'ranged', 'level' => 8, 'experience' => 60, 'gold' => 48, 'item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'quantity' => 1],
        'sketch_route' => ['label' => 'Sketch Route', 'category' => 'World', 'skill' => 'exploration', 'level' => 3, 'experience' => 50, 'gold' => 40, 'item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'quantity' => 1],
        'resource_room_notes' => ['label' => 'Resource Room Notes', 'category' => 'World', 'skill' => 'dungeoneering', 'level' => 8, 'experience' => 62, 'gold' => 50, 'item_key' => 'resource_note', 'item_name' => 'Resource Note', 'quantity' => 1],
        'dock_rope_order' => ['label' => 'Dock Rope Order', 'category' => 'World', 'skill' => 'sailing', 'level' => 8, 'experience' => 58, 'gold' => 48, 'item_key' => 'dock_rope', 'item_name' => 'Dock Rope', 'quantity' => 1],
        'flatbread_cache' => ['label' => 'Flatbread Cache', 'category' => 'World', 'skill' => 'survival', 'level' => 8, 'experience' => 58, 'gold' => 46, 'item_key' => 'grain_flatbread', 'item_name' => 'Grain Flatbread', 'quantity' => 1],
        'resource_note_sale' => ['label' => 'Resource Note Sale', 'category' => 'World', 'skill' => 'cartography', 'level' => 8, 'experience' => 58, 'gold' => 46, 'item_key' => 'resource_note', 'item_name' => 'Resource Note', 'quantity' => 1],
        'barter_errand' => ['label' => 'Barter Errand', 'category' => 'Social', 'skill' => 'reputation', 'level' => 3, 'experience' => 48, 'gold' => 38, 'item_key' => 'barter_note', 'item_name' => 'Barter Note', 'quantity' => 1],
        'crate_muster' => ['label' => 'Crate Muster', 'category' => 'Social', 'skill' => 'leadership', 'level' => 8, 'experience' => 62, 'gold' => 50, 'item_key' => 'supply_crate', 'item_name' => 'Supply Crate', 'quantity' => 1],
        'token_exchange' => ['label' => 'Token Exchange', 'category' => 'Social', 'skill' => 'trading', 'level' => 8, 'experience' => 58, 'gold' => 48, 'item_key' => 'market_token', 'item_name' => 'Market Token', 'quantity' => 1],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const JOB_TITLES = [
        'fishing' => [15 => 'River Market Icebox', 20 => 'Netmender Supply Run', 25 => 'Reef Eel Tally', 30 => 'Moonpool Bait Ledger', 35 => 'Deepwater Catch Hold', 40 => 'Pearlscale Fishery Claim', 45 => 'Stormdock Provision Lot', 50 => 'Tidewarden Reef Order', 75 => 'Leviathan Wake Bounty', 100 => 'Evergather Tide Tribute'],
        'mining' => [15 => 'Copper Vein Assay', 20 => 'Coalhouse Stock Writ', 25 => 'Cobalt Claim Ledger', 30 => 'Gem Pocket Survey', 35 => 'Deep Quarry Delivery', 40 => 'Runed Ore Inspection', 45 => 'Foundry Reserve Haul', 50 => 'Mountainheart Assay', 75 => 'Starmetal Claim Warrant', 100 => 'Crownspire Ore Tribute'],
        'woodcutting' => [15 => 'Hardwood Camp Quota', 20 => 'Resin Tap Ledger', 25 => 'Heartwood Survey Order', 30 => 'Bridge Beam Requisition', 35 => 'Grovekeeper Timber Lot', 40 => 'Stormfall Lumber Claim', 45 => 'Ancient Bark Harvest', 50 => 'Living Timber Writ', 75 => 'Elder Grove Stewardship', 100 => 'Worldroot Timber Tribute'],
        'foraging' => [15 => 'Herbalist Field Satchel', 20 => 'Mushroom Ring Survey', 25 => 'Silk Moss Parcel', 30 => 'Bitterroot Remedy Cache', 35 => 'Mooncap Grove Tally', 40 => 'Rare Bloom Preservation', 45 => 'Alchemist Wild Stock', 50 => 'Dawnpetal Reserve Order', 75 => 'Moonlit Orchid Warrant', 100 => 'Wildskeeper Seed Tribute'],
        'hunting' => [15 => 'Trail Trap Recovery', 20 => 'Hide Camp Muster', 25 => 'Fang Trophy Tally', 30 => 'Ridge Stalker Parcel', 35 => 'Monster Hide Delivery', 40 => 'Great Beast Tracking Writ', 45 => 'Hunter Lodge Reserve', 50 => 'Apex Spoor Dossier', 75 => 'Mythic Trophy Warrant', 100 => 'First Hunt Tribute'],
        'farming' => [15 => 'Bean Row Harvest', 20 => 'Herb Bed Ledger', 25 => 'Dusk Wheat Parcel', 30 => 'Orchard Market Cart', 35 => 'Greenhouse Yield Order', 40 => 'Seasonal Seed Audit', 45 => 'Sunfield Granary Lot', 50 => 'Harvest Guild Reserve', 75 => 'Mythic Crop Warrant', 100 => 'Evergarden Bounty'],
        'excavation' => [15 => 'Relic Grid Report', 20 => 'Bone Bed Recovery', 25 => 'Ancient Tablet Claim', 30 => 'Buried Mechanism Cache', 35 => 'Ruin Chamber Ledger', 40 => 'Lost Archive Parcel', 45 => 'Sanctum Dust Survey', 50 => 'Deep Archive Intake', 75 => 'Mythic Relic Warrant', 100 => 'First Age Reliquary'],
        'smelting' => [15 => 'Iron Furnace Batch', 20 => 'Coal Blend Ledger', 25 => 'Cobalt Bar Quota', 30 => 'Alloy Heat Order', 35 => 'Runed Ingot Pour', 40 => 'Foundry Night Shift', 45 => 'Prismatic Flux Batch', 50 => 'Master Furnace Intake', 75 => 'Starmetal Refinement Warrant', 100 => 'Worldforge Pour'],
        'milling' => [15 => 'Ashwood Plank Run', 20 => 'Bark Sheet Quota', 25 => 'Resin Board Ledger', 30 => 'Bridge Beam Batch', 35 => 'Precision Dowel Order', 40 => 'Heartwood Stock Intake', 45 => 'Living Timber Cut List', 50 => 'Guildhall Beam Requisition', 75 => 'Elderwood Mill Warrant', 100 => 'Worldroot Saw Order'],
        'tanning' => [15 => 'Cured Hide Parcel', 20 => 'Leather Strip Quota', 25 => 'Scale Backing Order', 30 => 'Harness Stock Ledger', 35 => 'Monster Hide Cure', 40 => 'Reinforced Leather Intake', 45 => 'Sinew Binding Lot', 50 => 'Beastguard Stock Writ', 75 => 'Mythic Hide Warrant', 100 => 'Apex Leather Tribute'],
        'cutting' => [15 => 'Polished Gem Packet', 20 => 'Socket Stone Ledger', 25 => 'Quartz Lens Order', 30 => 'Prismatic Facet Brief', 35 => 'Geode Appraisal Lot', 40 => 'Focus Cut Register', 45 => 'Mythic Gem Claim', 50 => 'Crown Facet Intake', 75 => 'Starfacet Warrant', 100 => 'First Light Jewel'],
        'weaving' => [15 => 'Fiber Thread Bundle', 20 => 'Reed Cloth Quota', 25 => 'Canvas Reinforcement Lot', 30 => 'Silk Loom Ledger', 35 => 'Spellthread Spool Order', 40 => 'Banner Cloth Requisition', 45 => 'Moonweave Bolt', 50 => 'Guild Loom Intake', 75 => 'Astral Thread Warrant', 100 => 'Evergather Loom Tribute'],
        'smithing' => [15 => 'Iron Fittings Order', 20 => 'Toolhead Forge Ticket', 25 => 'Steel Frame Request', 30 => 'Armor Rivet Lot', 35 => 'Runed Pick Head', 40 => 'Masterwork Anvil Brief', 45 => 'Crown Tool Commission', 50 => 'Warplate Assembly Writ', 75 => 'Meteor Gear Warrant', 100 => 'Anvil Saint Mandate'],
        'carpentry' => [15 => 'Handlewright Lot', 20 => 'Bow Stave Request', 25 => 'Strong Frame Ledger', 30 => 'Cratewright Delivery', 35 => 'Expedition Timber Kit', 40 => 'Guildhall Joinery Brief', 45 => 'Living Wood Handle', 50 => 'Master Carpenter Writ', 75 => 'Heartwood Frame Warrant', 100 => 'Worldroot Joinery Mandate'],
        'cooking' => [15 => 'Ration Kettle Order', 20 => 'Harbor Soup Shift', 25 => 'Skill Meal Platter', 30 => 'Hunter Feast Ledger', 35 => 'Raid Pantry Refill', 40 => 'Dusk Feast Request', 45 => 'Festival Kitchen Brief', 50 => 'Guild Banquet Order', 75 => 'Mythic Cuisine Warrant', 100 => 'Realm Chef Banquet'],
        'alchemy' => [15 => 'Field Tonic Crate', 20 => 'Ward Oil Ledger', 25 => 'Combat Draught Parcel', 30 => 'Catalyst Vial Brief', 35 => 'Bitterroot Remedy Order', 40 => 'Prismatic Reagent Intake', 45 => 'Transmutation Vessel', 50 => 'Grand Still Requisition', 75 => 'Mythic Elixir Warrant', 100 => 'Grand Alchemist Mandate'],
        'tailoring' => [15 => 'Field Wrap Bundle', 20 => 'Satchel Stitch Order', 25 => 'Robe Panel Ledger', 30 => 'Sailcloth Delivery', 35 => 'Banner Hem Brief', 40 => 'Spellcloth Garment Lot', 45 => 'Court Outfit Request', 50 => 'Guild Regalia Writ', 75 => 'Astral Vestment Warrant', 100 => 'Couture Master Mandate'],
        'leatherworking' => [15 => 'Pouchmaker Parcel', 20 => 'Tool Belt Order', 25 => 'Leather Armor Fit', 30 => 'Travel Harness Ledger', 35 => 'Monster Gear Brief', 40 => 'Saddle Stock Request', 45 => 'Rugged Kit Intake', 50 => 'Beastguard Harness Writ', 75 => 'Apex Hide Warrant', 100 => 'Hide Artisan Mandate'],
        'engineering' => [15 => 'Clockwork Spring Packet', 20 => 'Trapline Mechanism Order', 25 => 'Gadget Bench Brief', 30 => 'Siege Gear Ledger', 35 => 'Arcane Engine Coupling', 40 => 'Precision Trigger Lot', 45 => 'Survey Device Intake', 50 => 'Workshop Prototype Writ', 75 => 'Astral Engine Warrant', 100 => 'Chief Engineer Mandate'],
        'enchanting' => [15 => 'Minor Ward Oil Request', 20 => 'Charm Infusion Ledger', 25 => 'Socket Rune Brief', 30 => 'Trait Binding Parcel', 35 => 'Relic Wake Draft', 40 => 'Major Enchantment Order', 45 => 'Prismatic Rune Claim', 50 => 'Arcane Seal Requisition', 75 => 'Mythic Binding Warrant', 100 => 'Arcane Binder Mandate'],
        'jewelcrafting' => [15 => 'Copper Setting Lot', 20 => 'Silver Ring Request', 25 => 'Socket Trinket Ledger', 30 => 'Amulet Frame Brief', 35 => 'Focus Lens Parcel', 40 => 'Gem Sovereign Trial', 45 => 'Crown Bead Intake', 50 => 'Prismatic Lens Ledger', 75 => 'Mythic Jewelry Warrant', 100 => 'Gem Sovereign Mandate'],
        'boatbuilding' => [15 => 'Skiff Rib Delivery', 20 => 'Reed Float Order', 25 => 'Cargo Hull Ledger', 30 => 'Sail Frame Brief', 35 => 'Dockwright Timber Lot', 40 => 'Expedition Hull Request', 45 => 'Fleet Cargo Refit', 50 => 'Harbor Master Writ', 75 => 'Stormfleet Warrant', 100 => 'Shipwright Mandate'],
        'furniture' => [15 => 'Ashwood Stool Delivery', 20 => 'Tablemaker Work Slip', 25 => 'Display Stand Ledger', 30 => 'Trophy Hall Brief', 35 => 'Guild Fixture Parcel', 40 => 'Prestige Set Intake', 45 => 'Carved Crate Request', 50 => 'Hall Architect Writ', 75 => 'Royal Suite Warrant', 100 => 'Grand Hall Mandate'],
        'construction' => [15 => 'Trail Signpost Crew', 20 => 'Station Repair Writ', 25 => 'Workshop Frame Ledger', 30 => 'Fortification Timber Lot', 35 => 'Bridge Scaffold Request', 40 => 'Guildhall Foundation Brief', 45 => 'Watchtower Stone Order', 50 => 'Settlement Works Writ', 75 => 'Citadel Wall Warrant', 100 => 'Realm Builder Mandate'],
        'combat' => [15 => 'Guard Patrol Muster', 20 => 'Training Yard Drill', 25 => 'Iron Line Brief', 30 => 'Outrider Clash Report', 35 => 'Champion Trial Notice', 40 => 'Raid Vanguard Order', 45 => 'Warcamp Readiness Writ', 50 => 'Elite Tactics Mandate', 75 => 'Mythic Champion Warrant', 100 => 'Realm Champion Challenge'],
        'slayer' => [15 => 'Fang Study Notice', 20 => 'Marked Trophy Claim', 25 => 'Beast Weakness Dossier', 30 => 'Monster Bounty Ledger', 35 => 'Stalker Den Report', 40 => 'Elite Mark Writ', 45 => 'Great Beast Warrant', 50 => 'Nightfang Hunt Brief', 75 => 'Mythic Hunt Warrant', 100 => 'Monster Bane Challenge'],
        'defense' => [15 => 'Shield Line Drill', 20 => 'Field Repair Roster', 25 => 'Armor Mastery Brief', 30 => 'Party Guard Muster', 35 => 'Bulwark Supply Ledger', 40 => 'Dungeon Guard Writ', 45 => 'Wallbreaker Hold Order', 50 => 'Unbroken Line Mandate', 75 => 'Citadel Bulwark Warrant', 100 => 'Unbroken Wall Challenge'],
        'healing' => [15 => 'Triage Shift Notice', 20 => 'Sap Tonic Parcel', 25 => 'Group Recovery Roster', 30 => 'Expedition Medic Kit', 35 => 'Stabilizer Vial Ledger', 40 => 'Field Hospital Intake', 45 => 'Revival Rite Brief', 50 => 'Lifewarden Supply Writ', 75 => 'Mythic Renewal Warrant', 100 => 'Life Warden Challenge'],
        'magic' => [15 => 'Rune Thread Watch', 20 => 'Ward Circle Ledger', 25 => 'Elemental Focus Brief', 30 => 'Ritual Night Roster', 35 => 'Arcane Storm Report', 40 => 'Sealed Rune Intake', 45 => 'Spellguard Requisition', 50 => 'Archmage Trial Writ', 75 => 'Astral Rite Warrant', 100 => 'Archmage Challenge'],
        'ranged' => [15 => 'Bow Sighting Order', 20 => 'Arrow Stock Ledger', 25 => 'Special Shot Brief', 30 => 'Siege Range Roster', 35 => 'Trail Bow Refit', 40 => 'Trick Shot Notice', 45 => 'Skywatcher Quiver Lot', 50 => 'Marksman Trial Writ', 75 => 'Stormshot Warrant', 100 => 'Sky Archer Challenge'],
        'exploration' => [15 => 'Sketch Route Survey', 20 => 'Regional Path Ledger', 25 => 'Hidden Room Report', 30 => 'Distant Trail Cache', 35 => 'Ancient Gate Reading', 40 => 'Scoutmaster Route Brief', 45 => 'Frontier Map Intake', 50 => 'Worldwalker Waybill', 75 => 'Ancient Gate Warrant', 100 => 'Worldwalker Mandate'],
        'dungeoneering' => [15 => 'Room Check Notice', 20 => 'Trap Read Ledger', 25 => 'Party Route Dossier', 30 => 'Boss Room Supply List', 35 => 'Dungeon Resource Audit', 40 => 'Deep Chamber Brief', 45 => 'Vault Key Report', 50 => 'Labyrinth Route Writ', 75 => 'Mythic Chamber Warrant', 100 => 'Deep Warden Mandate'],
        'sailing' => [15 => 'Dock Rope Delivery', 20 => 'Coastal Trip Ledger', 25 => 'Cargo Run Manifest', 30 => 'Fleet Support Order', 35 => 'Storm Route Chart', 40 => 'Harbor Signal Brief', 45 => 'Tide Captain Supply Lot', 50 => 'Expedition Sail Writ', 75 => 'Stormroute Warrant', 100 => 'Tide Captain Mandate'],
        'survival' => [15 => 'Flatbread Cache Order', 20 => 'Weather Read Report', 25 => 'Long Trip Supply List', 30 => 'Hazard Kit Parcel', 35 => 'Hostile Region Brief', 40 => 'Campcraft Ledger', 45 => 'Last Light Cache', 50 => 'Wild March Writ', 75 => 'Hostile Wilds Warrant', 100 => 'Last Light Mandate'],
        'cartography' => [15 => 'Resource Note Sale', 20 => 'Route Map Ledger', 25 => 'Dungeon Chart Brief', 30 => 'Region Atlas Intake', 35 => 'Secret Road Survey', 40 => 'Surveyor Mark Parcel', 45 => 'Starmapper Grid Report', 50 => 'Navigator Archive Writ', 75 => 'Secret Atlas Warrant', 100 => 'Star Mapper Mandate'],
        'reputation' => [15 => 'Barter Note Errand', 20 => 'Faction Favor Ledger', 25 => 'Regional Rate Petition', 30 => 'Trusted Access Notice', 35 => 'Council Gift Parcel', 40 => 'Title Claim Brief', 45 => 'Envoy Introduction Writ', 50 => 'Realm Favor Mandate', 75 => 'Council Seat Warrant', 100 => 'Realm Envoy Mandate'],
        'leadership' => [15 => 'Crate Muster Notice', 20 => 'Party Call Roster', 25 => 'Guild Task Ledger', 30 => 'Raid Supply Brief', 35 => 'Banner Drill Order', 40 => 'Regional Campaign Writ', 45 => 'Command Tent Intake', 50 => 'War Table Mandate', 75 => 'Campaign Standard Warrant', 100 => 'Bannerlord Mandate'],
        'trading' => [15 => 'Market Token Exchange', 20 => 'Bulk Listing Ledger', 25 => 'Work Order Packet', 30 => 'Storefront Stock Brief', 35 => 'Trade Route Manifest', 40 => 'Regional Arbitrage Writ', 45 => 'Merchant Seal Intake', 50 => 'Guild Ledger Mandate', 75 => 'Royal Exchange Warrant', 100 => 'Market Sovereign Mandate'],
    ];

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @return list<string>
     */
    public static function jobKeys(): array
    {
        return array_keys(self::jobs());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableJobsFor(ConnectedRealmsPlayer $player): array
    {
        $inventory = ($player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->get())
            ->keyBy('item_key');

        return collect(self::jobs())
            ->map(function (array $job, string $key) use ($inventory, $player): array {
                $requiredLevel = (int) ($job['required_level'] ?? 1);
                $skillLevel = $this->players->currentSkillLevel($player, $job['skill']);
                $requirements = collect($job['requirements'])
                    ->map(function (array $requirement) use ($inventory): array {
                        $ownedQuantity = (int) ($inventory->get($requirement['item_key'])?->quantity ?? 0);

                        return $this->items->enrich([
                            ...$requirement,
                            'owned_quantity' => $ownedQuantity,
                            'has_enough' => $ownedQuantity >= $requirement['quantity'],
                        ]);
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => $job['label'],
                    'category' => $job['category'],
                    'skill' => $job['skill'],
                    'skill_label' => str($job['skill'])->headline()->toString(),
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
                    'is_unlocked' => $skillLevel >= $requiredLevel,
                    'experience' => $job['experience'],
                    'gold' => $job['gold'],
                    'requirements' => $requirements,
                    'rewards' => $job['rewards'],
                    'can_complete' => collect($requirements)->every(fn (array $requirement): bool => $requirement['has_enough'])
                        && $skillLevel >= $requiredLevel,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(User $user, string $jobKey): array
    {
        $job = self::jobs()[$jobKey] ?? null;

        if ($job === null) {
            throw ValidationException::withMessages([
                'job' => 'That Evergather job is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $jobKey, $job): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            $requiredLevel = (int) ($job['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $job['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'job' => "You need level {$requiredLevel} ".str($job['skill'])->headline()->toString().' for that job.',
                ]);
            }

            $requirementKeys = collect($job['requirements'])->pluck('item_key')->all();
            $stacks = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->whereIn('item_key', $requirementKeys)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_key');

            foreach ($job['requirements'] as $requirement) {
                $stack = $stacks->get($requirement['item_key']);

                if ($stack === null || $stack->quantity < $requirement['quantity']) {
                    throw ValidationException::withMessages([
                        'job' => "You need {$requirement['quantity']} {$requirement['item_name']} for that job.",
                    ]);
                }
            }

            foreach ($job['requirements'] as $requirement) {
                $stack = $stacks->get($requirement['item_key']);
                $stack->quantity -= $requirement['quantity'];

                if ($stack->quantity <= 0) {
                    $stack->delete();

                    continue;
                }

                $stack->save();
            }

            $delivered = $this->items->enrichMany($job['requirements']);

            $player->forceFill([
                'gold' => $player->gold + $job['gold'],
            ])->save();

            $this->players->awardSkillExperience($player, $job['skill'], $job['experience']);

            $completion = ConnectedRealmsJobCompletion::create([
                'player_id' => $player->id,
                'job_key' => $jobKey,
                'job_name' => $job['label'],
                'category' => $job['category'],
                'items_delivered' => $delivered,
                'rewards' => $job['rewards'],
                'experience_awarded' => $job['experience'],
                'gold_awarded' => $job['gold'],
            ]);

            return [
                'type' => 'job',
                'id' => $completion->id,
                'job_key' => $jobKey,
                'label' => $job['label'],
                'category' => $job['category'],
                'skill' => $job['skill'],
                'skill_label' => str($job['skill'])->headline()->toString(),
                'items_delivered' => $delivered,
                'rewards' => $job['rewards'],
                'experience_awarded' => $job['experience'],
                'gold_awarded' => $job['gold'],
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function jobs(): array
    {
        if (self::$jobCache !== null) {
            return self::$jobCache;
        }

        self::$jobCache = [
            ...self::JOBS,
            ...self::starterJobs(),
            ...self::expandedJobs(),
            ...self::midgameJobs(),
            ...self::endgameJobs(),
        ];

        return self::$jobCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function starterJobs(): array
    {
        return collect(self::STARTER_JOB_LINES)
            ->map(fn (array $job): array => self::job($job['label'], $job['category'], $job['skill'], $job['level'], $job['experience'], $job['gold'], [[
                'item_key' => $job['item_key'],
                'item_name' => $job['item_name'],
                'quantity' => $job['quantity'],
            ]]))
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expandedJobs(): array
    {
        return [
            'tannery_order' => self::job('Tannery Order', 'Commission', 'tanning', 1, 32, 34, [
                ['item_key' => 'cured_leather', 'item_name' => 'Cured Leather', 'quantity' => 1],
            ]),
            'gemcutters_brief' => self::job("Gemcutter's Brief", 'Commission', 'cutting', 1, 36, 42, [
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'quantity' => 1],
            ]),
            'loom_delivery' => self::job('Loom Delivery', 'Commission', 'weaving', 1, 32, 34, [
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 2],
            ]),
            'satchel_quota' => self::job('Satchel Quota', 'Workshop', 'tailoring', 1, 38, 44, [
                ['item_key' => 'cloth_satchel', 'item_name' => 'Cloth Satchel', 'quantity' => 1],
            ]),
            'leather_harness_order' => self::job('Leather Harness Order', 'Workshop', 'leatherworking', 1, 38, 44, [
                ['item_key' => 'leather_grip', 'item_name' => 'Leather Grip', 'quantity' => 1],
            ]),
            'engineers_lure_test' => self::job("Engineer's Lure Test", 'Workshop', 'engineering', 1, 46, 54, [
                ['item_key' => 'clockwork_lure', 'item_name' => 'Clockwork Lure', 'quantity' => 1],
            ]),
            'warded_charm_order' => self::job('Warded Charm Order', 'Arcane', 'enchanting', 1, 48, 56, [
                ['item_key' => 'ember_charm', 'item_name' => 'Ember Charm', 'quantity' => 1],
            ]),
            'jewelers_setting' => self::job("Jeweler's Setting", 'Luxury', 'jewelcrafting', 1, 44, 52, [
                ['item_key' => 'silver_ring', 'item_name' => 'Silver Ring', 'quantity' => 1],
            ]),
            'dockwright_invoice' => self::job('Dockwright Invoice', 'Settlement', 'boatbuilding', 1, 42, 50, [
                ['item_key' => 'skiff_rib', 'item_name' => 'Skiff Rib', 'quantity' => 1],
            ]),
            'hall_furnishing' => self::job('Hall Furnishing', 'Settlement', 'furniture', 1, 42, 50, [
                ['item_key' => 'trophy_stand', 'item_name' => 'Trophy Stand', 'quantity' => 1],
            ]),
            'mapmakers_request' => self::job("Mapmaker's Request", 'World', 'cartography', 1, 40, 46, [
                ['item_key' => 'route_map', 'item_name' => 'Route Map', 'quantity' => 1],
            ]),
            'merchant_manifest' => self::job('Merchant Manifest', 'Social', 'trading', 1, 40, 48, [
                ['item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'quantity' => 1],
            ]),
            'guard_patrol' => self::job('Guard Patrol', 'Combat', 'combat', 1, 52, 52, [
                ['item_key' => 'iron_knife', 'item_name' => 'Iron Knife', 'quantity' => 1],
                ['item_key' => 'hunter_ration', 'item_name' => 'Hunter Ration', 'quantity' => 1],
            ]),
            'monster_bounty' => self::job('Monster Bounty', 'Combat', 'slayer', 1, 58, 62, [
                ['item_key' => 'marked_trophy_bone', 'item_name' => 'Marked Trophy Bone', 'quantity' => 1],
                ['item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'quantity' => 1],
            ]),
            'shield_line_drill' => self::job('Shield Line Drill', 'Combat', 'defense', 1, 54, 54, [
                ['item_key' => 'repair_scaffold', 'item_name' => 'Repair Scaffold', 'quantity' => 1],
            ]),
            'triage_shift' => self::job('Triage Shift', 'Support', 'healing', 1, 54, 58, [
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
                ['item_key' => 'sunspike_herb', 'item_name' => 'Sunspike Herb', 'quantity' => 1],
            ]),
            'ritual_watch' => self::job('Ritual Watch', 'Arcane', 'magic', 1, 58, 60, [
                ['item_key' => 'ember_charm', 'item_name' => 'Ember Charm', 'quantity' => 1],
                ['item_key' => 'sealed_rune_chip', 'item_name' => 'Sealed Rune Chip', 'quantity' => 1],
            ]),
            'range_markers' => self::job('Range Markers', 'Combat', 'ranged', 1, 52, 52, [
                ['item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'quantity' => 1],
                ['item_key' => 'braided_sinew', 'item_name' => 'Braided Sinew', 'quantity' => 1],
            ]),
            'camp_quartermaster' => self::job('Camp Quartermaster', 'World', 'survival', 1, 52, 56, [
                ['item_key' => 'hunter_ration', 'item_name' => 'Hunter Ration', 'quantity' => 1],
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
            ]),
            'faction_errand' => self::job('Faction Errand', 'Social', 'reputation', 1, 48, 50, [
                ['item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'quantity' => 1],
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'quantity' => 1],
            ]),
            'raid_roster' => self::job('Raid Roster', 'Social', 'leadership', 1, 56, 58, [
                ['item_key' => 'repair_scaffold', 'item_name' => 'Repair Scaffold', 'quantity' => 1],
                ['item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'quantity' => 1],
            ]),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function midgameJobs(): array
    {
        $jobs = [];

        foreach (SkillCatalogService::keys() as $skill) {
            foreach ([15, 20, 30, 35, 40, 45] as $level) {
                $jobs["{$skill}_midgame_contract_{$level}"] = self::job(
                    self::jobTitleFor($skill, $level),
                    self::jobCategoryForLevel($level),
                    $skill,
                    $level,
                    55 + ($level * 5),
                    45 + ($level * 4),
                    [self::midgameRequirementFor($skill, $level)],
                );
            }
        }

        return $jobs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function endgameJobs(): array
    {
        $jobs = [];

        foreach (SkillCatalogService::keys() as $skill) {
            foreach ([25, 50, 75, 100] as $level) {
                $jobs["{$skill}_mastery_contract_{$level}"] = self::job(
                    self::jobTitleFor($skill, $level),
                    self::jobCategoryForLevel($level),
                    $skill,
                    $level,
                    80 + ($level * 7),
                    70 + ($level * 5),
                    [self::endgameRequirementFor($skill, $level)],
                );
            }
        }

        return $jobs;
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function midgameRequirementFor(string $skill, int $level): array
    {
        $effectiveLevel = max(20, min(40, $level));

        return match ($skill) {
            'fishing', 'mining', 'woodcutting', 'foraging', 'hunting', 'farming', 'excavation' => [
                'item_key' => self::midgameGatheringResourceKey($skill, $effectiveLevel),
                'item_name' => self::midgameGatheringResourceName($skill, $effectiveLevel),
                'quantity' => $level >= 35 ? 3 : 2,
            ],
            'combat' => self::midgameCraftedRequirement('smithing', $effectiveLevel),
            'slayer' => self::midgameCraftedRequirement('leatherworking', $effectiveLevel),
            'defense' => self::midgameCraftedRequirement('construction', $effectiveLevel),
            'healing' => self::midgameCraftedRequirement('alchemy', $effectiveLevel),
            'magic' => self::midgameCraftedRequirement('enchanting', $effectiveLevel),
            'ranged' => self::midgameCraftedRequirement('carpentry', $effectiveLevel),
            'exploration', 'dungeoneering', 'sailing', 'survival', 'cartography' => self::midgameCraftedRequirement('cartography', $effectiveLevel),
            'reputation', 'leadership', 'trading' => self::midgameCraftedRequirement('trading', $effectiveLevel),
            default => self::midgameCraftedRequirement($skill, $effectiveLevel),
        };
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $requirements
     * @return array<string, mixed>
     */
    private static function job(string $label, string $category, string $skill, int $requiredLevel, int $experience, int $gold, array $requirements): array
    {
        return [
            'label' => $label,
            'category' => $category,
            'skill' => $skill,
            'required_level' => $requiredLevel,
            'experience' => $experience,
            'gold' => $gold,
            'requirements' => $requirements,
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => $gold],
                ['type' => 'experience', 'label' => str($skill)->headline()->toString().' XP', 'quantity' => $experience],
            ],
        ];
    }

    private static function jobTitleFor(string $skill, int $level): string
    {
        if (isset(self::JOB_TITLES[$skill][$level])) {
            return self::JOB_TITLES[$skill][$level];
        }

        $label = str($skill)->headline()->toString();

        return match (true) {
            $level >= 100 => "{$label} Grand Mandate",
            $level >= 75 => "{$label} Mythic Warrant",
            $level >= 50 => "{$label} Masterwork Writ",
            $level >= 35 => "{$label} Guild Request",
            default => "{$label} Work Order",
        };
    }

    private static function jobCategoryForLevel(int $level): string
    {
        return match (true) {
            $level >= 100 => 'Realm Mandates',
            $level >= 75 => 'Mythic Warrants',
            $level >= 50 => 'Masterwork Writs',
            $level >= 35 => 'Expert Requests',
            $level >= 25 => 'Specialist Orders',
            default => 'Guild Commissions',
        };
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function endgameRequirementFor(string $skill, int $level): array
    {
        if ($level < 75) {
            return match ($skill) {
                'fishing' => ['item_key' => 'reef_eel', 'item_name' => 'Reef Eel', 'quantity' => 2],
                'mining' => ['item_key' => 'cobalt_ore', 'item_name' => 'Cobalt Ore', 'quantity' => 2],
                'woodcutting' => ['item_key' => 'resinwood_log', 'item_name' => 'Resinwood Log', 'quantity' => 2],
                'foraging' => ['item_key' => 'silk_moss', 'item_name' => 'Silk Moss', 'quantity' => 2],
                'hunting' => ['item_key' => 'monster_hide', 'item_name' => 'Monster Hide', 'quantity' => 1],
                'farming' => ['item_key' => 'dusk_wheat', 'item_name' => 'Dusk Wheat', 'quantity' => 2],
                'excavation' => ['item_key' => 'ancient_tablet', 'item_name' => 'Ancient Tablet', 'quantity' => 1],
                'combat', 'slayer', 'defense', 'healing', 'magic', 'ranged' => ['item_key' => 'dusk_feast', 'item_name' => 'Dusk Feast', 'quantity' => 1],
                'exploration', 'dungeoneering', 'sailing', 'survival', 'cartography' => ['item_key' => 'dungeon_chart', 'item_name' => 'Dungeon Chart', 'quantity' => 1],
                'reputation', 'leadership', 'trading' => ['item_key' => 'merchant_seal', 'item_name' => 'Merchant Seal', 'quantity' => 1],
                default => [
                    'item_key' => self::endgameCraftOutputKey($skill, 55),
                    'item_name' => self::endgameCraftOutputName($skill, 55),
                    'quantity' => 1,
                ],
            };
        }

        $effectiveLevel = $level >= 100 ? 100 : 75;

        return match ($skill) {
            'fishing', 'mining', 'woodcutting', 'foraging', 'hunting', 'farming', 'excavation' => [
                'item_key' => self::endgameGatheringResourceKey($skill, $effectiveLevel),
                'item_name' => self::endgameGatheringResourceName($skill, $effectiveLevel),
                'quantity' => $effectiveLevel >= 100 ? 4 : 3,
            ],
            'combat' => self::craftedRequirement('smithing', $effectiveLevel),
            'slayer' => self::craftedRequirement('leatherworking', $effectiveLevel),
            'defense' => self::craftedRequirement('construction', $effectiveLevel),
            'healing' => self::craftedRequirement('alchemy', $effectiveLevel),
            'magic' => self::craftedRequirement('enchanting', $effectiveLevel),
            'ranged' => self::craftedRequirement('carpentry', $effectiveLevel),
            'exploration', 'dungeoneering', 'sailing', 'survival', 'cartography' => self::craftedRequirement('cartography', $effectiveLevel),
            'reputation', 'leadership', 'trading' => self::craftedRequirement('trading', $effectiveLevel),
            default => self::craftedRequirement($skill, $effectiveLevel),
        };
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedRequirement(string $skill, int $level): array
    {
        return [
            'item_key' => self::endgameCraftOutputKey($skill, $level),
            'item_name' => self::endgameCraftOutputName($skill, $level),
            'quantity' => $level >= 100 ? 2 : 1,
        ];
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function midgameCraftedRequirement(string $skill, int $level): array
    {
        return [
            'item_key' => self::midgameCraftOutputKey($skill, $level),
            'item_name' => self::midgameCraftOutputName($skill, $level),
            'quantity' => $level >= 35 ? 2 : 1,
        ];
    }

    private static function midgameCraftOutputKey(string $skill, int $level): string
    {
        return str("{$skill} midgame work {$level}")->slug('_')->toString();
    }

    private static function midgameCraftOutputName(string $skill, int $level): string
    {
        return GeneratedItemNameService::midgameCraftOutputName($skill, $level);
    }

    private static function midgameGatheringResourceKey(string $skill, int $level): string
    {
        return str("{$skill} midgame resource {$level}")->slug('_')->toString();
    }

    private static function midgameGatheringResourceName(string $skill, int $level): string
    {
        return GeneratedItemNameService::midgameGatheringResourceName($skill, $level);
    }

    private static function endgameCraftOutputKey(string $skill, int $level): string
    {
        return str("{$skill} endgame work {$level}")->slug('_')->toString();
    }

    private static function endgameCraftOutputName(string $skill, int $level): string
    {
        return GeneratedItemNameService::endgameCraftOutputName($skill, $level);
    }

    private static function endgameGatheringResourceKey(string $skill, int $level): string
    {
        $prefix = $level >= 100 ? 'Evergather' : 'Mythic';
        $resource = match ($skill) {
            'fishing' => 'fish',
            'mining' => 'ore',
            'woodcutting' => 'log',
            'foraging' => 'bloom',
            'hunting' => 'hide',
            'farming' => 'grain',
            default => 'relic',
        };

        return str("{$skill} {$prefix} {$resource} {$level}")->slug('_')->toString();
    }

    private static function endgameGatheringResourceName(string $skill, int $level): string
    {
        $prefix = match (true) {
            $level >= 100 => 'Evergather',
            $level >= 95 => 'Prismatic',
            $level >= 85 => 'Astral',
            $level >= 75 => 'Mythic',
            $level >= 65 => 'Elder',
            default => 'Runed',
        };
        $resource = match ($skill) {
            'fishing' => 'fish',
            'mining' => 'ore',
            'woodcutting' => 'log',
            'foraging' => 'bloom',
            'hunting' => 'hide',
            'farming' => 'grain',
            default => 'relic',
        };

        return GeneratedItemNameService::endgameGatheringResourceName($skill, $resource, $prefix);
    }
}
