<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BitcraftSpacetimeStaticData
{
    private ?array $snapshot = null;

    private ?array $catalog = null;

    private ?array $recipeIndexes = null;

    private const RARITIES = [
        'Default',
        'Common',
        'Uncommon',
        'Rare',
        'Epic',
        'Legendary',
        'Mythic',
    ];

    public function isAvailable(): bool
    {
        if (! (bool) config('services.bitcraft_spacetime.enabled', true)) {
            return false;
        }

        if (app()->environment('testing') && ! (bool) config('services.bitcraft_spacetime.enabled_in_tests', false)) {
            return false;
        }

        return $this->snapshot() !== null;
    }

    public function targets(string $query): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $needle = Str::lower($query);
        $indexes = $this->recipeIndexes();

        return collect($this->catalog())
            ->filter(fn (array $item): bool => isset($indexes['recipesByOutputKey'][$this->targetKey($item['kind'], (int) $item['id'])]))
            ->filter(function (array $item) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                return str_contains(Str::lower((string) $item['name']), $needle)
                    || str_contains(Str::lower((string) $item['category']), $needle);
            })
            ->sortBy([
                fn (array $item) => Str::lower((string) $item['name']),
                fn (array $item) => $item['kind'],
            ])
            ->values()
            ->all();
    }

    public function detail(string $kind, int $id): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $kind = $kind === 'cargo' ? 'cargo' : 'item';
        $target = $this->catalog()[$this->targetKey($kind, $id)] ?? null;

        if (! $target) {
            return null;
        }

        $indexes = $this->recipeIndexes();
        $recipes = $indexes['recipesByOutputKey'][$this->targetKey($kind, $id)] ?? [];

        return [
            $kind => $target,
            'craftingRecipes' => collect($recipes['crafting'] ?? [])
                ->map(fn (array $recipe): array => $this->craftingRecipePayload($recipe))
                ->values()
                ->all(),
            'extractionRecipes' => collect($recipes['extraction'] ?? [])
                ->map(fn (array $recipe): array => $this->extractionRecipePayload($recipe, $target))
                ->values()
                ->all(),
            'marketStats' => [],
        ];
    }

    private function snapshot(): ?array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $path = (string) config('services.bitcraft_spacetime.static_snapshot_path');

        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        $snapshot = json_decode(File::get($path), true);

        if (! is_array($snapshot) || ! is_array(data_get($snapshot, 'tables'))) {
            return null;
        }

        return $this->snapshot = $snapshot;
    }

    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $catalog = [];

        foreach ($this->tableRows('item_desc') as $item) {
            $target = $this->targetPayload($item, 'item');
            $catalog[$this->targetKey('item', (int) $target['id'])] = $target;
        }

        foreach ($this->tableRows('cargo_desc') as $cargo) {
            $target = $this->targetPayload($cargo, 'cargo');
            $catalog[$this->targetKey('cargo', (int) $target['id'])] = $target;
        }

        return $this->catalog = $catalog;
    }

    private function recipeIndexes(): array
    {
        if ($this->recipeIndexes !== null) {
            return $this->recipeIndexes;
        }

        $recipesByOutputKey = [];

        foreach ($this->tableRows('crafting_recipe_desc') as $recipe) {
            foreach ($this->stacks(data_get($recipe, 'crafted_item_stacks', [])) as $stack) {
                $recipesByOutputKey[$this->stackKey($stack)]['crafting'][] = $recipe;
            }
        }

        foreach ($this->tableRows('extraction_recipe_desc') as $recipe) {
            foreach ($this->extractedStacks($recipe) as $stack) {
                $recipesByOutputKey[$this->stackKey($stack)]['extraction'][] = $recipe;
            }
        }

        return $this->recipeIndexes = [
            'recipesByOutputKey' => $recipesByOutputKey,
        ];
    }

    private function targetPayload(array $row, string $kind): array
    {
        return [
            'id' => data_get($row, 'id'),
            'kind' => $kind,
            'name' => data_get($row, 'name', 'Unknown item'),
            'category' => data_get($row, 'tag', $kind === 'cargo' ? 'Cargo' : null),
            'tier' => data_get($row, 'tier'),
            'rarity' => $this->rarityName(data_get($row, 'rarity')),
            'description' => data_get($row, 'description'),
            'iconAssetName' => data_get($row, 'icon_asset_name'),
        ];
    }

    private function craftingRecipePayload(array $recipe): array
    {
        $craftedStacks = $this->stacks(data_get($recipe, 'crafted_item_stacks', []));

        return [
            'id' => data_get($recipe, 'id'),
            'recipeName' => data_get($recipe, 'name', 'Recipe'),
            'craftingStation' => $this->buildingRequirementName(data_get($recipe, 'building_requirement')),
            'skillName' => $this->skillName(data_get($recipe, 'level_requirements.0.0')),
            'timeRequirement' => data_get($recipe, 'time_requirement'),
            'outputQuantity' => data_get($craftedStacks, '0.quantity', 1),
            'craftedItems' => $this->displayStacks($craftedStacks),
            'consumedItemStacks' => $this->stacks(data_get($recipe, 'consumed_item_stacks', [])),
            'consumedItems' => $this->displayStacks($this->stacks(data_get($recipe, 'consumed_item_stacks', []))),
        ];
    }

    private function extractionRecipePayload(array $recipe, array $target): array
    {
        $outputs = $this->extractedStacks($recipe);

        return [
            'id' => data_get($recipe, 'id'),
            'recipeName' => trim((string) data_get($recipe, 'verb_phrase', 'Extract').' '.(string) $target['name']),
            'craftingStation' => null,
            'skillName' => $this->skillName(data_get($recipe, 'level_requirements.0.0')),
            'timeRequirement' => data_get($recipe, 'time_requirement'),
            'outputQuantity' => data_get(
                collect($outputs)->first(fn (array $stack): bool => $this->stackKey($stack) === $this->targetKey($target['kind'], (int) $target['id'])),
                'quantity',
                1,
            ),
            'craftedItems' => $this->displayStacks($outputs),
            'consumedItemStacks' => $this->stacks(data_get($recipe, 'consumed_item_stacks', [])),
            'consumedItems' => $this->displayStacks($this->stacks(data_get($recipe, 'consumed_item_stacks', []))),
        ];
    }

    private function stacks(array $stacks): array
    {
        return collect($stacks)
            ->map(fn (array $stack): array => [
                'item_id' => data_get($stack, 'item_id', data_get($stack, '0')),
                'quantity' => data_get($stack, 'quantity', data_get($stack, '1', 1)),
                'item_type' => $this->stackKind(data_get($stack, 'item_type', data_get($stack, '2'))),
            ])
            ->filter(fn (array $stack): bool => filled($stack['item_id']))
            ->values()
            ->all();
    }

    private function extractedStacks(array $recipe): array
    {
        return collect(data_get($recipe, 'extracted_item_stacks', []))
            ->map(function (array $probabilisticStack): ?array {
                $option = data_get($probabilisticStack, '0');

                if (! is_array($option) || (int) data_get($option, '0') !== 0) {
                    return null;
                }

                $stack = data_get($option, '1');

                if (! is_array($stack)) {
                    return null;
                }

                return Arr::first($this->stacks([$stack]));
            })
            ->filter()
            ->values()
            ->all();
    }

    private function displayStacks(array $stacks): array
    {
        return collect($stacks)
            ->map(function (array $stack): array {
                $kind = $stack['item_type'];
                $id = (int) $stack['item_id'];
                $target = $this->catalog()[$this->targetKey($kind, $id)] ?? null;

                return [
                    'id' => $id,
                    'itemId' => $id,
                    'itemType' => $kind,
                    'kind' => $kind,
                    'name' => data_get($target, 'name', 'Unknown'),
                    'itemName' => data_get($target, 'name', 'Unknown'),
                    'quantity' => $stack['quantity'],
                ];
            })
            ->values()
            ->all();
    }

    private function tableRows(string $table): array
    {
        return data_get($this->snapshot(), "tables.{$table}.rows", []);
    }

    private function stackKind(mixed $type): string
    {
        if (is_array($type)) {
            return (int) data_get($type, '0') === 1 ? 'cargo' : 'item';
        }

        return ((string) $type) === '1' || $type === 'cargo' ? 'cargo' : 'item';
    }

    private function stackKey(array $stack): string
    {
        return $this->targetKey((string) $stack['item_type'], (int) $stack['item_id']);
    }

    private function targetKey(string $kind, int $id): string
    {
        return ($kind === 'cargo' ? 'cargo' : 'item').':'.$id;
    }

    private function skillName(mixed $skillId): ?string
    {
        if (! filled($skillId)) {
            return null;
        }

        return data_get(collect($this->tableRows('skill_desc'))->firstWhere('id', (int) $skillId), 'name');
    }

    private function buildingRequirementName(mixed $requirement): ?string
    {
        if (! is_array($requirement) || (int) data_get($requirement, '0') !== 0) {
            return null;
        }

        $buildingType = data_get($requirement, '1.building_type');

        if (! filled($buildingType)) {
            return null;
        }

        return data_get(collect($this->tableRows('building_type_desc'))->firstWhere('id', (int) $buildingType), 'name');
    }

    private function rarityName(mixed $rarity): ?string
    {
        if (is_array($rarity)) {
            return self::RARITIES[(int) data_get($rarity, '0')] ?? null;
        }

        return is_string($rarity) && $rarity !== '' ? $rarity : null;
    }
}
