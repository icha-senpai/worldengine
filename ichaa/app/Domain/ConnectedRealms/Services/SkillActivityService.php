<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SkillActivityService
{
    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items, private WorldEventService $events, private ToolEffectService $toolEffects) {}

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $activityCache = null;

    /**
     * @var array<string, array{track: string, theme: string, activities: list<string>, location: string, rewards: list<array{key: string, name: string}>}>
     */
    private const ACTIVITY_FAMILIES = [
        'smelting' => ['track' => 'Forge Work', 'theme' => 'Emberdeep Bellows', 'activities' => ['Candlemark Crucible Warmup', 'Wayside Slag Pull', 'Moonwake Bloom Pour', 'Hearthsign Coal Blend', 'Runebound Alloy Reading', 'Stormglass Flux Pour', 'Highguild Crucible Charge', 'Elderwake Highguild Temper', 'Mythgate Worldforge Draft', 'Crownmark Eternal Heat'], 'location' => 'Emberdeep Forge Hall', 'rewards' => [['key' => 'slag_glass', 'name' => 'Slagglass'], ['key' => 'forge_credit', 'name' => 'Heat-Seal']]],
        'milling' => ['track' => 'Mill Work', 'theme' => 'Whisperbough Planer', 'activities' => ['Candlemark Saw Set', 'Wayside Bark Plane', 'Moonwake Plank Grading', 'Hearthsign Resin Board Press', 'Runebound Beam Squaring', 'Stormglass Heartwood Lathe', 'Highguild Precision Dowel', 'Elderwake Oathbeam Cut', 'Mythgate Livingwood Join', 'Crownmark Worldroot Mill'], 'location' => 'Whisperbough Millhouse', 'rewards' => [['key' => 'sawdust_bundle', 'name' => 'Sweetdust'], ['key' => 'mill_token', 'name' => 'Millwheel']]],
        'tanning' => ['track' => 'Tannery Work', 'theme' => 'Briarwake Cure Vat', 'activities' => ['Candlemark Hide Rinse', 'Wayside Tannin Steep', 'Moonwake Rack Turn', 'Hearthsign Scale Backing', 'Runebound Harness Cure', 'Stormglass Beastguard Oil', 'Highguild Monsterhide Stretch', 'Elderwake Apex Leather Cure', 'Mythgate Primal Hide Temper', 'Crownmark Worldhide Finish'], 'location' => 'Briarwake Tannery', 'rewards' => [['key' => 'tannin_vial', 'name' => 'Amber Tannin'], ['key' => 'tannery_token', 'name' => 'Hidewright']]],
        'cutting' => ['track' => 'Lapidary Work', 'theme' => 'Gemcutter Facet Table', 'activities' => ['Candlemark Chip Sorting', 'Wayside Rough Cut', 'Moonwake Socket Polish', 'Hearthsign Lens Grind', 'Runebound Prism Split', 'Stormglass Focus Cut', 'Highguild Geode Appraisal', 'Elderwake Starfacet Align', 'Mythgate Crown Facet', 'Crownmark First-Light Cut'], 'location' => 'Gemcutter Row', 'rewards' => [['key' => 'gem_dust', 'name' => 'Prism Gemdust'], ['key' => 'lapidary_credit', 'name' => 'Facet Chip']]],
        'weaving' => ['track' => 'Loom Work', 'theme' => 'Sunfield Shuttle Loom', 'activities' => ['Candlemark Thread Wind', 'Wayside Reed Weave', 'Moonwake Canvas Stretch', 'Hearthsign Silk Pass', 'Runebound Spellthread Spin', 'Stormglass Banner Loom', 'Highguild Moonweave Bolt', 'Elderwake Elderwake Threading', 'Mythgate Crown Loom Pattern', 'Crownmark Worldcloth Finish'], 'location' => 'Sunfield Loomhall', 'rewards' => [['key' => 'loom_thread', 'name' => 'Dyed Loomthread'], ['key' => 'weaver_token', 'name' => 'Shuttle-Knot']]],
        'smithing' => ['track' => 'Smithing Orders', 'theme' => 'Moonwake Anvil', 'activities' => ['Candlemark Rivet Set', 'Wayside Toolhead Forge', 'Moonwake Steel Frame', 'Hearthsign Armor Rivet', 'Runebound Pick Head', 'Stormglass Anvil Brief', 'Highguild Warplate Fit', 'Elderwake Meteor Edge', 'Mythgate Crown Temper', 'Crownmark Anvil Saint Work'], 'location' => 'Moonwake Anvil Yard', 'rewards' => [['key' => 'weapon_blank', 'name' => 'Tempered Blank'], ['key' => 'smith_mark', 'name' => 'Anvil-Seal']]],
        'carpentry' => ['track' => 'Carpentry Orders', 'theme' => 'Whisperbough Joinery', 'activities' => ['Candlemark Handle Fit', 'Wayside Bow Stave', 'Moonwake Crate Join', 'Hearthsign Expedition Timber', 'Runebound Oathhall Joinery', 'Stormglass Living Handle', 'Highguild Master Carpenter Writ', 'Elderwake Heartwood Frame', 'Mythgate Worldroot Join', 'Crownmark Dovetail Masterwork'], 'location' => 'Whisperbough Workshop', 'rewards' => [['key' => 'joinery_piece', 'name' => 'Dovetail Joinery'], ['key' => 'carpenter_mark', 'name' => 'Plane-Shave']]],
        'cooking' => ['track' => 'Kitchen Orders', 'theme' => 'Moonwake Hearthline', 'activities' => ['Candlemark Ration Kettle', 'Wayside Harbor Soup', 'Moonwake Skill Meal Prep', 'Hearthsign Hunter Feast', 'Runebound Raid Pantry', 'Stormglass Dusk Feast', 'Highguild Banquet Plating', 'Elderwake Starfeast Simmer', 'Mythgate Realm Chef Course', 'Crownmark Oathhall Banquet'], 'location' => 'Moonwake Hearthline', 'rewards' => [['key' => 'kitchen_scrap', 'name' => 'Seasoned Hearth'], ['key' => 'cook_mark', 'name' => 'Copper Ladle']]],
        'alchemy' => ['track' => 'Alchemy Orders', 'theme' => 'Glimmerfen Still', 'activities' => ['Candlemark Tonic Steep', 'Wayside Ward Oil Decant', 'Moonwake Combat Draught', 'Hearthsign Catalyst Measure', 'Runebound Bitterroot Remedy', 'Stormglass Reagent Vessel', 'Highguild Transmutation Flask', 'Elderwake Dreamroot Elixir', 'Mythgate Grand Still Work', 'Crownmark Alchemist Mandate'], 'location' => 'Glimmerfen Stillroom', 'rewards' => [['key' => 'catalyst_drop', 'name' => 'Bright Catalyst'], ['key' => 'alchemist_mark', 'name' => 'Stillroom']]],
        'tailoring' => ['track' => 'Tailoring Orders', 'theme' => 'Sunfield Stitchery', 'activities' => ['Candlemark Field Wrap Hem', 'Wayside Satchel Stitch', 'Moonwake Robe Panel', 'Hearthsign Sailcloth Cut', 'Runebound Banner Hem', 'Stormglass Spellcloth Fit', 'Highguild Court Outfit', 'Elderwake Elderwake Vestment', 'Mythgate Regalia Pattern', 'Crownmark Couture Finish'], 'location' => 'Sunfield Stitchery', 'rewards' => [['key' => 'pattern_scrap', 'name' => 'Chalked Pattern'], ['key' => 'tailor_mark', 'name' => 'Needle-Eye']]],
        'leatherworking' => ['track' => 'Leather Orders', 'theme' => 'Briarwake Strap Bench', 'activities' => ['Candlemark Pouch Stitch', 'Wayside Tool Belt Fit', 'Moonwake Armor Panel', 'Hearthsign Travel Harness', 'Runebound Monster Gear', 'Stormglass Saddle Stock', 'Highguild Rugged Kit Fit', 'Elderwake Beastguard Harness', 'Mythgate Apex Hide Cut', 'Crownmark Hide Artisan Work'], 'location' => 'Briarwake Leather Bench', 'rewards' => [['key' => 'strap_cutting', 'name' => 'Oiled Strap'], ['key' => 'leatherworker_mark', 'name' => 'Awl-Punched']]],
        'engineering' => ['track' => 'Engineering Orders', 'theme' => 'Clockwork Assembly Yard', 'activities' => ['Candlemark Spring Packet', 'Wayside Trap Mechanism', 'Moonwake Gadget Bench', 'Hearthsign Siege Gear', 'Runebound Engine Coupling', 'Stormglass Trigger Tuning', 'Highguild Survey Device', 'Elderwake Elderwake Engine', 'Mythgate Prototype Yard', 'Crownmark Chief Engineer Trial'], 'location' => 'Clockwork Yard', 'rewards' => [['key' => 'gear_shaving', 'name' => 'Brass Gear'], ['key' => 'engineer_mark', 'name' => 'Caliper']]],
        'enchanting' => ['track' => 'Enchanting Orders', 'theme' => 'Moon Ward Infusion', 'activities' => ['Candlemark Charm Wash', 'Wayside Ward Oil Script', 'Moonwake Socket Rune', 'Hearthsign Trait Binding', 'Runebound Relic Wake', 'Stormglass Major Enchantment', 'Highguild Arcane Seal', 'Elderwake Elderwake Binding', 'Mythgate Mythgate Rune', 'Crownmark Arcane Binder Rite'], 'location' => 'Moon Ward Annex', 'rewards' => [['key' => 'rune_dust', 'name' => 'Sung Rune Dust'], ['key' => 'enchanter_mark', 'name' => 'Ward-Script']]],
        'jewelcrafting' => ['track' => 'Jewelry Orders', 'theme' => 'Gemcutter Setting', 'activities' => ['Candlemark Copper Bezel', 'Wayside Silver Ring', 'Moonwake Trinket Socket', 'Hearthsign Amulet Frame', 'Runebound Focus Lens', 'Stormglass Gem Trial', 'Highguild Mythgate Lens', 'Elderwake Starfacet Setting', 'Mythgate Crown Beadwork', 'Crownmark Gem Sovereign Work'], 'location' => 'Gemcutter Row', 'rewards' => [['key' => 'setting_wire', 'name' => 'Fine Setting Wire'], ['key' => 'jeweler_mark', 'name' => 'Loupe-Etched']]],
        'boatbuilding' => ['track' => 'Shipwright Orders', 'theme' => 'Moonwake Keel Bench', 'activities' => ['Candlemark Skiff Rib', 'Wayside Reed Float', 'Moonwake Cargo Hull', 'Hearthsign Sail Frame', 'Runebound Dockwright Timber', 'Stormglass Expedition Hull', 'Highguild Fleet Refit', 'Elderwake Stormfleet Keel', 'Mythgate Harbor Master Trial', 'Crownmark Shipwright Mandate'], 'location' => 'Moonwake Drydock', 'rewards' => [['key' => 'pitch_bucket', 'name' => 'Resin Pitch'], ['key' => 'shipwright_mark', 'name' => 'Keelstamp']]],
        'furniture' => ['track' => 'Furniture Orders', 'theme' => 'Hallwright Finish Table', 'activities' => ['Candlemark Stool Sanding', 'Wayside Table Fit', 'Moonwake Display Stand', 'Hearthsign Trophy Hall', 'Runebound Oathhall Fixture', 'Stormglass Prestige Fitting', 'Highguild Carved Crate', 'Elderwake Royal Suite Joinery', 'Mythgate Hall Architect Plan', 'Crownmark Grand Hall Finish'], 'location' => 'Oathhall Woodshop', 'rewards' => [['key' => 'varnish_pot', 'name' => 'Amber Varnish'], ['key' => 'furnisher_mark', 'name' => 'Carved Maker']]],
        'construction' => ['track' => 'Construction Orders', 'theme' => 'Settlement Frame Crew', 'activities' => ['Candlemark Signpost Brace', 'Wayside Station Repair', 'Moonwake Workshop Frame', 'Hearthsign Bridge Scaffold', 'Runebound Foundation Brief', 'Stormglass Watchtower Stone', 'Highguild Settlement Works', 'Elderwake Citadel Wall', 'Mythgate Realm Builder Survey', 'Crownmark Worldhall Raise'], 'location' => 'Settlement Works Yard', 'rewards' => [['key' => 'mason_chit', 'name' => 'Stonewise Mason'], ['key' => 'builder_mark', 'name' => 'Plumbline']]],
        'combat' => ['track' => 'Combat Drills', 'theme' => 'Moonwake Sparring', 'activities' => ['Candlemark Guard Cut', 'Wayside Footwork Round', 'Moonwake Stance Exchange', 'Hearthsign Vanguard Drill', 'Runebound Field Assignment', 'Stormglass Champion Trial', 'Highguild Warband Scrimmage', 'Elderwake Realmguard Trial', 'Mythgate Crown Duel', 'Crownmark Realm Champion Bout'], 'location' => 'Moonwake Training Ring', 'rewards' => [['key' => 'combat_badge', 'name' => 'Sparring Notch'], ['key' => 'training_blade', 'name' => 'Blunted Practice']]],
        'slayer' => ['track' => 'Slayer Marks', 'theme' => 'Briarwake Bounty', 'activities' => ['Candlemark Fang Study', 'Wayside Bounty Pin', 'Moonwake Weakness Read', 'Hearthsign Stalker Report', 'Runebound Nightfang Prep', 'Stormglass Greatbeast Mark', 'Highguild Monster Bane Drill', 'Elderwake Crownbeast Warrant', 'Mythgate Apex Trophy Claim', 'Crownmark First Hunt Trial'], 'location' => 'Briarwake Bounty Board', 'rewards' => [['key' => 'slayer_mark', 'name' => 'Fang-Etched Bounty'], ['key' => 'monster_trophy', 'name' => 'Salted Trophy']]],
        'defense' => ['track' => 'Guard Rotations', 'theme' => 'Old Gate Shieldline', 'activities' => ['Candlemark Shield Brace', 'Wayside Field Repair', 'Moonwake Armor Mastery', 'Hearthsign Party Guard', 'Runebound Bulwark Supply', 'Stormglass Dungeon Guard', 'Highguild Wallbreaker Hold', 'Elderwake Citadel Bulwark', 'Mythgate Unbroken Line', 'Crownmark Last Wall Stand'], 'location' => 'Old Gate Shield Line', 'rewards' => [['key' => 'defense_badge', 'name' => 'Shieldwall Rivet'], ['key' => 'shield_plate', 'name' => 'Dented Shield']]],
        'healing' => ['track' => 'Medic Rounds', 'theme' => 'Moonwake Triage', 'activities' => ['Candlemark Bandage Pack', 'Wayside Sap Tonic', 'Moonwake Recovery Round', 'Hearthsign Medic Kit', 'Runebound Stabilizer Vial', 'Stormglass Field Hospital', 'Highguild Revival Rite', 'Elderwake Lifewarden Supply', 'Mythgate Renewal Ward', 'Crownmark Life Warden Call'], 'location' => 'Moonwake Infirmary', 'rewards' => [['key' => 'healing_writ', 'name' => 'Clean-Bandage'], ['key' => 'medic_satchel', 'name' => 'Packed Medic']]],
        'magic' => ['track' => 'Arcane Trials', 'theme' => 'Moon Ward Channel', 'activities' => ['Candlemark Spark Channel', 'Wayside Ward Circle', 'Moonwake Elemental Focus', 'Hearthsign Ritual Night', 'Runebound Storm Report', 'Stormglass Rune Reading', 'Highguild Spellguard Work', 'Elderwake Elderwake Rite', 'Mythgate Archmage Trial', 'Crownmark Starward Channel'], 'location' => 'Moon Ward Circle', 'rewards' => [['key' => 'magic_seal', 'name' => 'Moonlit Casting'], ['key' => 'focus_shard', 'name' => 'Attuned Focus']]],
        'ranged' => ['track' => 'Range Trials', 'theme' => 'High Perch Marksmanship', 'activities' => ['Candlemark Bow Sighting', 'Wayside Arrow Stock', 'Moonwake Special Shot', 'Hearthsign Siege Range', 'Runebound Trail Bow Refit', 'Stormglass Trick Shot', 'Highguild Marksman Trial', 'Elderwake Stormshot Practice', 'Mythgate Sky Archer Drill', 'Crownmark High Perch Volley'], 'location' => 'High Perch Range', 'rewards' => [['key' => 'ranged_mark', 'name' => 'Feathered Score'], ['key' => 'fletching_bundle', 'name' => 'Waxed Fletching']]],
        'exploration' => ['track' => 'Scout Routes', 'theme' => 'Hidden Mile Scout', 'activities' => ['Candlemark Sketch Route', 'Wayside Regional Path', 'Moonwake Hidden Room', 'Hearthsign Distant Trail', 'Runebound Ancient Gate', 'Stormglass Frontier Proof', 'Highguild Worldwalker Waybill', 'Elderwake Gate Warrant', 'Mythgate Lost Road Reading', 'Crownmark Horizon Walk'], 'location' => 'Hidden Mile Route', 'rewards' => [['key' => 'explorer_badge', 'name' => 'Trail-Etched Compass'], ['key' => 'route_note', 'name' => 'Weathered Route']]],
        'dungeoneering' => ['track' => 'Dungeon Rooms', 'theme' => 'Lower Vault Breach', 'activities' => ['Candlemark Room Check', 'Wayside Trap Read', 'Moonwake Party Route', 'Hearthsign Boss Room Supply', 'Runebound Dungeon Audit', 'Stormglass Deep Chamber', 'Highguild Vault Key Report', 'Elderwake Labyrinth Writ', 'Mythgate Deep Warden Trial', 'Crownmark Lower Vault Crown'], 'location' => 'Lower Vault Wing', 'rewards' => [['key' => 'vault_key', 'name' => 'Notched Vault'], ['key' => 'trap_diagram', 'name' => 'Smudged Trap']]],
        'sailing' => ['track' => 'Sea Routes', 'theme' => 'Stormbreak Helm', 'activities' => ['Candlemark Dock Rope', 'Wayside Coastal Trip', 'Moonwake Cargo Manifest', 'Hearthsign Fleet Support', 'Runebound Sea Chart', 'Stormglass Harbor Signal', 'Highguild Tide Captain Lot', 'Elderwake Stormroute Warrant', 'Mythgate Expedition Sail', 'Crownmark Tide Captain Crossing'], 'location' => 'Stormbreak Channel', 'rewards' => [['key' => 'sailing_writ', 'name' => 'Brine-Sealed Sailing'], ['key' => 'tide_chart', 'name' => 'Saltcurl Tide']]],
        'survival' => ['track' => 'Survival Circuits', 'theme' => 'Cold Camp Trial', 'activities' => ['Candlemark Flatbread Cache', 'Wayside Weather Read', 'Moonwake Long Trip Supply', 'Hearthsign Hazard Kit', 'Runebound Hostile Region', 'Stormglass Campcraft Ledger', 'Highguild Last Light Cache', 'Elderwake Wild March', 'Mythgate Hostile Wilds', 'Crownmark Last Light March'], 'location' => 'Cold Camp Circuit', 'rewards' => [['key' => 'survival_mark', 'name' => 'Smoke-Cured Survival'], ['key' => 'camp_cache', 'name' => 'Waxcloth Camp']]],
        'cartography' => ['track' => 'Survey Work', 'theme' => 'Surveyor Ridge Charting', 'activities' => ['Candlemark Survey Note', 'Wayside Route Map', 'Moonwake Dungeon Chart', 'Hearthsign Region Atlas', 'Runebound Secret Road', 'Stormglass Survey Parcel', 'Highguild Starmapper Grid', 'Elderwake Navigator Archive', 'Mythgate Secret Atlas', 'Crownmark Star Map Draft'], 'location' => 'Surveyor Ridge', 'rewards' => [['key' => 'survey_writ', 'name' => 'Brass Survey'], ['key' => 'map_fragment', 'name' => 'Ink-Rubbed Map']]],
        'reputation' => ['track' => 'Faction Work', 'theme' => 'Council Errand', 'activities' => ['Candlemark Barter Note', 'Wayside Favor Seal', 'Moonwake Rate Petition', 'Hearthsign Council Gift', 'Runebound Title Claim', 'Stormglass Envoy Introduction', 'Highguild Realm Favor', 'Elderwake Council Seat Case', 'Mythgate Realm Envoy Hearing', 'Crownmark Concord Address'], 'location' => 'Regional Council Board', 'rewards' => [['key' => 'faction_seal', 'name' => 'Pressed Faction'], ['key' => 'favor_note', 'name' => 'Handwritten Favor']]],
        'leadership' => ['track' => 'Crew Commands', 'theme' => 'Muster Command', 'activities' => ['Candlemark Crate Muster', 'Wayside Party Call', 'Moonwake Oathhall Task', 'Hearthsign Raid Brief', 'Runebound Banner Drill', 'Stormglass Campaign Writ', 'Highguild Command Tent', 'Elderwake Standard Warrant', 'Mythgate War Table Mandate', 'Crownmark Bannerlord Call'], 'location' => 'Oathhall Muster Yard', 'rewards' => [['key' => 'crew_banner', 'name' => 'Hemmed Crew'], ['key' => 'order_sheet', 'name' => 'Signed Order']]],
        'trading' => ['track' => 'Trade Routes', 'theme' => 'Regional Brokerage', 'activities' => ['Candlemark Market Token', 'Wayside Bulk Listing', 'Moonwake Work Packet', 'Hearthsign Storefront Stock', 'Runebound Route Manifest', 'Stormglass Arbitrage Writ', 'Highguild Merchant Seal', 'Elderwake Royal Exchange', 'Mythgate Sovereign Counter', 'Crownmark Market Oath'], 'location' => 'Regional Brokerage', 'rewards' => [['key' => 'trade_writ', 'name' => 'Stamped Trade'], ['key' => 'ledger_page', 'name' => 'Price-Scratched Page']]],
    ];

    /**
     * @return list<string>
     */
    public static function activityKeys(): array
    {
        return array_keys(self::activities());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActivitiesFor(ConnectedRealmsPlayer $player): array
    {
        return collect(self::activities())
            ->map(function (array $activity, string $key) use ($player): array {
                $requiredLevel = (int) $activity['required_level'];
                $skillLevel = $this->players->currentSkillLevel($player, $activity['skill']);
                $tool = $this->players->equipmentForSkill($player, $activity['skill']);

                return [
                    'key' => $key,
                    'label' => $activity['label'],
                    'track' => $activity['track'],
                    'activity_type' => $activity['activity_type'],
                    'band' => $activity['band'],
                    'skill' => $activity['skill'],
                    'skill_label' => str($activity['skill'])->headline()->toString(),
                    'category' => $activity['category'],
                    'location' => $activity['location'],
                    'description' => $activity['description'],
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
                    'is_unlocked' => $skillLevel >= $requiredLevel,
                    'cooldown_seconds' => $this->cooldownSecondsFor($activity),
                    'experience' => $activity['experience'],
                    'gold' => $activity['gold'],
                    'loot_preview' => $this->items->enrichMany($activity['loot']),
                    'equipped_tool' => $this->players->toolPayload($tool),
                    'active_event' => $this->events->bonusForSkill($activity['skill'], 'activity'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function perform(User $user, string $activityKey, string $platform = 'website'): array
    {
        $activity = self::activities()[$activityKey] ?? null;

        if ($activity === null) {
            throw ValidationException::withMessages([
                'activity' => 'That Evergather activity is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $activityKey, $platform, $activity): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($player->next_action_at !== null && $player->next_action_at->isFuture()) {
                throw ValidationException::withMessages([
                    'activity' => 'Your next action is available '.$player->next_action_at->diffForHumans().'.',
                ]);
            }

            $requiredLevel = (int) $activity['required_level'];

            if ($this->players->currentSkillLevel($player, $activity['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'activity' => "You need level {$requiredLevel} ".str($activity['skill'])->headline()->toString().' for that activity.',
                ]);
            }

            $tool = $this->players->equipmentForSkill($player, $activity['skill']);
            $toolModifiers = $this->toolEffects->actionModifiers($tool);
            $eventBonus = $this->events->bonusForSkill($activity['skill'], 'activity');
            $experienceAwarded = random_int($activity['experience']['min'], $activity['experience']['max'])
                + $toolModifiers['experience']
                + max(0, (int) ($eventBonus['experience'] ?? 0));
            $goldAwarded = random_int($activity['gold']['min'], $activity['gold']['max'])
                + $toolModifiers['gold']
                + max(0, (int) ($eventBonus['gold'] ?? 0));
            $itemsAwarded = $this->rollLoot($activity['loot'], $toolModifiers['yield'] + max(0, (int) ($eventBonus['yield'] ?? 0)));
            $availableAt = now()->addSeconds($this->cooldownSecondsFor($activity, $toolModifiers['cooldown_reduction']));

            $this->players->awardSkillExperience($player, $activity['skill'], $experienceAwarded);

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
                'action' => $activityKey,
                'skill' => $activity['skill'],
                'platform' => $platform,
                'result_label' => $activity['location'],
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
                'type' => 'skill_activity',
                'id' => $log->id,
                'activity' => $activityKey,
                'label' => $activity['label'],
                'track' => $activity['track'],
                'band' => $activity['band'],
                'skill' => $activity['skill'],
                'skill_label' => str($activity['skill'])->headline()->toString(),
                'location' => $activity['location'],
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
     * @param  list<array{item_key: string, item_name: string, rarity: string, quantity: int, chance: int}>  $loot
     * @return list<array<string, mixed>>
     */
    private function rollLoot(array $loot, int $yieldBonus): array
    {
        return collect($loot)
            ->map(function (array $item) use ($yieldBonus): ?array {
                if (random_int(1, 100) > $item['chance']) {
                    return null;
                }

                return $this->items->enrich([
                    'item_key' => $item['item_key'],
                    'item_name' => $item['item_name'],
                    'rarity' => $item['rarity'],
                    'quantity' => $item['quantity'] + max(0, $yieldBonus),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function cooldownSecondsFor(array $activity, int $cooldownReduction = 0): int
    {
        $override = config('connected_realms.action_cooldown_seconds');

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        $baseCooldown = (int) $activity['cooldown_seconds'];

        return max(1, (int) floor($baseCooldown * ((100 - min(80, max(0, $cooldownReduction))) / 100)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function activities(): array
    {
        if (self::$activityCache !== null) {
            return self::$activityCache;
        }

        $skillDefinitions = collect(app(SkillCatalogService::class)->all())->keyBy('key');
        $activities = [];

        foreach (self::ACTIVITY_FAMILIES as $skill => $family) {
            $category = (string) ($skillDefinitions->get($skill)['category'] ?? 'General');

            foreach (EvergatherTierCatalog::tiers() as $tier) {
                $key = str("{$skill} {$tier['key_slug']} activity {$tier['level']}")->slug('_')->toString();
                $activities[$key] = self::activity($skill, $category, $family, $tier);
            }
        }

        self::$activityCache = $activities;

        return self::$activityCache;
    }

    /**
     * @param  array{track: string, theme: string, activities: list<string>, location: string, rewards: list<array{key: string, name: string}>}  $family
     * @param  array{level: int, band: string, key_slug: string, mark: string, station: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}  $tier
     * @return array<string, mixed>
     */
    private static function activity(string $skill, string $category, array $family, array $tier): array
    {
        $skillLabel = str($skill)->headline()->toString();
        $primaryReward = $family['rewards'][0];
        $secondaryReward = $family['rewards'][1];

        return [
            'label' => self::activityLabelFor($family, $tier),
            'track' => $family['track'],
            'activity_type' => "{$category} Activity",
            'band' => $tier['band'],
            'skill' => $skill,
            'category' => $category,
            'location' => "{$family['location']} - {$tier['station']}",
            'description' => self::descriptionFor($skillLabel, $family['track'], $tier['band'], $family['theme']),
            'required_level' => $tier['level'],
            'cooldown_seconds' => $tier['cooldown'],
            'experience' => ['min' => $tier['experience'][0], 'max' => $tier['experience'][1]],
            'gold' => ['min' => $tier['gold'][0], 'max' => $tier['gold'][1]],
            'loot' => [
                [
                    'item_key' => str("{$skill} {$tier['mark']} {$primaryReward['key']} {$tier['level']}")->slug('_')->toString(),
                    'item_name' => self::activityRewardName($primaryReward['name'], $tier, 0),
                    'rarity' => $tier['rarity'],
                    'quantity' => $tier['level'] >= 50 ? 2 : 1,
                    'chance' => 100,
                ],
                [
                    'item_key' => str("{$skill} {$tier['mark']} {$secondaryReward['key']} {$tier['level']}")->slug('_')->toString(),
                    'item_name' => self::activityRewardName($secondaryReward['name'], $tier, 1),
                    'rarity' => $tier['level'] >= 80 ? 'epic' : ($tier['level'] >= 30 ? 'rare' : 'uncommon'),
                    'quantity' => 1,
                    'chance' => $tier['level'] >= 80 ? 55 : 70,
                ],
            ],
        ];
    }

    /**
     * @param  array{activities: list<string>}  $family
     * @param  array{key_slug: string}  $tier
     */
    private static function activityLabelFor(array $family, array $tier): string
    {
        $index = self::tierIndex($tier['key_slug']);

        return $family['activities'][$index] ?? $family['activities'][0];
    }

    /**
     * @param  array{key_slug: string}  $tier
     */
    private static function activityRewardName(string $baseName, array $tier, int $rewardIndex): string
    {
        $forms = [
            'starter' => ['Candlemark %s Sample', 'Candlemark %s Tag'],
            'local' => ['Wayside %s Bundle', 'Wayside %s Notch'],
            'apprentice' => ['Moonwake %s Proof', 'Moonwake %s Mark'],
            'guild' => ['Hearthsign %s Packet', 'Hearthsign %s Chit'],
            'runed' => ['Runebound %s Etching', 'Runebound %s Seal'],
            'storm' => ['Stormglass %s Shard', 'Stormglass %s Sigil'],
            'elite' => ['Highguild %s Cache', 'Highguild %s Warrant'],
            'elder' => ['Elderwake %s Relic', 'Elderwake %s Crest'],
            'mythic' => ['Mythgate %s Core', 'Mythgate %s Oath'],
            'evergather' => ['Crownmark %s Crownpiece', 'Crownmark %s Testament'],
        ];

        return sprintf($forms[$tier['key_slug']][$rewardIndex] ?? '%s', $baseName);
    }

    private static function tierIndex(string $keySlug): int
    {
        return match ($keySlug) {
            'local' => 1,
            'apprentice' => 2,
            'guild' => 3,
            'runed' => 4,
            'storm' => 5,
            'elite' => 6,
            'elder' => 7,
            'mythic' => 8,
            'evergather' => 9,
            default => 0,
        };
    }

    private static function descriptionFor(string $skillLabel, string $track, string $band, string $theme): string
    {
        return "{$theme} work for {$skillLabel}, tuned for the {$band} level band with direct XP, gold, and tradeable {$track} rewards.";
    }
}
