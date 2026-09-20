<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsExpeditionRun;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpeditionService
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $expeditionCache = null;

    /**
     * @var array<string, array{region: string, supply_skill: string, reward_family: string, labels: list<string>}>
     */
    private const ROUTE_PROFILES = [
        'combat' => ['region' => 'Moonwake Training Ring', 'supply_skill' => 'smithing', 'reward_family' => 'Combat Badge', 'labels' => ['Guard Bout', 'Footwork Round', 'Stance Exchange', 'Vanguard Drill', 'Field Assignment', 'Champion Trial', 'Warband Scrimmage', 'Realmguard Bout', 'Crown Duel', 'Realm Champion Bout']],
        'slayer' => ['region' => 'Briarwake Bounty Board', 'supply_skill' => 'leatherworking', 'reward_family' => 'Slayer Mark', 'labels' => ['Fang Study', 'Bounty Pin', 'Weakness Read', 'Stalker Report', 'Nightfang Prep', 'Greatbeast Mark', 'Monster Bane Drill', 'Crownbeast Warrant', 'Apex Trophy Claim', 'First Hunt Trial']],
        'defense' => ['region' => 'Old Gate Shieldline', 'supply_skill' => 'construction', 'reward_family' => 'Defense Badge', 'labels' => ['Shield Brace', 'Field Repair', 'Armor Mastery', 'Party Guard', 'Bulwark Supply', 'Dungeon Guard', 'Wallbreaker Hold', 'Citadel Bulwark', 'Unbroken Line', 'Last Wall Stand']],
        'healing' => ['region' => 'Moonwake Infirmary', 'supply_skill' => 'alchemy', 'reward_family' => 'Healing Writ', 'labels' => ['Bandage Pack', 'Sap Tonic', 'Recovery Round', 'Medic Kit', 'Stabilizer Vial', 'Field Hospital', 'Revival Rite', 'Lifewarden Supply', 'Renewal Ward', 'Life Warden Call']],
        'magic' => ['region' => 'Moon Ward Circle', 'supply_skill' => 'enchanting', 'reward_family' => 'Magic Seal', 'labels' => ['Spark Channel', 'Ward Circle', 'Elemental Focus', 'Ritual Night', 'Storm Report', 'Rune Reading', 'Spellguard Work', 'Oldhall Rite', 'Archmage Trial', 'Starward Channel']],
        'ranged' => ['region' => 'High Perch Range', 'supply_skill' => 'carpentry', 'reward_family' => 'Ranged Mark', 'labels' => ['Bow Sighting', 'Arrow Stock', 'Special Shot', 'Siege Range', 'Trail Bow Refit', 'Trick Shot', 'Marksman Trial', 'Stormshot Practice', 'Sky Archer Drill', 'High Perch Volley']],
        'exploration' => ['region' => 'Hidden Mile Route', 'supply_skill' => 'cartography', 'reward_family' => 'Explorer Compass', 'labels' => ['Sketch Route', 'Regional Path', 'Hidden Room', 'Distant Trail', 'Ancient Gate', 'Frontier Proof', 'Worldwalker Waybill', 'Gate Warrant', 'Lost Road Reading', 'Horizon Walk']],
        'dungeoneering' => ['region' => 'Lower Vault Wing', 'supply_skill' => 'cartography', 'reward_family' => 'Vault Key', 'labels' => ['Room Check', 'Trap Read', 'Party Route', 'Boss Room Supply', 'Dungeon Audit', 'Deep Chamber', 'Vault Key Report', 'Labyrinth Writ', 'Deep Warden Trial', 'Lower Vault Crown']],
        'sailing' => ['region' => 'Stormbreak Channel', 'supply_skill' => 'boatbuilding', 'reward_family' => 'Sailing Writ', 'labels' => ['Dock Rope', 'Coastal Trip', 'Cargo Manifest', 'Fleet Support', 'Sea Chart', 'Harbor Signal', 'Tide Captain Lot', 'Stormroute Warrant', 'Expedition Sail', 'Tide Captain Crossing']],
        'survival' => ['region' => 'Cold Camp Circuit', 'supply_skill' => 'cooking', 'reward_family' => 'Survival Mark', 'labels' => ['Flatbread Cache', 'Weather Read', 'Long Trip Supply', 'Hazard Kit', 'Hostile Region', 'Campcraft Ledger', 'Last Light Cache', 'Wild March', 'Hostile Wilds', 'Last Light March']],
        'cartography' => ['region' => 'Surveyor Ridge', 'supply_skill' => 'cartography', 'reward_family' => 'Survey Writ', 'labels' => ['Survey Note', 'Route Map', 'Dungeon Chart', 'Region Atlas', 'Secret Road', 'Survey Parcel', 'Starmapper Grid', 'Navigator Archive', 'Secret Atlas', 'Star Map Draft']],
        'reputation' => ['region' => 'Regional Council Board', 'supply_skill' => 'trading', 'reward_family' => 'Faction Seal', 'labels' => ['Barter Note', 'Favor Seal', 'Rate Petition', 'Council Gift', 'Title Claim', 'Envoy Introduction', 'Realm Favor', 'Council Seat Case', 'Realm Envoy Hearing', 'Concord Address']],
        'leadership' => ['region' => 'Oathhall Muster Yard', 'supply_skill' => 'construction', 'reward_family' => 'Crew Banner', 'labels' => ['Crate Muster', 'Party Call', 'Oathhall Task', 'Raid Brief', 'Banner Drill', 'Campaign Writ', 'Command Tent', 'Standard Warrant', 'War Table Mandate', 'Bannerlord Call']],
        'trading' => ['region' => 'Regional Brokerage', 'supply_skill' => 'trading', 'reward_family' => 'Trade Writ', 'labels' => ['Market Token', 'Bulk Listing', 'Work Packet', 'Storefront Stock', 'Route Manifest', 'Arbitrage Writ', 'Merchant Seal', 'Royal Exchange', 'Sovereign Counter', 'Market Oath']],
    ];

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @return list<string>
     */
    public static function expeditionKeys(): array
    {
        return array_keys(self::expeditions());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableExpeditionsFor(ConnectedRealmsPlayer $player): array
    {
        $inventory = ($player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->get())
            ->keyBy('item_key');

        return collect(self::expeditions())
            ->map(function (array $expedition, string $key) use ($inventory, $player): array {
                $requiredLevel = (int) ($expedition['required_level'] ?? 1);
                $skillProgress = $this->players->skillProgressFor($player, $expedition['skill']);
                $skillLevel = $skillProgress['level'];
                $supplies = collect($expedition['supplies'])
                    ->map(function (array $supply) use ($inventory): array {
                        $ownedQuantity = (int) ($inventory->get($supply['item_key'])?->quantity ?? 0);

                        return $this->items->enrich([
                            ...$supply,
                            'owned_quantity' => $ownedQuantity,
                            'has_enough' => $ownedQuantity >= $supply['quantity'],
                        ]);
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => $expedition['label'],
                    'region' => $expedition['region'],
                    'skill' => $expedition['skill'],
                    'skill_label' => $skillProgress['skill_label'],
                    'required_level' => $requiredLevel,
                    'item_tier' => $expedition['item_tier'],
                    'level_band' => $expedition['level_band'],
                    'progression_phase' => $expedition['progression_phase'],
                    'skill_level' => $skillLevel,
                    'skill_progress' => $skillProgress,
                    'is_unlocked' => $skillLevel >= $requiredLevel,
                    'experience' => $expedition['experience'],
                    'gold' => $expedition['gold'],
                    'supplies' => $supplies,
                    'rewards' => $this->items->enrichMany($expedition['rewards']),
                    'can_start' => collect($supplies)->every(fn (array $supply): bool => $supply['has_enough'])
                        && $skillLevel >= $requiredLevel,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(User $user, string $expeditionKey): array
    {
        $expedition = self::expeditionForKey($expeditionKey);

        if ($expedition === null) {
            throw ValidationException::withMessages([
                'expedition' => 'That Evergather expedition is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $expeditionKey, $expedition): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            $requiredLevel = (int) ($expedition['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $expedition['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'expedition' => "You need level {$requiredLevel} ".str($expedition['skill'])->headline()->toString().' for that expedition.',
                ]);
            }

            $supplyKeys = collect($expedition['supplies'])->pluck('item_key')->all();
            $stacks = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->whereIn('item_key', $supplyKeys)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_key');

            foreach ($expedition['supplies'] as $supply) {
                $stack = $stacks->get($supply['item_key']);

                if ($stack === null || $stack->quantity < $supply['quantity']) {
                    throw ValidationException::withMessages([
                        'expedition' => "You need {$supply['quantity']} {$supply['item_name']} for that expedition.",
                    ]);
                }
            }

            foreach ($expedition['supplies'] as $supply) {
                $stack = $stacks->get($supply['item_key']);
                $stack->quantity -= $supply['quantity'];

                if ($stack->quantity <= 0) {
                    $stack->delete();

                    continue;
                }

                $stack->save();
            }

            $supplies = $this->items->enrichMany($expedition['supplies']);
            $rewards = $this->items->enrichMany($expedition['rewards']);

            foreach ($rewards as $reward) {
                $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                    'player_id' => $player->id,
                    'item_key' => $reward['item_key'],
                ]);
                $stack->fill([
                    'item_name' => $reward['item_name'],
                    'rarity' => $reward['rarity'],
                    'quantity' => (int) $stack->quantity + $reward['quantity'],
                ]);
                $stack->save();
            }

            $player->forceFill([
                'gold' => $player->gold + $expedition['gold'],
            ])->save();

            $skillProgress = $this->players->skillProgressPayload(
                $this->players->awardSkillExperience($player, $expedition['skill'], $expedition['experience']),
            );
            app(JobContractService::class)->recordMenuProgress($player, $expedition['skill'], $requiredLevel);

            $run = ConnectedRealmsExpeditionRun::create([
                'player_id' => $player->id,
                'expedition_key' => $expeditionKey,
                'expedition_name' => $expedition['label'],
                'status' => 'resolved',
                'supplies_consumed' => $supplies,
                'items_awarded' => $rewards,
                'experience_awarded' => $expedition['experience'],
                'gold_awarded' => $expedition['gold'],
                'resolved_at' => now(),
            ]);

            return [
                'type' => 'expedition',
                'id' => $run->id,
                'expedition_key' => $expeditionKey,
                'label' => $expedition['label'],
                'region' => $expedition['region'],
                'skill' => $expedition['skill'],
                'skill_label' => $skillProgress['skill_label'],
                'skill_level' => $skillProgress['level'],
                'skill_experience' => $skillProgress['experience'],
                'next_level_experience' => $skillProgress['next_level_experience'],
                'skill_progress' => $skillProgress,
                'items_awarded' => $rewards,
                'supplies_consumed' => $supplies,
                'experience_awarded' => $expedition['experience'],
                'gold_awarded' => $expedition['gold'],
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expeditions(): array
    {
        if (self::$expeditionCache !== null) {
            return self::$expeditionCache;
        }

        self::$expeditionCache = self::normalizeExpeditions(
            app(ConnectedRealmsContentService::class)->apply('expeditions', self::baseExpeditions()),
        );

        return self::$expeditionCache;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function expeditionForKey(string $expeditionKey): ?array
    {
        if (self::$expeditionCache !== null) {
            return self::$expeditionCache[$expeditionKey] ?? null;
        }

        $expedition = app(ConnectedRealmsContentService::class)->definitionFor(
            'expeditions',
            $expeditionKey,
            self::baseExpeditionForKey($expeditionKey),
        );

        if ($expedition === null) {
            return null;
        }

        return self::normalizeExpeditions([$expeditionKey => $expedition])[$expeditionKey];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function baseExpeditionForKey(string $expeditionKey): ?array
    {
        foreach (self::ROUTE_PROFILES as $skill => $profile) {
            foreach (EvergatherTierCatalog::tiers() as $index => $tier) {
                if ("{$skill}_{$tier['key_slug']}_expedition" !== $expeditionKey) {
                    continue;
                }

                return self::normalizeExpeditions([
                    $expeditionKey => self::expedition($skill, $profile, $tier, $index),
                ])[$expeditionKey];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function baseExpeditions(): array
    {
        $expeditions = [];

        foreach (self::ROUTE_PROFILES as $skill => $profile) {
            foreach (EvergatherTierCatalog::tiers() as $index => $tier) {
                $key = "{$skill}_{$tier['key_slug']}_expedition";
                $expeditions[$key] = self::expedition($skill, $profile, $tier, $index);
            }
        }

        return self::normalizeExpeditions($expeditions);
    }

    /**
     * @param  array{region: string, supply_skill: string, reward_family: string, labels: list<string>}  $profile
     * @param  array{level: int, item_tier: int, key_slug: string, mark: string, rarity: string}  $tier
     * @return array<string, mixed>
     */
    private static function expedition(string $skill, array $profile, array $tier, int $index): array
    {
        $requiredLevel = (int) $tier['level'];
        $itemTier = (int) $tier['item_tier'];
        $label = "{$tier['mark']} {$profile['labels'][$index]}";
        $gold = 42 + ($requiredLevel * 4) + ($itemTier * 3);
        $experience = 70 + ($requiredLevel * 6) + ($itemTier * 5);

        return [
            'label' => $label,
            'region' => "{$profile['region']} - {$tier['mark']} Route",
            'skill' => $skill,
            'required_level' => $requiredLevel,
            'item_tier' => $itemTier,
            'level_band' => EvergatherTierCatalog::tierForLevel($requiredLevel)['band'],
            'progression_phase' => EvergatherTierCatalog::progressionPhaseForLevel($requiredLevel),
            'experience' => $experience,
            'gold' => $gold,
            'supplies' => [
                self::craftedSupply((string) $profile['supply_skill'], $requiredLevel, $itemTier),
            ],
            'rewards' => [
                self::routeReward($skill, (string) $profile['reward_family'], $label, $tier),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $expeditions
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeExpeditions(array $expeditions): array
    {
        return collect($expeditions)
            ->map(function (array $expedition): array {
                $requiredLevel = EvergatherTierCatalog::nextTierLevelFor((int) ($expedition['required_level'] ?? 1));

                return [
                    ...$expedition,
                    'required_level' => $requiredLevel,
                    'item_tier' => EvergatherTierCatalog::itemTierForLevel($requiredLevel),
                    'level_band' => EvergatherTierCatalog::tierForLevel($requiredLevel)['band'],
                    'progression_phase' => EvergatherTierCatalog::progressionPhaseForLevel($requiredLevel),
                ];
            })
            ->all();
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedSupply(string $skill, int $level, int $itemTier): array
    {
        $output = array_key_exists($skill, CraftingService::recipeTierFamilies())
            ? CraftingService::tierLadderOutputForSkill($skill, EvergatherTierCatalog::nextTierLevelFor($level))
            : self::nearestCraftedOutput($skill, $level);

        return [
            'item_key' => $output['item_key'],
            'item_name' => $output['item_name'],
            'quantity' => $itemTier >= 10 ? 3 : ($itemTier >= 8 ? 2 : 1),
        ];
    }

    /**
     * @return array{item_key: string, item_name: string}
     */
    private static function nearestCraftedOutput(string $skill, int $level): array
    {
        $outputs = self::craftedOutputsBySkillAndLevel()[$skill] ?? [];

        if ($outputs === []) {
            return [
                'item_key' => str("{$skill} route supply {$level}")->slug('_')->toString(),
                'item_name' => str("{$skill} route supply")->headline()->toString(),
            ];
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
    private static function craftedOutputsBySkillAndLevel(): array
    {
        static $outputs = null;

        if ($outputs !== null) {
            return $outputs;
        }

        $outputs = [];

        foreach (CraftingService::baseRecipes() as $recipe) {
            $output = collect($recipe['outputs'] ?? [])
                ->first(fn (array $candidate): bool => ! isset($candidate['equipment_skill']));

            if ($output === null) {
                continue;
            }

            $skill = (string) $recipe['skill'];
            $requiredLevel = (int) ($recipe['required_level'] ?? 1);

            if (isset($outputs[$skill][$requiredLevel])) {
                continue;
            }

            $outputs[$skill][$requiredLevel] = [
                'item_key' => (string) $output['item_key'],
                'item_name' => (string) $output['item_name'],
            ];
        }

        return $outputs;
    }

    /**
     * @param  array{item_tier: int, mark: string, rarity: string}  $tier
     * @return array{item_key: string, item_name: string, rarity: string, quantity: int, item_tier: int}
     */
    private static function routeReward(string $skill, string $rewardFamily, string $label, array $tier): array
    {
        $itemTier = (int) $tier['item_tier'];

        return [
            'item_key' => str("expedition {$skill} tier {$itemTier} {$rewardFamily}")->slug('_')->toString(),
            'item_name' => "{$label} {$rewardFamily}",
            'rarity' => (string) $tier['rarity'],
            'quantity' => $itemTier >= 8 ? 2 : 1,
            'item_tier' => $itemTier,
        ];
    }
}
