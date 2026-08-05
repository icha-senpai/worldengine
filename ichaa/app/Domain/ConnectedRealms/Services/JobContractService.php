<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Collection;
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
        'bait_bucket_order' => ['label' => 'Bait Bucket Order', 'category' => 'Gathering', 'skill' => 'fishing', 'level' => 5, 'experience' => 34, 'gold' => 24, 'item_key' => 'tide_snail', 'item_name' => 'Tide Snail', 'quantity' => 3],
        'copper_assay' => ['label' => 'Copper Assay', 'category' => 'Gathering', 'skill' => 'mining', 'level' => 5, 'experience' => 36, 'gold' => 26, 'item_key' => 'copper_ore', 'item_name' => 'Copper Ore', 'quantity' => 3],
        'kindling_quota' => ['label' => 'Kindling Quota', 'category' => 'Gathering', 'skill' => 'woodcutting', 'level' => 5, 'experience' => 32, 'gold' => 24, 'item_key' => 'branch_bundle', 'item_name' => 'Branch Bundle', 'quantity' => 3],
        'seed_cache_sort' => ['label' => 'Seed Cache Sort', 'category' => 'Gathering', 'skill' => 'foraging', 'level' => 5, 'experience' => 30, 'gold' => 22, 'item_key' => 'common_seed', 'item_name' => 'Sunfield Seed Mix', 'quantity' => 3],
        'camp_meat_delivery' => ['label' => 'Camp Meat Delivery', 'category' => 'Gathering', 'skill' => 'hunting', 'level' => 5, 'experience' => 38, 'gold' => 28, 'item_key' => 'small_game_meat', 'item_name' => 'Small Game Meat', 'quantity' => 3],
        'bean_row_sample' => ['label' => 'Bean Row Sample', 'category' => 'Gathering', 'skill' => 'farming', 'level' => 10, 'experience' => 42, 'gold' => 30, 'item_key' => 'field_bean', 'item_name' => 'Field Bean', 'quantity' => 3],
        'pottery_sort' => ['label' => 'Pottery Sort', 'category' => 'Gathering', 'skill' => 'excavation', 'level' => 5, 'experience' => 38, 'gold' => 28, 'item_key' => 'pottery_shard', 'item_name' => 'Pottery Shard', 'quantity' => 3],
        'banked_forge_shift' => ['label' => 'Banked Forge Shift', 'category' => 'Processing', 'skill' => 'smelting', 'level' => 5, 'experience' => 42, 'gold' => 32, 'item_key' => 'banked_coal_blend', 'item_name' => 'Banked Coal Blend', 'quantity' => 1],
        'bark_sheet_bundle' => ['label' => 'Bark Sheet Bundle', 'category' => 'Processing', 'skill' => 'milling', 'level' => 5, 'experience' => 38, 'gold' => 30, 'item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
        'leather_strip_order' => ['label' => 'Leather Strip Order', 'category' => 'Processing', 'skill' => 'tanning', 'level' => 5, 'experience' => 42, 'gold' => 32, 'item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
        'gem_chip_packet' => ['label' => 'Gem Chip Packet', 'category' => 'Processing', 'skill' => 'cutting', 'level' => 5, 'experience' => 44, 'gold' => 34, 'item_key' => 'chipped_gemstone', 'item_name' => 'Chipped Gemstone', 'quantity' => 1],
        'reed_cloth_roll' => ['label' => 'Reed Cloth Roll', 'category' => 'Processing', 'skill' => 'weaving', 'level' => 5, 'experience' => 38, 'gold' => 30, 'item_key' => 'reed_cloth', 'item_name' => 'Reed Cloth', 'quantity' => 1],
        'fittings_batch' => ['label' => 'Fittings Batch', 'category' => 'Workshop', 'skill' => 'smithing', 'level' => 5, 'experience' => 46, 'gold' => 36, 'item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'quantity' => 1],
        'handle_lot' => ['label' => 'Handle Lot', 'category' => 'Workshop', 'skill' => 'carpentry', 'level' => 5, 'experience' => 42, 'gold' => 34, 'item_key' => 'ashwood_handle', 'item_name' => 'Ashwood Handle', 'quantity' => 1],
        'soup_kettle' => ['label' => 'Soup Kettle', 'category' => 'Provisioning', 'skill' => 'cooking', 'level' => 5, 'experience' => 44, 'gold' => 34, 'item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'quantity' => 1],
        'paste_vials' => ['label' => 'Paste Vials', 'category' => 'Support', 'skill' => 'alchemy', 'level' => 5, 'experience' => 46, 'gold' => 36, 'item_key' => 'bitterroot_paste', 'item_name' => 'Bitterroot Paste', 'quantity' => 1],
        'wrap_bundle' => ['label' => 'Wrap Bundle', 'category' => 'Workshop', 'skill' => 'tailoring', 'level' => 5, 'experience' => 42, 'gold' => 34, 'item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'quantity' => 1],
        'binding_order' => ['label' => 'Binding Order', 'category' => 'Workshop', 'skill' => 'leatherworking', 'level' => 5, 'experience' => 44, 'gold' => 34, 'item_key' => 'sinew_binding', 'item_name' => 'Sinew Binding', 'quantity' => 1],
        'spring_calibration' => ['label' => 'Spring Calibration', 'category' => 'Workshop', 'skill' => 'engineering', 'level' => 5, 'experience' => 48, 'gold' => 38, 'item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'quantity' => 1],
        'ward_oil_request' => ['label' => 'Ward Oil Request', 'category' => 'Arcane', 'skill' => 'enchanting', 'level' => 5, 'experience' => 50, 'gold' => 40, 'item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'quantity' => 1],
        'copper_setting_lot' => ['label' => 'Gemsetter Copper Lot', 'category' => 'Luxury', 'skill' => 'jewelcrafting', 'level' => 5, 'experience' => 46, 'gold' => 36, 'item_key' => 'copper_setting', 'item_name' => 'Copper Setting', 'quantity' => 1],
        'reed_float_bundle' => ['label' => 'Reed Float Bundle', 'category' => 'Settlement', 'skill' => 'boatbuilding', 'level' => 5, 'experience' => 44, 'gold' => 34, 'item_key' => 'reed_float', 'item_name' => 'Reed Float', 'quantity' => 1],
        'stool_delivery' => ['label' => 'Stool Delivery', 'category' => 'Settlement', 'skill' => 'furniture', 'level' => 5, 'experience' => 44, 'gold' => 34, 'item_key' => 'ashwood_stool', 'item_name' => 'Ashwood Stool', 'quantity' => 1],
        'signpost_crew' => ['label' => 'Signpost Crew', 'category' => 'Settlement', 'skill' => 'construction', 'level' => 5, 'experience' => 50, 'gold' => 40, 'item_key' => 'trail_signpost', 'item_name' => 'Trail Signpost', 'quantity' => 1],
        'blade_drill' => ['label' => 'Blade Drill', 'category' => 'Combat', 'skill' => 'combat', 'level' => 10, 'experience' => 60, 'gold' => 48, 'item_key' => 'training_blade', 'item_name' => 'Training Blade', 'quantity' => 1],
        'fang_study' => ['label' => 'Fang Study', 'category' => 'Combat', 'skill' => 'slayer', 'level' => 10, 'experience' => 62, 'gold' => 50, 'item_key' => 'sharp_fang', 'item_name' => 'Sharp Fang', 'quantity' => 1],
        'repair_line' => ['label' => 'Repair Line', 'category' => 'Combat', 'skill' => 'defense', 'level' => 10, 'experience' => 60, 'gold' => 48, 'item_key' => 'field_repair_kit', 'item_name' => 'Field Repair Kit', 'quantity' => 1],
        'sap_rounds' => ['label' => 'Sap Rounds', 'category' => 'Support', 'skill' => 'healing', 'level' => 10, 'experience' => 60, 'gold' => 48, 'item_key' => 'sap_tonic', 'item_name' => 'Sap Tonic', 'quantity' => 1],
        'rune_thread_watch' => ['label' => 'Runespun Thread Watch', 'category' => 'Arcane', 'skill' => 'magic', 'level' => 10, 'experience' => 64, 'gold' => 52, 'item_key' => 'rune_thread', 'item_name' => 'Rune Thread', 'quantity' => 1],
        'bow_sighting' => ['label' => 'Bow Sighting', 'category' => 'Combat', 'skill' => 'ranged', 'level' => 10, 'experience' => 60, 'gold' => 48, 'item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'quantity' => 1],
        'sketch_route' => ['label' => 'Sketch Route', 'category' => 'World', 'skill' => 'exploration', 'level' => 5, 'experience' => 50, 'gold' => 40, 'item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'quantity' => 1],
        'resource_room_notes' => ['label' => 'Chamber Survey Notes', 'category' => 'World', 'skill' => 'dungeoneering', 'level' => 10, 'experience' => 62, 'gold' => 50, 'item_key' => 'resource_note', 'item_name' => 'Chamber Survey Note', 'quantity' => 1],
        'dock_rope_order' => ['label' => 'Dock Rope Order', 'category' => 'World', 'skill' => 'sailing', 'level' => 10, 'experience' => 58, 'gold' => 48, 'item_key' => 'dock_rope', 'item_name' => 'Dock Rope', 'quantity' => 1],
        'flatbread_cache' => ['label' => 'Flatbread Cache', 'category' => 'World', 'skill' => 'survival', 'level' => 10, 'experience' => 58, 'gold' => 46, 'item_key' => 'grain_flatbread', 'item_name' => 'Grain Flatbread', 'quantity' => 1],
        'resource_note_sale' => ['label' => 'Chamber Note Transfer', 'category' => 'World', 'skill' => 'cartography', 'level' => 10, 'experience' => 58, 'gold' => 46, 'item_key' => 'resource_note', 'item_name' => 'Chamber Survey Note', 'quantity' => 1],
        'barter_errand' => ['label' => 'Barter Errand', 'category' => 'Social', 'skill' => 'reputation', 'level' => 5, 'experience' => 48, 'gold' => 38, 'item_key' => 'barter_note', 'item_name' => 'Barter Note', 'quantity' => 1],
        'crate_muster' => ['label' => 'Crate Muster', 'category' => 'Social', 'skill' => 'leadership', 'level' => 10, 'experience' => 62, 'gold' => 50, 'item_key' => 'supply_crate', 'item_name' => 'Supply Crate', 'quantity' => 1],
        'token_exchange' => ['label' => 'Token Exchange', 'category' => 'Social', 'skill' => 'trading', 'level' => 10, 'experience' => 58, 'gold' => 48, 'item_key' => 'market_token', 'item_name' => 'Market Token', 'quantity' => 1],
    ];

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items, private ItemPurposeService $purposes) {}

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

        $jobCatalog = [
            ...self::jobs(),
            ...$this->itemRequisitionJobsFor($inventory),
        ];

        return collect($jobCatalog)
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
        return DB::transaction(function () use ($user, $jobKey): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = self::jobs()[$jobKey] ?? $this->itemRequisitionJobFor($player, $jobKey);

            if ($job === null) {
                throw ValidationException::withMessages([
                    'job' => 'That Evergather job is not available.',
                ]);
            }

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

        self::$jobCache = self::normalizeRequiredLevels(
            app(ConnectedRealmsContentService::class)->apply('job_contracts', self::baseJobs()),
        );

        return self::$jobCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function baseJobs(): array
    {
        return self::normalizeRequiredLevels([
            ...self::JOBS,
            ...self::starterJobs(),
            ...self::expandedJobs(),
            ...self::midgameJobs(),
            ...self::endgameJobs(),
        ]);
    }

    /**
     * @param  Collection<string, ConnectedRealmsInventoryStack>  $inventory
     * @return array<string, array<string, mixed>>
     */
    private function itemRequisitionJobsFor($inventory): array
    {
        return $inventory
            ->filter(fn (ConnectedRealmsInventoryStack $stack): bool => $stack->quantity > 0)
            ->mapWithKeys(function (ConnectedRealmsInventoryStack $stack): array {
                $job = $this->purposes->requisitionFor([
                    'item_key' => $stack->item_key,
                    'item_name' => $stack->item_name,
                    'rarity' => $stack->rarity,
                ]);

                return [$job['key'] => $job];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemRequisitionJobFor(ConnectedRealmsPlayer $player, string $jobKey): ?array
    {
        $itemKey = $this->purposes->requisitionItemKey($jobKey);

        if ($itemKey === null) {
            return null;
        }

        $stack = ConnectedRealmsInventoryStack::query()
            ->where('player_id', $player->id)
            ->where('item_key', $itemKey)
            ->first();

        if ($stack === null || $stack->quantity <= 0) {
            return null;
        }

        return $this->purposes->requisitionFor([
            'item_key' => $stack->item_key,
            'item_name' => $stack->item_name,
            'rarity' => $stack->rarity,
        ]);
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
            'shield_line_drill' => self::job('Shieldwall Brace Drill', 'Combat', 'defense', 1, 54, 54, [
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
            foreach ([20, 30, 40, 50] as $level) {
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
            foreach ([65, 80, 100] as $level) {
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
        $effectiveLevel = max(20, min(50, $level));

        return match ($skill) {
            'fishing', 'mining', 'woodcutting', 'foraging', 'hunting', 'farming', 'excavation' => [
                'item_key' => self::midgameGatheringResourceKey($skill, $effectiveLevel),
                'item_name' => self::midgameGatheringResourceName($skill, $effectiveLevel),
                'quantity' => $level >= 40 ? 3 : 2,
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
            'required_level' => EvergatherTierCatalog::nextTierLevelFor($requiredLevel),
            'experience' => $experience,
            'gold' => $gold,
            'requirements' => $requirements,
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => $gold],
                ['type' => 'experience', 'label' => str($skill)->headline()->toString().' XP', 'quantity' => $experience],
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeRequiredLevels(array $jobs): array
    {
        return collect($jobs)
            ->map(fn (array $job): array => [
                ...$job,
                'required_level' => EvergatherTierCatalog::nextTierLevelFor((int) ($job['required_level'] ?? 1)),
            ])
            ->all();
    }

    private static function jobTitleFor(string $skill, int $level): string
    {
        $mark = EvergatherTierCatalog::markForLevel($level);
        $label = str($skill)->headline()->toString();

        return match (true) {
            $level >= 100 => "{$mark} {$label} Mandate",
            $level >= 80 => "{$mark} {$label} Claim",
            $level >= 65 => "{$mark} {$label} Writ",
            $level >= 50 => "{$mark} {$label} Commission",
            $level >= 40 => "{$mark} {$label} Request",
            $level >= 30 => "{$mark} {$label} Order",
            default => "{$mark} {$label} Posting",
        };
    }

    private static function jobCategoryForLevel(int $level): string
    {
        return match (true) {
            $level >= 100 => 'Realm Mandates',
            $level >= 80 => 'Mythgate Claims',
            $level >= 65 => 'Elderwake Writs',
            $level >= 50 => 'Highguild Commissions',
            $level >= 40 => 'Stormglass Requests',
            $level >= 30 => 'Runebound Orders',
            $level >= 20 => 'Hearthsign Postings',
            default => 'Posted Commissions',
        };
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function endgameRequirementFor(string $skill, int $level): array
    {
        if ($level < 65) {
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
                    'item_key' => self::midgameCraftOutputKey($skill, 50),
                    'item_name' => self::midgameCraftOutputName($skill, 50),
                    'quantity' => 1,
                ],
            };
        }

        $effectiveLevel = $level >= 100 ? 100 : ($level >= 80 ? 80 : 65);

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
            'quantity' => $level >= 40 ? 2 : 1,
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
        $prefix = EvergatherTierCatalog::markForLevel($level);
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
        $prefix = EvergatherTierCatalog::markForLevel($level);
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
