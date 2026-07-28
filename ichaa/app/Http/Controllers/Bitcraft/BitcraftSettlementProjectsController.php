<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitjitaClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class BitcraftSettlementProjectsController extends Controller
{
    private const DEFAULT_EMPIRE = 'Earth Kingdom';

    private const DEFAULT_CLAIM = 'Ba Sing Se';

    private const DEFAULT_DONATION_QUERY = 'donation';

    private const STORAGE_LOG_LIMIT = 1000;

    private const MAX_CRAFT_CONTRIBUTION_JOBS = 8;

    public function show(Request $request, BitjitaClient $bitjita): InertiaResponse
    {
        return Inertia::render('Bitcraft/SettlementProjects', $this->payload($request, $bitjita));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, BitjitaClient $bitjita): array
    {
        $filters = $this->filters($request);
        $state = [
            'empire' => null,
            'claim' => null,
            'claims' => [],
            'buildings' => [],
            'donationBuildings' => [],
            'selectedBuildings' => [],
            'construction' => [
                'projects' => [],
                'requirements' => [],
            ],
            'crafts' => [],
            'storageLogs' => [],
            'contributors' => [],
            'metrics' => [
                'buildingCount' => 0,
                'donationBuildingCount' => 0,
                'selectedBuildingCount' => 0,
                'storageLogCount' => 0,
                'activeCraftCount' => 0,
                'constructionProjectCount' => 0,
            ],
        ];
        $error = null;

        try {
            $empires = $filters['empireEntityId'] === ''
                ? $this->normalizeEmpires($bitjita->empires($filters['empire']))
                : [];
            $state['empire'] = $this->resolveEmpire($filters, $empires);

            if ($state['empire'] === null) {
                return [
                    'filters' => $filters,
                    'state' => $state,
                    'error' => "No Bitjita empire matched '{$filters['empire']}'.",
                    'sampledAt' => now()->toIso8601String(),
                ];
            }

            $filters['empireEntityId'] = (string) $state['empire']['entityId'];
            $state['claims'] = $this->normalizeClaims($bitjita->empireClaims($filters['empireEntityId']));
            $state['claim'] = $this->resolveClaim($filters, $state['claims']);

            if ($state['claim'] === null) {
                return [
                    'filters' => $filters,
                    'state' => $state,
                    'error' => "No {$state['empire']['name']} claim matched '{$filters['claimQ']}'.",
                    'sampledAt' => now()->toIso8601String(),
                ];
            }

            $filters['claimEntityId'] = (string) $state['claim']['entityId'];
            $state['buildings'] = $this->normalizeBuildings($bitjita->claimBuildings($filters['claimEntityId']));
            $inventories = $this->normalizeInventoryBuildings($bitjita->claimInventories($filters['claimEntityId']));
            $state['buildings'] = $this->mergeInventoryBuildings($state['buildings'], $inventories);
            $state['donationBuildings'] = $this->donationBuildings($state['buildings'], $filters['donationQ']);
            $state['selectedBuildings'] = $this->selectedBuildings($state['buildings'], $state['donationBuildings'], $filters['buildingEntityIds']);
            $state['construction'] = $this->normalizeConstruction($bitjita->claimConstruction($filters['claimEntityId']));
            $state['crafts'] = $this->craftsWithContributions($bitjita, $filters['claimEntityId'], $filters['includeCompletedCrafts']);
            $state['storageLogs'] = $this->storageLogs($bitjita, $state['selectedBuildings'], $filters);
            $state['contributors'] = $this->contributors($state['storageLogs'], $state['crafts']);
            $state['metrics'] = [
                'buildingCount' => count($state['buildings']),
                'donationBuildingCount' => count($state['donationBuildings']),
                'selectedBuildingCount' => count($state['selectedBuildings']),
                'storageLogCount' => count($state['storageLogs']),
                'activeCraftCount' => collect($state['crafts'])->where('completed', false)->count(),
                'constructionProjectCount' => count($state['construction']['projects']),
            ];
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Bitjita did not respond cleanly. Try refreshing in a moment.';
        }

        return [
            'filters' => $filters,
            'state' => $state,
            'error' => $error,
            'sampledAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'empire' => ['nullable', 'string', 'max:120'],
            'empireEntityId' => ['nullable', 'string', 'max:120'],
            'claimQ' => ['nullable', 'string', 'max:120'],
            'claimEntityId' => ['nullable', 'regex:/^\d+$/'],
            'donationQ' => ['nullable', 'string', 'max:80'],
            'buildingEntityIds' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
            'since' => ['nullable', 'date'],
            'includeCompletedCrafts' => ['nullable', 'boolean'],
        ]);

        return [
            'empire' => trim((string) ($validated['empire'] ?? self::DEFAULT_EMPIRE)) ?: self::DEFAULT_EMPIRE,
            'empireEntityId' => trim((string) ($validated['empireEntityId'] ?? '')),
            'claimQ' => trim((string) ($validated['claimQ'] ?? self::DEFAULT_CLAIM)) ?: self::DEFAULT_CLAIM,
            'claimEntityId' => trim((string) ($validated['claimEntityId'] ?? '')),
            'donationQ' => trim((string) ($validated['donationQ'] ?? self::DEFAULT_DONATION_QUERY)) ?: self::DEFAULT_DONATION_QUERY,
            'buildingEntityIds' => $this->selectedBuildingIds($validated['buildingEntityIds'] ?? ''),
            'since' => isset($validated['since'])
                ? Carbon::parse($validated['since'])->toIso8601String()
                : now()->subDays(7)->toIso8601String(),
            'includeCompletedCrafts' => $request->has('includeCompletedCrafts')
                ? $request->boolean('includeCompletedCrafts')
                : false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEmpires(array $payload): array
    {
        return collect(data_get($payload, 'empires', data_get($payload, 'data.empires', [])))
            ->map(fn (array $empire): array => [
                'entityId' => (string) data_get($empire, 'entityId', data_get($empire, 'id')),
                'name' => data_get($empire, 'name', data_get($empire, 'empireName', 'Unknown empire')),
            ])
            ->filter(fn (array $empire): bool => filled($empire['entityId']))
            ->values()
            ->all();
    }

    private function resolveEmpire(array $filters, array $empires): ?array
    {
        if ($filters['empireEntityId'] !== '') {
            $empire = collect($empires)->firstWhere('entityId', $filters['empireEntityId']);

            return [
                'entityId' => $filters['empireEntityId'],
                'name' => data_get($empire, 'name', $filters['empire']),
            ];
        }

        $needle = strtolower($filters['empire']);

        return collect($empires)
            ->first(fn (array $empire): bool => strtolower((string) $empire['name']) === $needle)
            ?? collect($empires)->first(fn (array $empire): bool => str_contains(strtolower((string) $empire['name']), $needle))
            ?? collect($empires)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeClaims(array $payload): array
    {
        return collect(data_get($payload, 'claims', data_get($payload, 'data.claims', [])))
            ->map(fn (array $claim): array => [
                'entityId' => (string) data_get($claim, 'entityId', data_get($claim, 'id')),
                'name' => data_get($claim, 'name', data_get($claim, 'claimName', 'Unknown claim')),
                'regionId' => data_get($claim, 'regionId'),
                'regionName' => data_get($claim, 'regionName'),
                'tier' => data_get($claim, 'tier'),
                'locationX' => data_get($claim, 'locationX'),
                'locationZ' => data_get($claim, 'locationZ'),
            ])
            ->filter(fn (array $claim): bool => filled($claim['entityId']))
            ->values()
            ->all();
    }

    private function resolveClaim(array $filters, array $claims): ?array
    {
        if ($filters['claimEntityId'] !== '') {
            return collect($claims)->firstWhere('entityId', $filters['claimEntityId'])
                ?? [
                    'entityId' => $filters['claimEntityId'],
                    'name' => $filters['claimQ'],
                    'regionId' => null,
                    'regionName' => null,
                    'tier' => null,
                    'locationX' => null,
                    'locationZ' => null,
                ];
        }

        $needle = strtolower($filters['claimQ']);

        return collect($claims)
            ->first(fn (array $claim): bool => strtolower((string) $claim['name']) === $needle)
            ?? collect($claims)->first(fn (array $claim): bool => str_contains(strtolower((string) $claim['name']), $needle));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBuildings(array $payload): array
    {
        $buildings = data_get($payload, 'buildings', data_get($payload, 'data.buildings', array_is_list($payload) ? $payload : []));

        return collect($buildings)
            ->map(fn (array $building): array => [
                'entityId' => (string) data_get($building, 'entityId', data_get($building, 'buildingEntityId')),
                'buildingDescriptionId' => data_get($building, 'buildingDescriptionId'),
                'buildingName' => data_get($building, 'buildingName', data_get($building, 'name', 'Unknown building')),
                'buildingNickname' => data_get($building, 'buildingNickname', data_get($building, 'nickname')),
                'level' => data_get($building, 'level'),
                'ownerName' => data_get($building, 'ownerName'),
                'locationX' => data_get($building, 'locationX'),
                'locationZ' => data_get($building, 'locationZ'),
                'storageSlots' => $this->buildingFunctionSlotCount($building, 'storage_slots'),
                'cargoSlots' => $this->buildingFunctionSlotCount($building, 'cargo_slots'),
                'inventory' => [],
            ])
            ->filter(fn (array $building): bool => filled($building['entityId']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInventoryBuildings(array $payload): array
    {
        return collect(data_get($payload, 'buildings', data_get($payload, 'data.buildings', [])))
            ->map(fn (array $building): array => [
                'entityId' => (string) data_get($building, 'entityId', data_get($building, 'buildingEntityId')),
                'buildingName' => data_get($building, 'buildingName', data_get($building, 'name', 'Unknown building')),
                'buildingNickname' => data_get($building, 'buildingNickname', data_get($building, 'nickname')),
                'inventory' => $this->normalizeInventoryStacks(data_get($building, 'inventory', [])),
            ])
            ->filter(fn (array $building): bool => filled($building['entityId']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $buildings
     * @param  array<int, array<string, mixed>>  $inventoryBuildings
     * @return array<int, array<string, mixed>>
     */
    private function mergeInventoryBuildings(array $buildings, array $inventoryBuildings): array
    {
        $byId = collect($buildings)->keyBy('entityId');

        foreach ($inventoryBuildings as $inventoryBuilding) {
            $existing = $byId->get($inventoryBuilding['entityId'], [
                'entityId' => $inventoryBuilding['entityId'],
                'buildingDescriptionId' => null,
                'buildingName' => $inventoryBuilding['buildingName'],
                'buildingNickname' => $inventoryBuilding['buildingNickname'],
                'level' => null,
                'ownerName' => null,
                'locationX' => null,
                'locationZ' => null,
                'storageSlots' => 0,
                'cargoSlots' => 0,
                'inventory' => [],
            ]);

            $byId->put($inventoryBuilding['entityId'], [
                ...$existing,
                'buildingName' => $existing['buildingName'] ?: $inventoryBuilding['buildingName'],
                'buildingNickname' => $existing['buildingNickname'] ?: $inventoryBuilding['buildingNickname'],
                'inventory' => $inventoryBuilding['inventory'],
            ]);
        }

        return $byId->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function donationBuildings(array $buildings, string $donationQuery): array
    {
        $needle = strtolower($donationQuery);

        return collect($buildings)
            ->filter(function (array $building) use ($needle): bool {
                $label = strtolower(collect([
                    $building['buildingNickname'],
                    $building['buildingName'],
                    $building['ownerName'],
                ])->filter()->join(' '));

                return str_contains($label, $needle);
            })
            ->sortBy(fn (array $building): string => strtolower((string) ($building['buildingNickname'] ?: $building['buildingName'])))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $buildings
     * @param  array<int, array<string, mixed>>  $donationBuildings
     * @param  array<int, string>  $selectedBuildingIds
     * @return array<int, array<string, mixed>>
     */
    private function selectedBuildings(array $buildings, array $donationBuildings, array $selectedBuildingIds): array
    {
        if ($selectedBuildingIds === []) {
            return $donationBuildings;
        }

        $selected = array_flip($selectedBuildingIds);

        return collect($buildings)
            ->filter(fn (array $building): bool => isset($selected[(string) $building['entityId']]))
            ->values()
            ->all();
    }

    /**
     * @return array{projects: array<int, array<string, mixed>>, requirements: array<int, array<string, mixed>>}
     */
    private function normalizeConstruction(array $payload): array
    {
        $projects = collect(data_get($payload, 'projects', data_get($payload, 'constructionProjects', [])))
            ->map(fn (array $project): array => [
                'entityId' => (string) data_get($project, 'entityId', data_get($project, 'id')),
                'name' => data_get($project, 'name', data_get($project, 'buildingName', 'Construction project')),
                'buildingEntityId' => data_get($project, 'buildingEntityId'),
                'buildingName' => data_get($project, 'buildingName'),
                'buildingNickname' => data_get($project, 'buildingNickname'),
                'progress' => data_get($project, 'progress', data_get($project, 'progressPercent')),
                'completed' => (bool) data_get($project, 'completed', false),
                'requirements' => $this->normalizeRequirementStacks(data_get($project, 'requirements', data_get($project, 'materials', []))),
            ])
            ->values()
            ->all();

        $requirements = collect([
            ...$this->normalizeRequirementStacks(data_get($payload, 'items', []), 'item'),
            ...$this->normalizeRequirementStacks(data_get($payload, 'cargos', data_get($payload, 'cargo', [])), 'cargo'),
        ])
            ->groupBy(fn (array $requirement): string => $requirement['kind'].':'.$requirement['id'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    ...$first,
                    'quantity' => $group->sum(fn (array $requirement): float => (float) $requirement['quantity']),
                    'contributed' => $group->sum(fn (array $requirement): float => (float) $requirement['contributed']),
                ];
            })
            ->values()
            ->all();

        return [
            'projects' => $projects,
            'requirements' => $requirements,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRequirementStacks(array $stacks, ?string $forcedKind = null): array
    {
        return collect($stacks)
            ->map(function (array $stack) use ($forcedKind): array {
                $kind = $forcedKind ?? $this->itemKind(data_get($stack, 'itemType', data_get($stack, 'type', data_get($stack, 'kind'))));

                return [
                    'id' => data_get($stack, 'id', data_get($stack, 'itemId', data_get($stack, 'cargoId'))),
                    'kind' => $kind,
                    'name' => data_get($stack, 'name', data_get($stack, 'itemName', data_get($stack, 'cargoName', 'Unknown item'))),
                    'quantity' => (float) data_get($stack, 'quantity', data_get($stack, 'required', data_get($stack, 'requiredQuantity', 0))),
                    'contributed' => (float) data_get($stack, 'contributed', data_get($stack, 'current', data_get($stack, 'currentQuantity', 0))),
                    'iconAssetName' => data_get($stack, 'iconAssetName'),
                ];
            })
            ->filter(fn (array $stack): bool => filled($stack['id']) || filled($stack['name']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function craftsWithContributions(BitjitaClient $bitjita, string $claimEntityId, bool $includeCompletedCrafts): array
    {
        $payload = $bitjita->crafts([
            'claimEntityId' => $claimEntityId,
            'completed' => $includeCompletedCrafts ? null : false,
        ]);

        return collect(data_get($payload, 'craftResults', data_get($payload, 'crafts', [])))
            ->map(fn (array $craft): array => $this->normalizeCraft($craft))
            ->take(self::MAX_CRAFT_CONTRIBUTION_JOBS)
            ->map(function (array $craft) use ($bitjita): array {
                if ($craft['entityId'] === '') {
                    return $craft;
                }

                try {
                    $craft['contributors'] = $this->normalizeCraftContributors($bitjita->craftContributions($craft['entityId']));
                } catch (Throwable $exception) {
                    report($exception);
                    $craft['contributors'] = [];
                    $craft['contributionError'] = 'Contributions unavailable.';
                }

                return $craft;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCraft(array $craft): array
    {
        return [
            'entityId' => (string) data_get($craft, 'entityId', data_get($craft, 'id', data_get($craft, 'craftId', ''))),
            'name' => data_get($craft, 'name', data_get($craft, 'recipeName', data_get($craft, 'craftedItemName', 'Craft job'))),
            'buildingEntityId' => data_get($craft, 'buildingEntityId'),
            'buildingName' => data_get($craft, 'buildingName'),
            'buildingNickname' => data_get($craft, 'buildingNickname'),
            'progress' => data_get($craft, 'progress', data_get($craft, 'progressPercent')),
            'completed' => (bool) data_get($craft, 'completed', data_get($craft, 'isCompleted', false)),
            'startedAt' => data_get($craft, 'startedAt', data_get($craft, 'createdAt')),
            'completedAt' => data_get($craft, 'completedAt'),
            'contributors' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCraftContributors(array $payload): array
    {
        return collect(data_get($payload, 'contributors', data_get($payload, 'contributions', [])))
            ->map(fn (array $contributor): array => [
                'playerEntityId' => (string) data_get($contributor, 'playerEntityId', data_get($contributor, 'entityId', data_get($contributor, 'player.id', ''))),
                'playerName' => data_get($contributor, 'playerName', data_get($contributor, 'username', data_get($contributor, 'player.username', 'Unknown'))),
                'progress' => (float) data_get($contributor, 'progress', data_get($contributor, 'totalProgress', data_get($contributor, 'progressContributed', 0))),
                'percent' => (float) data_get($contributor, 'percentage', data_get($contributor, 'percent', data_get($contributor, 'participationPercent', 0))),
                'count' => (int) data_get($contributor, 'count', data_get($contributor, 'contributionCount', 0)),
                'firstAt' => data_get($contributor, 'firstAt', data_get($contributor, 'firstContributionAt')),
                'lastAt' => data_get($contributor, 'lastAt', data_get($contributor, 'lastContributionAt')),
            ])
            ->sortByDesc(fn (array $contributor): float => $contributor['percent'] ?: $contributor['progress'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $selectedBuildings
     * @return array<int, array<string, mixed>>
     */
    private function storageLogs(BitjitaClient $bitjita, array $selectedBuildings, array $filters): array
    {
        return collect($selectedBuildings)
            ->flatMap(function (array $building) use ($bitjita, $filters) {
                $payload = $bitjita->storageLogs([
                    'buildingEntityId' => $building['entityId'],
                    'since' => $filters['since'],
                    'limit' => self::STORAGE_LOG_LIMIT,
                ]);

                $catalog = $this->storageLogCatalog($payload);

                return collect(data_get($payload, 'logs', []))
                    ->map(fn (array $log): array => $this->normalizeStorageLog($log, $building, $catalog));
            })
            ->sortByDesc(fn (array $log): string => (string) $log['createdAt'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function storageLogCatalog(array $payload): array
    {
        return collect([
            ...$this->normalizeStorageLogCatalogItems(data_get($payload, 'items', []), 'item'),
            ...$this->normalizeStorageLogCatalogItems(data_get($payload, 'cargos', data_get($payload, 'cargo', [])), 'cargo'),
        ])
            ->keyBy(fn (array $item): string => $item['kind'].':'.$item['id'])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStorageLogCatalogItems(array $items, string $kind): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'id' => data_get($item, 'id', data_get($item, 'itemId', data_get($item, 'cargoId'))),
                'kind' => $kind,
                'name' => data_get($item, 'name', data_get($item, 'itemName', data_get($item, 'cargoName', 'Unknown item'))),
                'iconAssetName' => data_get($item, 'iconAssetName'),
            ])
            ->filter(fn (array $item): bool => filled($item['id']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStorageLog(array $log, array $building, array $catalog): array
    {
        $data = data_get($log, 'data', []);
        $quantity = (float) data_get(
            $data,
            'quantity',
            data_get($log, 'quantity', data_get($log, 'amount', data_get($log, 'delta', data_get($log, 'quantityChange', 0))))
        );
        $action = strtolower((string) data_get(
            $data,
            'type',
            data_get($log, 'action', data_get($log, 'eventName', data_get($log, 'operation', data_get($log, 'type', ''))))
        ));
        $itemId = data_get($data, 'item_id')
            ?? data_get($data, 'cargo_id')
            ?? data_get($log, 'itemId')
            ?? data_get($log, 'cargoId')
            ?? data_get($log, 'item.id')
            ?? data_get($log, 'item_id')
            ?? data_get($log, 'cargo_id');
        $kind = $this->itemKind(data_get($data, 'item_type', data_get($log, 'itemType', data_get($log, 'item_type', data_get($log, 'kind')))));
        $catalogItem = $this->storageLogCatalogItem($catalog, $kind, $itemId);

        if ($quantity === 0.0) {
            $quantity = (float) data_get($log, 'quantityDelta', data_get($log, 'quantity_delta', data_get($log, 'change', 0)));
        }

        $direction = $this->storageLogDirection($action, $quantity);

        return [
            'id' => (string) data_get($log, 'id', data_get($log, 'entityId', md5(json_encode($log) ?: serialize($log)))),
            'playerEntityId' => (string) data_get($log, 'subjectEntityId', data_get($log, 'playerEntityId', data_get($log, 'player.entityId', ''))),
            'playerName' => data_get($log, 'subjectName')
                ?? data_get($log, 'playerName')
                ?? data_get($log, 'username')
                ?? data_get($log, 'actorUsername')
                ?? data_get($log, 'player.username')
                ?? data_get($log, 'player.name')
                ?? 'Unknown',
            'buildingEntityId' => (string) data_get($log, 'objectEntityId', data_get($log, 'buildingEntityId', $building['entityId'])),
            'buildingName' => data_get($log, 'building.buildingName', $building['buildingName']),
            'buildingNickname' => data_get($log, 'building.buildingNickname', $building['buildingNickname']),
            'itemId' => $itemId,
            'kind' => $catalogItem['kind'] ?? $kind,
            'itemName' => data_get($log, 'itemName', data_get($log, 'cargoName', data_get($log, 'item.name', data_get($catalogItem, 'name', 'Unknown item')))),
            'iconAssetName' => data_get($log, 'iconAssetName', data_get($catalogItem, 'iconAssetName')),
            'quantity' => abs($quantity),
            'signedQuantity' => $direction === 'out' ? -abs($quantity) : abs($quantity),
            'direction' => $direction,
            'action' => $action,
            'createdAt' => data_get($log, 'createdAt', data_get($log, 'timestamp', data_get($log, 'time'))),
        ];
    }

    private function storageLogCatalogItem(array $catalog, string $kind, mixed $itemId): ?array
    {
        if (! filled($itemId)) {
            return null;
        }

        return $catalog[$kind.':'.$itemId]
            ?? $catalog['item:'.$itemId]
            ?? $catalog['cargo:'.$itemId]
            ?? null;
    }

    private function storageLogDirection(string $action, float $quantity): string
    {
        if (str_contains($action, 'remove') || str_contains($action, 'withdraw') || str_contains($action, 'take')) {
            return 'out';
        }

        if (str_contains($action, 'deposit') || str_contains($action, 'add') || str_contains($action, 'put')) {
            return 'in';
        }

        return $quantity < 0 ? 'out' : 'in';
    }

    /**
     * @param  array<int, array<string, mixed>>  $storageLogs
     * @param  array<int, array<string, mixed>>  $crafts
     * @return array<int, array<string, mixed>>
     */
    private function contributors(array $storageLogs, array $crafts): array
    {
        $contributors = [];

        foreach ($storageLogs as $log) {
            if ($log['direction'] !== 'in') {
                continue;
            }

            $key = $log['playerEntityId'] ?: strtolower((string) $log['playerName']);
            $contributors[$key] ??= [
                'playerEntityId' => $log['playerEntityId'],
                'playerName' => $log['playerName'],
                'storageQuantity' => 0,
                'storageEvents' => 0,
                'craftProgress' => 0,
                'craftPercentTotal' => 0,
                'craftEvents' => 0,
            ];
            $contributors[$key]['storageQuantity'] += $log['quantity'];
            $contributors[$key]['storageEvents']++;
        }

        foreach ($crafts as $craft) {
            foreach ($craft['contributors'] as $contributor) {
                $key = $contributor['playerEntityId'] ?: strtolower((string) $contributor['playerName']);
                $contributors[$key] ??= [
                    'playerEntityId' => $contributor['playerEntityId'],
                    'playerName' => $contributor['playerName'],
                    'storageQuantity' => 0,
                    'storageEvents' => 0,
                    'craftProgress' => 0,
                    'craftPercentTotal' => 0,
                    'craftEvents' => 0,
                ];
                $contributors[$key]['craftProgress'] += $contributor['progress'];
                $contributors[$key]['craftPercentTotal'] += $contributor['percent'];
                $contributors[$key]['craftEvents'] += max(1, $contributor['count']);
            }
        }

        return collect($contributors)
            ->sortByDesc(fn (array $contributor): float => $contributor['storageQuantity'] + $contributor['craftProgress'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInventoryStacks(array $stacks): array
    {
        return collect($stacks)
            ->map(function (array $stack): array {
                $contents = data_get($stack, 'contents', []);

                return [
                    'id' => data_get($contents, 'item_id', data_get($stack, 'id', data_get($stack, 'itemId', data_get($stack, 'cargoId')))),
                    'kind' => $this->itemKind(data_get($contents, 'item_type', data_get($stack, 'itemType', data_get($stack, 'type', data_get($stack, 'kind'))))),
                    'name' => data_get($stack, 'name', data_get($stack, 'itemName', data_get($stack, 'cargoName', 'Unknown item'))),
                    'quantity' => (float) data_get($contents, 'quantity', data_get($stack, 'quantity', data_get($stack, 'amount', 0))),
                    'iconAssetName' => data_get($stack, 'iconAssetName'),
                ];
            })
            ->values()
            ->all();
    }

    private function buildingFunctionSlotCount(array $building, string $key): int
    {
        $functions = data_get($building, 'functions', []);

        if (! is_array($functions)) {
            return 0;
        }

        $functions = array_is_list($functions) ? $functions : [$functions];

        return collect($functions)->sum(fn (array $function): int => (int) data_get($function, $key, 0));
    }

    private function itemKind(mixed $type): string
    {
        return ((string) $type) === '1' || $type === 'cargo' ? 'cargo' : 'item';
    }

    /**
     * @return array<int, string>
     */
    private function selectedBuildingIds(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->unique()
            ->values()
            ->all();
    }
}
