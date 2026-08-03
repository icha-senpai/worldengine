<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CraftingService
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $recipeCache = null;

    /**
     * @var array<string, array{
     *     label: string,
     *     skill: string,
     *     required_level?: int,
     *     experience: int,
     *     gold_cost: int,
     *     ingredients: list<array{item_key: string, item_name: string, quantity: int}>,
     *     outputs: list<array{item_key: string, item_name: string, rarity: string, quantity: int}>
     * }>
     */
    private const RECIPES = [
        'grilled_minnow' => [
            'label' => 'Grilled Minnow',
            'skill' => 'cooking',
            'experience' => 20,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'river_minnow', 'item_name' => 'River Minnow', 'quantity' => 3],
            ],
            'outputs' => [
                ['item_key' => 'grilled_minnow', 'item_name' => 'Grilled Minnow', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'iron_bar' => [
            'label' => 'Iron Bar',
            'skill' => 'smelting',
            'experience' => 22,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'iron_ore', 'item_name' => 'Iron Ore', 'quantity' => 4],
            ],
            'outputs' => [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'ashwood_plank' => [
            'label' => 'Ashwood Plank',
            'skill' => 'carpentry',
            'experience' => 18,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'ashwood_log', 'item_name' => 'Ashwood Log', 'quantity' => 3],
            ],
            'outputs' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'field_tonic' => [
            'label' => 'Field Tonic',
            'skill' => 'alchemy',
            'experience' => 24,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'mooncap_mushroom', 'item_name' => 'Mooncap Mushroom', 'quantity' => 2],
                ['item_key' => 'sunspike_herb', 'item_name' => 'Sunspike Herb', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'cured_leather' => [
            'label' => 'Cured Leather',
            'skill' => 'tanning',
            'experience' => 22,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'soft_hide', 'item_name' => 'Soft Hide', 'quantity' => 3],
            ],
            'outputs' => [
                ['item_key' => 'cured_leather', 'item_name' => 'Cured Leather', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'polished_gem' => [
            'label' => 'Polished Gem',
            'skill' => 'cutting',
            'experience' => 26,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'rough_gem', 'item_name' => 'Rough Gem', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'fiber_thread' => [
            'label' => 'Fiber Thread',
            'skill' => 'weaving',
            'experience' => 18,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'wild_fiber', 'item_name' => 'Wild Fiber', 'quantity' => 3],
            ],
            'outputs' => [
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'rarity' => 'common', 'quantity' => 2],
            ],
        ],
        'iron_knife' => [
            'label' => 'Iron Knife',
            'skill' => 'smithing',
            'experience' => 28,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'iron_knife', 'item_name' => 'Iron Knife', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'trail_bow' => [
            'label' => 'Trail Bow',
            'skill' => 'carpentry',
            'experience' => 30,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'trail_bow', 'item_name' => 'Trail Bow', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'cloth_satchel' => [
            'label' => 'Cloth Satchel',
            'skill' => 'tailoring',
            'experience' => 24,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 2],
            ],
            'outputs' => [
                ['item_key' => 'cloth_satchel', 'item_name' => 'Cloth Satchel', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'leather_grip' => [
            'label' => 'Leather Grip',
            'skill' => 'leatherworking',
            'experience' => 24,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'cured_leather', 'item_name' => 'Cured Leather', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'leather_grip', 'item_name' => 'Leather Grip', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'clockwork_lure' => [
            'label' => 'Clockwork Lure',
            'skill' => 'engineering',
            'experience' => 34,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'clockwork_lure', 'item_name' => 'Clockwork Lure', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'ember_charm' => [
            'label' => 'Ember Charm',
            'skill' => 'enchanting',
            'experience' => 36,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'relic_fragment', 'item_name' => 'Relic Fragment', 'quantity' => 2],
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'ember_charm', 'item_name' => 'Ember Charm', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'silver_ring' => [
            'label' => 'Silver Ring',
            'skill' => 'jewelcrafting',
            'experience' => 32,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'silver_scale', 'item_name' => 'Silver Scale', 'quantity' => 1],
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'silver_ring', 'item_name' => 'Silver Ring', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'skiff_rib' => [
            'label' => 'Skiff Rib',
            'skill' => 'boatbuilding',
            'experience' => 30,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 2],
            ],
            'outputs' => [
                ['item_key' => 'skiff_rib', 'item_name' => 'Skiff Rib', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'trophy_stand' => [
            'label' => 'Trophy Stand',
            'skill' => 'furniture',
            'experience' => 28,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
                ['item_key' => 'marked_trophy_bone', 'item_name' => 'Marked Trophy Bone', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'trophy_stand', 'item_name' => 'Trophy Stand', 'rarity' => 'uncommon', 'quantity' => 1],
            ],
        ],
        'repair_scaffold' => [
            'label' => 'Repair Scaffold',
            'skill' => 'construction',
            'experience' => 34,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'repair_scaffold', 'item_name' => 'Repair Scaffold', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'route_map' => [
            'label' => 'Route Map',
            'skill' => 'cartography',
            'experience' => 30,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'relic_fragment', 'item_name' => 'Relic Fragment', 'quantity' => 1],
                ['item_key' => 'wild_fiber', 'item_name' => 'Wild Fiber', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'route_map', 'item_name' => 'Route Map', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
        'trade_manifest' => [
            'label' => 'Trade Manifest',
            'skill' => 'trading',
            'experience' => 26,
            'gold_cost' => 0,
            'ingredients' => [
                ['item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'quantity' => 2],
                ['item_key' => 'wild_fiber', 'item_name' => 'Wild Fiber', 'quantity' => 1],
            ],
            'outputs' => [
                ['item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'rarity' => 'common', 'quantity' => 1],
            ],
        ],
    ];

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items) {}

    /**
     * @return list<string>
     */
    public static function recipeKeys(): array
    {
        return array_keys(self::recipes());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableRecipesFor(ConnectedRealmsPlayer $player): array
    {
        $inventory = ($player->relationLoaded('inventoryStacks')
            ? $player->inventoryStacks
            : $player->inventoryStacks()->get())
            ->keyBy('item_key');

        return collect(self::recipes())
            ->map(function (array $recipe, string $key) use ($inventory, $player): array {
                $requiredLevel = (int) ($recipe['required_level'] ?? 1);
                $skillLevel = $this->players->currentSkillLevel($player, $recipe['skill']);
                $ingredients = collect($recipe['ingredients'])
                    ->map(function (array $ingredient) use ($inventory): array {
                        $ownedQuantity = (int) ($inventory->get($ingredient['item_key'])?->quantity ?? 0);

                        return $this->items->enrich([
                            ...$ingredient,
                            'owned_quantity' => $ownedQuantity,
                            'has_enough' => $ownedQuantity >= $ingredient['quantity'],
                        ]);
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => $recipe['label'],
                    'skill' => $recipe['skill'],
                    'skill_label' => str($recipe['skill'])->headline()->toString(),
                    'category' => $recipe['category'] ?? 'Crafting',
                    'required_level' => $requiredLevel,
                    'skill_level' => $skillLevel,
                    'is_unlocked' => $skillLevel >= $requiredLevel,
                    'experience' => $recipe['experience'],
                    'gold_cost' => $recipe['gold_cost'],
                    'ingredients' => $ingredients,
                    'outputs' => $this->items->enrichMany($recipe['outputs']),
                    'can_craft' => collect($ingredients)->every(fn (array $ingredient): bool => $ingredient['has_enough'])
                        && $player->gold >= $recipe['gold_cost']
                        && $skillLevel >= $requiredLevel,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function craft(User $user, string $recipeKey): array
    {
        $recipe = self::recipes()[$recipeKey] ?? null;

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => 'That Evergather recipe is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $recipeKey, $recipe): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($player->gold < $recipe['gold_cost']) {
                throw ValidationException::withMessages([
                    'recipe' => 'You do not have enough gold for that recipe.',
                ]);
            }

            $requiredLevel = (int) ($recipe['required_level'] ?? 1);

            if ($this->players->currentSkillLevel($player, $recipe['skill']) < $requiredLevel) {
                throw ValidationException::withMessages([
                    'recipe' => "You need level {$requiredLevel} ".str($recipe['skill'])->headline()->toString().' for that recipe.',
                ]);
            }

            $ingredientKeys = collect($recipe['ingredients'])->pluck('item_key')->all();
            $stacks = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->whereIn('item_key', $ingredientKeys)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_key');

            foreach ($recipe['ingredients'] as $ingredient) {
                $stack = $stacks->get($ingredient['item_key']);

                if ($stack === null || $stack->quantity < $ingredient['quantity']) {
                    throw ValidationException::withMessages([
                        'recipe' => "You need {$ingredient['quantity']} {$ingredient['item_name']} for that recipe.",
                    ]);
                }
            }

            foreach ($recipe['ingredients'] as $ingredient) {
                $stack = $stacks->get($ingredient['item_key']);
                $stack->quantity -= $ingredient['quantity'];

                if ($stack->quantity <= 0) {
                    $stack->delete();

                    continue;
                }

                $stack->save();
            }

            $consumed = $this->items->enrichMany($recipe['ingredients']);
            $outputs = $this->items->enrichMany($recipe['outputs']);

            foreach ($outputs as $output) {
                if (isset($output['equipment_skill'])) {
                    $this->players->equipTool(
                        $player,
                        $output['equipment_skill'],
                        $output['item_key'],
                        $output['item_name'],
                        $output['rarity'],
                        (int) ($output['durability'] ?? 100),
                        $output['bonuses'],
                        'crafted',
                        $player->display_name,
                        (int) $recipe['required_level'],
                    );

                    continue;
                }

                $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                    'player_id' => $player->id,
                    'item_key' => $output['item_key'],
                ]);

                $stack->fill([
                    'item_name' => $output['item_name'],
                    'rarity' => $output['rarity'],
                    'quantity' => (int) $stack->quantity + $output['quantity'],
                ]);
                $stack->save();
            }

            if ($recipe['gold_cost'] > 0) {
                $player->forceFill([
                    'gold' => $player->gold - $recipe['gold_cost'],
                ])->save();
            }

            $this->players->awardSkillExperience($player, $recipe['skill'], $recipe['experience']);

            $log = ConnectedRealmsCraftingLog::create([
                'player_id' => $player->id,
                'recipe_key' => $recipeKey,
                'recipe_name' => $recipe['label'],
                'skill' => $recipe['skill'],
                'items_consumed' => $consumed,
                'items_created' => $outputs,
                'experience_awarded' => $recipe['experience'],
                'gold_cost' => $recipe['gold_cost'],
            ]);

            return [
                'type' => 'crafting',
                'id' => $log->id,
                'recipe_key' => $recipeKey,
                'label' => $recipe['label'],
                'skill' => $recipe['skill'],
                'skill_label' => str($recipe['skill'])->headline()->toString(),
                'items_consumed' => $consumed,
                'items_created' => $outputs,
                'experience_awarded' => $recipe['experience'],
                'gold_cost' => $recipe['gold_cost'],
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function recipes(): array
    {
        if (self::$recipeCache !== null) {
            return self::$recipeCache;
        }

        self::$recipeCache = [
            ...self::RECIPES,
            ...self::earlyRecipes(),
            ...self::expandedRecipes(),
            ...self::midgameRecipes(),
            ...self::endgameRecipes(),
            ...self::toolRecipes(),
        ];

        return self::$recipeCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function earlyRecipes(): array
    {
        return [
            'banked_coal_blend' => self::itemRecipe('Banked Coal Blend', 'smelting', 3, 30, [
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 2],
                ['item_key' => 'chalkstone', 'item_name' => 'Chalkstone', 'quantity' => 1],
            ], [['item_key' => 'banked_coal_blend', 'item_name' => 'Banked Coal Blend', 'rarity' => 'common', 'quantity' => 1]], 'Processing'),
            'copper_nails' => self::itemRecipe('Copper Nails', 'smelting', 8, 42, [
                ['item_key' => 'copper_ore', 'item_name' => 'Copper Ore', 'quantity' => 3],
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 1],
            ], [['item_key' => 'copper_nails', 'item_name' => 'Copper Nails', 'rarity' => 'common', 'quantity' => 4]], 'Processing'),
            'whisperbark_sheet' => self::itemRecipe('Whisperbark Sheet', 'milling', 3, 28, [
                ['item_key' => 'whisperbark', 'item_name' => 'Whisperbark', 'quantity' => 3],
            ], [['item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'rarity' => 'common', 'quantity' => 1]], 'Processing'),
            'ashwood_dowel' => self::itemRecipe('Ashwood Dowel', 'milling', 8, 40, [
                ['item_key' => 'branch_bundle', 'item_name' => 'Branch Bundle', 'quantity' => 3],
                ['item_key' => 'ashwood_log', 'item_name' => 'Ashwood Log', 'quantity' => 1],
            ], [['item_key' => 'ashwood_dowel', 'item_name' => 'Ashwood Dowel', 'rarity' => 'common', 'quantity' => 2]], 'Processing'),
            'soft_leather_strip' => self::itemRecipe('Soft Leather Strip', 'tanning', 3, 30, [
                ['item_key' => 'soft_hide', 'item_name' => 'Soft Hide', 'quantity' => 2],
                ['item_key' => 'bitterroot', 'item_name' => 'Bitterroot', 'quantity' => 1],
            ], [['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'rarity' => 'common', 'quantity' => 2]], 'Processing'),
            'scale_lining' => self::itemRecipe('Scale Lining', 'tanning', 8, 44, [
                ['item_key' => 'bright_scale', 'item_name' => 'Bright Scale', 'quantity' => 2],
                ['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
            ], [['item_key' => 'scale_lining', 'item_name' => 'Scale Lining', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'chipped_gemstone' => self::itemRecipe('Chipped Gemstone', 'cutting', 3, 32, [
                ['item_key' => 'rough_gem', 'item_name' => 'Rough Gem', 'quantity' => 1],
                ['item_key' => 'flint_chip', 'item_name' => 'Flint Chip', 'quantity' => 1],
            ], [['item_key' => 'chipped_gemstone', 'item_name' => 'Chipped Gemstone', 'rarity' => 'common', 'quantity' => 1]], 'Processing'),
            'amber_bead_string' => self::itemRecipe('Amber Bead String', 'cutting', 8, 46, [
                ['item_key' => 'amber_bead', 'item_name' => 'Amber Bead', 'quantity' => 1],
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 1],
            ], [['item_key' => 'amber_bead_string', 'item_name' => 'Amber Bead String', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'reed_cloth' => self::itemRecipe('Reed Cloth', 'weaving', 3, 28, [
                ['item_key' => 'reed_stem', 'item_name' => 'Reed Stem', 'quantity' => 3],
                ['item_key' => 'wild_fiber', 'item_name' => 'Wild Fiber', 'quantity' => 1],
            ], [['item_key' => 'reed_cloth', 'item_name' => 'Reed Cloth', 'rarity' => 'common', 'quantity' => 1]], 'Processing'),
            'twined_cord' => self::itemRecipe('Twined Cord', 'weaving', 8, 38, [
                ['item_key' => 'wild_fiber', 'item_name' => 'Wild Fiber', 'quantity' => 2],
                ['item_key' => 'reed_stem', 'item_name' => 'Reed Stem', 'quantity' => 2],
            ], [['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'rarity' => 'common', 'quantity' => 2]], 'Processing'),
            'iron_fittings' => self::itemRecipe('Iron Fittings', 'smithing', 3, 36, [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 1],
            ], [['item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'rarity' => 'common', 'quantity' => 2]], 'Crafting'),
            'training_blade' => self::itemRecipe('Training Blade', 'smithing', 8, 52, [
                ['item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'quantity' => 1],
                ['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
            ], [['item_key' => 'training_blade', 'item_name' => 'Training Blade', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'ashwood_handle' => self::itemRecipe('Ashwood Handle', 'carpentry', 3, 32, [
                ['item_key' => 'ashwood_dowel', 'item_name' => 'Ashwood Dowel', 'quantity' => 1],
                ['item_key' => 'whisperbark', 'item_name' => 'Whisperbark', 'quantity' => 1],
            ], [['item_key' => 'ashwood_handle', 'item_name' => 'Ashwood Handle', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'marker_stake' => self::itemRecipe('Marker Stake', 'carpentry', 8, 46, [
                ['item_key' => 'ashwood_dowel', 'item_name' => 'Ashwood Dowel', 'quantity' => 1],
                ['item_key' => 'survey_marker', 'item_name' => 'Survey Marker', 'quantity' => 1],
            ], [['item_key' => 'marker_stake', 'item_name' => 'Marker Stake', 'rarity' => 'common', 'quantity' => 2]], 'Crafting'),
            'brine_soup' => self::itemRecipe('Brine Soup', 'cooking', 3, 34, [
                ['item_key' => 'tide_snail', 'item_name' => 'Tide Snail', 'quantity' => 2],
                ['item_key' => 'kelp_frond', 'item_name' => 'Kelp Frond', 'quantity' => 1],
            ], [['item_key' => 'brine_soup', 'item_name' => 'Brine Soup', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'grain_flatbread' => self::itemRecipe('Grain Flatbread', 'cooking', 8, 44, [
                ['item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'quantity' => 2],
                ['item_key' => 'field_bean', 'item_name' => 'Field Bean', 'quantity' => 1],
            ], [['item_key' => 'grain_flatbread', 'item_name' => 'Grain Flatbread', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'bitterroot_paste' => self::itemRecipe('Bitterroot Paste', 'alchemy', 3, 34, [
                ['item_key' => 'bitterroot', 'item_name' => 'Bitterroot', 'quantity' => 2],
                ['item_key' => 'nettle_leaf', 'item_name' => 'Nettle Leaf', 'quantity' => 1],
            ], [['item_key' => 'bitterroot_paste', 'item_name' => 'Bitterroot Paste', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'sap_tonic' => self::itemRecipe('Sap Tonic', 'alchemy', 8, 48, [
                ['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1],
                ['item_key' => 'marrowroot', 'item_name' => 'Marrowroot', 'quantity' => 2],
            ], [['item_key' => 'sap_tonic', 'item_name' => 'Sap Tonic', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'field_wraps' => self::itemRecipe('Field Wraps', 'tailoring', 3, 32, [
                ['item_key' => 'reed_cloth', 'item_name' => 'Reed Cloth', 'quantity' => 1],
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 1],
            ], [['item_key' => 'field_wraps', 'item_name' => 'Field Wraps', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'foragers_pouch' => self::itemRecipe("Forager's Pouch", 'tailoring', 8, 46, [
                ['item_key' => 'reed_cloth', 'item_name' => 'Reed Cloth', 'quantity' => 1],
                ['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'quantity' => 1],
            ], [['item_key' => 'foragers_pouch', 'item_name' => "Forager's Pouch", 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'sinew_binding' => self::itemRecipe('Sinew Binding', 'leatherworking', 3, 34, [
                ['item_key' => 'braided_sinew', 'item_name' => 'Braided Sinew', 'quantity' => 1],
                ['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
            ], [['item_key' => 'sinew_binding', 'item_name' => 'Sinew Binding', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'trail_boots' => self::itemRecipe('Trail Boots', 'leatherworking', 8, 48, [
                ['item_key' => 'cured_leather', 'item_name' => 'Cured Leather', 'quantity' => 1],
                ['item_key' => 'soft_leather_strip', 'item_name' => 'Soft Leather Strip', 'quantity' => 1],
            ], [['item_key' => 'trail_boots', 'item_name' => 'Trail Boots', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'wound_spring' => self::itemRecipe('Wound Spring', 'engineering', 3, 38, [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 1],
                ['item_key' => 'flint_chip', 'item_name' => 'Flint Chip', 'quantity' => 1],
            ], [['item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'snare_trigger' => self::itemRecipe('Snare Trigger', 'engineering', 8, 54, [
                ['item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'quantity' => 1],
                ['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'quantity' => 1],
            ], [['item_key' => 'snare_trigger', 'item_name' => 'Snare Trigger', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'minor_ward_oil' => self::itemRecipe('Minor Ward Oil', 'enchanting', 3, 40, [
                ['item_key' => 'sealed_rune_chip', 'item_name' => 'Sealed Rune Chip', 'quantity' => 1],
                ['item_key' => 'pressed_oil', 'item_name' => 'Pressed Oil', 'quantity' => 1],
            ], [['item_key' => 'minor_ward_oil', 'item_name' => 'Minor Ward Oil', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'rune_thread' => self::itemRecipe('Rune Thread', 'enchanting', 8, 56, [
                ['item_key' => 'sealed_rune_chip', 'item_name' => 'Sealed Rune Chip', 'quantity' => 1],
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 1],
            ], [['item_key' => 'rune_thread', 'item_name' => 'Rune Thread', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'copper_setting' => self::itemRecipe('Copper Setting', 'jewelcrafting', 3, 36, [
                ['item_key' => 'copper_nails', 'item_name' => 'Copper Nails', 'quantity' => 1],
                ['item_key' => 'chipped_gemstone', 'item_name' => 'Chipped Gemstone', 'quantity' => 1],
            ], [['item_key' => 'copper_setting', 'item_name' => 'Copper Setting', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'scale_brooch' => self::itemRecipe('Scale Brooch', 'jewelcrafting', 8, 52, [
                ['item_key' => 'bright_scale', 'item_name' => 'Bright Scale', 'quantity' => 1],
                ['item_key' => 'copper_setting', 'item_name' => 'Copper Setting', 'quantity' => 1],
            ], [['item_key' => 'scale_brooch', 'item_name' => 'Scale Brooch', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'reed_float' => self::itemRecipe('Reed Float', 'boatbuilding', 3, 34, [
                ['item_key' => 'reed_stem', 'item_name' => 'Reed Stem', 'quantity' => 3],
                ['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'quantity' => 1],
            ], [['item_key' => 'reed_float', 'item_name' => 'Reed Float', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'dock_rope' => self::itemRecipe('Dock Rope', 'boatbuilding', 8, 48, [
                ['item_key' => 'twined_cord', 'item_name' => 'Twined Cord', 'quantity' => 2],
                ['item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
            ], [['item_key' => 'dock_rope', 'item_name' => 'Dock Rope', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'ashwood_stool' => self::itemRecipe('Ashwood Stool', 'furniture', 3, 34, [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
                ['item_key' => 'copper_nails', 'item_name' => 'Copper Nails', 'quantity' => 1],
            ], [['item_key' => 'ashwood_stool', 'item_name' => 'Ashwood Stool', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'supply_crate' => self::itemRecipe('Supply Crate', 'furniture', 8, 48, [
                ['item_key' => 'ashwood_plank', 'item_name' => 'Ashwood Plank', 'quantity' => 1],
                ['item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
            ], [['item_key' => 'supply_crate', 'item_name' => 'Supply Crate', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'trail_signpost' => self::itemRecipe('Trail Signpost', 'construction', 3, 38, [
                ['item_key' => 'marker_stake', 'item_name' => 'Marker Stake', 'quantity' => 1],
                ['item_key' => 'copper_nails', 'item_name' => 'Copper Nails', 'quantity' => 1],
            ], [['item_key' => 'trail_signpost', 'item_name' => 'Trail Signpost', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'field_repair_kit' => self::itemRecipe('Field Repair Kit', 'construction', 8, 54, [
                ['item_key' => 'iron_fittings', 'item_name' => 'Iron Fittings', 'quantity' => 1],
                ['item_key' => 'ashwood_handle', 'item_name' => 'Ashwood Handle', 'quantity' => 1],
            ], [['item_key' => 'field_repair_kit', 'item_name' => 'Field Repair Kit', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'sketch_map' => self::itemRecipe('Sketch Map', 'cartography', 3, 36, [
                ['item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 1],
            ], [['item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'rarity' => 'common', 'quantity' => 1]], 'World'),
            'resource_note' => self::itemRecipe('Resource Note', 'cartography', 8, 50, [
                ['item_key' => 'sketch_map', 'item_name' => 'Sketch Map', 'quantity' => 1],
                ['item_key' => 'survey_marker', 'item_name' => 'Survey Marker', 'quantity' => 1],
            ], [['item_key' => 'resource_note', 'item_name' => 'Resource Note', 'rarity' => 'common', 'quantity' => 1]], 'World'),
            'barter_note' => self::itemRecipe('Barter Note', 'trading', 3, 34, [
                ['item_key' => 'whisperbark_sheet', 'item_name' => 'Whisperbark Sheet', 'quantity' => 1],
                ['item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'quantity' => 1],
            ], [['item_key' => 'barter_note', 'item_name' => 'Barter Note', 'rarity' => 'common', 'quantity' => 1]], 'Social'),
            'market_token' => self::itemRecipe('Market Token', 'trading', 8, 48, [
                ['item_key' => 'barter_note', 'item_name' => 'Barter Note', 'quantity' => 1],
                ['item_key' => 'copper_setting', 'item_name' => 'Copper Setting', 'quantity' => 1],
            ], [['item_key' => 'market_token', 'item_name' => 'Market Token', 'rarity' => 'uncommon', 'quantity' => 1]], 'Social'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expandedRecipes(): array
    {
        return [
            'copper_bar' => self::itemRecipe('Copper Bar', 'smelting', 1, 24, [
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 2],
                ['item_key' => 'copper_ore', 'item_name' => 'Copper Ore', 'quantity' => 2],
            ], [['item_key' => 'copper_bar', 'item_name' => 'Copper Bar', 'rarity' => 'common', 'quantity' => 1]], 'Processing'),
            'steel_ingot' => self::itemRecipe('Steel Ingot', 'smelting', 15, 58, [
                ['item_key' => 'iron_bar', 'item_name' => 'Iron Bar', 'quantity' => 2],
                ['item_key' => 'coal_chunk', 'item_name' => 'Coal Chunk', 'quantity' => 3],
            ], [['item_key' => 'steel_ingot', 'item_name' => 'Steel Ingot', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'star_metal_ingot' => self::itemRecipe('Star Metal Ingot', 'smelting', 45, 118, [
                ['item_key' => 'star_metal_ore', 'item_name' => 'Star Metal Ore', 'quantity' => 2],
                ['item_key' => 'void_coal', 'item_name' => 'Void Coal', 'quantity' => 2],
            ], [['item_key' => 'star_metal_ingot', 'item_name' => 'Star Metal Ingot', 'rarity' => 'epic', 'quantity' => 1]], 'Processing'),
            'resin_plank' => self::itemRecipe('Resin Plank', 'milling', 1, 26, [
                ['item_key' => 'ashwood_log', 'item_name' => 'Ashwood Log', 'quantity' => 2],
                ['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1],
            ], [['item_key' => 'resin_plank', 'item_name' => 'Resin Plank', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'heartwood_beam' => self::itemRecipe('Heartwood Beam', 'milling', 45, 112, [
                ['item_key' => 'heartwood_log', 'item_name' => 'Heartwood Log', 'quantity' => 2],
                ['item_key' => 'amber_resin', 'item_name' => 'Amber Resin', 'quantity' => 2],
            ], [['item_key' => 'heartwood_beam', 'item_name' => 'Heartwood Beam', 'rarity' => 'rare', 'quantity' => 1]], 'Processing'),
            'reinforced_leather' => self::itemRecipe('Reinforced Leather', 'tanning', 15, 54, [
                ['item_key' => 'cured_leather', 'item_name' => 'Cured Leather', 'quantity' => 2],
                ['item_key' => 'braided_sinew', 'item_name' => 'Braided Sinew', 'quantity' => 2],
            ], [['item_key' => 'reinforced_leather', 'item_name' => 'Reinforced Leather', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'monster_hide_panel' => self::itemRecipe('Monster Hide Panel', 'tanning', 45, 108, [
                ['item_key' => 'monster_hide', 'item_name' => 'Monster Hide', 'quantity' => 1],
                ['item_key' => 'battle_sinew', 'item_name' => 'Battle Sinew', 'quantity' => 2],
            ], [['item_key' => 'monster_hide_panel', 'item_name' => 'Monster Hide Panel', 'rarity' => 'rare', 'quantity' => 1]], 'Processing'),
            'prism_lens' => self::itemRecipe('Prism Lens', 'cutting', 15, 62, [
                ['item_key' => 'prism_geode', 'item_name' => 'Prism Geode', 'quantity' => 1],
                ['item_key' => 'polished_gem', 'item_name' => 'Polished Gem', 'quantity' => 1],
            ], [['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'rarity' => 'rare', 'quantity' => 1]], 'Processing'),
            'silk_bolt' => self::itemRecipe('Silk Bolt', 'weaving', 15, 52, [
                ['item_key' => 'silk_moss', 'item_name' => 'Silk Moss', 'quantity' => 3],
                ['item_key' => 'fiber_thread', 'item_name' => 'Fiber Thread', 'quantity' => 1],
            ], [['item_key' => 'silk_bolt', 'item_name' => 'Silk Bolt', 'rarity' => 'uncommon', 'quantity' => 1]], 'Processing'),
            'spellthread' => self::itemRecipe('Spellthread', 'weaving', 45, 106, [
                ['item_key' => 'spirit_orchid', 'item_name' => 'Spirit Orchid', 'quantity' => 1],
                ['item_key' => 'dreamleaf', 'item_name' => 'Dreamleaf', 'quantity' => 2],
            ], [['item_key' => 'spellthread', 'item_name' => 'Spellthread', 'rarity' => 'epic', 'quantity' => 1]], 'Processing'),
            'hunter_ration' => self::itemRecipe('Hunter Ration', 'cooking', 1, 30, [
                ['item_key' => 'lean_game_meat', 'item_name' => 'Lean Game Meat', 'quantity' => 2],
                ['item_key' => 'sunfield_grain', 'item_name' => 'Sunfield Grain', 'quantity' => 2],
            ], [['item_key' => 'hunter_ration', 'item_name' => 'Hunter Ration', 'rarity' => 'common', 'quantity' => 1]], 'Crafting'),
            'dusk_feast' => self::itemRecipe('Dusk Feast', 'cooking', 30, 84, [
                ['item_key' => 'dusk_wheat', 'item_name' => 'Dusk Wheat', 'quantity' => 3],
                ['item_key' => 'reef_eel', 'item_name' => 'Reef Eel', 'quantity' => 2],
                ['item_key' => 'pressed_oil', 'item_name' => 'Pressed Oil', 'quantity' => 1],
            ], [['item_key' => 'dusk_feast', 'item_name' => 'Dusk Feast', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'revival_salve' => self::itemRecipe('Revival Salve', 'alchemy', 35, 96, [
                ['item_key' => 'lunar_bloom', 'item_name' => 'Lunar Bloom', 'quantity' => 1],
                ['item_key' => 'field_tonic', 'item_name' => 'Field Tonic', 'quantity' => 1],
                ['item_key' => 'pressed_oil', 'item_name' => 'Pressed Oil', 'quantity' => 1],
            ], [['item_key' => 'revival_salve', 'item_name' => 'Revival Salve', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'reinforced_pack' => self::itemRecipe('Reinforced Pack', 'tailoring', 15, 58, [
                ['item_key' => 'cloth_satchel', 'item_name' => 'Cloth Satchel', 'quantity' => 1],
                ['item_key' => 'reinforced_leather', 'item_name' => 'Reinforced Leather', 'quantity' => 1],
            ], [['item_key' => 'reinforced_pack', 'item_name' => 'Reinforced Pack', 'rarity' => 'uncommon', 'quantity' => 1]], 'Crafting'),
            'silk_sail' => self::itemRecipe('Silk Sail', 'tailoring', 30, 86, [
                ['item_key' => 'silk_bolt', 'item_name' => 'Silk Bolt', 'quantity' => 2],
                ['item_key' => 'spellthread', 'item_name' => 'Spellthread', 'quantity' => 1],
            ], [['item_key' => 'silk_sail', 'item_name' => 'Silk Sail', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'monster_leather_armor' => self::itemRecipe('Monster Leather Armor', 'leatherworking', 45, 118, [
                ['item_key' => 'monster_hide_panel', 'item_name' => 'Monster Hide Panel', 'quantity' => 2],
                ['item_key' => 'apex_claw', 'item_name' => 'Apex Claw', 'quantity' => 1],
            ], [['item_key' => 'monster_leather_armor', 'item_name' => 'Monster Leather Armor', 'rarity' => 'epic', 'quantity' => 1]], 'Crafting'),
            'survey_compass' => self::itemRecipe('Survey Compass', 'engineering', 15, 68, [
                ['item_key' => 'clockwork_spring', 'item_name' => 'Clockwork Spring', 'quantity' => 2],
                ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1],
            ], [['item_key' => 'survey_compass', 'item_name' => 'Survey Compass', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'arcane_focus' => self::itemRecipe('Arcane Focus', 'enchanting', 30, 92, [
                ['item_key' => 'rune_slate', 'item_name' => 'Rune Slate', 'quantity' => 1],
                ['item_key' => 'ember_charm', 'item_name' => 'Ember Charm', 'quantity' => 1],
            ], [['item_key' => 'arcane_focus', 'item_name' => 'Arcane Focus', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'prism_amulet' => self::itemRecipe('Prism Amulet', 'jewelcrafting', 30, 90, [
                ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1],
                ['item_key' => 'pearl_cluster', 'item_name' => 'Pearl Cluster', 'quantity' => 1],
            ], [['item_key' => 'prism_amulet', 'item_name' => 'Prism Amulet', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'cargo_skiff' => self::itemRecipe('Cargo Skiff', 'boatbuilding', 25, 78, [
                ['item_key' => 'skiff_rib', 'item_name' => 'Skiff Rib', 'quantity' => 2],
                ['item_key' => 'silk_sail', 'item_name' => 'Silk Sail', 'quantity' => 1],
            ], [['item_key' => 'cargo_skiff', 'item_name' => 'Cargo Skiff', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'guild_table' => self::itemRecipe('Guild Table', 'furniture', 25, 76, [
                ['item_key' => 'heartwood_beam', 'item_name' => 'Heartwood Beam', 'quantity' => 1],
                ['item_key' => 'trophy_stand', 'item_name' => 'Trophy Stand', 'quantity' => 1],
            ], [['item_key' => 'guild_table', 'item_name' => 'Guild Table', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'watchtower_frame' => self::itemRecipe('Watchtower Frame', 'construction', 30, 88, [
                ['item_key' => 'repair_scaffold', 'item_name' => 'Repair Scaffold', 'quantity' => 2],
                ['item_key' => 'steel_ingot', 'item_name' => 'Steel Ingot', 'quantity' => 1],
            ], [['item_key' => 'watchtower_frame', 'item_name' => 'Watchtower Frame', 'rarity' => 'rare', 'quantity' => 1]], 'Crafting'),
            'dungeon_chart' => self::itemRecipe('Dungeon Chart', 'cartography', 20, 72, [
                ['item_key' => 'ancient_tablet', 'item_name' => 'Ancient Tablet', 'quantity' => 1],
                ['item_key' => 'route_map', 'item_name' => 'Route Map', 'quantity' => 1],
            ], [['item_key' => 'dungeon_chart', 'item_name' => 'Dungeon Chart', 'rarity' => 'rare', 'quantity' => 1]], 'World'),
            'merchant_seal' => self::itemRecipe('Merchant Seal', 'trading', 20, 70, [
                ['item_key' => 'trade_manifest', 'item_name' => 'Trade Manifest', 'quantity' => 1],
                ['item_key' => 'silver_ring', 'item_name' => 'Silver Ring', 'quantity' => 1],
            ], [['item_key' => 'merchant_seal', 'item_name' => 'Merchant Seal', 'rarity' => 'rare', 'quantity' => 1]], 'Social'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function toolRecipes(): array
    {
        $families = (new ToolCatalogService)->families();
        $tiers = [
            ['prefix' => 'Apprentice', 'rarity' => 'uncommon', 'level' => 1, 'xp' => 44, 'experience_bonus' => 9, 'yield_bonus' => 2, 'extra' => ['item_key' => 'amber_sap', 'item_name' => 'Amber Sap', 'quantity' => 1]],
            ['prefix' => 'Guild', 'rarity' => 'rare', 'level' => 20, 'xp' => 86, 'experience_bonus' => 17, 'yield_bonus' => 3, 'extra' => ['item_key' => 'prism_lens', 'item_name' => 'Prism Lens', 'quantity' => 1]],
            ['prefix' => 'Journeyman', 'rarity' => 'rare', 'level' => 25, 'xp' => 98, 'experience_bonus' => 19, 'yield_bonus' => 3, 'extra' => null],
            ['prefix' => 'Artisan', 'rarity' => 'rare', 'level' => 30, 'xp' => 112, 'experience_bonus' => 22, 'yield_bonus' => 4, 'extra' => null],
            ['prefix' => 'Expert', 'rarity' => 'epic', 'level' => 35, 'xp' => 124, 'experience_bonus' => 24, 'yield_bonus' => 4, 'extra' => null],
            ['prefix' => 'Runed', 'rarity' => 'epic', 'level' => 40, 'xp' => 132, 'experience_bonus' => 26, 'yield_bonus' => 5, 'extra' => null],
            ['prefix' => 'Crown', 'rarity' => 'epic', 'level' => 45, 'xp' => 140, 'experience_bonus' => 27, 'yield_bonus' => 5, 'extra' => null],
            ['prefix' => 'Masterwork', 'rarity' => 'epic', 'level' => 50, 'xp' => 146, 'experience_bonus' => 28, 'yield_bonus' => 5, 'extra' => ['item_key' => 'star_metal_ingot', 'item_name' => 'Star Metal Ingot', 'quantity' => 1]],
            ['prefix' => 'Mythic', 'rarity' => 'epic', 'level' => 75, 'xp' => 220, 'experience_bonus' => 42, 'yield_bonus' => 7, 'extra' => null],
            ['prefix' => 'Ascendant', 'rarity' => 'legendary', 'level' => 100, 'xp' => 340, 'experience_bonus' => 65, 'yield_bonus' => 10, 'extra' => null],
        ];
        $recipes = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $tier) {
                $itemName = "{$tier['prefix']} {$family['noun']}";
                $key = str($itemName)->slug('_')->toString().'_craft';
                $extra = $tier['extra'] ?? self::craftedToolWorkIngredient($family['craft'], $tier['level']);

                $recipes[$key] = [
                    'label' => $itemName,
                    'skill' => $family['craft'],
                    'category' => 'Tools',
                    'required_level' => $tier['level'],
                    'experience' => $tier['xp'],
                    'gold_cost' => 0,
                    'ingredients' => [
                        ['item_key' => $family['base'], 'item_name' => $family['base_name'], 'quantity' => $tier['level'] >= 50 ? 3 : 2],
                        $extra,
                    ],
                    'outputs' => [[
                        'item_key' => str($itemName)->slug('_')->toString(),
                        'item_name' => $itemName,
                        'rarity' => $tier['rarity'],
                        'quantity' => 1,
                        'equipment_skill' => $skill,
                        'durability' => 100,
                        'bonuses' => [
                            'experience' => $tier['experience_bonus'],
                            'yield' => $tier['yield_bonus'],
                        ],
                    ]],
                ];
            }
        }

        return $recipes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function midgameRecipes(): array
    {
        $families = [
            'smelting' => ['category' => 'Processing', 'ingredient_skill' => 'mining'],
            'milling' => ['category' => 'Processing', 'ingredient_skill' => 'woodcutting'],
            'tanning' => ['category' => 'Processing', 'ingredient_skill' => 'hunting'],
            'cutting' => ['category' => 'Processing', 'ingredient_skill' => 'mining'],
            'weaving' => ['category' => 'Processing', 'ingredient_skill' => 'farming'],
            'smithing' => ['category' => 'Crafting', 'ingredient_skill' => 'mining'],
            'carpentry' => ['category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'cooking' => ['category' => 'Crafting', 'ingredient_skill' => 'farming'],
            'alchemy' => ['category' => 'Crafting', 'ingredient_skill' => 'foraging'],
            'tailoring' => ['category' => 'Crafting', 'ingredient_skill' => 'farming'],
            'leatherworking' => ['category' => 'Crafting', 'ingredient_skill' => 'hunting'],
            'engineering' => ['category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'enchanting' => ['category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'jewelcrafting' => ['category' => 'Crafting', 'ingredient_skill' => 'mining'],
            'boatbuilding' => ['category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'furniture' => ['category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'construction' => ['category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'cartography' => ['category' => 'World', 'ingredient_skill' => 'excavation'],
            'trading' => ['category' => 'Social', 'ingredient_skill' => 'farming'],
        ];
        $tiers = [
            ['level' => 20, 'prefix' => 'Silver', 'rarity' => 'uncommon', 'experience' => 74],
            ['level' => 25, 'prefix' => 'Sable', 'rarity' => 'uncommon', 'experience' => 88],
            ['level' => 30, 'prefix' => 'Runed', 'rarity' => 'rare', 'experience' => 106],
            ['level' => 35, 'prefix' => 'Moon', 'rarity' => 'rare', 'experience' => 128],
            ['level' => 40, 'prefix' => 'Storm', 'rarity' => 'epic', 'experience' => 154],
            ['level' => 45, 'prefix' => 'Star', 'rarity' => 'epic', 'experience' => 182],
        ];
        $recipes = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $tier) {
                $level = $tier['level'];
                $outputKey = self::midgameRecipeOutputKey($skill, $level);
                $recipes[$outputKey] = self::itemRecipe(
                    self::midgameRecipeOutputName($skill, $level),
                    $skill,
                    $level,
                    $tier['experience'],
                    [
                        [
                            'item_key' => self::midgameResourceKey($family['ingredient_skill'], min($level, 40)),
                            'item_name' => GeneratedItemNameService::midgameGatheringResourceName($family['ingredient_skill'], $level),
                            'quantity' => $level >= 35 ? 3 : 2,
                        ],
                    ],
                    [
                        [
                            'item_key' => $outputKey,
                            'item_name' => self::midgameRecipeOutputName($skill, $level),
                            'rarity' => $tier['rarity'],
                            'quantity' => 1,
                        ],
                    ],
                    $family['category'],
                );
            }
        }

        return $recipes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function endgameRecipes(): array
    {
        $families = [
            'smelting' => ['noun' => 'Ingot', 'category' => 'Processing', 'ingredient_skill' => 'mining', 'ingredient' => 'ore'],
            'milling' => ['noun' => 'Beam', 'category' => 'Processing', 'ingredient_skill' => 'woodcutting', 'ingredient' => 'log'],
            'tanning' => ['noun' => 'Leather', 'category' => 'Processing', 'ingredient_skill' => 'hunting', 'ingredient' => 'hide'],
            'cutting' => ['noun' => 'Facet', 'category' => 'Processing', 'ingredient_skill' => 'mining', 'ingredient' => 'geode'],
            'weaving' => ['noun' => 'Bolt', 'category' => 'Processing', 'ingredient_skill' => 'farming', 'ingredient' => 'grain'],
            'smithing' => ['noun' => 'Armament', 'category' => 'Crafting', 'ingredient_skill' => 'mining', 'ingredient' => 'ore'],
            'carpentry' => ['noun' => 'Frame', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting', 'ingredient' => 'log'],
            'cooking' => ['noun' => 'Feast', 'category' => 'Crafting', 'ingredient_skill' => 'farming', 'ingredient' => 'fruit'],
            'alchemy' => ['noun' => 'Elixir', 'category' => 'Crafting', 'ingredient_skill' => 'foraging', 'ingredient' => 'bloom'],
            'tailoring' => ['noun' => 'Vestment', 'category' => 'Crafting', 'ingredient_skill' => 'farming', 'ingredient' => 'seed'],
            'leatherworking' => ['noun' => 'Harness', 'category' => 'Crafting', 'ingredient_skill' => 'hunting', 'ingredient' => 'hide'],
            'engineering' => ['noun' => 'Engine', 'category' => 'Crafting', 'ingredient_skill' => 'excavation', 'ingredient' => 'relic'],
            'enchanting' => ['noun' => 'Sigil', 'category' => 'Crafting', 'ingredient_skill' => 'excavation', 'ingredient' => 'rune'],
            'jewelcrafting' => ['noun' => 'Crown', 'category' => 'Crafting', 'ingredient_skill' => 'mining', 'ingredient' => 'geode'],
            'boatbuilding' => ['noun' => 'Hull', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting', 'ingredient' => 'branch'],
            'furniture' => ['noun' => 'Hall Set', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting', 'ingredient' => 'resin'],
            'construction' => ['noun' => 'Citadel Frame', 'category' => 'Crafting', 'ingredient_skill' => 'excavation', 'ingredient' => 'tablet'],
            'cartography' => ['noun' => 'Atlas', 'category' => 'World', 'ingredient_skill' => 'excavation', 'ingredient' => 'rune'],
            'trading' => ['noun' => 'Charter', 'category' => 'Social', 'ingredient_skill' => 'farming', 'ingredient' => 'seed'],
        ];
        $tiers = [
            ['level' => 55, 'prefix' => 'Runed', 'rarity' => 'rare', 'experience' => 150],
            ['level' => 65, 'prefix' => 'Elder', 'rarity' => 'rare', 'experience' => 190],
            ['level' => 75, 'prefix' => 'Mythic', 'rarity' => 'epic', 'experience' => 245],
            ['level' => 85, 'prefix' => 'Astral', 'rarity' => 'epic', 'experience' => 310],
            ['level' => 95, 'prefix' => 'Prismatic', 'rarity' => 'legendary', 'experience' => 395],
            ['level' => 100, 'prefix' => 'Evergather', 'rarity' => 'legendary', 'experience' => 520],
        ];
        $recipes = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $tier) {
                $level = $tier['level'];
                $key = self::endgameRecipeOutputKey($skill, $level);
                $recipes[$key] = self::itemRecipe(
                    self::endgameRecipeOutputName($skill, $level),
                    $skill,
                    $level,
                    $tier['experience'],
                    [
                        [
                            'item_key' => self::endgameResourceKey($family['ingredient_skill'], $tier['prefix'], $family['ingredient'], $level),
                            'item_name' => GeneratedItemNameService::endgameGatheringResourceName($family['ingredient_skill'], $family['ingredient'], $tier['prefix']),
                            'quantity' => $level >= 95 ? 4 : 3,
                        ],
                    ],
                    [
                        [
                            'item_key' => $key,
                            'item_name' => self::endgameRecipeOutputName($skill, $level),
                            'rarity' => $tier['rarity'],
                            'quantity' => 1,
                        ],
                    ],
                    $family['category'],
                );
            }
        }

        return $recipes;
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     * @param  list<array<string, mixed>>  $outputs
     * @return array<string, mixed>
     */
    private static function itemRecipe(string $label, string $skill, int $requiredLevel, int $experience, array $ingredients, array $outputs, string $category): array
    {
        return [
            'label' => $label,
            'skill' => $skill,
            'category' => $category,
            'required_level' => $requiredLevel,
            'experience' => $experience,
            'gold_cost' => 0,
            'ingredients' => $ingredients,
            'outputs' => $outputs,
        ];
    }

    private static function endgameRecipeOutputKey(string $skill, int $level): string
    {
        return str("{$skill} endgame work {$level}")->slug('_')->toString();
    }

    private static function midgameRecipeOutputKey(string $skill, int $level): string
    {
        return str("{$skill} midgame work {$level}")->slug('_')->toString();
    }

    private static function midgameRecipeOutputName(string $skill, int $level): string
    {
        return GeneratedItemNameService::midgameCraftOutputName($skill, $level);
    }

    private static function midgameResourceKey(string $skill, int $level): string
    {
        return str("{$skill} midgame resource {$level}")->slug('_')->toString();
    }

    private static function endgameRecipeOutputName(string $skill, int $level): string
    {
        return GeneratedItemNameService::endgameCraftOutputName($skill, $level);
    }

    private static function endgameResourceKey(string $skill, string $prefix, string $item, int $level): string
    {
        return str("{$skill} {$prefix} {$item} {$level}")->slug('_')->toString();
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedToolWorkIngredient(string $skill, int $level): array
    {
        if ($level < 50) {
            return [
                'item_key' => self::midgameRecipeOutputKey($skill, $level),
                'item_name' => self::midgameRecipeOutputName($skill, $level),
                'quantity' => 1,
            ];
        }

        return [
            'item_key' => self::endgameRecipeOutputKey($skill, $level),
            'item_name' => self::endgameRecipeOutputName($skill, $level),
            'quantity' => $level >= 100 ? 2 : 1,
        ];
    }
}
