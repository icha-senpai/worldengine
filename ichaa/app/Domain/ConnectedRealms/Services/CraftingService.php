<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
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

    public function __construct(private ConnectedRealmsPlayerService $players, private ItemCatalogService $items, private ToolEffectService $toolEffects) {}

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
                $tool = $this->players->equipmentForSkill($player, $recipe['skill']);
                $toolModifiers = $this->toolEffects->actionModifiers($tool);
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
                    'equipped_tool' => $this->players->toolPayload($tool),
                    'material_preservation' => [
                        'can_apply' => $this->toolCanModifyRecipe($tool, $requiredLevel) && $this->preservedMaterials($recipe['ingredients'], $toolModifiers['material_preservation']) !== [],
                        'chance' => $toolModifiers['material_preservation'],
                    ],
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

            $tool = $this->players->equipmentForSkill($player, $recipe['skill']);
            $toolModifiers = $this->toolEffects->actionModifiers($tool);
            $preservedMaterials = $this->toolCanModifyRecipe($tool, $requiredLevel)
                ? $this->preservedMaterials($recipe['ingredients'], $toolModifiers['material_preservation'])
                : [];

            foreach ($recipe['ingredients'] as $ingredient) {
                $stack = $stacks->get($ingredient['item_key']);
                $stack->quantity -= $ingredient['quantity'];

                if ($stack->quantity <= 0) {
                    $stack->delete();

                    continue;
                }

                $stack->save();
            }

            $this->grantPreservedMaterials($player, $preservedMaterials);

            $consumed = $this->consumedItems($recipe['ingredients'], $preservedMaterials);

            if ($preservedMaterials !== []) {
                $tool = $this->players->wearEquippedTool($player, $tool);
            }

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

            app(JobContractService::class)->recordItemProgress(
                $player,
                $recipe['skill'],
                $this->contractObjectiveTypeForRecipe($recipe['skill']),
                $outputs,
                $requiredLevel,
            );

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
                'tool' => $this->players->toolPayload($tool),
                'materials_preserved' => $this->items->enrichMany($preservedMaterials),
                'experience_awarded' => $recipe['experience'],
                'gold_cost' => $recipe['gold_cost'],
            ];
        });
    }

    private function toolCanModifyRecipe(?ConnectedRealmsEquipmentSlot $tool, int $requiredLevel): bool
    {
        if ($tool === null || (int) $tool->durability <= 0) {
            return false;
        }

        $toolTierLevel = (int) $tool->tier_level;

        return $toolTierLevel <= 0 ? $requiredLevel <= 1 : $toolTierLevel >= $requiredLevel;
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     * @return list<array{item_key: string, item_name: string, quantity: int}>
     */
    private function preservedMaterials(array $ingredients, int $preservation): array
    {
        if ($preservation <= 0) {
            return [];
        }

        $ingredient = collect($ingredients)
            ->first(fn (array $ingredient): bool => (int) $ingredient['quantity'] > 1);

        if ($ingredient === null) {
            return [];
        }

        return [[
            'item_key' => $ingredient['item_key'],
            'item_name' => $ingredient['item_name'],
            'quantity' => 1,
        ]];
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $materials
     */
    private function grantPreservedMaterials(ConnectedRealmsPlayer $player, array $materials): void
    {
        foreach ($materials as $material) {
            $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                'player_id' => $player->id,
                'item_key' => $material['item_key'],
            ]);

            $stack->fill([
                'item_name' => $material['item_name'],
                'rarity' => 'common',
                'quantity' => (int) $stack->quantity + $material['quantity'],
            ])->save();
        }
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $preservedMaterials
     * @return list<array<string, mixed>>
     */
    private function consumedItems(array $ingredients, array $preservedMaterials): array
    {
        $preservedByKey = collect($preservedMaterials)->pluck('quantity', 'item_key');

        return $this->items->enrichMany(collect($ingredients)
            ->map(fn (array $ingredient): array => [
                ...$ingredient,
                'preserved_quantity' => (int) ($preservedByKey[$ingredient['item_key']] ?? 0),
                'net_quantity' => max(0, (int) $ingredient['quantity'] - (int) ($preservedByKey[$ingredient['item_key']] ?? 0)),
            ])
            ->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function recipes(): array
    {
        if (self::$recipeCache !== null) {
            return self::$recipeCache;
        }

        self::$recipeCache = self::normalizeRequiredLevels(
            app(ConnectedRealmsContentService::class)->apply('crafting_recipes', self::baseRecipes()),
        );

        return self::$recipeCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function baseRecipes(): array
    {
        return [
            ...self::tierLadderRecipes(),
            ...self::toolRecipes(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function toolRecipes(): array
    {
        $tools = new ToolCatalogService;
        $families = $tools->families();
        $tiers = $tools->tierPath();
        $recipes = [];

        foreach ($families as $skill => $family) {
            foreach ($tiers as $tier) {
                $itemName = $tools->tierToolName($family, $tier);
                $itemKey = $tools->tierToolKey($family, $tier);
                $key = "{$itemKey}_craft";
                $extra = self::craftedToolWorkIngredient($family['craft'], $tier['level']);

                $recipes[$key] = [
                    'label' => $itemName,
                    'skill' => $family['craft'],
                    'category' => 'Tools',
                    'required_level' => $tier['level'],
                    'experience' => $tier['xp'],
                    'gold_cost' => 0,
                    'ingredients' => [
                        self::craftedToolBaseIngredient($family, (int) $tier['level']),
                        $extra,
                    ],
                    'outputs' => [[
                        'item_key' => $itemKey,
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
    private static function tierLadderRecipes(): array
    {
        $families = self::recipeTierFamilies();
        $recipes = [];

        foreach ($families as $skill => $family) {
            foreach (EvergatherTierCatalog::tiers() as $tier) {
                $level = (int) $tier['level'];
                $ingredient = self::tierCoverageIngredient($family['ingredient_skill'], $level);
                $outputName = self::tierCoverageRecipeOutputName($skill, $level);
                $outputKey = self::tierCoverageRecipeOutputKey($skill, $level);

                $recipes[$outputKey] = self::itemRecipe(
                    $outputName,
                    $skill,
                    $level,
                    self::tierCoverageExperience($tier),
                    [[
                        ...$ingredient,
                        'quantity' => self::tierCoverageIngredientQuantity($level),
                    ]],
                    [[
                        'item_key' => $outputKey,
                        'item_name' => $outputName,
                        'rarity' => $tier['rarity'],
                        'quantity' => 1,
                    ]],
                    $family['category'],
                );
            }
        }

        return $recipes;
    }

    /**
     * @return array<string, array{noun: string, category: string, ingredient_skill: string}>
     */
    public static function recipeTierFamilies(): array
    {
        return [
            'smelting' => ['noun' => 'Ingot', 'category' => 'Processing', 'ingredient_skill' => 'mining'],
            'milling' => ['noun' => 'Board', 'category' => 'Processing', 'ingredient_skill' => 'woodcutting'],
            'tanning' => ['noun' => 'Leather', 'category' => 'Processing', 'ingredient_skill' => 'hunting'],
            'cutting' => ['noun' => 'Facet', 'category' => 'Processing', 'ingredient_skill' => 'mining'],
            'weaving' => ['noun' => 'Bolt', 'category' => 'Processing', 'ingredient_skill' => 'farming'],
            'smithing' => ['noun' => 'Armament', 'category' => 'Crafting', 'ingredient_skill' => 'mining'],
            'carpentry' => ['noun' => 'Joinery', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'cooking' => ['noun' => 'Meal', 'category' => 'Crafting', 'ingredient_skill' => 'farming'],
            'alchemy' => ['noun' => 'Tonic', 'category' => 'Crafting', 'ingredient_skill' => 'foraging'],
            'tailoring' => ['noun' => 'Pattern', 'category' => 'Crafting', 'ingredient_skill' => 'farming'],
            'leatherworking' => ['noun' => 'Harness', 'category' => 'Crafting', 'ingredient_skill' => 'hunting'],
            'engineering' => ['noun' => 'Assembly', 'category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'enchanting' => ['noun' => 'Oil', 'category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'jewelcrafting' => ['noun' => 'Setting', 'category' => 'Crafting', 'ingredient_skill' => 'mining'],
            'boatbuilding' => ['noun' => 'Rib', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'furniture' => ['noun' => 'Fixture', 'category' => 'Crafting', 'ingredient_skill' => 'woodcutting'],
            'construction' => ['noun' => 'Frame', 'category' => 'Crafting', 'ingredient_skill' => 'excavation'],
            'cartography' => ['noun' => 'Map', 'category' => 'World', 'ingredient_skill' => 'excavation'],
            'trading' => ['noun' => 'Writ', 'category' => 'Social', 'ingredient_skill' => 'farming'],
        ];
    }

    /**
     * @return array{item_key: string, item_name: string}
     */
    private static function tierCoverageIngredient(string $skill, int $level): array
    {
        $outputs = [];

        foreach (GatheringActionService::baseActionDefinitions() as $action) {
            if (($action['skill'] ?? null) !== $skill) {
                continue;
            }

            $actionLevel = (int) ($action['required_level'] ?? 1);
            $loot = $action['loot'][0] ?? null;

            if ($loot === null || isset($outputs[$actionLevel])) {
                continue;
            }

            $outputs[$actionLevel] = [
                'item_key' => (string) ($loot['item_key'] ?? $loot['key']),
                'item_name' => (string) ($loot['item_name'] ?? $loot['name']),
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

    private static function tierCoverageRecipeOutputName(string $skill, int $level): string
    {
        $family = self::recipeTierFamilies()[$skill];
        $mark = EvergatherTierCatalog::markForLevel($level);

        return "{$mark} {$family['noun']}";
    }

    /**
     * @return array{item_key: string, item_name: string}
     */
    public static function tierLadderOutputForSkill(string $skill, int $level): array
    {
        return [
            'item_key' => self::tierCoverageRecipeOutputKey($skill, $level),
            'item_name' => self::tierCoverageRecipeOutputName($skill, $level),
        ];
    }

    private static function tierCoverageRecipeOutputKey(string $skill, int $level): string
    {
        return str($skill.' '.self::tierCoverageRecipeOutputName($skill, $level))->slug('_')->toString();
    }

    /**
     * @param  array{experience: array{int, int}}  $tier
     */
    private static function tierCoverageExperience(array $tier): int
    {
        return (int) round(((int) $tier['experience'][0] + (int) $tier['experience'][1]) / 2);
    }

    private static function tierCoverageIngredientQuantity(int $level): int
    {
        return match (true) {
            $level >= 100 => 4,
            $level >= 65 => 3,
            $level >= 20 => 2,
            default => 2,
        };
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     * @param  list<array<string, mixed>>  $outputs
     * @return array<string, mixed>
     */
    private static function itemRecipe(string $label, string $skill, int $requiredLevel, int $experience, array $ingredients, array $outputs, string $category): array
    {
        $requiredLevel = EvergatherTierCatalog::nextTierLevelFor($requiredLevel);
        $craftTier = EvergatherTierCatalog::itemTierForLevel($requiredLevel);

        return [
            'label' => $label,
            'skill' => $skill,
            'category' => $category,
            'required_level' => $requiredLevel,
            'craft_tier' => $craftTier,
            'experience' => $experience,
            'gold_cost' => 0,
            'ingredients' => $ingredients,
            'outputs' => collect($outputs)
                ->map(fn (array $output): array => [
                    ...$output,
                    'item_tier' => (int) ($output['item_tier'] ?? $craftTier),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $recipes
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeRequiredLevels(array $recipes): array
    {
        return collect($recipes)
            ->map(function (array $recipe): array {
                $requiredLevel = EvergatherTierCatalog::nextTierLevelFor((int) ($recipe['required_level'] ?? 1));

                return [
                    ...$recipe,
                    'required_level' => $requiredLevel,
                    'craft_tier' => (int) ($recipe['craft_tier'] ?? EvergatherTierCatalog::itemTierForLevel($requiredLevel)),
                ];
            })
            ->all();
    }

    private function contractObjectiveTypeForRecipe(string $skill): string
    {
        return in_array($skill, ['smelting', 'milling', 'tanning', 'cutting', 'weaving'], true) ? 'process' : 'craft';
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedToolBaseIngredient(array $family, int $level): array
    {
        $base = (string) $family['base'];
        $sourceSkill = isset(self::recipeTierFamilies()[$base]) ? $base : null;
        $fallback = $sourceSkill === null
            ? ['item_key' => $family['base'], 'item_name' => $family['base_name']]
            : [
                'item_key' => self::tierCoverageRecipeOutputKey($sourceSkill, $level),
                'item_name' => self::tierCoverageRecipeOutputName($sourceSkill, $level),
            ];

        return [
            ...$fallback,
            'quantity' => $level >= 50 ? 3 : 2,
        ];
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    private static function craftedToolWorkIngredient(string $skill, int $level): array
    {
        return self::toolWorkIngredientForSkill($skill, $level);
    }

    /**
     * @return array{item_key: string, item_name: string, quantity: int}
     */
    public static function toolWorkIngredientForSkill(string $skill, int $level): array
    {
        $ingredientSkill = self::recipeTierFamilies()[$skill]['ingredient_skill'] ?? null;
        $ingredient = $ingredientSkill === null
            ? [
                'item_key' => self::tierCoverageRecipeOutputKey($skill, $level),
                'item_name' => self::tierCoverageRecipeOutputName($skill, $level),
            ]
            : self::tierCoverageIngredient($ingredientSkill, $level);

        return [
            ...$ingredient,
            'quantity' => $level >= 100 ? 2 : 1,
        ];
    }
}
