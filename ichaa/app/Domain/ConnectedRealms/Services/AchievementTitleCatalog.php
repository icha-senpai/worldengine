<?php

namespace App\Domain\ConnectedRealms\Services;

class AchievementTitleCatalog
{
    /**
     * @var array<string, string>
     */
    private const BASE_TITLES = [
        'first_steps' => 'Trailhand',
        'working_hands' => 'Bench Hand',
        'contract_hand' => 'Board Runner',
        'pathfinder' => 'Road Finder',
        'market_voice' => 'Market Caller',
        'full_pockets' => 'Coin Purse',
        'packed_satchel' => 'Satchel Keeper',
        'skill_spark' => 'Quick Learner',
        'ready_toolbelt' => 'Ready Toolbelt',
        'steady_hands' => 'Steady Hand',
        'route_runner' => 'Route Runner',
        'field_legend' => 'Field Legend',
        'gathering_circle' => 'Gathering Circle',
        'wilds_initiate' => 'Wilds Initiate',
        'wilds_specialist' => 'Wilds Specialist',
        'bench_warm' => 'Bench Warmer',
        'workshop_shift' => 'Workshop Regular',
        'artisan_season' => 'Artisan Season',
        'profession_sampler' => 'Trade Sampler',
        'apprentice_artisan' => 'Workbench Initiate',
        'master_artisan' => 'Workshop Master',
        'reliable_hand' => 'Reliable Hand',
        'guild_worker' => 'Board Regular',
        'contract_legend' => 'Commission Legend',
        'caravaner' => 'Caravan Hand',
        'far_runner' => 'Far Runner',
        'worldwalker' => 'Horizon Walker',
        'world_skill_spark' => 'Roadwise Student',
        'trail_authority' => 'Trail Authority',
        'vendor_regular' => 'Vendor Regular',
        'ledger_friend' => 'Ledger Friend',
        'market_stall' => 'Market Stallholder',
        'buyer_eye' => 'Buyer Eye',
        'trade_regular' => 'Trade Regular',
        'coin_chest' => 'Coin Chest',
        'treasury_key' => 'Treasury Key',
        'realm_fortune' => 'Hall Fortune',
        'quartermaster' => 'Quartermaster',
        'warehouse_mind' => 'Warehouse Mind',
        'collector_shelf' => 'Collector Shelf',
        'rare_keeper' => 'Rare Keeper',
        'apprentice_spark' => 'Kindled Spark',
        'journeyman_spark' => 'Journeyman Spark',
        'expert_spark' => 'Expert Spark',
        'master_spark' => 'Master Spark',
        'legend_spark' => 'Legend Spark',
        'level_100_oath' => 'Level 100 Vow',
        'broad_training' => 'Broad Training',
        'polymath_path' => 'Polymath Path',
        'full_slate' => 'Full Slate',
        'skill_quiver' => 'Skill Quiver',
        'mastery_circle' => 'Mastery Circle',
        'realm_mastery' => 'Hall Mastery',
        'combat_recruit' => 'Combat Recruit',
        'threat_breaker' => 'Threat Breaker',
        'battle_company' => 'Battle Company',
        'hunter_edge' => 'Hunter Edge',
        'social_foothold' => 'Social Foothold',
        'known_name' => 'Known Name',
        'realm_regular' => 'Realm Regular',
        'realm_veteran' => 'Realm Veteran',
    ];

    /**
     * @var array<int, string>
     */
    private const ACCOUNT_TITLES = [
        1 => 'First Ledger Line',
        5 => 'Wayside Regular',
        10 => 'Moonwake Name',
        20 => 'Hearth Board Citizen',
        30 => 'Runic Account Keeper',
        40 => 'Stormbreak Veteran',
        50 => 'Highroad Patron',
        65 => 'Oldhall Fixture',
        80 => 'Gate Ledger Master',
        100 => 'First Hall Name',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const SKILL_TITLES = [
        'fishing' => [1 => 'Riverbank Caster', 5 => 'Reedline Angler', 10 => 'Coastal Shoal Reader', 20 => 'Harbor Netter', 30 => 'Blueclaw Pot Hand', 40 => 'Glasswater Sounder', 50 => 'Deep Ray Skipper', 65 => 'Kelp Trench Hauler', 80 => 'Leviathan Wake Drifter', 100 => 'First Tide Angler'],
        'mining' => [1 => 'Surface Miner', 5 => 'Ore Sorter', 10 => 'Coal Seam Hand', 20 => 'Bog Iron Assayer', 30 => 'Hematite Splitter', 40 => 'Fulgurite Tapper', 50 => 'Deepvein Cutter', 65 => 'Star-Iron Delver', 80 => 'Gate Quarry Mason', 100 => 'First Forge Breaker'],
        'woodcutting' => [1 => 'Trail Tree Cutter', 5 => 'Bark Sorter', 10 => 'Hardwood Feller', 20 => 'Coppice Marker', 30 => 'Knotpine Puller', 40 => 'Thunderheart Trimmer', 50 => 'Amberheart Notcher', 65 => 'Elder Yew Sawyer', 80 => 'Gatewood Axeman', 100 => 'First Grove Warden'],
        'foraging' => [1 => 'Trail Herb Picker', 5 => 'Moss Sorter', 10 => 'Mushroom Ring Hand', 20 => 'Bitterleaf Gatherer', 30 => 'Redroot Puller', 40 => 'Glasscap Cutter', 50 => 'Orchid Surveyor', 65 => 'Dreamroot Walker', 80 => 'Gatecap Forager', 100 => 'First Bloom Keeper'],
        'hunting' => [1 => 'Small Trap Setter', 5 => 'Track Sorter', 10 => 'Trail Tracker', 20 => 'Brindle Hide Skiver', 30 => 'Sinew Field Hand', 40 => 'Whitejaw Bone Hunter', 50 => 'Ridgeback Pursuer', 65 => 'Greatbeast Stalker', 80 => 'Crownbeast Trailer', 100 => 'First Hunt Tracker'],
        'farming' => [1 => 'Garden Plot Hand', 5 => 'Seed Sorter', 10 => 'Herb Bed Tender', 20 => 'Barley Row Harvester', 30 => 'Blueflax Puller', 40 => 'Rainbean Grower', 50 => 'Lanternfruit Watcher', 65 => 'Terrace Harvester', 80 => 'Gatefield Planter', 100 => 'First Harvest Master'],
        'excavation' => [1 => 'Old Mound Digger', 5 => 'Sherd Sorter', 10 => 'Bone Bed Sifter', 20 => 'Kiln Sherd Reader', 30 => 'Boundary Tablet Lifter', 40 => 'Gatehouse Sifter', 50 => 'Vault Plaque Keeper', 65 => 'Reliquary Delver', 80 => 'Gate Archive Reader', 100 => 'First City Delver'],
        'smelting' => [1 => 'Copper Bar Tender', 5 => 'Slag Sorter', 10 => 'Coal-Efficient Smelter', 20 => 'Charcoal Bloom Worker', 30 => 'Hematite Bloom Master', 40 => 'Lightning Bloom Pourer', 50 => 'Deepvein Ingot Maker', 65 => 'Star-Iron Refiner', 80 => 'Gateglass Furnace Keeper', 100 => 'First Forge Chemist'],
        'milling' => [1 => 'Rough Plank Sawyer', 5 => 'Offcut Sorter', 10 => 'Bark Sheet Planer', 20 => 'Pegged Pine Miller', 30 => 'Knotpine Boardwright', 40 => 'Weather-Seal Sawyer', 50 => 'Amberheart Beamwright', 65 => 'Elder Yew Miller', 80 => 'Gatewood Sawyer', 100 => 'First Grove Sawyer'],
        'tanning' => [1 => 'Rawhide Washer', 5 => 'Vat Sorter', 10 => 'Cured Hide Worker', 20 => 'Brindle Panel Maker', 30 => 'Sinew Backer', 40 => 'Whitejaw Scale Currier', 50 => 'Ridgeback Leatherworker', 65 => 'Greatbeast Currier', 80 => 'Crownbeast Hidewright', 100 => 'First Hunt Hide Master'],
        'cutting' => [1 => 'Rough Gem Cutter', 5 => 'Chip Sorter', 10 => 'Polishing Wheel Hand', 20 => 'Socket Gem Cutter', 30 => 'Hematite Lens Grinder', 40 => 'Fulgurite Prism Splitter', 50 => 'Deepvein Jewel Cutter', 65 => 'Starfacet Lapidary', 80 => 'Gateglass Gemwright', 100 => 'First Light Faceter'],
        'weaving' => [1 => 'Rough Cloth Weaver', 5 => 'Thread Sorter', 10 => 'Bundle Spinner', 20 => 'Linen Bolt Weaver', 30 => 'Blueflax Sailcloth Maker', 40 => 'Rainthread Loomhand', 50 => 'Lanternsilk Weaver', 65 => 'Starloom Threader', 80 => 'Gate-Dye Loomkeeper', 100 => 'First Dawn Weaver'],
        'smithing' => [1 => 'Field Fittings Smith', 5 => 'Component Fitter', 10 => 'Tool Blank Smith', 20 => 'Gate Nail Forger', 30 => 'Hematite Toolsmith', 40 => 'Storm-Forged Edgemaker', 50 => 'Deepvein Guardplater', 65 => 'Meteor Edge Smith', 80 => 'Gateforged Armorer', 100 => 'First Anvil Saint'],
        'carpentry' => [1 => 'Handle Fitter', 5 => 'Component Joiner', 10 => 'Simple Chair Maker', 20 => 'Pegged Frame Carpenter', 30 => 'Knotpine Cratewright', 40 => 'Weatherbow Staver', 50 => 'Amberheart Joiner', 65 => 'Livingwood Carpenter', 80 => 'Gatewood Master', 100 => 'First Grove Carpenter'],
        'cooking' => [1 => 'Simple Meal Cook', 5 => 'Pantry Fitter', 10 => 'Ration Kettle Hand', 20 => 'Harbor Rationer', 30 => 'Blueflax Baker', 40 => 'Rainbean Stew Cook', 50 => 'Lanternfruit Feastmaker', 65 => 'Starfeast Chef', 80 => 'Gatehouse Banqueter', 100 => 'First Hearth Chef'],
        'alchemy' => [1 => 'Field Tonic Brewer', 5 => 'Vial Fitter', 10 => 'Gathering Oil Maker', 20 => 'Bitterleaf Steeper', 30 => 'Redroot Mixer', 40 => 'Glasscap Catalyst Keeper', 50 => 'Orchid Still Brewer', 65 => 'Dreamroot Alchemist', 80 => 'Gateglass Transmuter', 100 => 'First Still Master'],
        'tailoring' => [1 => 'Cloth Wrap Stitcher', 5 => 'Needle Fitter', 10 => 'Bag Maker', 20 => 'Linen Pattern Cutter', 30 => 'Blueflax Satchel Maker', 40 => 'Rainthread Robe Tailor', 50 => 'Lanternsilk Cutter', 65 => 'Starthread Tailor', 80 => 'Gate-Dye Regalia Maker', 100 => 'First Needle Couturier'],
        'leatherworking' => [1 => 'Pouch Stitcher', 5 => 'Awl Fitter', 10 => 'Tool Belt Maker', 20 => 'Brindle Strap Cutter', 30 => 'Sinew Belt Maker', 40 => 'Whitejaw Saddle Stocker', 50 => 'Ridgeback Harness Maker', 65 => 'Greatbeast Harnesswright', 80 => 'Crownbeast Saddler', 100 => 'First Hunt Leatherworker'],
        'engineering' => [1 => 'Mechanism Tinkerer', 5 => 'Caliper Fitter', 10 => 'Trap Mechanic', 20 => 'Brass Spring Builder', 30 => 'Hematite Gearsmith', 40 => 'Storm-Tuned Triggerer', 50 => 'Deepvein Device Maker', 65 => 'Elder Gear Engineer', 80 => 'Gatehouse Prototyper', 100 => 'First Clockwork Chief'],
        'enchanting' => [1 => 'Minor Charm Washer', 5 => 'Ward Fitter', 10 => 'Trait Oil Scribe', 20 => 'Bitterleaf Warder', 30 => 'Boundary Rune Washer', 40 => 'Glasscap Ink Binder', 50 => 'Vaultlight Infuser', 65 => 'Awakened Rune Binder', 80 => 'Gate Script Enchanter', 100 => 'First Ward Binder'],
        'jewelcrafting' => [1 => 'Copper Setting Hand', 5 => 'Bezel Fitter', 10 => 'Ring Maker', 20 => 'Copper Bezel Setter', 30 => 'Hematite Mount Jeweler', 40 => 'Fulgurite Lens Setter', 50 => 'Deepvein Amulet Maker', 65 => 'Starfacet Jeweler', 80 => 'Gateglass Diadem Maker', 100 => 'First Light Jeweler'],
        'boatbuilding' => [1 => 'Raft Ribber', 5 => 'Keel Fitter', 10 => 'Skiff Builder', 20 => 'Harbor Rib Maker', 30 => 'Knotpine Keelwright', 40 => 'Weather-Seal Hullwright', 50 => 'Amberheart Shipwright', 65 => 'Stormfleet Keeler', 80 => 'Gatewater Hullwright', 100 => 'First Tide Shipwright'],
        'furniture' => [1 => 'Stool Sanding Hand', 5 => 'Finish Fitter', 10 => 'Table Maker', 20 => 'Pegged Hall Maker', 30 => 'Knotpine Standwright', 40 => 'Weathered Trophy Fitter', 50 => 'Amberheart Hallwright', 65 => 'Royal Suite Joiner', 80 => 'Gatehall Carver', 100 => 'First Hall Architect'],
        'construction' => [1 => 'Repair Crew Hand', 5 => 'Plumbline Fitter', 10 => 'Station Builder', 20 => 'Lime Frame Builder', 30 => 'Boundary Wall Bracer', 40 => 'Fulgurite Watch Mason', 50 => 'Deepvein Bridgewright', 65 => 'Citadel Wall Framer', 80 => 'Gatehouse Founder', 100 => 'First City Builder'],
        'combat' => [1 => 'Guard Cut Recruit', 5 => 'Role Drill Fighter', 10 => 'Stance Student', 20 => 'Vanguard Driller', 30 => 'Field Assignment Veteran', 40 => 'Champion Yard Fighter', 50 => 'Warband Scrapper', 65 => 'Realmguard Challenger', 80 => 'Champion Trialist', 100 => 'First Ring Champion'],
        'slayer' => [1 => 'Trail Mark Tracker', 5 => 'Fang Study Hand', 10 => 'Weakness Reader', 20 => 'Bounty Line Hunter', 30 => 'Nightfang Preparer', 40 => 'Greatbeast Marker', 50 => 'Monster Bane Driller', 65 => 'Crownbeast Warrant Hunter', 80 => 'Apex Trophy Hunter', 100 => 'First Hunt Bane'],
        'defense' => [1 => 'Guard Brace Recruit', 5 => 'Shield Drill Hand', 10 => 'Shield Worker', 20 => 'Gate Brace Holder', 30 => 'Armor Hold Veteran', 40 => 'Bulwark Post Guard', 50 => 'Wallbreaker Holder', 65 => 'Citadel Bulwark', 80 => 'Unbroken Line Keeper', 100 => 'First Wall Defender'],
        'healing' => [1 => 'First Aid Hand', 5 => 'Triage Driller', 10 => 'Tonic Medic', 20 => 'Triage Cart Runner', 30 => 'Recovery Tent Medic', 40 => 'Stabilizer Route Healer', 50 => 'Field Hospital Keeper', 65 => 'Expedition Medic', 80 => 'Renewal Warder', 100 => 'First Ward Lifekeeper'],
        'magic' => [1 => 'Spark Channeler', 5 => 'Ward Drill Student', 10 => 'Ward Circle Scribe', 20 => 'Ward Circle Adept', 30 => 'Element Focus Keeper', 40 => 'Arcane Weather Reader', 50 => 'Spellguard Worker', 65 => 'Ritual Circle Mage', 80 => 'Archmage Trialist', 100 => 'First Ward Archmage'],
        'ranged' => [1 => 'Simple Shot Archer', 5 => 'Range Drill Hand', 10 => 'Steady Aim Shooter', 20 => 'Bow Sightline Archer', 30 => 'Special Shot Caller', 40 => 'Trail Bow Refit Shooter', 50 => 'Skywatch Quiver Keeper', 65 => 'Marksman Trial Archer', 80 => 'Stormshot Ranger', 100 => 'First Perch Archer'],
        'exploration' => [1 => 'Local Path Walker', 5 => 'Field Note Scout', 10 => 'Regional Route Finder', 20 => 'Sketch Route Scout', 30 => 'Hidden Room Finder', 40 => 'Old Gate Walker', 50 => 'Worldwalker Waybill Bearer', 65 => 'Gate Route Surveyor', 80 => 'Lost Road Reader', 100 => 'First Horizon Walker'],
        'dungeoneering' => [1 => 'Room Check Delver', 5 => 'Trap Note Keeper', 10 => 'Trap Reader', 20 => 'Lower Room Checker', 30 => 'Party Route Delver', 40 => 'Dungeon Auditor', 50 => 'Vault Key Reporter', 65 => 'Labyrinth Router', 80 => 'Vault Descent Delver', 100 => 'First Deep Warden'],
        'sailing' => [1 => 'Dock Rope Hand', 5 => 'Sea Note Keeper', 10 => 'Coastal Trip Sailor', 20 => 'Dock Rope Runner', 30 => 'Cargo Manifest Sailor', 40 => 'Harbor Signal Caller', 50 => 'Tide Captain Lot Hand', 65 => 'Expedition Sailmaster', 80 => 'Stormroute Crosser', 100 => 'First Tide Captain'],
        'survival' => [1 => 'Camp Basics Hand', 5 => 'Field Note Camper', 10 => 'Weather Reader', 20 => 'Weather Route Scout', 30 => 'Long Trip Supplier', 40 => 'Campcraft Ledger Keeper', 50 => 'Last Light Cache Finder', 65 => 'Wild Marcher', 80 => 'Hostile Wilds Walker', 100 => 'First Last-Light Keeper'],
        'cartography' => [1 => 'Sketch Map Maker', 5 => 'Survey Note Keeper', 10 => 'Resource Marker', 20 => 'Survey Note Draftsman', 30 => 'Dungeon Chart Maker', 40 => 'Survey Parcel Mapper', 50 => 'Starmapper Grid Hand', 65 => 'Navigator Archivist', 80 => 'Secret Atlas Keeper', 100 => 'First Star Mapper'],
        'reputation' => [1 => 'Local Notice Runner', 5 => 'Favor Note Keeper', 10 => 'Faction Errand Hand', 20 => 'Favor Seal Courier', 30 => 'Rate Petition Advocate', 40 => 'Title Claim Speaker', 50 => 'Realm Favor Holder', 65 => 'Envoy Hearing Voice', 80 => 'Council Seat Candidate', 100 => 'First Concord Envoy'],
        'leadership' => [1 => 'Party Caller', 5 => 'Muster Note Keeper', 10 => 'Small Team Lead', 20 => 'Party Call Captain', 30 => 'Oathhall Taskmaster', 40 => 'Banner Drill Leader', 50 => 'Command Tent Officer', 65 => 'War Table Marshal', 80 => 'Campaign Standard Bearer', 100 => 'First Bannerlord'],
        'trading' => [1 => 'Market Lister', 5 => 'Price Note Keeper', 10 => 'Bulk Listing Broker', 20 => 'Token Exchange Clerk', 30 => 'Work Packet Broker', 40 => 'Route Manifest Trader', 50 => 'Merchant Seal Holder', 65 => 'Market Ledger Keeper', 80 => 'Royal Exchange Broker', 100 => 'First Market Sovereign'],
    ];

    public function titleFor(string $key, string $label, int $level, SkillCatalogService $catalog): string
    {
        if (array_key_exists($key, self::BASE_TITLES)) {
            return self::BASE_TITLES[$key];
        }

        if (preg_match('/^account_level_(\d+)$/', $key, $matches) === 1) {
            return self::ACCOUNT_TITLES[(int) $matches[1]]
                ?? $this->fallbackTieredTitle('Account', (int) $matches[1]);
        }

        if (preg_match('/^skill_milestone_([a-z_]+)_(\d+)$/', $key, $matches) === 1) {
            return $this->skillMilestoneTitle($matches[1], (int) $matches[2], $catalog);
        }

        if ($level > 0) {
            return $this->fallbackTieredTitle($label, $level);
        }

        return "{$label} Reward Title";
    }

    public function hasSkillMilestoneTitle(string $skill, int $level): bool
    {
        return isset(self::SKILL_TITLES[$skill][$level]);
    }

    private function skillMilestoneTitle(string $skill, int $level, SkillCatalogService $catalog): string
    {
        if (isset(self::SKILL_TITLES[$skill][$level])) {
            return self::SKILL_TITLES[$skill][$level];
        }

        $definition = $catalog->definition($skill);

        return $this->fallbackTieredTitle($definition['label'], $level);
    }

    private function fallbackTieredTitle(string $subject, int $level): string
    {
        return "{$subject} Level {$level} Title";
    }
}
