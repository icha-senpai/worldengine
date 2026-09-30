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

    private ?array $snapshotMetadata = null;

    private array $databaseTableRows = [];

    private const RARITIES = [
        'Default',
        'Common',
        'Uncommon',
        'Rare',
        'Epic',
        'Legendary',
        'Mythic',
    ];

    public function __construct(private BitcraftSpacetimeSnapshotStore $snapshotStore) {}

    public function isAvailable(): bool
    {
        if (! (bool) config('services.bitcraft_spacetime.enabled', true)) {
            return false;
        }

        if (app()->environment('testing') && ! (bool) config('services.bitcraft_spacetime.enabled_in_tests', false)) {
            return false;
        }

        return $this->databaseMetadata() !== null || $this->snapshot() !== null;
    }

    public function metadata(): array
    {
        $metadata = $this->databaseMetadata() ?? $this->fileMetadata();

        return [
            'enabled' => (bool) config('services.bitcraft_spacetime.enabled', true),
            'available' => $this->isAvailable(),
            'generatedAt' => data_get($metadata, 'generatedAt'),
            'database' => data_get($metadata, 'database'),
            'storage' => data_get($metadata, 'storage'),
            'tables' => data_get($metadata, 'tables', []),
        ];
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

    public function catalogForKeys(array $keys): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => isset($this->catalog()[$key])
                ? [$key => $this->catalog()[$key]]
                : [])
            ->all();
    }

    public function catalogSearch(string $query, int $limit = 100): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $needle = Str::lower($query);

        return collect($this->catalog())
            ->filter(function (array $item) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                return str_contains(Str::lower((string) $item['name']), $needle)
                    || str_contains(Str::lower((string) $item['category']), $needle);
            })
            ->sortBy([
                fn (array $item): string => Str::lower((string) $item['name']),
                fn (array $item): string => (string) $item['kind'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    public function craftingRecipesForIds(array $recipeIds): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $ids = collect($recipeIds)
            ->map(fn (mixed $recipeId): int => (int) $recipeId)
            ->filter(fn (int $recipeId): bool => $recipeId > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $idLookup = $ids->flip();

        return collect($this->tableRows('crafting_recipe_desc'))
            ->filter(fn (array $recipe): bool => $idLookup->has((int) data_get($recipe, 'id')))
            ->mapWithKeys(fn (array $recipe): array => [
                (int) data_get($recipe, 'id') => $this->craftingRecipePayload($recipe),
            ])
            ->all();
    }

    public function toolRateEntries(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return collect($this->tableRows('extraction_recipe_desc'))
            ->filter(fn (array $recipe): bool => count($this->toolRequirements($recipe)) > 0)
            ->map(fn (array $recipe): array => $this->toolRateEntryPayload($recipe))
            ->sortBy([
                fn (array $entry): string => Str::lower((string) data_get($entry, 'skill.name', '')),
                fn (array $entry): int => (int) data_get($entry, 'levelRequirement', 0),
                fn (array $entry): string => Str::lower((string) data_get($entry, 'resource.name', '')),
                fn (array $entry): string => Str::lower((string) data_get($entry, 'verb', '')),
            ])
            ->values()
            ->all();
    }

    private function snapshot(): ?array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $databaseSnapshot = $this->snapshotStore->currentSnapshot();

        if ($databaseSnapshot !== null) {
            return $this->snapshot = $databaseSnapshot;
        }

        $path = $this->snapshotPath();

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
            'tag' => data_get($row, 'tag', $kind === 'cargo' ? 'Cargo' : null),
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

    private function toolRateEntryPayload(array $recipe): array
    {
        $resource = $this->resourcePayload((int) data_get($recipe, 'resource_id'));
        $toolRequirement = $this->toolRequirements($recipe)[0] ?? [];
        $levelRequirement = $this->levelRequirement($recipe);
        $experience = $this->experiencePerProgress($recipe);
        $verb = trim((string) data_get($recipe, 'verb_phrase', 'Extract'));
        $resourceName = (string) data_get($resource, 'name', 'Unknown resource');

        return [
            'id' => (int) data_get($recipe, 'id'),
            'resourceId' => (int) data_get($recipe, 'resource_id'),
            'name' => trim($verb.' '.$resourceName),
            'verb' => $verb,
            'timeRequirement' => (float) data_get($recipe, 'time_requirement', 0),
            'staminaRequirement' => (float) data_get($recipe, 'stamina_requirement', 0),
            'toolDurabilityLost' => (int) data_get($recipe, 'tool_durability_lost', 0),
            'range' => (int) data_get($recipe, 'range', 0),
            'allowUseHands' => (bool) data_get($recipe, 'allow_use_hands', false),
            'showInProgression' => (bool) data_get($recipe, 'show_in_progression', false),
            'levelRequirement' => (int) ($levelRequirement['level'] ?? 0),
            'skill' => [
                'id' => $levelRequirement['skill_id'] ?? $experience['skill_id'] ?? null,
                'name' => $this->skillName($levelRequirement['skill_id'] ?? $experience['skill_id'] ?? null),
            ],
            'tool' => [
                'id' => $toolRequirement['tool_type'] ?? null,
                'name' => $this->toolTypeName($toolRequirement['tool_type'] ?? null),
                'level' => (int) ($toolRequirement['level'] ?? 0),
                'power' => (int) ($toolRequirement['power'] ?? 0),
            ],
            'experiencePerProgress' => [
                'skill_id' => $experience['skill_id'] ?? null,
                'skillName' => $this->skillName($experience['skill_id'] ?? null),
                'quantity' => (float) ($experience['quantity'] ?? 0),
            ],
            'resource' => $resource,
            'spawnedResource' => $this->resourcePayload((int) data_get($resource, 'onDestroyYieldResourceId')),
            'outputs' => $this->displayProbabilisticStacks($this->probabilisticExtractedStacks($recipe)),
            'consumedItems' => $this->displayInputStacks($this->inputStacks(data_get($recipe, 'consumed_item_stacks', []))),
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

    private function inputStacks(array $stacks): array
    {
        return collect($stacks)
            ->map(function (array $stack): array {
                $parsed = Arr::first($this->stacks([$stack]));

                return [
                    ...($parsed ?? []),
                    'consumption_chance' => (float) data_get($stack, 'consumption_chance', data_get($stack, '4', 1)),
                ];
            })
            ->filter(fn (array $stack): bool => filled($stack['item_id'] ?? null))
            ->values()
            ->all();
    }

    private function extractedStacks(array $recipe): array
    {
        return collect($this->probabilisticExtractedStacks($recipe))
            ->map(fn (array $stack): array => Arr::only($stack, ['item_id', 'quantity', 'item_type']))
            ->values()
            ->all();
    }

    private function probabilisticExtractedStacks(array $recipe): array
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

                $parsed = Arr::first($this->stacks([$stack]));

                if (! $parsed) {
                    return null;
                }

                return [
                    ...$parsed,
                    'probability' => (float) data_get($probabilisticStack, 'probability', data_get($probabilisticStack, '1', 1)),
                ];
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
                    'iconAssetName' => data_get($target, 'iconAssetName'),
                    'tier' => data_get($target, 'tier'),
                    'rarity' => data_get($target, 'rarity'),
                ];
            })
            ->values()
            ->all();
    }

    private function displayProbabilisticStacks(array $stacks): array
    {
        return collect($this->displayStacks($stacks))
            ->map(function (array $stack, int $index) use ($stacks): array {
                return [
                    ...$stack,
                    'probability' => (float) data_get($stacks, "{$index}.probability", 1),
                ];
            })
            ->values()
            ->all();
    }

    private function displayInputStacks(array $stacks): array
    {
        return collect($this->displayStacks($stacks))
            ->map(function (array $stack, int $index) use ($stacks): array {
                return [
                    ...$stack,
                    'consumptionChance' => (float) data_get($stacks, "{$index}.consumption_chance", 1),
                ];
            })
            ->values()
            ->all();
    }

    private function tableRows(string $table): array
    {
        if ($this->databaseMetadata() !== null) {
            if (! array_key_exists($table, $this->databaseTableRows)) {
                $this->databaseTableRows[$table] = $this->snapshotStore->tableRows($table);
            }

            return $this->databaseTableRows[$table];
        }

        return data_get($this->snapshot(), "tables.{$table}.rows", []);
    }

    private function databaseMetadata(): ?array
    {
        if ($this->snapshotMetadata !== null) {
            return $this->snapshotMetadata;
        }

        return $this->snapshotMetadata = $this->snapshotStore->currentMetadata();
    }

    private function fileMetadata(): array
    {
        $snapshot = $this->snapshot();

        return [
            'generatedAt' => data_get($snapshot, 'generatedAt'),
            'database' => data_get($snapshot, 'database'),
            'storage' => $snapshot === null ? null : 'file',
            'tables' => collect(data_get($snapshot, 'tables', []))
                ->map(fn (array $table): int => (int) data_get($table, 'count', count(data_get($table, 'rows', []))))
                ->all(),
        ];
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

    private function resourcePayload(int $resourceId): ?array
    {
        if ($resourceId <= 0) {
            return null;
        }

        $resource = collect($this->tableRows('resource_desc'))->firstWhere('id', $resourceId);

        if (! is_array($resource)) {
            return null;
        }

        return [
            'id' => (int) data_get($resource, 'id'),
            'name' => data_get($resource, 'name', 'Unknown resource'),
            'category' => data_get($resource, 'tag'),
            'tier' => data_get($resource, 'tier'),
            'rarity' => $this->rarityName(data_get($resource, 'rarity')),
            'maxHealth' => (int) data_get($resource, 'max_health', 0),
            'ignoreDamage' => (bool) data_get($resource, 'ignore_damage', false),
            'showTimeLeft' => (bool) data_get($resource, 'show_time_left', false),
            'onDestroyYieldResourceId' => (int) data_get($resource, 'on_destroy_yield_resource_id', 0),
            'iconAssetName' => data_get($resource, 'icon_asset_name'),
        ];
    }

    private function toolRequirements(array $recipe): array
    {
        return collect(data_get($recipe, 'tool_requirements', []))
            ->map(fn (array $requirement): array => [
                'tool_type' => data_get($requirement, 'tool_type', data_get($requirement, '0')),
                'level' => data_get($requirement, 'level', data_get($requirement, '1')),
                'power' => data_get($requirement, 'power', data_get($requirement, '2')),
            ])
            ->filter(fn (array $requirement): bool => filled($requirement['tool_type'] ?? null))
            ->values()
            ->all();
    }

    private function levelRequirement(array $recipe): array
    {
        $requirement = data_get($recipe, 'level_requirements.0', []);

        return [
            'skill_id' => data_get($requirement, 'skill_id', data_get($requirement, '0')),
            'level' => data_get($requirement, 'level', data_get($requirement, '1')),
        ];
    }

    private function experiencePerProgress(array $recipe): array
    {
        $experience = data_get($recipe, 'experience_per_progress.0', []);

        return [
            'skill_id' => data_get($experience, 'skill_id', data_get($experience, '0')),
            'quantity' => data_get($experience, 'quantity', data_get($experience, '1', 0)),
        ];
    }

    private function skillName(mixed $skillId): ?string
    {
        if (! filled($skillId)) {
            return null;
        }

        return data_get(collect($this->tableRows('skill_desc'))->firstWhere('id', (int) $skillId), 'name');
    }

    private function toolTypeName(mixed $toolTypeId): ?string
    {
        if (! filled($toolTypeId)) {
            return null;
        }

        return data_get(collect($this->tableRows('tool_type_desc'))->firstWhere('id', (int) $toolTypeId), 'name');
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

    private function snapshotPath(): string
    {
        $path = (string) config('services.bitcraft_spacetime.static_snapshot_path');

        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function rarityName(mixed $rarity): ?string
    {
        if (is_array($rarity)) {
            return self::RARITIES[(int) data_get($rarity, '0')] ?? null;
        }

        return is_string($rarity) && $rarity !== '' ? $rarity : null;
    }
}
