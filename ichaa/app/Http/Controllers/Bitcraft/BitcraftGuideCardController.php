<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BitcraftGuideCardController extends Controller
{
    public function options(Request $request, BitcraftSpacetimeStaticData $spacetime): JsonResponse
    {
        $filters = $request->validate([
            'activity' => ['required', Rule::in(['crafting', 'gathering'])],
            'q' => ['nullable', 'string', 'max:255'],
        ]);
        $query = trim($filters['q'] ?? '');
        $items = $filters['activity'] === 'crafting'
            ? collect($spacetime->craftingTargets($query, 40))
            : collect($spacetime->toolRateEntries())
                ->filter(fn (array $entry): bool => $query === '' || Str::contains(Str::lower(implode(' ', [
                    $entry['name'], data_get($entry, 'skill.name', ''), data_get($entry, 'tool.name', ''),
                    ...array_column($entry['outputs'] ?? [], 'name'),
                ])), Str::lower($query)))
                ->take(40)->map(fn (array $entry): array => [
                    'id' => $entry['id'], 'name' => $entry['name'],
                    'iconAssetName' => data_get($entry, 'resource.iconAssetName'),
                    'category' => data_get($entry, 'skill.name'),
                ])->values();

        return response()->json(['items' => $items, 'available' => $spacetime->isAvailable()]);
    }

    public function data(Request $request, BitcraftSpacetimeStaticData $spacetime): JsonResponse
    {
        $filters = $request->validate([
            'activity' => ['required', Rule::in(['crafting', 'gathering'])],
            'recipeId' => ['nullable', 'integer', 'min:1'],
            'itemId' => ['required_if:activity,crafting', 'nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(['item', 'cargo'])],
        ]);
        abort_unless($spacetime->isAvailable(), 503, 'The game snapshot is unavailable.');

        if ($filters['activity'] === 'crafting') {
            $kind = $filters['kind'] ?? 'item';
            $detail = $spacetime->detail($kind, (int) $filters['itemId']);
            abort_if($detail === null, 404, 'This item is not in the current snapshot.');
            $recipes = $detail['craftingRecipes'] ?? [];
            if (! empty($filters['recipeId'])) {
                abort_unless(collect($recipes)->contains(fn (array $recipe): bool => (int) $recipe['id'] === (int) $filters['recipeId']), 404, 'This crafting recipe is not in the current snapshot.');
            }
            abort_if($recipes === [], 404, 'This crafting recipe is not in the current snapshot.');

            return response()->json(['item' => $detail[$kind], 'recipes' => $recipes, 'snapshot' => $spacetime->metadata()]);
        }

        abort_if(empty($filters['recipeId']), 422, 'Choose a gathering action.');
        $entry = collect($spacetime->toolRateEntries())->firstWhere('id', (int) $filters['recipeId']);
        abort_if($entry === null, 404, 'This gathering action is not in the current snapshot.');

        return response()->json(['entry' => $entry, 'snapshot' => $spacetime->metadata()]);
    }
}
