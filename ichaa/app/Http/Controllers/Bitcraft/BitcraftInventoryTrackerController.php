<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftWidgetProfile;
use App\Domain\Bitcraft\Services\BitjitaClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class BitcraftInventoryTrackerController extends Controller
{
    private const DEFAULT_CHARACTER = 'icha';

    private const DEFAULT_TITLE = 'Fishing Day!';

    private const DEFAULT_ICONS = '🐟 🎣';

    private const TRACKED_SOURCE_KINDS = [
        'inventory',
        'raft',
        'skiff',
        'clipper',
        'cart',
        'wagon',
        'deer',
        'ox',
        'goat',
        'personal cache',
    ];

    public function show(Request $request, BitjitaClient $bitjita): InertiaResponse|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($request->has('source') && $this->hasProfileInput($request)) {
            $this->saveProfile($filters);

            if (! $filters['setup']) {
                return redirect()->route('bitcraft.inventory-tracker', ['source' => $filters['source']]);
            }
        }

        $snapshot = $this->trackerSnapshot($bitjita, $filters);
        $snapshotFilters = $filters;

        if (filled(data_get($snapshot, 'tracker.player.entityId'))) {
            $snapshotFilters['character'] = (string) data_get($snapshot, 'tracker.player.entityId');
        }

        return Inertia::render('Bitcraft/InventoryTracker', [
            'filters' => $filters,
            'snapshot' => $snapshot,
            'snapshotUrl' => route('bitcraft.inventory-tracker.snapshot', $this->snapshotQuery($snapshotFilters), false),
        ]);
    }

    public function snapshot(Request $request, BitjitaClient $bitjita): JsonResponse
    {
        return response()->json($this->trackerSnapshot($bitjita, $this->filters($request)));
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
            'itemSearch' => ['nullable', 'string', 'max:120'],
            'itemKey' => ['nullable', 'regex:/^(item|cargo):\d+$/'],
            'itemKeys' => ['nullable', 'string', 'max:1000'],
            'itemNeeds' => ['nullable', 'string', 'max:2000'],
            'need' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'setup' => ['nullable', 'boolean'],
        ]);
        $source = trim((string) ($validated['source'] ?? 'default')) ?: 'default';
        $stored = $request->has('source') && ! $this->hasProfileInput($request)
            ? $this->profileSettings('inventory-tracker', $source)
            : [];
        $itemKeys = $this->selectedItemKeys(
            $validated['itemKeys'] ?? data_get($stored, 'itemKeys', ''),
            $validated['itemKey'] ?? data_get($stored, 'itemKey', ''),
        );
        $itemNeeds = $this->selectedItemNeeds($validated['itemNeeds'] ?? data_get($stored, 'itemNeeds', ''));

        return [
            'source' => $source,
            'character' => trim((string) ($validated['character'] ?? data_get($stored, 'character', self::DEFAULT_CHARACTER))) ?: self::DEFAULT_CHARACTER,
            'title' => trim((string) ($validated['title'] ?? data_get($stored, 'title', self::DEFAULT_TITLE))) ?: self::DEFAULT_TITLE,
            'icons' => $request->has('icons')
                ? trim((string) ($validated['icons'] ?? ''))
                : trim((string) data_get($stored, 'icons', self::DEFAULT_ICONS)),
            'itemSearch' => trim((string) ($validated['itemSearch'] ?? data_get($stored, 'itemSearch', ''))),
            'itemKey' => $itemKeys[0] ?? '',
            'itemKeys' => $itemKeys,
            'itemNeeds' => $itemNeeds,
            'need' => isset($validated['need']) ? (int) $validated['need'] : data_get($stored, 'need'),
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
            ->except('setup', 'source', 'itemSearch')
            ->map(fn ($value, string $key) => $key === 'itemNeeds' && is_array($value)
                ? $this->serializeItemNeeds($value)
                : (is_array($value) ? implode(',', $value) : $value))
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{tracker: ?array<string, mixed>, options: array<int, array<string, mixed>>, error: ?string, sampledAt: string}
     */
    private function trackerSnapshot(BitjitaClient $bitjita, array $filters): array
    {
        try {
            $player = $this->resolvePlayer($bitjita, (string) $filters['character']);

            if ($player === null) {
                return $this->snapshotError("No Bitjita player matched '{$filters['character']}'.", []);
            }

            $inventoriesPayload = $bitjita->playerInventories((string) data_get($player, 'entityId'));

            $catalog = $this->catalog($inventoriesPayload);
            $inventoryEntries = $this->inventoryEntries($inventoriesPayload, $catalog);
            $options = $this->options($inventoryEntries, $catalog, $bitjita);
            $itemKeys = $filters['itemKeys'] ?? [];
            $itemKeys = is_array($itemKeys) ? $itemKeys : $this->selectedItemKeys((string) $itemKeys);
            $itemNeeds = $filters['itemNeeds'] ?? [];
            $itemNeeds = is_array($itemNeeds) ? $itemNeeds : $this->selectedItemNeeds((string) $itemNeeds);

            if ($itemKeys === []) {
                return [
                    'tracker' => null,
                    'options' => $options,
                    'error' => null,
                    'sampledAt' => now()->toIso8601String(),
                ];
            }

            $need = $filters['need'] ? (int) $filters['need'] : null;
            $trackedItems = collect($itemKeys)
                ->map(function (string $itemKey) use ($options, $catalog, $inventoryEntries, $itemNeeds, $need): ?array {
                    $selectedOption = collect($options)->firstWhere('key', $itemKey)
                        ?? data_get($catalog, $itemKey);

                    if (! is_array($selectedOption)) {
                        return null;
                    }

                    $matchingEntries = collect($inventoryEntries)
                        ->filter(fn (array $entry): bool => $entry['key'] === $itemKey)
                        ->values()
                        ->all();
                    $quantity = collect($matchingEntries)->sum('quantity');
                    $targetNeed = data_get($itemNeeds, $itemKey, $need);

                    return [
                        'key' => $itemKey,
                        'id' => data_get($selectedOption, 'id'),
                        'kind' => data_get($selectedOption, 'kind'),
                        'name' => data_get($selectedOption, 'name', 'Unknown item'),
                        'tag' => data_get($selectedOption, 'tag'),
                        'tier' => data_get($selectedOption, 'tier'),
                        'rarity' => data_get($selectedOption, 'rarity'),
                        'quantity' => $quantity,
                        'need' => $targetNeed,
                        'remaining' => $targetNeed === null ? null : max(0, $targetNeed - $quantity),
                        'progressPercent' => $targetNeed === null ? null : round(min(100, ($quantity / max(1, $targetNeed)) * 100), 1),
                        'sources' => $this->sourceBreakdown($matchingEntries),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($trackedItems === []) {
                return $this->snapshotError('The selected item is not in the current Bitjita inventory payload.', $options);
            }
            $primaryItem = $trackedItems[0];

            return [
                'tracker' => [
                    'player' => [
                        'entityId' => (string) data_get($player, 'entityId'),
                        'username' => (string) data_get($player, 'username', 'Unknown'),
                    ],
                    'item' => [
                        'key' => data_get($primaryItem, 'key'),
                        'id' => data_get($primaryItem, 'id'),
                        'kind' => data_get($primaryItem, 'kind'),
                        'name' => data_get($primaryItem, 'name', 'Unknown item'),
                        'tag' => data_get($primaryItem, 'tag'),
                        'tier' => data_get($primaryItem, 'tier'),
                        'rarity' => data_get($primaryItem, 'rarity'),
                    ],
                    'items' => $trackedItems,
                    'quantity' => data_get($primaryItem, 'quantity', 0),
                    'need' => $need,
                    'remaining' => data_get($primaryItem, 'remaining'),
                    'progressPercent' => data_get($primaryItem, 'progressPercent'),
                    'sources' => data_get($primaryItem, 'sources', []),
                ],
                'options' => $options,
                'error' => null,
                'sampledAt' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->snapshotError('Bitjita did not respond cleanly. The tracker will try again shortly.', []);
        }
    }

    /**
     * @return array<int, string>
     */
    private function hasProfileInput(Request $request): bool
    {
        return collect([
            'character',
            'title',
            'icons',
            'itemSearch',
            'itemKey',
            'itemKeys',
            'itemNeeds',
            'need',
        ])->contains(fn (string $key): bool => $request->has($key));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSettings(string $widget, string $source): array
    {
        return BitcraftWidgetProfile::query()
            ->where('widget', $widget)
            ->where('source', $source)
            ->first()
            ?->settings ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function saveProfile(array $filters): void
    {
        BitcraftWidgetProfile::query()->updateOrCreate(
            [
                'widget' => 'inventory-tracker',
                'source' => $filters['source'],
            ],
            [
                'settings' => collect($filters)->except('setup')->all(),
            ],
        );
    }

    private function selectedItemKeys(array|string $itemKeys, array|string $itemKey = ''): array
    {
        $itemKeys = is_array($itemKeys) ? $itemKeys : explode(',', $itemKeys);
        $itemKey = is_array($itemKey) ? $itemKey : [$itemKey];

        $keys = collect($itemKeys)
            ->merge($itemKey)
            ->map(fn (string $key): string => trim($key))
            ->filter(fn (string $key): bool => preg_match('/^(item|cargo):\d+$/', $key) === 1)
            ->unique()
            ->values()
            ->all();

        return $keys;
    }

    /**
     * @return array<string, int>
     */
    private function selectedItemNeeds(array|string $itemNeeds): array
    {
        if (is_array($itemNeeds)) {
            return collect($itemNeeds)
                ->mapWithKeys(fn (int|string $need, int|string $key): array => preg_match('/^(item|cargo):\d+$/', (string) $key) === 1 && (int) $need > 0
                    ? [(string) $key => (int) $need]
                    : [])
                ->all();
        }

        return collect(explode(',', $itemNeeds))
            ->mapWithKeys(function (string $entry): array {
                $separator = strpos($entry, '=');

                if ($separator === false) {
                    return [];
                }

                $key = trim(substr($entry, 0, $separator));
                $need = (int) trim(substr($entry, $separator + 1));

                if (preg_match('/^(item|cargo):\d+$/', $key) !== 1 || $need < 1) {
                    return [];
                }

                return [$key => $need];
            })
            ->all();
    }

    /**
     * @param  array<string, int>  $itemNeeds
     */
    private function serializeItemNeeds(array $itemNeeds): string
    {
        return collect($itemNeeds)
            ->map(fn (int $need, string $key): string => "{$key}={$need}")
            ->implode(',');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlayer(BitjitaClient $bitjita, string $character): ?array
    {
        if (ctype_digit($character)) {
            return data_get($bitjita->player($character), 'player');
        }

        $players = data_get($bitjita->players($character), 'players', []);
        $selected = collect($players)->first(
            fn (array $player): bool => strcasecmp((string) data_get($player, 'username'), $character) === 0,
        ) ?? collect($players)->first();

        if (! $selected || blank(data_get($selected, 'entityId'))) {
            return null;
        }

        return data_get($bitjita->player((string) data_get($selected, 'entityId')), 'player');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(array $payload): array
    {
        return collect([
            ...$this->catalogEntries(data_get($payload, 'items', []), 'item'),
            ...$this->catalogEntries(data_get($payload, 'cargos', []), 'cargo'),
        ])->keyBy('key')->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogEntries(array $entries, string $kind): array
    {
        return collect($entries)
            ->map(function (array $entry, int|string $key) use ($kind): array {
                $id = data_get($entry, 'id', is_numeric($key) ? $key : null);

                return [
                    'key' => "{$kind}:{$id}",
                    'id' => (int) $id,
                    'kind' => $kind,
                    'name' => (string) data_get($entry, 'name', 'Unknown item'),
                    'tag' => data_get($entry, 'tag'),
                    'tier' => data_get($entry, 'tier'),
                    'rarity' => data_get($entry, 'rarityStr', data_get($entry, 'rarity')),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['id'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     * @return array<int, array<string, mixed>>
     */
    private function inventoryEntries(array $payload, array $catalog): array
    {
        return collect(data_get($payload, 'inventories', []))
            ->filter(fn (array $inventory): bool => $this->shouldTrackInventory($inventory))
            ->flatMap(function (array $inventory) use ($catalog) {
                return collect(data_get($inventory, 'pockets', data_get($inventory, 'inventory', [])))
                    ->map(function (array $pocket) use ($inventory, $catalog): ?array {
                        $contents = data_get($pocket, 'contents', []);
                        $itemType = (int) data_get($contents, 'itemType', data_get($contents, 'item_type', 0));
                        $kind = $itemType === 1 ? 'cargo' : 'item';
                        $id = (int) data_get($contents, 'itemId', data_get($contents, 'item_id', 0));
                        $key = "{$kind}:{$id}";
                        $item = data_get($catalog, $key, [
                            'key' => $key,
                            'id' => $id,
                            'kind' => $kind,
                            'name' => 'Unknown item',
                        ]);

                        if ($id <= 0 || blank(data_get($contents, 'quantity'))) {
                            return null;
                        }

                        return [
                            'key' => $key,
                            'id' => $id,
                            'kind' => $kind,
                            'name' => data_get($item, 'name', 'Unknown item'),
                            'quantity' => (int) data_get($contents, 'quantity', 0),
                            'source' => $this->sourceSummary($inventory),
                        ];
                    })
                    ->filter()
                    ->all();
            })
            ->values()
            ->all();
    }

    private function shouldTrackInventory(array $inventory): bool
    {
        $name = strtolower((string) data_get($inventory, 'inventoryName', data_get($inventory, 'buildingName', '')));

        return collect(self::TRACKED_SOURCE_KINDS)
            ->contains(fn (string $kind): bool => str_contains($name, $kind));
    }

    /**
     * @return array{name: string, kind: string}
     */
    private function sourceSummary(array $inventory): array
    {
        $name = (string) data_get($inventory, 'inventoryName', data_get($inventory, 'buildingName', 'Inventory'));
        $lowerName = strtolower($name);
        $kind = collect(self::TRACKED_SOURCE_KINDS)
            ->first(fn (string $sourceKind): bool => str_contains($lowerName, $sourceKind)) ?? 'inventory';

        return [
            'name' => $name,
            'kind' => $kind,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventoryEntries
     * @param  array<string, array<string, mixed>>  $catalog
     * @return array<int, array<string, mixed>>
     */
    private function options(array $inventoryEntries, array $catalog, BitjitaClient $bitjita): array
    {
        $inventoryOptions = collect($inventoryEntries)
            ->groupBy('key')
            ->map(function ($entries, string $key) use ($catalog): array {
                $catalogEntry = data_get($catalog, $key, []);
                $first = $entries->first();

                return [
                    ...$catalogEntry,
                    'key' => $key,
                    'id' => data_get($catalogEntry, 'id', data_get($first, 'id')),
                    'kind' => data_get($catalogEntry, 'kind', data_get($first, 'kind')),
                    'name' => data_get($catalogEntry, 'name', data_get($first, 'name')),
                    'quantity' => $entries->sum('quantity'),
                ];
            });

        $catalogOptions = collect([
            ...$this->catalogEntries(data_get($bitjita->items(), 'items', []), 'item'),
            ...$this->catalogEntries(data_get($bitjita->cargo(), 'cargos', []), 'cargo'),
        ])
            ->map(fn (array $option): array => [
                ...$option,
                'quantity' => 0,
            ])
            ->keyBy('key');

        return $catalogOptions
            ->merge(collect($catalog)->map(fn (array $option): array => [
                ...$option,
                'quantity' => 0,
            ]))
            ->merge($inventoryOptions)
            ->sortBy(fn (array $option): string => strtolower((string) data_get($option, 'name')).'|'.(string) data_get($option, 'kind'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sourceBreakdown(array $entries): array
    {
        return collect($entries)
            ->groupBy(fn (array $entry): string => data_get($entry, 'source.name', 'Inventory'))
            ->map(fn ($group): array => [
                'name' => data_get($group->first(), 'source.name', 'Inventory'),
                'kind' => data_get($group->first(), 'source.kind', 'inventory'),
                'quantity' => $group->sum('quantity'),
            ])
            ->sortByDesc('quantity')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array{tracker: null, options: array<int, array<string, mixed>>, error: string, sampledAt: string}
     */
    private function snapshotError(string $message, array $options): array
    {
        return [
            'tracker' => null,
            'options' => $options,
            'error' => $message,
            'sampledAt' => now()->toIso8601String(),
        ];
    }
}
