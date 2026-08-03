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
     * @var array<string, array{track: string, verb: string, location: string, rewards: list<array{key: string, name: string}>}>
     */
    private const ACTIVITY_FAMILIES = [
        'smelting' => ['track' => 'Forge Work', 'verb' => 'Run', 'location' => 'Emberdeep Forge Hall', 'rewards' => [['key' => 'slag_glass', 'name' => 'Slag Glass'], ['key' => 'forge_credit', 'name' => 'Forge Credit']]],
        'milling' => ['track' => 'Mill Work', 'verb' => 'Plane', 'location' => 'Whisperbough Millhouse', 'rewards' => [['key' => 'sawdust_bundle', 'name' => 'Sawdust Bundle'], ['key' => 'mill_token', 'name' => 'Mill Token']]],
        'tanning' => ['track' => 'Tannery Work', 'verb' => 'Cure', 'location' => 'Briarwake Tannery', 'rewards' => [['key' => 'tannin_vial', 'name' => 'Tannin Vial'], ['key' => 'tannery_token', 'name' => 'Tannery Token']]],
        'cutting' => ['track' => 'Lapidary Work', 'verb' => 'Facet', 'location' => 'Gemcutter Row', 'rewards' => [['key' => 'gem_dust', 'name' => 'Gem Dust'], ['key' => 'lapidary_credit', 'name' => 'Lapidary Credit']]],
        'weaving' => ['track' => 'Loom Work', 'verb' => 'Weave', 'location' => 'Sunfield Loomhall', 'rewards' => [['key' => 'loom_thread', 'name' => 'Loom Thread'], ['key' => 'weaver_token', 'name' => 'Weaver Token']]],
        'smithing' => ['track' => 'Smithing Orders', 'verb' => 'Forge', 'location' => 'Moonwake Anvil Yard', 'rewards' => [['key' => 'weapon_blank', 'name' => 'Weapon Blank'], ['key' => 'smith_mark', 'name' => 'Smith Mark']]],
        'carpentry' => ['track' => 'Carpentry Orders', 'verb' => 'Shape', 'location' => 'Whisperbough Workshop', 'rewards' => [['key' => 'joinery_piece', 'name' => 'Joinery Piece'], ['key' => 'carpenter_mark', 'name' => 'Carpenter Mark']]],
        'cooking' => ['track' => 'Kitchen Orders', 'verb' => 'Prepare', 'location' => 'Moonwake Hearthline', 'rewards' => [['key' => 'kitchen_scrap', 'name' => 'Kitchen Scrap'], ['key' => 'cook_mark', 'name' => 'Cook Mark']]],
        'alchemy' => ['track' => 'Alchemy Orders', 'verb' => 'Brew', 'location' => 'Glimmerfen Stillroom', 'rewards' => [['key' => 'catalyst_drop', 'name' => 'Catalyst Drop'], ['key' => 'alchemist_mark', 'name' => 'Alchemist Mark']]],
        'tailoring' => ['track' => 'Tailoring Orders', 'verb' => 'Stitch', 'location' => 'Sunfield Stitchery', 'rewards' => [['key' => 'pattern_scrap', 'name' => 'Pattern Scrap'], ['key' => 'tailor_mark', 'name' => 'Tailor Mark']]],
        'leatherworking' => ['track' => 'Leather Orders', 'verb' => 'Bind', 'location' => 'Briarwake Leather Bench', 'rewards' => [['key' => 'strap_cutting', 'name' => 'Strap Cutting'], ['key' => 'leatherworker_mark', 'name' => 'Leatherworker Mark']]],
        'engineering' => ['track' => 'Engineering Orders', 'verb' => 'Assemble', 'location' => 'Clockwork Yard', 'rewards' => [['key' => 'gear_shaving', 'name' => 'Gear Shaving'], ['key' => 'engineer_mark', 'name' => 'Engineer Mark']]],
        'enchanting' => ['track' => 'Enchanting Orders', 'verb' => 'Infuse', 'location' => 'Moon Ward Annex', 'rewards' => [['key' => 'rune_dust', 'name' => 'Rune Dust'], ['key' => 'enchanter_mark', 'name' => 'Enchanter Mark']]],
        'jewelcrafting' => ['track' => 'Jewelry Orders', 'verb' => 'Set', 'location' => 'Gemcutter Row', 'rewards' => [['key' => 'setting_wire', 'name' => 'Setting Wire'], ['key' => 'jeweler_mark', 'name' => 'Jeweler Mark']]],
        'boatbuilding' => ['track' => 'Shipwright Orders', 'verb' => 'Fit', 'location' => 'Moonwake Drydock', 'rewards' => [['key' => 'pitch_bucket', 'name' => 'Pitch Bucket'], ['key' => 'shipwright_mark', 'name' => 'Shipwright Mark']]],
        'furniture' => ['track' => 'Furniture Orders', 'verb' => 'Finish', 'location' => 'Guild Hall Shop', 'rewards' => [['key' => 'varnish_pot', 'name' => 'Varnish Pot'], ['key' => 'furnisher_mark', 'name' => 'Furnisher Mark']]],
        'construction' => ['track' => 'Construction Orders', 'verb' => 'Build', 'location' => 'Settlement Works Yard', 'rewards' => [['key' => 'mason_chit', 'name' => 'Mason Chit'], ['key' => 'builder_mark', 'name' => 'Builder Mark']]],
        'combat' => ['track' => 'Combat Drills', 'verb' => 'Engage', 'location' => 'Moonwake Training Ring', 'rewards' => [['key' => 'combat_badge', 'name' => 'Combat Badge'], ['key' => 'training_blade', 'name' => 'Training Blade']]],
        'slayer' => ['track' => 'Slayer Marks', 'verb' => 'Hunt', 'location' => 'Briarwake Bounty Board', 'rewards' => [['key' => 'slayer_mark', 'name' => 'Slayer Mark'], ['key' => 'monster_trophy', 'name' => 'Monster Trophy']]],
        'defense' => ['track' => 'Guard Rotations', 'verb' => 'Hold', 'location' => 'Old Gate Shield Line', 'rewards' => [['key' => 'defense_badge', 'name' => 'Defense Badge'], ['key' => 'shield_plate', 'name' => 'Shield Plate']]],
        'healing' => ['track' => 'Medic Rounds', 'verb' => 'Stabilize', 'location' => 'Moonwake Infirmary', 'rewards' => [['key' => 'healing_writ', 'name' => 'Healing Writ'], ['key' => 'medic_satchel', 'name' => 'Medic Satchel']]],
        'magic' => ['track' => 'Arcane Trials', 'verb' => 'Channel', 'location' => 'Moon Ward Circle', 'rewards' => [['key' => 'magic_seal', 'name' => 'Magic Seal'], ['key' => 'focus_shard', 'name' => 'Focus Shard']]],
        'ranged' => ['track' => 'Range Trials', 'verb' => 'Mark', 'location' => 'High Perch Range', 'rewards' => [['key' => 'ranged_mark', 'name' => 'Ranged Mark'], ['key' => 'fletching_bundle', 'name' => 'Fletching Bundle']]],
        'exploration' => ['track' => 'Scout Routes', 'verb' => 'Scout', 'location' => 'Hidden Mile Route', 'rewards' => [['key' => 'explorer_badge', 'name' => 'Explorer Badge'], ['key' => 'route_note', 'name' => 'Route Note']]],
        'dungeoneering' => ['track' => 'Dungeon Rooms', 'verb' => 'Clear', 'location' => 'Lower Vault Wing', 'rewards' => [['key' => 'vault_key', 'name' => 'Vault Key'], ['key' => 'trap_diagram', 'name' => 'Trap Diagram']]],
        'sailing' => ['track' => 'Sailing Runs', 'verb' => 'Navigate', 'location' => 'Stormbreak Channel', 'rewards' => [['key' => 'sailing_writ', 'name' => 'Sailing Writ'], ['key' => 'tide_chart', 'name' => 'Tide Chart']]],
        'survival' => ['track' => 'Survival Circuits', 'verb' => 'Endure', 'location' => 'Cold Camp Circuit', 'rewards' => [['key' => 'survival_mark', 'name' => 'Survival Mark'], ['key' => 'camp_cache', 'name' => 'Camp Cache']]],
        'cartography' => ['track' => 'Survey Work', 'verb' => 'Chart', 'location' => 'Surveyor Ridge', 'rewards' => [['key' => 'survey_writ', 'name' => 'Survey Writ'], ['key' => 'map_fragment', 'name' => 'Map Fragment']]],
        'reputation' => ['track' => 'Faction Work', 'verb' => 'Represent', 'location' => 'Regional Council Board', 'rewards' => [['key' => 'faction_seal', 'name' => 'Faction Seal'], ['key' => 'favor_note', 'name' => 'Favor Note']]],
        'leadership' => ['track' => 'Crew Commands', 'verb' => 'Coordinate', 'location' => 'Guild Muster Yard', 'rewards' => [['key' => 'crew_banner', 'name' => 'Crew Banner'], ['key' => 'order_sheet', 'name' => 'Order Sheet']]],
        'trading' => ['track' => 'Trade Routes', 'verb' => 'Broker', 'location' => 'Regional Trade Loop', 'rewards' => [['key' => 'trade_writ', 'name' => 'Trade Writ'], ['key' => 'ledger_page', 'name' => 'Ledger Page']]],
    ];

    /**
     * @var list<array{level: int, band: string, prefix: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}>
     */
    private const TIERS = [
        ['level' => 1, 'band' => '1-30', 'prefix' => 'Starter', 'rarity' => 'common', 'experience' => [22, 34], 'gold' => [3, 8], 'cooldown' => 70],
        ['level' => 5, 'band' => '1-30', 'prefix' => 'Local', 'rarity' => 'common', 'experience' => [30, 46], 'gold' => [4, 10], 'cooldown' => 85],
        ['level' => 10, 'band' => '1-30', 'prefix' => 'Apprentice', 'rarity' => 'uncommon', 'experience' => [40, 60], 'gold' => [6, 13], 'cooldown' => 100],
        ['level' => 20, 'band' => '1-30', 'prefix' => 'Guild', 'rarity' => 'uncommon', 'experience' => [58, 86], 'gold' => [8, 18], 'cooldown' => 125],
        ['level' => 30, 'band' => '30-50', 'prefix' => 'Runed', 'rarity' => 'rare', 'experience' => [78, 116], 'gold' => [12, 25], 'cooldown' => 155],
        ['level' => 40, 'band' => '30-50', 'prefix' => 'Storm', 'rarity' => 'rare', 'experience' => [102, 152], 'gold' => [16, 34], 'cooldown' => 190],
        ['level' => 50, 'band' => '50-80', 'prefix' => 'Elite', 'rarity' => 'rare', 'experience' => [132, 196], 'gold' => [22, 45], 'cooldown' => 230],
        ['level' => 65, 'band' => '50-80', 'prefix' => 'Elder', 'rarity' => 'epic', 'experience' => [176, 260], 'gold' => [30, 62], 'cooldown' => 285],
        ['level' => 80, 'band' => '80-100', 'prefix' => 'Mythic', 'rarity' => 'epic', 'experience' => [238, 350], 'gold' => [42, 84], 'cooldown' => 350],
        ['level' => 100, 'band' => '80-100', 'prefix' => 'Evergather', 'rarity' => 'legendary', 'experience' => [360, 520], 'gold' => [62, 120], 'cooldown' => 430],
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

            foreach (self::TIERS as $tier) {
                $key = str("{$skill} {$tier['prefix']} activity {$tier['level']}")->slug('_')->toString();
                $activities[$key] = self::activity($skill, $category, $family, $tier);
            }
        }

        self::$activityCache = $activities;

        return self::$activityCache;
    }

    /**
     * @param  array{track: string, verb: string, location: string, rewards: list<array{key: string, name: string}>}  $family
     * @param  array{level: int, band: string, prefix: string, rarity: string, experience: array{int, int}, gold: array{int, int}, cooldown: int}  $tier
     * @return array<string, mixed>
     */
    private static function activity(string $skill, string $category, array $family, array $tier): array
    {
        $skillLabel = str($skill)->headline()->toString();
        $primaryReward = $family['rewards'][0];
        $secondaryReward = $family['rewards'][1];

        return [
            'label' => "{$tier['prefix']} {$skillLabel} {$family['verb']}",
            'track' => $family['track'],
            'activity_type' => "{$category} Activity",
            'band' => $tier['band'],
            'skill' => $skill,
            'category' => $category,
            'location' => "{$tier['prefix']} {$family['location']}",
            'description' => self::descriptionFor($skillLabel, $family['track'], $tier['band']),
            'required_level' => $tier['level'],
            'cooldown_seconds' => $tier['cooldown'],
            'experience' => ['min' => $tier['experience'][0], 'max' => $tier['experience'][1]],
            'gold' => ['min' => $tier['gold'][0], 'max' => $tier['gold'][1]],
            'loot' => [
                [
                    'item_key' => str("{$skill} {$tier['prefix']} {$primaryReward['key']} {$tier['level']}")->slug('_')->toString(),
                    'item_name' => "{$tier['prefix']} {$primaryReward['name']}",
                    'rarity' => $tier['rarity'],
                    'quantity' => $tier['level'] >= 50 ? 2 : 1,
                    'chance' => 100,
                ],
                [
                    'item_key' => str("{$skill} {$tier['prefix']} {$secondaryReward['key']} {$tier['level']}")->slug('_')->toString(),
                    'item_name' => "{$tier['prefix']} {$secondaryReward['name']}",
                    'rarity' => $tier['level'] >= 80 ? 'epic' : ($tier['level'] >= 30 ? 'rare' : 'uncommon'),
                    'quantity' => 1,
                    'chance' => $tier['level'] >= 80 ? 55 : 70,
                ],
            ],
        ];
    }

    private static function descriptionFor(string $skillLabel, string $track, string $band): string
    {
        return "{$track} progression for {$skillLabel}, tuned for the {$band} level band with direct XP, gold, and tradeable rewards.";
    }
}
