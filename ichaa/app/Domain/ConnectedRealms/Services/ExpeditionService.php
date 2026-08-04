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
     * @var array<string, array{
     *     label: string,
     *     region: string,
     *     skill: string,
     *     required_level?: int,
     *     experience: int,
     *     gold: int,
     *     supplies: list<array{item_key: string, item_name: string, quantity: int}>,
     *     rewards: list<array{item_key: string, item_name: string, rarity: string, quantity: int}>
     * }>
     */
    private const EXPEDITIONS = [
        'moonwake_supply_run' => [
            'label' => 'Moonwake Provision Walk',
            'region' => 'Moonwake Coast',
            'skill' => 'exploration',
            'experience' => 45,
            'gold' => 30,
            'supplies' => [
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'shell_charm', 'item_name' => 'Shell Charm', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'emberdeep_delve' => [
            'label' => 'Emberdeep Delve',
            'region' => 'Emberdeep Quarry',
            'skill' => 'exploration',
            'experience' => 60,
            'gold' => 45,
            'supplies' => [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'ember_relic', 'item_name' => 'Ember Relic', 'rarity' => 'rare', 'quantity' => 1],
            ],
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private const STARTER_EXPEDITIONS = [
        'shoreline_patrol' => [
            'label' => 'Shoreline Patrol',
            'region' => 'Moonwake Coast',
            'skill' => 'exploration',
            'required_level' => 3,
            'experience' => 58,
            'gold' => 40,
            'supplies' => [
                ['item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'quantity' => 1],
                ['item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'tideglass_shard', 'item_name' => 'Tideglass Shard', 'rarity' => 'rare', 'quantity' => 1],
                ['item_key' => 'salt_shell', 'item_name' => 'Salt Shell', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'watchtower_walk' => [
            'label' => 'Watchtower Walk',
            'region' => 'Old Gate Ruins',
            'skill' => 'dungeoneering',
            'required_level' => 3,
            'experience' => 62,
            'gold' => 44,
            'supplies' => [
                ['item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'quantity' => 1],
                ['item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'survey_marker', 'item_name' => 'Survey Marker', 'rarity' => 'uncommon', 'quantity' => 1],
                ['item_key' => 'pottery_shard', 'item_name' => 'Pottery Shard', 'rarity' => 'common', 'quantity' => 2],
            ],
        ],
        'harbor_practice' => [
            'label' => 'Harbor Practice',
            'region' => 'Moonwake Harbor',
            'skill' => 'sailing',
            'required_level' => 3,
            'experience' => 56,
            'gold' => 42,
            'supplies' => [
                ['item_key' => 'reed_float', 'item_name' => 'Reed Float', 'quantity' => 1],
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'dock_rope', 'item_name' => 'Dock Rope', 'rarity' => 'common', 'quantity' => 1],
                ['item_key' => 'kelp_frond', 'item_name' => 'Kelp Frond', 'rarity' => 'common', 'quantity' => 2],
            ],
        ],
        'campfire_loop' => [
            'label' => 'Ashcamp Provision Cart',
            'region' => 'Briarwake Camps',
            'skill' => 'survival',
            'required_level' => 3,
            'experience' => 56,
            'gold' => 42,
            'supplies' => [
                ['item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'quantity' => 1],
                ['item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'marrowroot', 'item_name' => 'Marrowroot', 'rarity' => 'common', 'quantity' => 2],
                ['item_key' => 'feather_bundle', 'item_name' => 'Feather Bundle', 'rarity' => 'common', 'quantity' => 2],
            ],
        ],
        'training_ring' => [
            'label' => 'Training Ring',
            'region' => 'Moonwake Yard',
            'skill' => 'combat',
            'required_level' => 3,
            'experience' => 66,
            'gold' => 52,
            'supplies' => [
                ['item_key' => 'training_blade', 'item_name' => 'Training Blade', 'quantity' => 1],
                ['item_key' => 'grain_flatbread', 'item_name' => 'Grain Flatbread', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'battle_sinew', 'item_name' => 'Battle Sinew', 'rarity' => 'uncommon', 'quantity' => 1],
                ['item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'minor_mark' => [
            'label' => 'Minor Mark',
            'region' => 'Briarwake Run',
            'skill' => 'slayer',
            'required_level' => 3,
            'experience' => 68,
            'gold' => 54,
            'supplies' => [
                ['item_key' => 'snare_trigger', 'item_name' => 'Snare Trigger', 'quantity' => 1],
                ['item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'sharp_fang', 'item_name' => 'Sharp Fang', 'rarity' => 'uncommon', 'quantity' => 1],
                ['item_key' => 'marked_trophy_bone', 'item_name' => 'Marked Trophy Bone', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'shield_watch' => [
            'label' => 'Shield Watch',
            'region' => 'Old Gate Front',
            'skill' => 'defense',
            'required_level' => 3,
            'experience' => 64,
            'gold' => 50,
            'supplies' => [
                ['item_key' => 'field_repair_kit', 'item_name' => 'Field Repair Kit', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'rarity' => 'common', 'quantity' => 1],
                ['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'medic_round' => [
            'label' => 'Medic Round',
            'region' => 'Moonwake Infirmary',
            'skill' => 'healing',
            'required_level' => 3,
            'experience' => 64,
            'gold' => 50,
            'supplies' => [
                ['item_key' => 'sap_tonic', 'item_name' => 'Sap Tonic', 'quantity' => 1],
                ['item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'warded_clearing' => [
            'label' => 'Warded Clearing',
            'region' => 'Glimmerfen Verge',
            'skill' => 'magic',
            'required_level' => 3,
            'experience' => 68,
            'gold' => 54,
            'supplies' => [
                ['item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'sealed_rune_chip', 'item_name' => 'Sealed Rune Chip', 'rarity' => 'uncommon', 'quantity' => 1],
                ['item_key' => 'rune_thread', 'item_name' => 'Rune Thread', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'range_walk' => [
            'label' => 'Range Walk',
            'region' => 'Lantern Shoals',
            'skill' => 'ranged',
            'required_level' => 3,
            'experience' => 64,
            'gold' => 50,
            'supplies' => [
                ['item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'quantity' => 1],
                ['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'feather_bundle', 'item_name' => 'Feather Bundle', 'rarity' => 'common', 'quantity' => 2],
                ['item_key' => 'sharp_fang', 'item_name' => 'Sharp Fang', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'notice_delivery' => [
            'label' => 'Notice Delivery',
            'region' => 'Regional Notice Board',
            'skill' => 'reputation',
            'required_level' => 3,
            'experience' => 58,
            'gold' => 46,
            'supplies' => [
                ['item_key' => 'barter_note', 'item_name' => 'Barter Note', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'market_token', 'item_name' => 'Market Token', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'crew_call' => [
            'label' => 'Crew Call',
            'region' => 'Oathhall Yard',
            'skill' => 'leadership',
            'required_level' => 3,
            'experience' => 64,
            'gold' => 52,
            'supplies' => [
                ['item_key' => 'supply_crate', 'item_name' => 'Supply Crate', 'quantity' => 1],
                ['item_key' => 'grain_flatbread', 'item_name' => 'Grain Flatbread', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'banner_commission', 'item_name' => 'Banner Commission', 'rarity' => 'rare', 'quantity' => 1],
            ],
        ],
        'market_loop' => [
            'label' => 'Harbor Price Walk',
            'region' => 'Moonwake Exchange',
            'skill' => 'trading',
            'required_level' => 3,
            'experience' => 58,
            'gold' => 46,
            'supplies' => [
                ['item_key' => 'market_token', 'item_name' => 'Market Token', 'quantity' => 1],
            ],
            'rewards' => [
                ['item_key' => 'merchant_seal', 'item_name' => 'Merchant Seal', 'rarity' => 'rare', 'quantity' => 1],
            ],
        ],
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
                $skillLevel = $this->players->currentSkillLevel($player, $expedition['skill']);
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
                    'skill_label' => str($expedition['skill'])->headline()->toString(),
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
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
        $expedition = self::expeditions()[$expeditionKey] ?? null;

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

            $this->players->awardSkillExperience($player, $expedition['skill'], $expedition['experience']);

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

        self::$expeditionCache = [
            ...self::EXPEDITIONS,
            ...self::STARTER_EXPEDITIONS,
            ...self::expandedExpeditions(),
            ...self::midgameExpeditions(),
            ...self::endgameExpeditions(),
        ];

        return self::$expeditionCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expandedExpeditions(): array
    {
        return [
            'sunken_archive_survey' => self::expedition('Sunken Archive Survey', 'Sunken Archive', 'dungeoneering', 1, 64, 52, [
                ['item_key' => 'route_map', 'item_name' => 'Route Map', 'quantity' => 1],
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
            ], [
                ['item_key' => 'ancient_tablet', 'item_name' => 'Ancient Tablet', 'rarity' => 'rare', 'quantity' => 1],
                ['item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'rarity' => 'uncommon', 'quantity' => 2],
            ]),
            'coastal_cargo_run' => self::expedition('Coastal Cargo Manifest', 'Moonwake Coast', 'sailing', 1, 58, 54, [
                ['item_key' => 'skiff_rib', 'item_name' => 'Skiff Rib', 'quantity' => 1],
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'quantity' => 1],
            ], [
                ['item_key' => 'tideglass_shard', 'item_name' => 'Tideglass Shard', 'rarity' => 'rare', 'quantity' => 1],
                ['item_key' => 'reef_eel', 'item_name' => 'Reef Eel', 'rarity' => 'uncommon', 'quantity' => 2],
            ]),
            'redfang_warband' => self::expedition('Redfang Warband', 'Redfang Break', 'combat', 1, 72, 68, [
                ['item_key' => 'iron_knife', 'item_name' => 'Iron Knife', 'quantity' => 1],
                ['item_key' => 'hunter_ration', 'item_name' => 'Hunter Ration', 'quantity' => 1],
            ], [
                ['item_key' => 'battle_sinew', 'item_name' => 'Battle Sinew', 'rarity' => 'uncommon', 'quantity' => 2],
                ['item_key' => 'sharp_fang', 'item_name' => 'Sharp Fang', 'rarity' => 'uncommon', 'quantity' => 1],
            ]),
            'storm_route_chart' => self::expedition('Stormglass Sea Chart', 'Stormward Sea', 'cartography', 20, 94, 86, [
                ['item_key' => 'dungeon_chart', 'item_name' => 'Dungeon Chart', 'quantity' => 1],
                ['item_key' => 'survey_compass', 'item_name' => 'Survey Compass', 'quantity' => 1],
            ], [
                ['item_key' => 'secret_atlas_leaf', 'item_name' => 'Secret Atlas Leaf', 'rarity' => 'epic', 'quantity' => 1],
            ]),
            'deep_sanctum_clear' => self::expedition('Deep Sanctum Clear', 'Buried Gate Core', 'dungeoneering', 25, 104, 94, [
                ['item_key' => 'dungeon_chart', 'item_name' => 'Dungeon Chart', 'quantity' => 1],
                ['item_key' => 'revival_salve', 'item_name' => 'Revival Salve', 'quantity' => 1],
            ], [
                ['item_key' => 'gate_core', 'item_name' => 'Gate Core', 'rarity' => 'epic', 'quantity' => 1],
                ['item_key' => 'rune_slate', 'item_name' => 'Rune Slate', 'rarity' => 'rare', 'quantity' => 1],
            ]),
            'storm_fleet_supply' => self::expedition('Storm Fleet Supply', 'Stormward Sea', 'sailing', 25, 98, 92, [
                ['item_key' => 'cargo_skiff', 'item_name' => 'Cargo Skiff', 'quantity' => 1],
                ['item_key' => 'silk_sail', 'item_name' => 'Silk Sail', 'quantity' => 1],
            ], [
                ['item_key' => 'captains_writ', 'item_name' => "Captain's Writ", 'rarity' => 'rare', 'quantity' => 1],
            ]),
            'hostile_region_camp' => self::expedition('Hostile Region Camp', 'Crownbeast Range', 'survival', 20, 92, 86, [
                ['item_key' => 'dusk_feast', 'item_name' => 'Dusk Feast', 'quantity' => 1],
                ['item_key' => 'reinforced_pack', 'item_name' => 'Reinforced Pack', 'quantity' => 1],
            ], [
                ['item_key' => 'primal_hide', 'item_name' => 'Primal Hide', 'rarity' => 'rare', 'quantity' => 1],
                ['item_key' => 'spirit_fruit', 'item_name' => 'Spirit Fruit', 'rarity' => 'epic', 'quantity' => 1],
            ]),
            'council_envoy_run' => self::expedition('Council Envoy Visit', 'Regional Council', 'reputation', 20, 90, 88, [
                ['item_key' => 'merchant_seal', 'item_name' => 'Merchant Seal', 'quantity' => 1],
                ['item_key' => 'guild_table', 'item_name' => 'Oathhall Table', 'quantity' => 1],
            ], [
                ['item_key' => 'council_token', 'item_name' => 'Council Token', 'rarity' => 'rare', 'quantity' => 1],
            ]),
            'raid_supply_push' => self::expedition('Raid Supply Push', 'Old Gate Front', 'leadership', 25, 108, 100, [
                ['item_key' => 'watchtower_frame', 'item_name' => 'Watchtower Frame', 'quantity' => 1],
                ['item_key' => 'dusk_feast', 'item_name' => 'Dusk Feast', 'quantity' => 1],
            ], [
                ['item_key' => 'banner_commission', 'item_name' => 'Banner Commission', 'rarity' => 'rare', 'quantity' => 1],
            ]),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function midgameExpeditions(): array
    {
        $skills = [
            'combat' => ['region' => 'Midfield Trial Grounds', 'supply' => 'smithing', 'reward' => 'combat_badge', 'labels' => [15 => 'Silverbank Guard Road', 20 => 'Sablecross Vanguard Drill', 25 => 'Runebound Field Assignment', 30 => 'Moonwake Shield Break', 35 => 'Stormglass Champion Yard', 40 => 'Starline Warband Push', 45 => 'Crownline Trial March']],
            'slayer' => ['region' => 'Marked Hunt Trail', 'supply' => 'leatherworking', 'reward' => 'slayer_mark', 'labels' => [15 => 'Silverbank Fang Trail', 20 => 'Sablecross Bounty Line', 25 => 'Runebound Weakness Hunt', 30 => 'Moonwake Stalker Den', 35 => 'Stormglass Nightfang Mark', 40 => 'Starline Greatbeast Warrant', 45 => 'Crownline Trophy Road']],
            'defense' => ['region' => 'Shield Line Post', 'supply' => 'construction', 'reward' => 'defense_badge', 'labels' => [15 => 'Silverbank Gate Brace', 20 => 'Sablecross Repair Roster', 25 => 'Runebound Armor Hold', 30 => 'Moonwake Party Guard', 35 => 'Stormglass Bulwark Post', 40 => 'Starline Dungeon Guard', 45 => 'Crownline Wallbreaker Stand']],
            'healing' => ['region' => 'Field Medic Route', 'supply' => 'alchemy', 'reward' => 'healing_writ', 'labels' => [15 => 'Silverbank Triage Cart', 20 => 'Sablecross Sap Tonic Run', 25 => 'Runebound Recovery Tent', 30 => 'Moonwake Medic Kit Walk', 35 => 'Stormglass Stabilizer Route', 40 => 'Starline Field Hospital', 45 => 'Crownline Revival Path']],
            'magic' => ['region' => 'Moon Ward Circle', 'supply' => 'enchanting', 'reward' => 'magic_seal', 'labels' => [15 => 'Silverbank Rune Thread Road', 20 => 'Sablecross Ward Circle', 25 => 'Runebound Element Focus', 30 => 'Moonwake Ritual Night', 35 => 'Stormglass Arcane Weather', 40 => 'Starline Sealed Reading', 45 => 'Crownline Spellguard Route']],
            'ranged' => ['region' => 'High Perch Range', 'supply' => 'carpentry', 'reward' => 'ranged_mark', 'labels' => [15 => 'Silverbank Bow Sightline', 20 => 'Sablecross Arrow Stock', 25 => 'Runebound Special Shot', 30 => 'Moonwake Siege Range', 35 => 'Stormglass Trail Bow Refit', 40 => 'Starline Trick Shot Walk', 45 => 'Crownline Skywatch Quiver']],
            'exploration' => ['region' => 'Hidden Mile Route', 'supply' => 'cartography', 'reward' => 'explorer_badge', 'labels' => [15 => 'Silverbank Sketch Route', 20 => 'Sablecross Regional Path', 25 => 'Runebound Hidden Room', 30 => 'Moonwake Distant Trail', 35 => 'Stormglass Ancient Gate', 40 => 'Starline Frontier Proof', 45 => 'Crownline Worldwalker Waybill']],
            'dungeoneering' => ['region' => 'Lower Vault Wing', 'supply' => 'cartography', 'reward' => 'vault_key', 'labels' => [15 => 'Silverbank Room Check', 20 => 'Sablecross Trap Read', 25 => 'Runebound Party Route', 30 => 'Moonwake Boss Room Stock', 35 => 'Stormglass Dungeon Audit', 40 => 'Starline Deep Chamber', 45 => 'Crownline Vault Key Report']],
            'sailing' => ['region' => 'Stormbreak Channel', 'supply' => 'boatbuilding', 'reward' => 'sailing_writ', 'labels' => [15 => 'Silverbank Dock Rope', 20 => 'Sablecross Coastal Trip', 25 => 'Runebound Cargo Manifest', 30 => 'Moonwake Fleet Support', 35 => 'Stormbreak Glassway Crossing', 40 => 'Starline Harbor Signal', 45 => 'Crownline Tide Captain Lot']],
            'survival' => ['region' => 'Cold Camp Circuit', 'supply' => 'cooking', 'reward' => 'survival_mark', 'labels' => [15 => 'Silverbank Flatbread Cache', 20 => 'Sablecross Weather Read', 25 => 'Runebound Long Trip Stock', 30 => 'Moonwake Hazard Kit', 35 => 'Stormglass Hostile Region', 40 => 'Starline Campcraft Ledger', 45 => 'Crownline Last Light Cache']],
            'cartography' => ['region' => 'Surveyor Ridge', 'supply' => 'cartography', 'reward' => 'survey_writ', 'labels' => [15 => 'Silverbank Survey Note', 20 => 'Sablecross Route Map', 25 => 'Runebound Dungeon Chart', 30 => 'Moonwake Region Atlas', 35 => 'Stormglass Secret Road', 40 => 'Starline Survey Parcel', 45 => 'Crownline Starmapper Grid']],
            'reputation' => ['region' => 'Faction Errand Route', 'supply' => 'trading', 'reward' => 'faction_seal', 'labels' => [15 => 'Silverbank Barter Note', 20 => 'Sablecross Favor Seal', 25 => 'Runebound Rate Petition', 30 => 'Moonwake Council Gift', 35 => 'Stormglass Title Claim', 40 => 'Starline Envoy Introduction', 45 => 'Crownline Realm Favor']],
            'leadership' => ['region' => 'Crew Muster Yard', 'supply' => 'construction', 'reward' => 'crew_banner', 'labels' => [15 => 'Silverbank Crate Muster', 20 => 'Sablecross Party Call', 25 => 'Runebound Oathhall Task', 30 => 'Moonwake Raid Brief', 35 => 'Stormglass Banner Drill', 40 => 'Starline Campaign Writ', 45 => 'Crownline Command Tent']],
            'trading' => ['region' => 'Crossroads Brokerage', 'supply' => 'trading', 'reward' => 'trade_writ', 'labels' => [15 => 'Silverbank Token Exchange', 20 => 'Sablecross Bulk Listing', 25 => 'Runebound Work Packet', 30 => 'Moonwake Storefront Stock', 35 => 'Stormglass Route Manifest', 40 => 'Starline Arbitrage Writ', 45 => 'Crownline Merchant Seal']],
        ];
        $tiers = [
            ['level' => 15, 'mark' => 'Silverbank', 'rarity' => 'uncommon', 'experience' => 92, 'gold' => 76],
            ['level' => 20, 'mark' => 'Sablecross', 'rarity' => 'uncommon', 'experience' => 112, 'gold' => 92],
            ['level' => 25, 'mark' => 'Runebound', 'rarity' => 'rare', 'experience' => 132, 'gold' => 108],
            ['level' => 30, 'mark' => 'Moonwake', 'rarity' => 'rare', 'experience' => 156, 'gold' => 128],
            ['level' => 35, 'mark' => 'Stormglass', 'rarity' => 'rare', 'experience' => 184, 'gold' => 148],
            ['level' => 40, 'mark' => 'Starline', 'rarity' => 'epic', 'experience' => 216, 'gold' => 176],
            ['level' => 45, 'mark' => 'Crownline', 'rarity' => 'epic', 'experience' => 252, 'gold' => 204],
        ];
        $expeditions = [];

        foreach ($skills as $skill => $definition) {
            foreach ($tiers as $tier) {
                $level = $tier['level'];
                $expeditions["{$skill}_midgame_expedition_{$level}"] = self::expedition(
                    $definition['labels'][$level],
                    $definition['region'],
                    $skill,
                    $level,
                    $tier['experience'],
                    $tier['gold'],
                    [
                        self::midgameCraftedSupply($definition['supply'], max(20, min(40, $level))),
                    ],
                    [
                        [
                            'item_key' => "{$definition['reward']}_{$level}",
                            'item_name' => "{$tier['mark']} Expedition ".self::rewardDisplayName($definition['reward']),
                            'rarity' => $tier['rarity'],
                            'quantity' => 1,
                        ],
                    ],
                );
            }
        }

        return $expeditions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function endgameExpeditions(): array
    {
        $skills = [
            'combat' => ['region' => 'Champion Trial Grounds', 'supply' => 'smithing', 'reward' => 'champion_crest', 'labels' => [50 => 'Highguard Champion Trial', 75 => 'Mythgate Realmguard Bout', 100 => 'Crownmark Realm Champion Rite']],
            'slayer' => ['region' => 'Crownbeast Hunt Board', 'supply' => 'leatherworking', 'reward' => 'bane_trophy', 'labels' => [50 => 'Highguard Nightfang Hunt', 75 => 'Mythgate Crownbeast Warrant', 100 => 'Crownmark Monster Bane Road']],
            'defense' => ['region' => 'Unbroken Wall', 'supply' => 'construction', 'reward' => 'bulwark_oath', 'labels' => [50 => 'Highguard Party Wall', 75 => 'Mythgate Citadel Bulwark', 100 => 'Crownmark Unbroken Stand']],
            'healing' => ['region' => 'Life Warden Hospice', 'supply' => 'alchemy', 'reward' => 'life_ward', 'labels' => [50 => 'Highguard Expedition Medic', 75 => 'Mythgate Lifewarden Renewal', 100 => 'Crownmark Life Warden Vigil']],
            'magic' => ['region' => 'Archmage Circle', 'supply' => 'enchanting', 'reward' => 'arcane_vow', 'labels' => [50 => 'Highguard Ritual Circle', 75 => 'Mythgate Astral Rite', 100 => 'Crownmark Archmage Channel']],
            'ranged' => ['region' => 'Sky Archer Range', 'supply' => 'carpentry', 'reward' => 'sky_mark', 'labels' => [50 => 'Highguard Marksman Trial', 75 => 'Mythgate Stormshot Range', 100 => 'Crownmark Sky Archer Volley']],
            'exploration' => ['region' => 'Worldwalker Gate', 'supply' => 'cartography', 'reward' => 'worldwalker_token', 'labels' => [50 => 'Highguard Worldwalker Gate', 75 => 'Mythgate Ancient Passage', 100 => 'Crownmark Horizon Crossing']],
            'dungeoneering' => ['region' => 'Deep Warden Halls', 'supply' => 'cartography', 'reward' => 'deep_warden_key', 'labels' => [50 => 'Highguard Labyrinth Route', 75 => 'Mythgate Vault Descent', 100 => 'Crownmark Deep Warden Descent']],
            'sailing' => ['region' => 'Tide Captain Route', 'supply' => 'boatbuilding', 'reward' => 'tide_captain_writ', 'labels' => [50 => 'Highguard Expedition Sail', 75 => 'Mythgate Stormroute Crossing', 100 => 'Crownmark Tide Captain Voyage']],
            'survival' => ['region' => 'Last Light Wilds', 'supply' => 'cooking', 'reward' => 'last_light_brand', 'labels' => [50 => 'Highguard Wild March', 75 => 'Mythgate Hostile Wilds', 100 => 'Crownmark Last Light Trek']],
            'cartography' => ['region' => 'Star Mapper Observatory', 'supply' => 'cartography', 'reward' => 'star_atlas', 'labels' => [50 => 'Highguard Navigator Archive', 75 => 'Mythgate Secret Atlas', 100 => 'Crownmark Star Mapper Draft']],
            'reputation' => ['region' => 'Realm Envoy Council', 'supply' => 'trading', 'reward' => 'envoy_seal', 'labels' => [50 => 'Highguard Envoy Hearing', 75 => 'Mythgate Council Seat', 100 => 'Crownmark Envoy Address']],
            'leadership' => ['region' => 'Bannerlord Campaign', 'supply' => 'construction', 'reward' => 'bannerlord_standard', 'labels' => [50 => 'Highguard War Table', 75 => 'Mythgate Campaign Standard', 100 => 'Crownmark Bannerlord Muster']],
            'trading' => ['region' => 'Market Sovereign Exchange', 'supply' => 'trading', 'reward' => 'sovereign_ledger', 'labels' => [50 => 'Highguard Market Ledger', 75 => 'Mythgate Royal Exchange', 100 => 'Crownmark Sovereign Market']],
        ];
        $tiers = [
            ['level' => 50, 'mark' => 'Highguard', 'rarity' => 'rare', 'experience' => 180, 'gold' => 160],
            ['level' => 75, 'mark' => 'Mythgate', 'rarity' => 'epic', 'experience' => 300, 'gold' => 260],
            ['level' => 100, 'mark' => 'Crownmark', 'rarity' => 'legendary', 'experience' => 520, 'gold' => 440],
        ];
        $expeditions = [];

        foreach ($skills as $skill => $definition) {
            foreach ($tiers as $tier) {
                $level = $tier['level'];
                $expeditions["{$skill}_expedition_{$level}"] = self::expedition(
                    $definition['labels'][$level],
                    $definition['region'],
                    $skill,
                    $level,
                    $tier['experience'],
                    $tier['gold'],
                    [
                        self::craftedSupply($definition['supply'], $level >= 75 ? $level : 55),
                    ],
                    [
                        [
                            'item_key' => "{$definition['reward']}_{$level}",
                            'item_name' => "{$tier['mark']} Expedition ".self::rewardDisplayName($definition['reward']),
                            'rarity' => $tier['rarity'],
                            'quantity' => 1,
                        ],
                    ],
                );
            }
        }

        return $expeditions;
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $supplies
     * @param  list<array{item_key: string, item_name: string, rarity: string, quantity: int}>  $rewards
     * @return array<string, mixed>
     */
    private static function expedition(string $label, string $region, string $skill, int $requiredLevel, int $experience, int $gold, array $supplies, array $rewards): array
    {
        return [
            'label' => $label,
            'region' => $region,
            'skill' => $skill,
            'required_level' => $requiredLevel,
            'experience' => $experience,
            'gold' => $gold,
            'supplies' => $supplies,
            'rewards' => $rewards,
        ];
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedSupply(string $skill, int $level): array
    {
        return [
            'item_key' => str("{$skill} endgame work {$level}")->slug('_')->toString(),
            'item_name' => self::craftedSupplyName($skill, $level),
            'quantity' => $level >= 100 ? 2 : 1,
        ];
    }

    private static function craftedSupplyName(string $skill, int $level): string
    {
        return GeneratedItemNameService::endgameCraftOutputName($skill, $level);
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function midgameCraftedSupply(string $skill, int $level): array
    {
        return [
            'item_key' => str("{$skill} midgame work {$level}")->slug('_')->toString(),
            'item_name' => self::midgameCraftedSupplyName($skill, $level),
            'quantity' => $level >= 35 ? 2 : 1,
        ];
    }

    private static function midgameCraftedSupplyName(string $skill, int $level): string
    {
        return GeneratedItemNameService::midgameCraftOutputName($skill, $level);
    }

    private static function rewardDisplayName(string $reward): string
    {
        return match ($reward) {
            'combat_badge' => 'Sparring Crest',
            'slayer_mark' => 'Fang-Etched Hunt Mark',
            'defense_badge' => 'Shieldwall Rivet',
            'healing_writ' => 'Clean-Bandage Writ',
            'magic_seal' => 'Moonlit Casting Seal',
            'ranged_mark' => 'Feathered Score Tab',
            'explorer_badge' => 'Trail-Etched Compass Plate',
            'vault_key' => 'Notched Vault Key',
            'sailing_writ' => 'Brine-Sealed Sailing Writ',
            'survival_mark' => 'Smoke-Cured Survival Mark',
            'survey_writ' => 'Brass Survey Writ',
            'faction_seal' => 'Pressed Faction Seal',
            'crew_banner' => 'Hemmed Crew Pennant',
            'trade_writ' => 'Broker-Sealed Trade Note',
            'champion_crest' => 'Realm Champion Crest',
            'bane_trophy' => 'Nightfang Bane Trophy',
            'bulwark_oath' => 'Bulwark Oath Plate',
            'life_ward' => 'Life Warden Sigil',
            'arcane_vow' => 'Archmage Vow Seal',
            'sky_mark' => 'Sky Archer Score Tab',
            'worldwalker_token' => 'Worldwalker Trail Token',
            'deep_warden_key' => 'Deep Warden Key',
            'tide_captain_writ' => 'Tide Captain Seal',
            'last_light_brand' => 'Last Light Brand',
            'star_atlas' => 'Star Mapper Atlas',
            'envoy_seal' => 'Realm Envoy Seal',
            'bannerlord_standard' => 'Bannerlord Standard',
            'sovereign_ledger' => 'Sovereign Exchange Ledger',
            default => str($reward)->headline()->toString(),
        };
    }
}
