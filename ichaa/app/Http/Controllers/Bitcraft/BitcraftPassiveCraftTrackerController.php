<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Domain\Bitcraft\Services\BitcraftRelayClient;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Http\Controllers\Bitcraft\Concerns\NormalizesBitcraftWidgetTheme;
use App\Http\Controllers\Bitcraft\Concerns\ScopesBitcraftWidgetProfiles;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class BitcraftPassiveCraftTrackerController extends Controller
{
    use NormalizesBitcraftWidgetTheme;
    use ScopesBitcraftWidgetProfiles;

    private const DEFAULT_CHARACTER = 'icha';

    private const DEFAULT_TITLE = 'Passive Crafts';

    private const DEFAULT_ICONS = '🧵 🔨';

    public function show(
        Request $request,
        BitcraftPlayerData $playerData,
        BitcraftRelayClient $relay,
        BitcraftSpacetimeStaticData $spacetime,
    ): InertiaResponse|RedirectResponse {
        $filters = $this->filters($request);

        if ($request->has('source') && $this->hasProfileInput($request)) {
            $this->saveBitcraftWidgetProfile($request, 'passive-craft-tracker', $filters);

            if (! $filters['setup']) {
                return redirect()->route('bitcraft.passive-crafts', $this->bitcraftWidgetProfileRouteParameters($request, $filters));
            }
        }

        $snapshot = $this->trackerSnapshot($playerData, $relay, $spacetime, $filters);
        $snapshot['refresh'] = $playerData->refreshStatus();
        $snapshotFilters = $filters;

        if (filled(data_get($snapshot, 'tracker.player.entityId'))) {
            $snapshotFilters['character'] = (string) data_get($snapshot, 'tracker.player.entityId');
        }

        return Inertia::render('Bitcraft/PassiveCraftTracker', [
            'filters' => $filters,
            'snapshot' => $snapshot,
            'snapshotUrl' => route('bitcraft.passive-crafts.snapshot', $this->snapshotQuery($snapshotFilters), false),
        ]);
    }

    public function setup(
        Request $request,
        BitcraftPlayerData $playerData,
        BitcraftRelayClient $relay,
        BitcraftSpacetimeStaticData $spacetime,
    ): InertiaResponse|RedirectResponse {
        $request->merge(['setup' => true]);

        return $this->show($request, $playerData, $relay, $spacetime);
    }

    public function snapshot(
        Request $request,
        BitcraftPlayerData $playerData,
        BitcraftRelayClient $relay,
        BitcraftSpacetimeStaticData $spacetime,
    ): JsonResponse {
        $snapshot = $this->trackerSnapshot($playerData, $relay, $spacetime, $this->filters($request));

        return response()->json([...$snapshot, 'refresh' => $playerData->refreshStatus()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'character' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:80'],
            'icons' => ['nullable', 'string', 'max:40'],
            'setup' => ['nullable', 'boolean'],
            'user' => ['nullable', 'integer', 'min:1'],
            ...$this->widgetThemeValidationRules(),
        ]);
        $source = trim((string) ($validated['source'] ?? 'default')) ?: 'default';
        $userId = $this->bitcraftWidgetProfileUserId($request, $validated);
        $stored = $request->has('source') && ! $this->hasProfileInput($request)
            ? $this->bitcraftWidgetProfileSettings('passive-craft-tracker', $source, $userId)
            : [];

        return [
            'user' => $userId,
            'source' => $source,
            'character' => $this->bitcraftCharacter($request, $validated, $stored, self::DEFAULT_CHARACTER),
            'title' => trim((string) ($validated['title'] ?? data_get($stored, 'title', self::DEFAULT_TITLE))) ?: self::DEFAULT_TITLE,
            'icons' => $request->has('icons')
                ? trim((string) ($validated['icons'] ?? ''))
                : trim((string) data_get($stored, 'icons', self::DEFAULT_ICONS)),
            ...$this->widgetThemeSettings($validated, $stored),
            'setup' => $request->has('setup') ? $request->boolean('setup') : false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function snapshotQuery(array $filters): array
    {
        return collect($filters)
            ->except(['setup', 'source', ...$this->widgetThemeInputKeys()])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{tracker: ?array<string, mixed>, error: ?string, sampledAt: string}
     */
    private function trackerSnapshot(
        BitcraftPlayerData $playerData,
        BitcraftRelayClient $relay,
        BitcraftSpacetimeStaticData $spacetime,
        array $filters,
    ): array {
        try {
            $player = $this->resolvePlayer($playerData, $relay, (string) $filters['character']);

            if ($player === null) {
                return $this->snapshotError("No BitCraft player matched '{$filters['character']}'.");
            }

            $crafts = $this->apiCrafts($playerData, (string) data_get($player, 'entityId'), $spacetime);

            if ($crafts === null || ($playerData->refreshStatus()['delayed'] && $relay->isEnabled())) {
                if (! $relay->isEnabled()) {
                    return $this->snapshotError('Passive craft providers did not respond, and BitCraft Relay is disabled.');
                }

                try {
                    $payload = $relay->playerCrafts((string) data_get($player, 'entityId'), false);

                    if (! is_array(data_get($payload, 'crafts'))) {
                        throw new \UnexpectedValueException('Invalid Relay craft response');
                    }

                    $crafts = $this->relayCrafts($payload['crafts'], $spacetime, $relay);
                    $playerData->usingRelay();
                } catch (Throwable $exception) {
                    if ($crafts === null) {
                        throw $exception;
                    }
                }
            }

            $groups = $this->craftGroups($crafts);
            $longestGroup = collect($groups)->sortByDesc('estimatedRemainingSeconds')->first();

            return [
                'tracker' => [
                    'player' => [
                        'entityId' => (string) data_get($player, 'entityId'),
                        'username' => (string) data_get($player, 'username', 'Unknown'),
                    ],
                    'crafts' => $crafts,
                    'groups' => $groups,
                    'activeCount' => count($crafts),
                    'totalQueued' => collect($crafts)->sum('craftCount'),
                    'totalOutputs' => collect($groups)->sum('totalOutputQuantity'),
                    'estimatedRemainingSeconds' => collect($groups)->max('estimatedRemainingSeconds') ?? 0,
                    'timerSource' => data_get($longestGroup, 'timerSource'),
                ],
                'error' => null,
                'sampledAt' => $playerData->refreshStatus()['updatedAt'] ?? now()->toIso8601String(),
                'refresh' => $playerData->refreshStatus(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->snapshotError('Player data or BitCraft Relay did not respond cleanly. The tracker will try again shortly.');
        }
    }

    private function hasProfileInput(Request $request): bool
    {
        return collect([
            'character',
            'title',
            'icons',
            ...$this->widgetThemeInputKeys(),
        ])->contains(fn (string $key): bool => $request->has($key));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlayer(BitcraftPlayerData $playerData, BitcraftRelayClient $relay, string $character): ?array
    {
        try {
            $player = $this->resolveApiPlayer($playerData, $character);

            if ($player !== null) {
                return $player;
            }
        } catch (Throwable) {
            // Relay remains available when both player API providers fail.
        }

        if (! $relay->isEnabled()) {
            return null;
        }

        return $this->resolveRelayPlayer($relay, $character);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveApiPlayer(BitcraftPlayerData $playerData, string $character): ?array
    {
        if (ctype_digit($character)) {
            return $this->bitjitaPlayerPayload(data_get($playerData->player($character), 'player', []));
        }

        $players = data_get($playerData->players($character), 'players', []);
        $selected = collect($players)->first(
            fn (array $player): bool => strcasecmp((string) data_get($player, 'username'), $character) === 0,
        ) ?? collect($players)->first();

        if (! $selected || blank(data_get($selected, 'entityId'))) {
            return null;
        }

        return $this->bitjitaPlayerPayload(data_get($playerData->player((string) data_get($selected, 'entityId')), 'player', []));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bitjitaPlayerPayload(array $player): ?array
    {
        if (blank(data_get($player, 'entityId'))) {
            return null;
        }

        return [
            'entityId' => (string) data_get($player, 'entityId'),
            'username' => (string) data_get($player, 'username', 'Unknown'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRelayPlayer(BitcraftRelayClient $relay, string $character): ?array
    {
        if (ctype_digit($character)) {
            return $this->relayPlayerPayload($relay->player($character));
        }

        $players = $relay->players($character);
        $selected = collect($players)->first(
            fn (array $player): bool => strcasecmp((string) data_get($player, 'username'), $character) === 0,
        ) ?? collect($players)->first();

        if (! $selected || blank(data_get($selected, 'entity_id'))) {
            return null;
        }

        return $this->relayPlayerPayload($selected);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relayPlayerPayload(array $player): ?array
    {
        if (blank(data_get($player, 'entity_id'))) {
            return null;
        }

        return [
            'entityId' => (string) data_get($player, 'entity_id'),
            'username' => (string) data_get($player, 'username', 'Unknown'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<int, array<string, mixed>>
     */
    private function relayCrafts(array $crafts, BitcraftSpacetimeStaticData $spacetime, BitcraftRelayClient $relay): array
    {
        $catalog = $this->catalogForCrafts($crafts, $spacetime);
        $claims = $this->claimsForCrafts($crafts, $relay);
        $recipes = $this->recipesForCrafts($crafts, $spacetime);

        return collect($crafts)
            ->filter(fn (array $craft): bool => (bool) data_get($craft, 'is_passive', false))
            ->map(fn (array $craft): array => $this->craftPayload($craft, $catalog, $claims, $recipes, 'relay'))
            ->sortBy([
                fn (array $craft): bool => (bool) data_get($craft, 'completed', false),
                fn (array $craft): int => -1 * (int) data_get($craft, 'progressPercent', 0),
                fn (array $craft): string => strtolower((string) data_get($craft, 'name')),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function apiCrafts(BitcraftPlayerData $playerData, string $playerEntityId, BitcraftSpacetimeStaticData $spacetime): ?array
    {
        try {
            $payload = $playerData->playerPassiveCrafts($playerEntityId);
            $crafts = data_get($payload, 'craftResults', []);
            $source = (string) data_get($payload, 'source', 'bitjita');
        } catch (Throwable) {
            return null;
        }

        if (! is_array($crafts)) {
            return null;
        }

        $catalog = $this->catalogForCrafts($crafts, $spacetime);
        $recipes = $this->recipesForCrafts($crafts, $spacetime);

        return collect($crafts)
            ->filter(fn (array $craft): bool => ! in_array(strtolower((string) data_get($craft, 'status')), ['complete', 'completed'], true))
            ->map(fn (array $craft): array => $this->craftPayload($craft, $catalog, [], $recipes, $source))
            ->sortBy([
                fn (array $craft): bool => (bool) data_get($craft, 'completed', false),
                fn (array $craft): int => -1 * (int) data_get($craft, 'progressPercent', 0),
                fn (array $craft): string => strtolower((string) data_get($craft, 'name')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<string, array<string, mixed>>
     */
    private function catalogForCrafts(array $crafts, BitcraftSpacetimeStaticData $spacetime): array
    {
        $keys = collect($crafts)
            ->flatMap(fn (array $craft): array => $this->craftedItems($craft))
            ->map(fn (array $item): string => $this->itemKeyFromRelayItem($item))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $spacetime->catalogForKeys($keys);
    }

    /**
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<int, array<string, mixed>>
     */
    private function recipesForCrafts(array $crafts, BitcraftSpacetimeStaticData $spacetime): array
    {
        $recipeIds = collect($crafts)
            ->map(fn (array $craft): int => $this->recipeId($craft))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $spacetime->craftingRecipesForIds($recipeIds);
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     * @param  array<string, array<string, mixed>>  $claims
     * @param  array<int, array<string, mixed>>  $recipes
     * @return array<string, mixed>
     */
    private function craftPayload(array $craft, array $catalog, array $claims, array $recipes, string $source): array
    {
        $craftCount = max(1, (int) data_get($craft, 'craft_count', data_get($craft, 'craftCount', 1)));
        $progress = max(0, (int) data_get($craft, 'progress', 0));
        $totalActions = max(0, (int) data_get($craft, 'total_actions_required', 0));
        $recipeId = $this->recipeId($craft);
        $recipeTimeRequirement = max(0, (float) data_get($recipes, "{$recipeId}.timeRequirement", 0));
        $recipeTotalSeconds = $recipeTimeRequirement > 0 ? (int) ceil($recipeTimeRequirement * $craftCount) : 0;
        $startedAt = $this->craftStartedAt($craft);
        $finishedAt = $startedAt !== null && $recipeTotalSeconds > 0
            ? $startedAt->addSeconds($recipeTotalSeconds)
            : null;
        $usesApiTimer = in_array($source, ['bitjita', 'bitjuice'], true) && $startedAt !== null && $finishedAt !== null;

        if ($usesApiTimer) {
            $totalActions = $recipeTotalSeconds;
            $progress = min($totalActions, max(0, now()->getTimestamp() - $startedAt->getTimestamp()));
        }

        $remainingActions = max(0, $totalActions - $progress);
        $usesRecipeTimer = $recipeTotalSeconds > 0 && $totalActions <= 1 && $remainingActions <= 1;
        $estimatedRemainingSeconds = $usesApiTimer || ! $usesRecipeTimer
            ? $remainingActions
            : $recipeTotalSeconds;
        $claimEntityId = (string) data_get($craft, 'claim_entity_id', data_get($craft, 'claimEntityId'));
        $claim = data_get($claims, $claimEntityId) ?? $this->bitjitaClaimPayload($craft);
        $outputs = collect($this->craftedItems($craft))
            ->map(fn (array $item): array => $this->outputPayload($item, $catalog, $craftCount))
            ->filter(fn (array $item): bool => (int) $item['id'] > 0)
            ->values()
            ->all();
        $primaryOutput = $outputs[0] ?? null;

        return [
            'entityId' => (string) data_get($craft, 'entity_id', data_get($craft, 'entityId')),
            'recipeId' => $recipeId,
            'buildingEntityId' => (string) data_get($craft, 'building_entity_id', data_get($craft, 'buildingEntityId')),
            'buildingName' => (string) data_get($craft, 'building_name', data_get($craft, 'buildingName', 'Unknown station')),
            'claimEntityId' => $claimEntityId,
            'claim' => $claim,
            'completed' => (bool) data_get($craft, 'completed', strtolower((string) data_get($craft, 'status')) === 'complete'),
            'status' => data_get($craft, 'status'),
            'slot' => data_get($craft, 'slot'),
            'isPassive' => (bool) data_get($craft, 'is_passive', true),
            'isPublic' => (bool) data_get($craft, 'is_public', false),
            'ownerEntityId' => (string) data_get($craft, 'owner_entity_id', data_get($craft, 'ownerEntityId')),
            'ownerUsername' => (string) data_get($craft, 'owner_username', data_get($craft, 'ownerUsername', 'Unknown')),
            'craftCount' => $craftCount,
            'progress' => $progress,
            'totalActionsRequired' => $totalActions,
            'remainingActions' => $remainingActions,
            'recipeTimeRequirement' => $recipeTimeRequirement,
            'estimatedTotalSeconds' => $usesRecipeTimer || $usesApiTimer ? $recipeTotalSeconds : $totalActions,
            'estimatedRemainingSeconds' => $estimatedRemainingSeconds,
            'timerSource' => $usesApiTimer ? $source : ($usesRecipeTimer ? 'recipe' : 'relay'),
            'startedAt' => $startedAt?->toIso8601String(),
            'finishesAt' => $finishedAt?->toIso8601String(),
            'progressPercent' => $totalActions > 0 ? round(min(100, ($progress / $totalActions) * 100), 1) : 0,
            'outputs' => $outputs,
            'name' => data_get($primaryOutput, 'name', data_get($craft, 'recipeName', 'Recipe #'.$recipeId)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<string, array<string, mixed>>
     */
    private function claimsForCrafts(array $crafts, BitcraftRelayClient $relay): array
    {
        return collect($crafts)
            ->pluck('claim_entity_id')
            ->filter()
            ->unique()
            ->mapWithKeys(function (string|int $claimEntityId) use ($relay): array {
                try {
                    $claim = $this->claimPayload($relay->claim((string) $claimEntityId));

                    return $claim === null ? [] : [(string) $claimEntityId => $claim];
                } catch (Throwable) {
                    return [];
                }
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimPayload(array $claim): ?array
    {
        $entityId = data_get($claim, 'entity_id', data_get($claim, 'entityId'));

        if (blank($entityId)) {
            return null;
        }

        return [
            'entityId' => (string) $entityId,
            'name' => (string) data_get($claim, 'name', 'Unknown claim'),
            'region' => data_get($claim, 'region', data_get($claim, 'regionId')),
            'regionName' => data_get($claim, 'region_name', data_get($claim, 'regionName')),
            'locationX' => data_get($claim, 'location_x', data_get($claim, 'locationX')),
            'locationZ' => data_get($claim, 'location_z', data_get($claim, 'locationZ')),
            'locationDimension' => data_get($claim, 'location_dimension', data_get($claim, 'locationDimension')),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<int, array<string, mixed>>
     */
    private function craftGroups(array $crafts): array
    {
        return collect($crafts)
            ->groupBy(fn (array $craft): string => implode('|', [
                (string) data_get($craft, 'outputs.0.key', 'unknown'),
                (string) data_get($craft, 'claimEntityId', 'unknown'),
            ]))
            ->map(function ($group): array {
                $first = $group->first();
                $output = data_get($first, 'outputs.0');
                $totalProgress = $group->sum('progress');
                $totalActions = $group->sum('totalActionsRequired');
                $longestCraft = $group->sortByDesc('estimatedRemainingSeconds')->first();
                $buildingNames = $group
                    ->pluck('buildingName')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'key' => (string) data_get($first, 'outputs.0.key', data_get($first, 'name')),
                    'name' => (string) data_get($first, 'name', 'Unknown craft'),
                    'claim' => data_get($first, 'claim'),
                    'claimEntityId' => data_get($first, 'claimEntityId'),
                    'buildingNames' => $buildingNames,
                    'buildingCount' => count($buildingNames),
                    'craftsCount' => $group->count(),
                    'totalQueued' => $group->sum('craftCount'),
                    'totalOutputQuantity' => $group->sum(fn (array $craft): int => (int) data_get($craft, 'outputs.0.totalQuantity', 0)),
                    'progress' => $totalProgress,
                    'totalActionsRequired' => $totalActions,
                    'remainingActions' => max(0, $totalActions - $totalProgress),
                    'estimatedRemainingSeconds' => $group->max('estimatedRemainingSeconds') ?? 0,
                    'estimatedTotalSeconds' => $group->max('estimatedTotalSeconds') ?? 0,
                    'timerSource' => data_get($longestCraft, 'timerSource'),
                    'startedAt' => data_get($longestCraft, 'startedAt'),
                    'finishesAt' => data_get($longestCraft, 'finishesAt'),
                    'progressPercent' => $totalActions > 0 ? round(min(100, ($totalProgress / $totalActions) * 100), 1) : 0,
                    'output' => $output,
                ];
            })
            ->sortBy([
                fn (array $group): int => -1 * (int) data_get($group, 'totalOutputQuantity', 0),
                fn (array $group): string => strtolower((string) data_get($group, 'name')),
                fn (array $group): string => strtolower((string) data_get($group, 'claim.name', '')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    private function outputPayload(array $item, array $catalog, int $craftCount): array
    {
        $key = $this->itemKeyFromRelayItem($item);
        $id = (int) data_get($item, 'item_id', data_get($item, 'itemId', 0));
        $catalogItem = data_get($catalog, $key, []);
        $quantity = max(0, (int) data_get($item, 'quantity', 0));

        return [
            'key' => $key,
            'id' => $id,
            'kind' => str_starts_with($key, 'cargo:') ? 'cargo' : 'item',
            'name' => (string) data_get($catalogItem, 'name', 'Unknown item'),
            'tag' => data_get($catalogItem, 'tag'),
            'tier' => data_get($catalogItem, 'tier'),
            'rarity' => data_get($catalogItem, 'rarity'),
            'quantity' => $quantity,
            'totalQuantity' => $quantity * $craftCount,
        ];
    }

    private function itemKeyFromRelayItem(array $item): string
    {
        $id = (int) data_get($item, 'item_id', data_get($item, 'itemId', 0));

        if ($id <= 0) {
            return '';
        }

        return (strtolower((string) data_get($item, 'item_type', data_get($item, 'itemType'))) === 'cargo' ? 'cargo' : 'item').':'.$id;
    }

    private function recipeId(array $craft): int
    {
        return (int) data_get($craft, 'recipe_id', data_get($craft, 'recipeId', 0));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function craftedItems(array $craft): array
    {
        $items = data_get($craft, 'crafted_item', data_get($craft, 'craftedItem', []));

        return is_array($items) ? $items : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bitjitaClaimPayload(array $craft): ?array
    {
        $claimEntityId = data_get($craft, 'claimEntityId');

        if (blank($claimEntityId)) {
            return null;
        }

        return [
            'entityId' => (string) $claimEntityId,
            'name' => (string) data_get($craft, 'claimName', 'Unknown claim'),
            'region' => data_get($craft, 'regionId'),
            'regionName' => data_get($craft, 'regionName'),
            'locationX' => data_get($craft, 'claimLocationX'),
            'locationZ' => data_get($craft, 'claimLocationZ'),
            'locationDimension' => data_get($craft, 'locationDimension'),
        ];
    }

    private function craftStartedAt(array $craft): ?CarbonImmutable
    {
        $timestamp = data_get($craft, 'timestamp', data_get($craft, 'startedAt'));

        if (blank($timestamp)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{tracker: null, error: string, sampledAt: string}
     */
    private function snapshotError(string $message): array
    {
        return [
            'tracker' => null,
            'error' => $message,
            'sampledAt' => now()->toIso8601String(),
        ];
    }
}
