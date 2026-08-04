<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsAchievementClaim;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsExpeditionRun;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayerSkill;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Models\User;

class ConnectedRealmsPlayerService
{
    public function __construct(private SkillCatalogService $catalog, private ItemCatalogService $items, private ToolCatalogService $tools, private ToolEffectService $toolEffects, private ItemGuideService $itemGuide) {}

    /**
     * @var array<int, bool>
     */
    private array $starterEquipmentEnsuredFor = [];

    /**
     * @var array<string, string>
     */
    private const SPECIES = [
        'human' => 'Human',
        'sylvan' => 'Sylvan',
        'tideborn' => 'Tideborn',
        'stonekin' => 'Stonekin',
        'drifter' => 'Drifter',
    ];

    /**
     * @var array<string, string>
     */
    private const HOME_REGIONS = [
        'moonwake_coast' => 'Moonwake Coast',
        'emberdeep_quarry' => 'Emberdeep Quarry',
        'whisperbough_stand' => 'Whisperbough Stand',
        'glimmerfen_trail' => 'Glimmerfen Trail',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const APPEARANCE_OPTIONS = [
        'body_style' => [
            'balanced' => 'Balanced',
            'compact' => 'Compact',
            'tall' => 'Tall',
            'broad' => 'Broad',
        ],
        'palette' => [
            'moonlit' => 'Moonlit',
            'ember' => 'Ember',
            'verdant' => 'Verdant',
            'tideglass' => 'Tideglass',
        ],
        'hair_style' => [
            'short' => 'Short',
            'long' => 'Long',
            'braided' => 'Braided',
            'shaved' => 'Shaved',
        ],
        'outfit' => [
            'traveler' => 'Traveler',
            'gatherer' => 'Gatherer',
            'artisan' => 'Artisan',
            'delver' => 'Delver',
        ],
    ];

    /**
     * @var array<string, array{
     *     slot_label: string,
     *     skill: string,
     *     item_key: string,
     *     item_name: string,
     *     rarity: string,
     *     durability: int,
     *     bonuses: array{skill: string, experience: int, yield: int}
     * }>
     */
    private const STARTER_EQUIPMENT = [
        'tool_fishing' => [
            'slot_label' => 'Fishing Tool',
            'skill' => 'fishing',
            'item_key' => 'reed_rod',
            'item_name' => 'Reed Rod',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'fishing', 'experience' => 4, 'yield' => 1],
        ],
        'tool_mining' => [
            'slot_label' => 'Mining Tool',
            'skill' => 'mining',
            'item_key' => 'worn_pickaxe',
            'item_name' => 'Worn Pickaxe',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'mining', 'experience' => 4, 'yield' => 1],
        ],
        'tool_woodcutting' => [
            'slot_label' => 'Woodcutting Tool',
            'skill' => 'woodcutting',
            'item_key' => 'trail_hatchet',
            'item_name' => 'Trail Hatchet',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'woodcutting', 'experience' => 4, 'yield' => 1],
        ],
        'tool_foraging' => [
            'slot_label' => 'Foraging Tool',
            'skill' => 'foraging',
            'item_key' => 'woven_satchel',
            'item_name' => 'Woven Satchel',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'foraging', 'experience' => 4, 'yield' => 1],
        ],
        'tool_hunting' => [
            'slot_label' => 'Hunting Tool',
            'skill' => 'hunting',
            'item_key' => 'snare_kit',
            'item_name' => 'Snare Kit',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'hunting', 'experience' => 4, 'yield' => 1],
        ],
        'tool_farming' => [
            'slot_label' => 'Farming Tool',
            'skill' => 'farming',
            'item_key' => 'seed_spade',
            'item_name' => 'Seed Spade',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'farming', 'experience' => 4, 'yield' => 1],
        ],
        'tool_excavation' => [
            'slot_label' => 'Excavation Tool',
            'skill' => 'excavation',
            'item_key' => 'field_trowel',
            'item_name' => 'Field Trowel',
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => 'excavation', 'experience' => 4, 'yield' => 1],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function speciesKeys(): array
    {
        return array_keys(self::SPECIES);
    }

    /**
     * @return list<string>
     */
    public static function homeRegionKeys(): array
    {
        return array_keys(self::HOME_REGIONS);
    }

    /**
     * @return list<string>
     */
    public static function appearanceKeys(string $field): array
    {
        return array_keys(self::APPEARANCE_OPTIONS[$field] ?? []);
    }

    public function playerForUser(User $user): ConnectedRealmsPlayer
    {
        $player = ConnectedRealmsPlayer::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $user->name,
                'species' => 'human',
                'home_region' => 'moonwake_coast',
                'appearance' => $this->defaultAppearance(),
                'gold' => 0,
            ],
        );

        $this->ensureStarterEquipment($player);

        return $player;
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForUser(User $user, GatheringActionService $gathering, SkillActivityService $activities, CraftingService $crafting, JobContractService $jobs, ExpeditionService $expeditions, MarketplaceService $marketplace, ProgressionService $progression, WorldEventService $worldEvents, ShopService $shop, ToolRarityUpgradeService $toolUpgrades, ToolTierUpgradeService $toolTierUpgrades): array
    {
        $basePlayer = $this->playerForUser($user);
        $loadedPlayer = function () use ($basePlayer): ConnectedRealmsPlayer {
            static $player = null;

            if ($player instanceof ConnectedRealmsPlayer) {
                return $player;
            }

            $player = ConnectedRealmsPlayer::query()
                ->whereKey($basePlayer->id)
                ->with([
                    'skills' => fn ($query) => $query->orderBy('skill'),
                    'equipmentSlots' => fn ($query) => $query->with('tool')->orderBy('slot'),
                    'tools' => fn ($query) => $query->orderBy('status')->orderBy('item_name'),
                    'inventoryStacks' => fn ($query) => $query->orderBy('item_name'),
                    'craftingLogs' => fn ($query) => $query->latest()->limit(6),
                    'jobCompletions' => fn ($query) => $query->latest()->limit(6),
                    'expeditionRuns' => fn ($query) => $query->latest()->limit(6),
                    'actionLogs' => fn ($query) => $query->latest()->limit(8),
                    'achievementClaims' => fn ($query) => $query->latest('claimed_at'),
                ])
                ->firstOrFail();

            return $player;
        };
        $totalExperience = fn (): int => (int) $loadedPlayer()->skills->sum('experience');
        $inventoryQuantity = fn (): int => (int) $loadedPlayer()->inventoryStacks->sum('quantity');
        $inventoryWeight = fn (): float => (float) $loadedPlayer()->inventoryStacks->sum(fn (ConnectedRealmsInventoryStack $stack): float => $this->items->enrich([
            'item_key' => $stack->item_key,
            'item_name' => $stack->item_name,
            'rarity' => $stack->rarity,
            'quantity' => $stack->quantity,
        ])['total_weight']);
        $equipmentBySkill = fn () => $loadedPlayer()->equipmentSlots
            ->keyBy(fn (ConnectedRealmsEquipmentSlot $slot): string => (string) ($slot->bonuses['skill'] ?? $slot->slot));
        $progressionSnapshot = function () use ($loadedPlayer, $progression, $totalExperience, $inventoryQuantity): array {
            static $snapshot = null;

            if (is_array($snapshot)) {
                return $snapshot;
            }

            $snapshot = $progression->snapshotFor($loadedPlayer(), $totalExperience(), $inventoryQuantity());

            return $snapshot;
        };
        $actions = function () use ($loadedPlayer, $gathering, $equipmentBySkill): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = collect($gathering->availableActionsFor($loadedPlayer()))
                ->map(fn (array $action): array => [
                    ...$action,
                    'equipped_tool' => $this->toolPayload($equipmentBySkill()->get($action['skill'])),
                ])
                ->values()
                ->all();

            return $rows;
        };
        $skillActivities = function () use ($loadedPlayer, $activities): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = $activities->availableActivitiesFor($loadedPlayer());

            return $rows;
        };
        $craftingRecipes = function () use ($loadedPlayer, $crafting): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = $crafting->availableRecipesFor($loadedPlayer());

            return $rows;
        };
        $jobContracts = function () use ($loadedPlayer, $jobs): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = $jobs->availableJobsFor($loadedPlayer());

            return $rows;
        };
        $expeditionRoutes = function () use ($loadedPlayer, $expeditions): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = $expeditions->availableExpeditionsFor($loadedPlayer());

            return $rows;
        };
        $marketplaceSnapshot = function () use ($loadedPlayer, $marketplace): array {
            static $snapshot = null;

            if (is_array($snapshot)) {
                return $snapshot;
            }

            $snapshot = $marketplace->snapshotFor($loadedPlayer());

            return $snapshot;
        };
        $inventorySnapshot = function () use ($loadedPlayer): array {
            static $rows = null;

            if (is_array($rows)) {
                return $rows;
            }

            $rows = $loadedPlayer()->inventoryStacks
                ->map(fn (ConnectedRealmsInventoryStack $stack): array => $this->items->enrich([
                    'item_key' => $stack->item_key,
                    'item_name' => $stack->item_name,
                    'rarity' => $stack->rarity,
                    'quantity' => $stack->quantity,
                ]))
                ->values()
                ->all();

            return $rows;
        };
        $activityIndex = fn (): array => $this->activityIndex($actions(), $skillActivities(), $craftingRecipes(), $jobContracts(), $expeditionRoutes());

        return [
            'player' => fn (): array => [
                'id' => $loadedPlayer()->id,
                'display_name' => $loadedPlayer()->display_name,
                'title' => $loadedPlayer()->title,
                'species' => $loadedPlayer()->species,
                'species_label' => self::SPECIES[$loadedPlayer()->species] ?? str($loadedPlayer()->species)->headline()->toString(),
                'pronouns' => $loadedPlayer()->pronouns,
                'home_region' => $loadedPlayer()->home_region,
                'home_region_label' => self::HOME_REGIONS[$loadedPlayer()->home_region] ?? str($loadedPlayer()->home_region)->headline()->toString(),
                'appearance' => $this->normalizedAppearance($loadedPlayer()->appearance),
                'reward_loadout' => $this->rewardLoadoutPayload($loadedPlayer()->reward_loadout, $loadedPlayer()->achievementClaims),
                'gold' => $loadedPlayer()->gold,
                'last_action_at' => optional($loadedPlayer()->last_action_at)->toIso8601String(),
                'next_action_at' => optional($loadedPlayer()->next_action_at)->toIso8601String(),
                'can_act_now' => $loadedPlayer()->next_action_at === null || $loadedPlayer()->next_action_at->isPast(),
            ],
            'character_options' => fn (): array => $this->characterOptions(),
            'actions' => $actions,
            'skill_activities' => $skillActivities,
            'equipment' => fn (): array => $loadedPlayer()->equipmentSlots
                ->map(fn (ConnectedRealmsEquipmentSlot $slot): array => $this->toolPayload($slot))
                ->values()
                ->all(),
            'tool_inventory' => fn (): array => $loadedPlayer()->tools
                ->map(fn (ConnectedRealmsTool $tool): array => $this->toolInstancePayload($tool))
                ->values()
                ->all(),
            'tool_rarity_upgrades' => fn (): array => $toolUpgrades->snapshotFor($loadedPlayer()),
            'tool_tier_upgrades' => fn (): array => $toolTierUpgrades->snapshotFor($loadedPlayer()),
            'crafting_recipes' => $craftingRecipes,
            'jobs' => $jobContracts,
            'expeditions' => $expeditionRoutes,
            'marketplace' => $marketplaceSnapshot,
            'shop' => fn (): array => $shop->snapshotFor($loadedPlayer()),
            'progression' => $progressionSnapshot,
            'skills' => fn (): array => $this->skillRowsForPlayer($loadedPlayer(), $activityIndex()),
            'skill_catalog' => fn (): array => [
                'groups' => $this->catalog->groupedCatalog(),
                'pacing' => $this->catalog->pacing(),
            ],
            'item_catalog' => fn (): array => [
                'rarities' => $this->items->rarityProfiles(),
            ],
            'item_guide' => fn (): array => $this->itemGuide->snapshot($inventorySnapshot(), $actions(), $skillActivities(), $craftingRecipes(), $jobContracts(), $expeditionRoutes(), $shop->snapshotFor($loadedPlayer()), $marketplaceSnapshot()),
            'inventory' => $inventorySnapshot,
            'recent_actions' => fn (): array => $loadedPlayer()->actionLogs
                ->map(fn (ConnectedRealmsActionLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'skill' => $log->skill,
                    'platform' => $log->platform,
                    'result_label' => $log->result_label,
                    'tool_item_key' => $log->tool_item_key,
                    'tool_item_name' => $log->tool_item_name,
                    'event_key' => $log->event_key,
                    'event_label' => $log->event_label,
                    'items_awarded' => $this->items->enrichMany($log->items_awarded ?? []),
                    'experience_awarded' => $log->experience_awarded,
                    'gold_awarded' => $log->gold_awarded,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'available_at' => optional($log->available_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'recent_crafts' => fn (): array => $loadedPlayer()->craftingLogs
                ->map(fn (ConnectedRealmsCraftingLog $log): array => [
                    'id' => $log->id,
                    'recipe_key' => $log->recipe_key,
                    'recipe_name' => $log->recipe_name,
                    'skill' => $log->skill,
                    'items_created' => $this->items->enrichMany($log->items_created ?? []),
                    'experience_awarded' => $log->experience_awarded,
                    'gold_cost' => $log->gold_cost,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'recent_jobs' => fn (): array => $loadedPlayer()->jobCompletions
                ->map(fn (ConnectedRealmsJobCompletion $completion): array => [
                    'id' => $completion->id,
                    'job_key' => $completion->job_key,
                    'job_name' => $completion->job_name,
                    'category' => $completion->category,
                    'experience_awarded' => $completion->experience_awarded,
                    'gold_awarded' => $completion->gold_awarded,
                    'created_at' => optional($completion->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'recent_expeditions' => fn (): array => $loadedPlayer()->expeditionRuns
                ->map(fn (ConnectedRealmsExpeditionRun $run): array => [
                    'id' => $run->id,
                    'expedition_key' => $run->expedition_key,
                    'expedition_name' => $run->expedition_name,
                    'status' => $run->status,
                    'items_awarded' => $this->items->enrichMany($run->items_awarded ?? []),
                    'experience_awarded' => $run->experience_awarded,
                    'gold_awarded' => $run->gold_awarded,
                    'resolved_at' => optional($run->resolved_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'summary' => fn (): array => [
                'total_experience' => $totalExperience(),
                'inventory_quantity' => $inventoryQuantity(),
                'inventory_weight' => round($inventoryWeight(), 2),
                'account_level' => $progressionSnapshot()['account_level'],
                'known_skills' => $loadedPlayer()->skills->count(),
                'action_count' => $loadedPlayer()->actionLogs()->count(),
                'craft_count' => $loadedPlayer()->craftingLogs()->count(),
                'job_count' => $loadedPlayer()->jobCompletions()->count(),
                'expedition_count' => $loadedPlayer()->expeditionRuns()->count(),
                'shop_offer_count' => count(ShopService::offerKeys()),
            ],
            'world_events' => fn (): array => $worldEvents->calendar(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCharacter(User $user, array $data): ConnectedRealmsPlayer
    {
        $player = $this->playerForUser($user);

        $player->forceFill([
            'display_name' => $data['display_name'],
            'title' => $data['title'] ?? null,
            'species' => $data['species'],
            'pronouns' => $data['pronouns'] ?? null,
            'home_region' => $data['home_region'],
            'appearance' => $this->normalizedAppearance($data['appearance'] ?? []),
        ])->save();

        return $player;
    }

    public function equipmentForSkill(ConnectedRealmsPlayer $player, string $skill): ?ConnectedRealmsEquipmentSlot
    {
        $this->ensureStarterEquipment($player);

        $slot = $this->tools->familyForSkill($skill)['slot'] ?? null;

        if ($slot === null) {
            return null;
        }

        if ($player->relationLoaded('equipmentSlots')) {
            return $player->equipmentSlots->firstWhere('slot', $slot);
        }

        return $player->equipmentSlots()
            ->where('slot', $slot)
            ->first();
    }

    /**
     * @param  array{experience: int, yield: int, skill?: string}  $bonuses
     */
    public function equipTool(ConnectedRealmsPlayer $player, string $skill, string $itemKey, string $itemName, string $rarity, int $durability, array $bonuses, string $origin = 'crafted', ?string $makerName = null, int $tierLevel = 1): ConnectedRealmsEquipmentSlot
    {
        $slot = $this->tools->familyForSkill($skill)['slot'] ?? null;

        if ($slot === null) {
            throw new \InvalidArgumentException("{$skill} does not support an Evergather tool slot.");
        }

        $equipment = ConnectedRealmsEquipmentSlot::query()->firstOrNew([
            'player_id' => $player->id,
            'slot' => $slot,
        ]);

        if ($equipment->exists && $equipment->tool_id !== null) {
            ConnectedRealmsTool::query()
                ->whereKey($equipment->tool_id)
                ->where('status', ConnectedRealmsTool::STATUS_EQUIPPED)
                ->update(['status' => ConnectedRealmsTool::STATUS_INVENTORY]);
        }

        $tool = ConnectedRealmsTool::create([
            'player_id' => $player->id,
            'slot' => $slot,
            'skill' => $skill,
            'item_key' => $itemKey,
            'item_name' => $itemName,
            'rarity' => $rarity,
            'durability' => $durability,
            'bonuses' => [
                'skill' => $skill,
                'experience' => max(0, (int) $bonuses['experience']),
                'yield' => max(0, (int) $bonuses['yield']),
            ],
            'rarity_progress' => 0,
            'origin' => $origin,
            'status' => ConnectedRealmsTool::STATUS_EQUIPPED,
            'maker_name' => $makerName,
            'tier_level' => max(1, $tierLevel),
        ]);

        $equipment->fill([
            'tool_id' => $tool->id,
            'item_key' => $itemKey,
            'item_name' => $itemName,
            'rarity' => $rarity,
            'durability' => $durability,
            'bonuses' => [
                'skill' => $skill,
                'experience' => max(0, (int) $bonuses['experience']),
                'yield' => max(0, (int) $bonuses['yield']),
            ],
            'rarity_progress' => 0,
            'origin' => $origin,
            'maker_name' => $makerName,
            'tier_level' => max(1, $tierLevel),
        ]);
        $equipment->save();

        return $equipment;
    }

    public function equipToolInstance(ConnectedRealmsPlayer $player, ConnectedRealmsTool $tool): ConnectedRealmsEquipmentSlot
    {
        $equipment = ConnectedRealmsEquipmentSlot::query()->firstOrNew([
            'player_id' => $player->id,
            'slot' => $tool->slot,
        ]);

        if ($equipment->exists && $equipment->tool_id !== null && $equipment->tool_id !== $tool->id) {
            ConnectedRealmsTool::query()
                ->whereKey($equipment->tool_id)
                ->where('status', ConnectedRealmsTool::STATUS_EQUIPPED)
                ->update(['status' => ConnectedRealmsTool::STATUS_INVENTORY]);
        }

        $tool->forceFill([
            'player_id' => $player->id,
            'status' => ConnectedRealmsTool::STATUS_EQUIPPED,
        ])->save();

        return $this->syncEquipmentSlotFromTool($equipment, $tool);
    }

    public function equipStarterToolForSlot(ConnectedRealmsPlayer $player, string $slot): ConnectedRealmsEquipmentSlot
    {
        $equipment = $this->starterEquipmentForSlot($slot);

        if ($equipment === null) {
            throw new \InvalidArgumentException("{$slot} does not have an Evergather starter tool.");
        }

        $starterTool = ConnectedRealmsTool::query()
            ->where('player_id', $player->id)
            ->where('slot', $slot)
            ->where('origin', 'starter')
            ->where('status', ConnectedRealmsTool::STATUS_INVENTORY)
            ->first();

        if ($starterTool === null) {
            $starterTool = ConnectedRealmsTool::create([
                'player_id' => $player->id,
                'slot' => $slot,
                'skill' => $equipment['skill'],
                'item_key' => $equipment['item_key'],
                'item_name' => $equipment['item_name'],
                'rarity' => $equipment['rarity'],
                'durability' => $equipment['durability'],
                'bonuses' => $equipment['bonuses'],
                'rarity_progress' => 0,
                'origin' => 'starter',
                'status' => ConnectedRealmsTool::STATUS_INVENTORY,
                'tier_level' => 0,
            ]);
        }

        return $this->equipToolInstance($player, $starterTool);
    }

    public function syncEquipmentSlotFromTool(ConnectedRealmsEquipmentSlot $equipment, ConnectedRealmsTool $tool): ConnectedRealmsEquipmentSlot
    {
        $equipment->fill([
            'tool_id' => $tool->id,
            'item_key' => $tool->item_key,
            'item_name' => $tool->item_name,
            'rarity' => $tool->rarity,
            'durability' => $tool->durability,
            'bonuses' => $tool->bonuses,
            'rarity_progress' => $tool->rarity_progress,
            'origin' => $tool->origin,
            'maker_name' => $tool->maker_name,
            'tier_level' => $tool->tier_level,
            'upgrade_count' => $tool->upgrade_count,
            'rarity_upgrade_attempts' => $tool->rarity_upgrade_attempts,
        ]);
        $equipment->save();

        return $equipment;
    }

    public function ensureToolInstanceForEquipment(ConnectedRealmsEquipmentSlot $equipment): ConnectedRealmsTool
    {
        if ($equipment->tool_id !== null) {
            $tool = ConnectedRealmsTool::query()->find($equipment->tool_id);

            if ($tool !== null) {
                return $tool;
            }
        }

        $tool = ConnectedRealmsTool::create([
            'player_id' => $equipment->player_id,
            'slot' => $equipment->slot,
            'skill' => (string) ($equipment->bonuses['skill'] ?? str($equipment->slot)->after('tool_')->toString()),
            'item_key' => $equipment->item_key,
            'item_name' => $equipment->item_name,
            'rarity' => $equipment->rarity,
            'durability' => $equipment->durability,
            'bonuses' => $equipment->bonuses ?? [],
            'rarity_progress' => (int) $equipment->rarity_progress,
            'origin' => $equipment->origin ?? 'starter',
            'status' => ConnectedRealmsTool::STATUS_EQUIPPED,
            'maker_name' => $equipment->maker_name,
            'tier_level' => (int) $equipment->tier_level,
            'upgrade_count' => (int) $equipment->upgrade_count,
            'rarity_upgrade_attempts' => (int) $equipment->rarity_upgrade_attempts,
        ]);

        $equipment->forceFill(['tool_id' => $tool->id])->save();

        return $tool;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toolPayload(?ConnectedRealmsEquipmentSlot $slot): ?array
    {
        if ($slot === null) {
            return null;
        }

        $skill = $slot->bonuses['skill'] ?? null;
        $skillMeta = $this->toolSkillMeta($skill);

        $payload = $this->items->enrich([
            'slot' => $slot->slot,
            'tool_id' => $slot->tool_id,
            'slot_label' => $this->slotLabel($slot->slot),
            'skill' => $skill,
            'skill_label' => $skillMeta['label'],
            'category' => $skillMeta['category'],
            'item_key' => $slot->item_key,
            'item_name' => $slot->item_name,
            'rarity' => $slot->rarity,
            'durability' => $slot->durability,
            'experience_bonus' => (int) ($slot->bonuses['experience'] ?? 0),
            'yield_bonus' => (int) ($slot->bonuses['yield'] ?? 0),
            'rarity_progress' => (int) $slot->rarity_progress,
            'origin' => $slot->origin,
            'origin_label' => str($slot->origin ?? 'starter')->headline()->toString(),
            'maker_name' => $slot->maker_name,
            'tier_level' => (int) $slot->tier_level,
            'upgrade_count' => (int) $slot->upgrade_count,
            'tier_upgrade_count' => (int) ($slot->tool?->tier_upgrade_count ?? 0),
            'rarity_upgrade_attempts' => (int) $slot->rarity_upgrade_attempts,
        ]);

        $effects = $this->toolEffects->payloadForEquipment($slot);

        return [
            ...$payload,
            'tool_effects' => $effects,
            'signature_trait' => $effects['signature_trait'],
            'discipline' => $effects['discipline'],
            'perks' => $effects['perks'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolInstancePayload(ConnectedRealmsTool $tool): array
    {
        $skillMeta = $this->toolSkillMeta($tool->skill);

        $payload = $this->items->enrich([
            'tool_id' => $tool->id,
            'slot' => $tool->slot,
            'slot_label' => $this->slotLabel($tool->slot),
            'skill' => $tool->skill,
            'skill_label' => $skillMeta['label'],
            'category' => $skillMeta['category'],
            'item_key' => $tool->item_key,
            'item_name' => $tool->item_name,
            'rarity' => $tool->rarity,
            'durability' => $tool->durability,
            'experience_bonus' => (int) ($tool->bonuses['experience'] ?? 0),
            'yield_bonus' => (int) ($tool->bonuses['yield'] ?? 0),
            'rarity_progress' => (int) $tool->rarity_progress,
            'origin' => $tool->origin,
            'origin_label' => str($tool->origin ?? 'crafted')->headline()->toString(),
            'status' => $tool->status,
            'status_label' => str($tool->status)->headline()->toString(),
            'maker_name' => $tool->maker_name,
            'tier_level' => (int) $tool->tier_level,
            'upgrade_count' => (int) $tool->upgrade_count,
            'tier_upgrade_count' => (int) $tool->tier_upgrade_count,
            'rarity_upgrade_attempts' => (int) $tool->rarity_upgrade_attempts,
        ]);

        $effects = $this->toolEffects->payloadForEquipment($tool);
        $payload = [
            ...$payload,
            'tool_effects' => $effects,
            'signature_trait' => $effects['signature_trait'],
            'discipline' => $effects['discipline'],
            'perks' => $effects['perks'],
        ];
        $floor = $this->toolMarketFloor($payload);
        $ceiling = $floor * 8;

        return [
            ...$payload,
            'market_floor_price' => $floor,
            'market_ceiling_price' => $ceiling,
            'market_price_band' => "{$floor}-{$ceiling}g",
            'npc_buy_price' => max(1, (int) floor($floor * 0.65)),
        ];
    }

    public function awardSkillExperience(ConnectedRealmsPlayer $player, string $skill, int $experience): ConnectedRealmsPlayerSkill
    {
        $record = ConnectedRealmsPlayerSkill::query()->firstOrCreate(
            [
                'player_id' => $player->id,
                'skill' => $skill,
            ],
            [
                'level' => 1,
                'experience' => 0,
            ],
        );

        $record->experience += $experience;
        $record->level = $this->catalog->levelForExperience($record->experience);
        $record->save();

        return $record;
    }

    public function currentSkillLevel(ConnectedRealmsPlayer $player, string $skill): int
    {
        if ($player->relationLoaded('skills')) {
            $experience = (int) ($player->skills->firstWhere('skill', $skill)?->experience ?? 0);

            return $this->catalog->levelForExperience($experience);
        }

        $experience = (int) ConnectedRealmsPlayerSkill::query()
            ->where('player_id', $player->id)
            ->where('skill', $skill)
            ->value('experience');

        return $this->catalog->levelForExperience($experience);
    }

    /**
     * @return array<string, mixed>
     */
    public function characterOptions(): array
    {
        return [
            'species' => $this->optionRows(self::SPECIES),
            'home_regions' => $this->optionRows(self::HOME_REGIONS),
            'appearance' => collect(self::APPEARANCE_OPTIONS)
                ->map(fn (array $options): array => $this->optionRows($options))
                ->all(),
        ];
    }

    /**
     * @return array{body_style: string, palette: string, hair_style: string, outfit: string}
     */
    private function defaultAppearance(): array
    {
        return [
            'body_style' => 'balanced',
            'palette' => 'moonlit',
            'hair_style' => 'short',
            'outfit' => 'traveler',
        ];
    }

    /**
     * @return array{body_style: string, palette: string, hair_style: string, outfit: string}
     */
    private function normalizedAppearance(mixed $appearance): array
    {
        $appearance = is_array($appearance) ? $appearance : [];

        return [
            'body_style' => in_array($appearance['body_style'] ?? null, self::appearanceKeys('body_style'), true)
                ? $appearance['body_style']
                : 'balanced',
            'palette' => in_array($appearance['palette'] ?? null, self::appearanceKeys('palette'), true)
                ? $appearance['palette']
                : 'moonlit',
            'hair_style' => in_array($appearance['hair_style'] ?? null, self::appearanceKeys('hair_style'), true)
                ? $appearance['hair_style']
                : 'short',
            'outfit' => in_array($appearance['outfit'] ?? null, self::appearanceKeys('outfit'), true)
                ? $appearance['outfit']
                : 'traveler',
        ];
    }

    /**
     * @param  iterable<int, ConnectedRealmsAchievementClaim>  $claims
     * @return array{title_claim_key: string|null, title_label: string|null, title_source: string|null, has_equipped: bool}
     */
    private function rewardLoadoutPayload(mixed $loadout, iterable $claims): array
    {
        $loadout = is_array($loadout) ? $loadout : [];
        $claimsByKey = collect($claims)->keyBy('achievement_key');
        $titleClaim = $claimsByKey->get($this->nullableRewardKey($loadout['title_claim_key'] ?? null));

        return [
            'title_claim_key' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? $titleClaim->achievement_key : null,
            'title_label' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? (string) ($titleClaim->reward['title'] ?? '') : null,
            'title_source' => $titleClaim instanceof ConnectedRealmsAchievementClaim ? $titleClaim->achievement_label : null,
            'has_equipped' => $titleClaim instanceof ConnectedRealmsAchievementClaim,
        ];
    }

    private function nullableRewardKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function ensureStarterEquipment(ConnectedRealmsPlayer $player): void
    {
        if (isset($this->starterEquipmentEnsuredFor[$player->id])) {
            return;
        }

        foreach ($this->tools->families() as $family) {
            $slot = $family['slot'];
            $equipment = $this->starterEquipmentForFamily($family);
            $equipmentSlot = ConnectedRealmsEquipmentSlot::query()->firstOrCreate(
                [
                    'player_id' => $player->id,
                    'slot' => $slot,
                ],
                [
                    'item_key' => $equipment['item_key'],
                    'item_name' => $equipment['item_name'],
                    'rarity' => $equipment['rarity'],
                    'durability' => $equipment['durability'],
                    'bonuses' => $equipment['bonuses'],
                    'rarity_progress' => 0,
                    'origin' => 'starter',
                    'tier_level' => 0,
                    'upgrade_count' => 0,
                    'rarity_upgrade_attempts' => 0,
                ],
            );

            $this->ensureToolInstanceForEquipment($equipmentSlot);
        }

        $this->starterEquipmentEnsuredFor[$player->id] = true;
    }

    private function slotLabel(string $slot): string
    {
        return $this->starterEquipmentForSlot($slot)['slot_label'] ?? str($slot)->headline()->toString();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function starterEquipmentForSlot(string $slot): ?array
    {
        if (isset(self::STARTER_EQUIPMENT[$slot])) {
            return self::STARTER_EQUIPMENT[$slot];
        }

        $family = $this->tools->familyForSlot($slot);

        return $family === null ? null : $this->starterEquipmentForFamily($family);
    }

    /**
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private function starterEquipmentForFamily(array $family): array
    {
        if (isset(self::STARTER_EQUIPMENT[$family['slot']])) {
            return self::STARTER_EQUIPMENT[$family['slot']];
        }

        $itemName = "Starter {$family['noun']}";

        return [
            'slot_label' => "{$family['label']} Tool",
            'skill' => $family['skill'],
            'item_key' => str($itemName)->slug('_')->toString(),
            'item_name' => $itemName,
            'rarity' => 'common',
            'durability' => 100,
            'bonuses' => ['skill' => $family['skill'], 'experience' => 4, 'yield' => 1],
        ];
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function toolMarketFloor(array $tool): int
    {
        $statValue = ((int) ($tool['experience_bonus'] ?? 0) * 4) + ((int) ($tool['yield_bonus'] ?? 0) * 26);
        $effectValue = (int) ($tool['tool_effects']['modifiers']['market_premium'] ?? 0);
        $historyValue = ((int) ($tool['tier_level'] ?? 0) * 3)
            + ((int) ($tool['upgrade_count'] ?? 0) * 45)
            + ((int) ($tool['tier_upgrade_count'] ?? 0) * 30);
        $baseValue = (int) ($tool['vendor_value'] ?? 25);

        return max((int) ($tool['market_floor_price'] ?? 1), $baseValue + $statValue + $historyValue + $effectValue);
    }

    /**
     * @return array{label: string|null, category: string|null}
     */
    private function toolSkillMeta(mixed $skill): array
    {
        if (! is_string($skill) || $skill === '') {
            return [
                'label' => null,
                'category' => null,
            ];
        }

        $definition = $this->catalog->definition($skill);

        return [
            'label' => $definition['label'],
            'category' => $definition['category'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skillRowsForPlayer(ConnectedRealmsPlayer $player, array $activityIndex): array
    {
        $records = $player->skills->keyBy('skill');

        return collect($this->catalog->all())
            ->map(function (array $definition) use ($records, $activityIndex): array {
                $record = $records->get($definition['key']);
                $experience = (int) ($record?->experience ?? 0);
                $level = $this->catalog->levelForExperience($experience);
                $nextLevelExperience = $this->catalog->nextLevelExperience($level);

                return [
                    'skill' => $definition['key'],
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'category' => $definition['category'],
                    'role' => $definition['role'],
                    'description' => $definition['description'],
                    'level' => $level,
                    'experience' => $experience,
                    'current_level_experience' => $this->catalog->experienceForLevel($level),
                    'next_level_experience' => $nextLevelExperience,
                    'max_level' => SkillCatalogService::MAX_LEVEL,
                    'unlocks' => $this->catalog->milestoneUnlocks($definition['key']),
                    'activities' => $activityIndex[$definition['key']] ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $activities
     * @param  list<array<string, mixed>>  $recipes
     * @param  list<array<string, mixed>>  $jobs
     * @param  list<array<string, mixed>>  $expeditions
     * @return array<string, list<array{type: string, label: string, required_level: int, unlocked: bool}>>
     */
    private function activityIndex(array $actions, array $activities, array $recipes, array $jobs, array $expeditions): array
    {
        $rows = [];

        foreach ($actions as $action) {
            $rows[$action['skill']][] = $this->activityRow('Resource Run', $action);
        }

        foreach ($activities as $activity) {
            $rows[$activity['skill']][] = $this->activityRow('Activity', $activity);
        }

        foreach ($recipes as $recipe) {
            $rows[$recipe['skill']][] = $this->activityRow('Recipe', $recipe);
        }

        foreach ($jobs as $job) {
            $rows[$job['skill']][] = $this->activityRow('Commission', $job);
        }

        foreach ($expeditions as $expedition) {
            $rows[$expedition['skill']][] = $this->activityRow('Expedition', $expedition);
        }

        return collect($rows)
            ->map(fn (array $entries): array => collect($entries)
                ->sortBy(['required_level', 'label'])
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{type: string, label: string, required_level: int, unlocked: bool}
     */
    private function activityRow(string $type, array $entry): array
    {
        return [
            'type' => $type,
            'label' => $entry['label'],
            'required_level' => (int) ($entry['required_level'] ?? 1),
            'unlocked' => (bool) ($entry['is_unlocked'] ?? true),
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{key: string, label: string}>
     */
    private function optionRows(array $options): array
    {
        return collect($options)
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }
}
