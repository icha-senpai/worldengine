<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitjitaClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Response;
use Throwable;

class BitcraftToolController extends Controller
{
    private const CRAFTING_TREE_MAX_DEPTH = 6;

    private const HEX_COIN_ITEM_ID = 1;

    public function market(Request $request, BitjitaClient $bitjita): Response
    {
        return $this->marketPage($request, $bitjita, 'market');
    }

    public function barterStalls(Request $request, BitjitaClient $bitjita): Response
    {
        return $this->marketPage($request, $bitjita, 'barter');
    }

    private function marketPage(Request $request, BitjitaClient $bitjita, string $tool): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'claimQ' => ['nullable', 'string', 'max:120'],
            'claimEntityId' => ['nullable', 'regex:/^\d+$/'],
            'empire' => ['nullable', 'string', 'max:120'],
            'empireEntityId' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'regionId' => ['nullable', 'string', 'max:120'],
            'itemId' => ['nullable', 'integer', 'min:1'],
            'itemKind' => ['nullable', 'in:item,cargo'],
            'side' => ['nullable', 'in:sell,buy'],
            'hasOrders' => ['nullable', 'boolean'],
            'hasSellOrders' => ['nullable', 'boolean'],
            'hasBuyOrders' => ['nullable', 'boolean'],
        ]);

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'category' => trim((string) ($validated['category'] ?? '')),
            'claimQ' => trim((string) ($validated['claimQ'] ?? '')),
            'claimEntityId' => trim((string) ($validated['claimEntityId'] ?? '')),
            'empire' => trim((string) ($validated['empire'] ?? '')),
            'empireEntityId' => trim((string) ($validated['empireEntityId'] ?? '')),
            'empireName' => null,
            'region' => trim((string) ($validated['region'] ?? $validated['regionId'] ?? '')),
            'regionId' => null,
            'regionName' => null,
            'itemId' => $validated['itemId'] ?? null,
            'itemKind' => $validated['itemKind'] ?? '',
            'side' => $validated['side'] ?? '',
            'hasOrders' => $request->has('hasOrders') ? $request->boolean('hasOrders') : null,
            'hasSellOrders' => $request->has('hasSellOrders') ? $request->boolean('hasSellOrders') : null,
            'hasBuyOrders' => $request->has('hasBuyOrders') ? $request->boolean('hasBuyOrders') : null,
        ];

        $market = [
            'items' => [],
            'categories' => [],
            'claims' => [],
            'empires' => [],
            'tradeBuildings' => [],
            'listings' => [],
            'claim' => null,
            'orderBook' => null,
            'metrics' => [],
        ];
        $error = null;
        $regions = [];

        try {
            $regions = $this->normalizeRegions($bitjita->regions());
            $resolvedRegion = $this->resolveRegion($filters['region'], $regions);
            $filters['regionId'] = $resolvedRegion['regionId'];
            $filters['regionName'] = $resolvedRegion['regionName'];

            if ($filters['region'] !== '' && blank($filters['regionId'])) {
                $error = "No Bitjita region matched '{$filters['region']}'.";
            }
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Bitjita regions did not respond cleanly. Try again in a moment.';
        }

        if ($this->shouldSearchEmpire($filters)) {
            try {
                if ($filters['empire'] !== '' && ! ctype_digit($filters['empire'])) {
                    $market['empires'] = $this->normalizeEmpires($bitjita->empires($filters['empire']));
                }

                $resolvedEmpire = $this->resolveEmpire($filters, $market['empires']);
                $filters['empireEntityId'] = $resolvedEmpire['empireEntityId'];
                $filters['empireName'] = $resolvedEmpire['empireName'];

                if ($filters['empire'] !== '' && blank($filters['empireEntityId'])) {
                    $error = "No Bitjita empire matched '{$filters['empire']}'.";
                }
            } catch (Throwable $exception) {
                report($exception);
                $error = 'Bitjita empires did not respond cleanly. Try again in a moment.';
            }
        }

        if ($this->shouldSearchClaims($filters) && ! $this->hasUnresolvedRegion($filters) && ! $this->hasUnresolvedEmpire($filters)) {
            try {
                if ($tool === 'barter') {
                    $market['claims'] = $filters['empireEntityId'] !== ''
                        ? $this->claimSearchResults($bitjita, $filters)
                        : [];
                } else {
                    $market['claims'] = $this->claimSearchResults($bitjita, $filters);
                }
            } catch (Throwable $exception) {
                report($exception);
                $error = 'Bitjita claim search did not respond cleanly. Try again in a moment.';
            }
        }

        if ($this->hasSearchInput($filters)) {
            try {
                $claims = $market['claims'];
                $empires = $market['empires'];
                $selectedItem = null;

                if ($filters['claimEntityId'] !== '') {
                    if ($tool === 'market') {
                        $market = $this->normalizeMarket($bitjita->market($this->marketSearchFilters($filters)));
                        $market['claims'] = $claims;
                        $market['empires'] = $empires;
                        $market['claim'] = $this->selectedClaim($claims, $filters['claimEntityId']);
                    } else {
                        $claim = collect($claims)->firstWhere('entityId', $filters['claimEntityId'])
                            ?? $this->normalizeClaim(data_get($bitjita->claim($filters['claimEntityId']), 'claim', []));
                        $stalls = $this->filteredBarterStalls(
                            $this->normalizeStalls($bitjita->stalls()),
                            $filters,
                            [$claim],
                            $claim,
                        );

                        $market['claims'] = $this->claimsFromStalls($stalls, [$claim]);
                        $market['claim'] = $claim;
                        $market['tradeBuildings'] = $this->tradeBuildingsFromStalls($stalls);
                        $market['listings'] = $this->shouldListBarterOrders($filters)
                            ? $this->barterStallListings($stalls, $filters)
                            : [];
                        $market['items'] = $this->marketItemsFromListings($market['listings']);
                        $market['categories'] = $this->categoriesFromMarketItems($market['items']);
                    }

                    $selectedItem = $this->selectedMarketItem($market['items'], $filters);
                } elseif ($tool === 'barter' && $this->shouldSearchGlobalBarter($filters)) {
                    $stalls = $this->filteredBarterStalls(
                        $this->normalizeStalls($bitjita->stalls()),
                        $filters,
                        $claims,
                    );

                    $market['claims'] = $this->claimsFromStalls($stalls, $claims);
                    $market['empires'] = $empires;
                    $market['tradeBuildings'] = $this->tradeBuildingsFromStalls($stalls);
                    $market['listings'] = $this->shouldListBarterOrders($filters)
                        ? $this->barterStallListings($stalls, $filters)
                        : [];
                    $market['items'] = $this->marketItemsFromListings($market['listings']);
                    $market['categories'] = $this->categoriesFromMarketItems($market['items']);
                } elseif ($tool === 'market' && $this->shouldSearchGlobalMarket($filters)) {
                    $market = $this->normalizeMarket($bitjita->market($this->marketSearchFilters($filters)));
                    $market['claims'] = $claims;
                    $market['empires'] = $empires;
                    $selectedItem = $this->selectedMarketItem($market['items'], $filters);
                }

                if ($selectedItem && $tool === 'market') {
                    $market['orderBook'] = $this->normalizeMarketOrderBook($bitjita->marketOrders(
                        $selectedItem['kind'],
                        $selectedItem['id'],
                        ['claimEntityId' => $filters['claimEntityId']],
                    ));
                }
            } catch (Throwable $exception) {
                report($exception);
                $error = 'Bitjita did not respond cleanly. Try the search again in a moment.';
            }
        }

        return $this->page('Bitcraft/Market', [
            'filters' => $filters,
            'regions' => $regions,
            'market' => $market,
            'tool' => $this->marketTool($tool),
            'error' => $error,
        ]);
    }

    public function crafting(Request $request, BitjitaClient $bitjita): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'itemId' => ['nullable', 'integer', 'min:1'],
            'itemKind' => ['nullable', 'in:item,cargo'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $itemId = $validated['itemId'] ?? null;
        $itemKind = $validated['itemKind'] ?? 'item';
        $quantity = (int) ($validated['quantity'] ?? 1);
        $items = [];
        $detail = null;
        $error = null;

        try {
            if ($query !== '') {
                $items = $this->recipeTargets($bitjita, $query);
            }

            if ($itemId) {
                $detail = $this->craftingTargetDetailPayload(
                    $bitjita,
                    $this->craftingTargetDetail($bitjita, $itemKind, (int) $itemId),
                    $itemKind,
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Bitjita did not respond cleanly. Try the lookup again in a moment.';
        }

        return $this->page('Bitcraft/Crafting', [
            'filters' => [
                'q' => $query,
                'itemId' => $itemId,
                'itemKind' => $itemKind,
                'quantity' => $quantity,
            ],
            'items' => $items,
            'detail' => $detail,
            'error' => $error,
        ]);
    }

    private function hasSearchInput(array $filters): bool
    {
        return collect($filters)
            ->contains(fn ($value) => $value !== null && $value !== '' && $value !== false);
    }

    private function shouldSearchClaims(array $filters): bool
    {
        return $filters['claimQ'] !== ''
            || $filters['region'] !== ''
            || $filters['empire'] !== ''
            || $filters['empireEntityId'] !== '';
    }

    private function hasUnresolvedRegion(array $filters): bool
    {
        return $filters['region'] !== '' && blank($filters['regionId']);
    }

    private function shouldSearchEmpire(array $filters): bool
    {
        return $filters['empire'] !== '' || $filters['empireEntityId'] !== '';
    }

    private function hasUnresolvedEmpire(array $filters): bool
    {
        return $filters['empire'] !== '' && blank($filters['empireEntityId']);
    }

    private function shouldSearchGlobalMarket(array $filters): bool
    {
        if ($filters['q'] !== '' || $filters['category'] !== '' || filled($filters['itemId'])) {
            return true;
        }

        if ($this->shouldSearchClaims($filters)) {
            return false;
        }

        return collect(Arr::only($filters, ['hasOrders', 'hasSellOrders', 'hasBuyOrders']))
            ->contains(fn ($value) => $value === true);
    }

    private function shouldSearchGlobalBarter(array $filters): bool
    {
        return $this->hasBarterClaimScope($filters) || $this->shouldSearchBarterItems($filters);
    }

    private function hasBarterClaimScope(array $filters): bool
    {
        return $filters['claimQ'] !== ''
            || $filters['region'] !== ''
            || $filters['empire'] !== ''
            || $filters['empireEntityId'] !== '';
    }

    private function shouldSearchBarterItems(array $filters): bool
    {
        return $filters['q'] !== ''
            || $filters['category'] !== ''
            || filled($filters['itemId']);
    }

    private function shouldListBarterOrders(array $filters): bool
    {
        return $this->shouldSearchBarterItems($filters)
            || $filters['claimEntityId'] !== ''
            || $filters['claimQ'] !== '';
    }

    private function marketSearchFilters(array $filters): array
    {
        return Arr::only($filters, [
            'q',
            'category',
            'claimEntityId',
            'hasOrders',
            'hasSellOrders',
            'hasBuyOrders',
        ]);
    }

    private function marketTool(string $tool): array
    {
        if ($tool === 'barter') {
            return [
                'key' => 'barter-stalls',
                'routeName' => 'bitcraft.barter-stalls',
                'title' => 'Barter Stall Finder',
                'subtitle' => 'Find claim barter stalls by item, claim, and region.',
                'claimIdLabel' => 'Claim / stall ID',
                'claimSearchLabel' => 'Stall search',
                'claimSectionTitle' => 'Stalls',
                'claimSectionSubtitle' => 'Claims with barter stations',
                'claimEmptyLabel' => 'Search a name or region to find claims with barter stations.',
                'tradeBuildingSingular' => 'barter station',
                'tradeBuildingPlural' => 'barter stations',
                'buildingSectionTitle' => 'Barter Stations',
                'buildingSectionSubtitle' => 'Trade buildings inside the claim',
                'buildingEmptyLabel' => 'Pick a claim to find barter stations.',
                'listingSectionTitle' => 'Stall Listings',
                'clearLabel' => 'Clear Stall',
                'claimLinkLabel' => 'Use this stall ->',
            ];
        }

        return [
            'key' => 'market',
            'routeName' => 'bitcraft.market',
            'title' => 'Market Finder',
            'subtitle' => 'Find market trades by item, claim, and region.',
            'claimIdLabel' => 'Claim / market ID',
            'claimSearchLabel' => 'Claim search',
            'claimSectionTitle' => 'Markets',
            'claimSectionSubtitle' => 'Matching claims',
            'claimEmptyLabel' => 'Search a name or region to find claims.',
            'tradeBuildingSingular' => 'market building',
            'tradeBuildingPlural' => 'market buildings',
            'buildingSectionTitle' => 'Market Buildings',
            'buildingSectionSubtitle' => 'Market buildings inside the claim',
            'buildingEmptyLabel' => 'Pick a claim to find market buildings.',
            'listingSectionTitle' => 'Market Listings',
            'clearLabel' => 'Clear Market',
            'claimLinkLabel' => 'Use this market ->',
        ];
    }

    private function normalizeMarket(array $payload): array
    {
        $data = data_get($payload, 'data', $payload);

        return [
            'items' => collect(data_get($data, 'items', []))
                ->map(fn (array $item) => $this->normalizeMarketItem($item))
                ->values()
                ->all(),
            'categories' => collect(data_get($data, 'categories', []))
                ->map(fn ($category) => is_array($category) ? data_get($category, 'name', '') : $category)
                ->filter()
                ->values()
                ->all(),
            'claims' => [],
            'empires' => [],
            'tradeBuildings' => [],
            'listings' => [],
            'claim' => null,
            'orderBook' => null,
            'metrics' => data_get($data, 'metrics', []),
        ];
    }

    private function normalizeMarketItem(array $item): array
    {
        $stats = data_get($item, 'stats', []);

        return [
            'id' => data_get($item, 'id', data_get($item, 'itemId')),
            'type' => data_get($item, 'itemType', data_get($item, 'type', 'item')),
            'kind' => $this->itemKind(data_get($item, 'itemType', data_get($item, 'type', 'item'))),
            'name' => data_get($item, 'name', data_get($item, 'itemName', 'Unknown item')),
            'category' => data_get($item, 'category', data_get($item, 'tag')),
            'tier' => data_get($item, 'tier', data_get($item, 'itemTier')),
            'rarity' => data_get($item, 'rarityStr', data_get($item, 'itemRarityStr')),
            'iconAssetName' => data_get($item, 'iconAssetName'),
            'lowestSellPrice' => data_get($item, 'lowestSellPrice', data_get($stats, 'lowestSellPrice')),
            'highestBuyPrice' => data_get($item, 'highestBuyPrice', data_get($stats, 'highestBuyPrice')),
            'sellOrderCount' => data_get($item, 'sellOrderCount', data_get($item, 'sellOrders', data_get($stats, 'sellOrderCount'))),
            'buyOrderCount' => data_get($item, 'buyOrderCount', data_get($item, 'buyOrders', data_get($stats, 'buyOrderCount'))),
        ];
    }

    private function selectedMarketItem(array $items, array $filters): ?array
    {
        if (! $filters['itemId']) {
            return null;
        }

        return [
            'id' => $filters['itemId'],
            'kind' => $filters['itemKind'] ?: $this->itemKind(data_get(
                collect($items)->firstWhere('id', $filters['itemId']),
                'type',
                'item',
            )),
        ];
    }

    private function selectedClaim(array $claims, string $claimEntityId): ?array
    {
        return collect($claims)->firstWhere('entityId', $claimEntityId)
            ?? (filled($claimEntityId) ? ['entityId' => $claimEntityId, 'name' => null] : null);
    }

    private function normalizeClaims(array $payload): array
    {
        return collect(data_get($payload, 'claims', []))
            ->map(fn (array $claim) => $this->normalizeClaim($claim))
            ->filter(fn (array $claim) => filled($claim['entityId']))
            ->values()
            ->all();
    }

    private function normalizeClaim(array $claim): array
    {
        return [
            'entityId' => data_get($claim, 'entityId'),
            'name' => data_get($claim, 'name', 'Unnamed claim'),
            'empireEntityId' => data_get($claim, 'empireEntityId'),
            'empireName' => data_get($claim, 'empireName'),
            'regionId' => data_get($claim, 'regionId'),
            'regionName' => data_get($claim, 'regionName'),
            'tier' => data_get($claim, 'tier'),
            'locationX' => data_get($claim, 'locationX'),
            'locationZ' => data_get($claim, 'locationZ'),
            'treasury' => data_get($claim, 'treasury'),
        ];
    }

    private function normalizeEmpires(array $payload): array
    {
        return collect(data_get($payload, 'empires', []))
            ->map(fn (array $empire) => [
                'entityId' => (string) data_get($empire, 'entityId', data_get($empire, 'empireEntityId', data_get($empire, 'id'))),
                'name' => data_get($empire, 'name', data_get($empire, 'empireName', 'Unnamed empire')),
                'claimCount' => data_get($empire, 'claimCount', data_get($empire, 'claimsCount', data_get($empire, 'numClaims'))),
                'memberCount' => data_get($empire, 'memberCount', data_get($empire, 'membersCount', data_get($empire, 'numMembers'))),
                'treasury' => data_get($empire, 'treasury'),
            ])
            ->filter(fn (array $empire) => filled($empire['entityId']))
            ->values()
            ->all();
    }

    private function normalizeRegions(array $payload): array
    {
        $regions = data_get($payload, 'regions', $payload);

        return collect(is_array($regions) ? $regions : [])
            ->map(fn (array $region) => [
                'regionId' => (string) data_get($region, 'regionId'),
                'regionName' => data_get($region, 'regionName'),
            ])
            ->filter(fn (array $region) => filled($region['regionId']) && filled($region['regionName']))
            ->sortBy('regionName')
            ->values()
            ->all();
    }

    private function resolveRegion(string $input, array $regions): array
    {
        if ($input === '') {
            return [
                'regionId' => null,
                'regionName' => null,
            ];
        }

        if (ctype_digit($input)) {
            return [
                'regionId' => $input,
                'regionName' => data_get(collect($regions)->firstWhere('regionId', $input), 'regionName'),
            ];
        }

        $needle = strtolower($input);
        $region = collect($regions)
            ->first(fn (array $region) => strtolower((string) $region['regionName']) === $needle)
            ?? collect($regions)->first(fn (array $region) => str_contains(strtolower((string) $region['regionName']), $needle));

        return [
            'regionId' => data_get($region, 'regionId'),
            'regionName' => data_get($region, 'regionName'),
        ];
    }

    private function resolveEmpire(array $filters, array $empires): array
    {
        if ($filters['empireEntityId'] !== '') {
            $empire = collect($empires)->firstWhere('entityId', $filters['empireEntityId']);

            return [
                'empireEntityId' => $filters['empireEntityId'],
                'empireName' => data_get($empire, 'name'),
            ];
        }

        if ($filters['empire'] === '') {
            return [
                'empireEntityId' => null,
                'empireName' => null,
            ];
        }

        if (ctype_digit($filters['empire'])) {
            return [
                'empireEntityId' => $filters['empire'],
                'empireName' => null,
            ];
        }

        $needle = strtolower($filters['empire']);
        $empire = collect($empires)
            ->first(fn (array $empire) => strtolower((string) $empire['name']) === $needle)
            ?? collect($empires)->first(fn (array $empire) => str_contains(strtolower((string) $empire['name']), $needle))
            ?? collect($empires)->first();

        return [
            'empireEntityId' => data_get($empire, 'entityId'),
            'empireName' => data_get($empire, 'name'),
        ];
    }

    private function claimSearchResults(BitjitaClient $bitjita, array $filters): array
    {
        if ($filters['empireEntityId'] !== '') {
            return $this->filterClaims(
                $this->normalizeClaims($bitjita->empireClaims($filters['empireEntityId'])),
                $filters,
            );
        }

        return $this->normalizeClaims($bitjita->claims([
            'q' => $filters['claimQ'],
            'regionId' => $filters['regionId'],
        ]));
    }

    private function filterClaims(array $claims, array $filters): array
    {
        $query = strtolower($filters['claimQ']);

        return collect($claims)
            ->filter(fn (array $claim) => $filters['regionId'] === null || (string) $claim['regionId'] === (string) $filters['regionId'])
            ->filter(fn (array $claim) => $query === '' || str_contains(strtolower((string) $claim['name']), $query))
            ->values()
            ->all();
    }

    private function normalizeStalls(array $payload): array
    {
        return collect(data_get($payload, 'stalls', []))
            ->map(fn (array $stall) => [
                'entityId' => data_get($stall, 'entityId'),
                'ownerName' => data_get($stall, 'ownerName'),
                'ownerEntityId' => data_get($stall, 'ownerEntityId'),
                'regionId' => (string) data_get($stall, 'regionId'),
                'regionName' => data_get($stall, 'regionName'),
                'marketModeEnabled' => (bool) data_get($stall, 'marketModeEnabled', false),
                'nickname' => data_get($stall, 'nickname'),
                'appearanceOverrideId' => data_get($stall, 'appearanceOverrideId'),
                'overrideIconAssetName' => data_get($stall, 'overrideIconAssetName'),
                'overrideModelAddress' => data_get($stall, 'overrideModelAddress'),
                'orderCount' => (int) data_get($stall, 'orderCount', count(data_get($stall, 'orders', []))),
                'locationX' => data_get($stall, 'locationX'),
                'locationZ' => data_get($stall, 'locationZ'),
                'claimName' => data_get($stall, 'claimName'),
                'orders' => collect(data_get($stall, 'orders', []))
                    ->map(fn (array $order) => [
                        'entityId' => data_get($order, 'entityId'),
                        'remainingStock' => data_get($order, 'remainingStock'),
                        'offerItems' => $this->normalizeStallStacks(data_get($order, 'offerItems', []), 'item'),
                        'requiredItems' => $this->normalizeStallStacks(data_get($order, 'requiredItems', []), 'item'),
                        'offerCargo' => $this->normalizeStallStacks(data_get($order, 'offerCargo', []), 'cargo'),
                        'requiredCargo' => $this->normalizeStallStacks(data_get($order, 'requiredCargo', []), 'cargo'),
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $stall) => filled($stall['entityId']))
            ->values()
            ->all();
    }

    private function normalizeStallStacks(array $stacks, string $kind): array
    {
        return collect($stacks)
            ->map(fn (array $stack) => [
                'id' => data_get($stack, 'itemId'),
                'name' => data_get($stack, 'itemName', 'Unknown item'),
                'kind' => $kind,
                'quantity' => data_get($stack, 'quantity'),
                'iconAssetName' => data_get($stack, 'iconAssetName'),
            ])
            ->filter(fn (array $stack) => filled($stack['id']))
            ->values()
            ->all();
    }

    private function filteredBarterStalls(array $stalls, array $filters, array $claims = [], ?array $selectedClaim = null): array
    {
        return collect($stalls)
            ->filter(fn (array $stall) => $this->stallMatchesBarterScope($stall, $filters, $claims, $selectedClaim))
            ->filter(fn (array $stall) => ! $this->shouldSearchBarterItems($filters) || $this->barterStallListings([$stall], $filters) !== [])
            ->values()
            ->all();
    }

    private function stallMatchesBarterScope(array $stall, array $filters, array $claims = [], ?array $selectedClaim = null): bool
    {
        if ($filters['regionId'] !== '' && $filters['regionId'] !== null && (string) $stall['regionId'] !== (string) $filters['regionId']) {
            return false;
        }

        if ($filters['regionId'] === null && $filters['region'] !== '' && ! str_contains(strtolower((string) $stall['regionName']), strtolower($filters['region']))) {
            return false;
        }

        $claimName = strtolower((string) data_get($stall, 'claimName'));

        if ($selectedClaim && filled(data_get($selectedClaim, 'name'))) {
            $selectedClaimName = strtolower((string) data_get($selectedClaim, 'name'));

            if ($claimName !== $selectedClaimName && ! str_contains($claimName, $selectedClaimName)) {
                return false;
            }
        }

        if ($filters['claimQ'] !== '') {
            $needle = strtolower($filters['claimQ']);
            $haystack = strtolower(collect([
                data_get($stall, 'claimName'),
                data_get($stall, 'nickname'),
                data_get($stall, 'ownerName'),
            ])->filter()->join(' '));

            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        if ($filters['empireEntityId'] !== '' && $claims !== []) {
            $claimNames = collect($claims)
                ->pluck('name')
                ->filter()
                ->map(fn ($name) => strtolower((string) $name))
                ->all();

            if (! in_array($claimName, $claimNames, true)) {
                return false;
            }
        }

        return true;
    }

    private function claimsFromStalls(array $stalls, array $fallbackClaims = []): array
    {
        $fallbackClaimsByName = collect($fallbackClaims)
            ->keyBy(fn (array $claim) => strtolower((string) data_get($claim, 'name')));

        $claims = collect($stalls)
            ->filter(fn (array $stall) => filled($stall['claimName']))
            ->groupBy(fn (array $stall) => strtolower((string) $stall['claimName']))
            ->map(function ($group, string $claimName) use ($fallbackClaimsByName) {
                $first = $group->first();
                $fallback = $fallbackClaimsByName->get($claimName, []);

                return [
                    'entityId' => data_get($fallback, 'entityId', 'stall-claim:'.md5((string) $first['claimName'])),
                    'name' => $first['claimName'],
                    'empireEntityId' => data_get($fallback, 'empireEntityId'),
                    'empireName' => data_get($fallback, 'empireName'),
                    'regionId' => $first['regionId'],
                    'regionName' => $first['regionName'],
                    'tier' => data_get($fallback, 'tier'),
                    'locationX' => $first['locationX'],
                    'locationZ' => $first['locationZ'],
                    'treasury' => data_get($fallback, 'treasury'),
                    'tradeBuildingCount' => $group->count(),
                    'tradeOrderCount' => $group->sum('orderCount'),
                    'tradeBuildings' => $this->tradeBuildingsFromStalls($group->values()->all()),
                    'tradeBuildingNames' => $group->pluck('nickname')->filter()->unique()->values()->all(),
                ];
            })
            ->values();

        if ($claims->isNotEmpty()) {
            return $claims->all();
        }

        return $fallbackClaims;
    }

    private function tradeBuildingsFromStalls(array $stalls): array
    {
        return collect($stalls)
            ->map(fn (array $stall) => [
                'entityId' => data_get($stall, 'entityId'),
                'buildingDescriptionId' => null,
                'buildingName' => 'Barter Stall',
                'buildingNickname' => data_get($stall, 'nickname'),
                'iconAssetName' => data_get($stall, 'overrideIconAssetName'),
                'level' => null,
                'tradeOrders' => data_get($stall, 'orderCount', count(data_get($stall, 'orders', []))),
                'buildingKind' => 'Stall',
                'barterKind' => 'Stall',
                'storageSlots' => null,
                'cargoSlots' => null,
                'claimEntityId' => null,
                'claimName' => data_get($stall, 'claimName'),
                'regionId' => data_get($stall, 'regionId'),
                'regionName' => data_get($stall, 'regionName'),
                'locationX' => data_get($stall, 'locationX'),
                'locationZ' => data_get($stall, 'locationZ'),
                'ownerName' => data_get($stall, 'ownerName'),
                'inventoryItems' => $this->inventoryItemsFromStallOrders(data_get($stall, 'orders', [])),
            ])
            ->values()
            ->all();
    }

    private function inventoryItemsFromStallOrders(array $orders): array
    {
        return collect($orders)
            ->flatMap(fn (array $order) => $this->orderStacks($order, 'offer'))
            ->unique(fn (array $stack) => $stack['kind'].':'.$stack['id'])
            ->map(fn (array $stack) => [
                'id' => $stack['id'],
                'type' => $stack['kind'],
                'kind' => $stack['kind'],
                'name' => $stack['name'],
                'category' => $stack['kind'] === 'cargo' ? 'Cargo' : 'Item',
                'tier' => null,
                'rarity' => null,
                'iconAssetName' => $stack['iconAssetName'],
                'quantity' => $stack['quantity'],
                'locked' => null,
                'volume' => null,
            ])
            ->sortBy(fn (array $item) => strtolower((string) $item['name']))
            ->values()
            ->all();
    }

    private function barterStallListings(array $stalls, array $filters): array
    {
        return collect($stalls)
            ->flatMap(fn (array $stall) => collect(data_get($stall, 'orders', []))
                ->flatMap(fn (array $order) => $this->barterOrderListings($stall, $order, $filters)))
            ->sortBy([
                fn (array $listing) => strtolower((string) $listing['itemName']),
                fn (array $listing) => $listing['side'] === 'sell' ? 0 : 1,
                fn (array $listing) => $this->numericOrNull($listing['price']) ?? PHP_FLOAT_MAX,
                fn (array $listing) => strtolower((string) data_get($listing, 'stall.name')),
            ])
            ->values()
            ->all();
    }

    private function barterOrderListings(array $stall, array $order, array $filters): array
    {
        $listings = [];

        if ($filters['side'] !== 'buy') {
            foreach ($this->matchingOrderStacks($order, $filters, 'offer') as $stack) {
                $listing = $this->barterOrderListing($stall, $order, $stack, 'sell');

                if ($listing !== null) {
                    $listings[] = $listing;
                }
            }
        }

        if ($filters['side'] !== 'sell') {
            foreach ($this->matchingOrderStacks($order, $filters, 'required') as $stack) {
                $listing = $this->barterOrderListing($stall, $order, $stack, 'buy');

                if ($listing !== null) {
                    $listings[] = $listing;
                }
            }
        }

        return $listings;
    }

    private function matchingOrderStacks(array $order, array $filters, string $set): array
    {
        return collect($this->orderStacks($order, $set))
            ->filter(fn (array $stack) => $this->stackMatchesFilters($stack, $filters))
            ->values()
            ->all();
    }

    private function stackMatchesFilters(array $stack, array $filters): bool
    {
        $query = strtolower($filters['q']);
        $category = strtolower($filters['category']);

        if ($filters['itemId'] && (string) $stack['id'] !== (string) $filters['itemId']) {
            return false;
        }

        if ($filters['itemKind'] !== '' && $stack['kind'] !== $filters['itemKind']) {
            return false;
        }

        if ($query !== '' && ! str_contains(strtolower((string) $stack['name']), $query) && (string) $stack['id'] !== $filters['q']) {
            return false;
        }

        if ($category !== '' && ! str_contains(strtolower((string) $stack['name']), $category) && ! str_contains($stack['kind'], $category)) {
            return false;
        }

        return true;
    }

    private function barterOrderListing(array $stall, array $order, array $stack, string $side): ?array
    {
        $offerStacks = $this->orderStacks($order, 'offer');
        $requiredStacks = $this->orderStacks($order, 'required');
        $coinStack = $this->coinStack($side === 'sell' ? $requiredStacks : $offerStacks);

        if ($coinStack === null) {
            return null;
        }

        $bundlePrice = data_get($coinStack, 'quantity');
        $stackQuantity = (float) data_get($stack, 'quantity', 1);
        $unitPrice = $stackQuantity > 0 ? (float) $bundlePrice / $stackQuantity : null;

        return [
            'entityId' => data_get($order, 'entityId').':'.$side.':'.$stack['kind'].':'.$stack['id'],
            'source' => 'stall-order',
            'side' => $side,
            'ownerUsername' => data_get($stall, 'ownerName'),
            'claimEntityId' => null,
            'claimName' => data_get($stall, 'claimName'),
            'itemId' => $stack['id'],
            'itemType' => $stack['kind'],
            'itemName' => $stack['name'],
            'itemCategory' => $stack['kind'] === 'cargo' ? 'Cargo' : 'Item',
            'itemTier' => null,
            'itemRarity' => null,
            'iconAssetName' => $stack['iconAssetName'],
            'price' => $unitPrice,
            'quantity' => data_get($order, 'remainingStock'),
            'regionName' => data_get($stall, 'regionName'),
            'updatedAt' => null,
            'stall' => $this->stallSummary($stall),
            'stallMatchStatus' => 'matched',
            'remainingStock' => data_get($order, 'remainingStock'),
            'bundlePrice' => $bundlePrice,
            'priceCurrency' => $bundlePrice !== null ? 'Hex Coin' : null,
            'offerStacks' => $offerStacks,
            'requiredStacks' => $requiredStacks,
            'offerSummary' => $this->stackSummary($offerStacks),
            'requiredSummary' => $this->stackSummary($requiredStacks),
        ];
    }

    private function orderStacks(array $order, string $set): array
    {
        return $set === 'offer'
            ? array_merge(data_get($order, 'offerItems', []), data_get($order, 'offerCargo', []))
            : array_merge(data_get($order, 'requiredItems', []), data_get($order, 'requiredCargo', []));
    }

    private function coinStack(array $stacks): ?array
    {
        return collect($stacks)
            ->first(fn (array $stack) => $stack['kind'] === 'item' && (string) $stack['id'] === (string) self::HEX_COIN_ITEM_ID);
    }

    private function stackSummary(array $stacks): string
    {
        return collect($stacks)
            ->map(fn (array $stack) => number_format((float) $stack['quantity']).'x '.$stack['name'])
            ->join(' + ');
    }

    private function stallSummary(array $stall): array
    {
        return [
            'entityId' => data_get($stall, 'entityId'),
            'name' => data_get($stall, 'nickname') ?: data_get($stall, 'ownerName', 'Unnamed stall'),
            'buildingName' => 'Barter Stall',
            'buildingNickname' => data_get($stall, 'nickname'),
            'buildingKind' => 'Stall',
            'ownerName' => data_get($stall, 'ownerName'),
            'locationX' => data_get($stall, 'locationX'),
            'locationZ' => data_get($stall, 'locationZ'),
        ];
    }

    private function marketItemsFromListings(array $listings): array
    {
        return collect($listings)
            ->groupBy(fn (array $listing) => $this->itemKind($listing['itemType']).':'.$listing['itemId'])
            ->map(function ($group) {
                $first = $group->first();
                $sellListings = $group->where('side', 'sell');
                $buyListings = $group->where('side', 'buy');

                return [
                    'id' => $first['itemId'],
                    'type' => $first['itemType'],
                    'kind' => $this->itemKind($first['itemType']),
                    'name' => $first['itemName'],
                    'category' => $first['itemCategory'],
                    'tier' => $first['itemTier'],
                    'rarity' => $first['itemRarity'],
                    'iconAssetName' => $first['iconAssetName'],
                    'lowestSellPrice' => $sellListings->min(fn (array $listing) => $this->numericOrNull($listing['price'])),
                    'highestBuyPrice' => $buyListings->max(fn (array $listing) => $this->numericOrNull($listing['price'])),
                    'sellOrderCount' => $sellListings->count(),
                    'buyOrderCount' => $buyListings->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function categoriesFromMarketItems(array $items): array
    {
        return collect($items)
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeMarketOrderBook(array $payload): array
    {
        $item = data_get($payload, 'item', []);

        return [
            'item' => [
                'id' => data_get($item, 'id'),
                'name' => data_get($item, 'name', 'Unknown item'),
                'category' => data_get($item, 'tag'),
                'tier' => data_get($item, 'tier'),
                'rarity' => data_get($item, 'rarityStr'),
            ],
            'sellOrders' => $this->normalizeOrders(data_get($payload, 'sellOrders', []), 'sell'),
            'buyOrders' => $this->normalizeOrders(data_get($payload, 'buyOrders', []), 'buy'),
            'stats' => data_get($payload, 'stats', []),
        ];
    }

    private function normalizeOrders(array $orders, string $side): array
    {
        return collect($orders)
            ->map(fn (array $order) => [
                'entityId' => data_get($order, 'entityId'),
                'side' => $side,
                'ownerUsername' => data_get($order, 'ownerUsername'),
                'claimEntityId' => data_get($order, 'claimEntityId'),
                'claimName' => data_get($order, 'claimName'),
                'price' => data_get($order, 'priceThreshold', data_get($order, 'price')),
                'quantity' => data_get($order, 'quantity'),
                'regionName' => data_get($order, 'regionName'),
                'updatedAt' => data_get($order, 'updatedAt'),
            ])
            ->values()
            ->all();
    }

    private function itemKind(mixed $type): string
    {
        return ((string) $type) === '1' || $type === 'cargo' ? 'cargo' : 'item';
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeItems(array $payload): array
    {
        return $this->normalizeCraftingTargets(data_get($payload, 'items', data_get($payload, 'data.items', [])), 'item');
    }

    private function normalizeCargo(array $payload): array
    {
        return $this->normalizeCraftingTargets(data_get($payload, 'cargos', data_get($payload, 'cargo', [])), 'cargo');
    }

    private function normalizeCraftingTargets(array $items, string $kind): array
    {
        return collect($items)
            ->map(fn (array $item) => $this->normalizeCraftingTarget($item, $kind))
            ->filter(fn (array $item) => filled($item['id']))
            ->values()
            ->all();
    }

    private function normalizeCraftingTarget(array $item, string $kind): array
    {
        return [
            'id' => data_get($item, 'id', data_get($item, 'itemId')),
            'kind' => $kind,
            'name' => data_get($item, 'name', data_get($item, 'itemName', 'Unknown item')),
            'category' => data_get($item, 'category', data_get($item, 'tag', $kind === 'cargo' ? 'Cargo' : null)),
            'tier' => data_get($item, 'tier'),
            'rarity' => data_get($item, 'rarityStr'),
            'description' => data_get($item, 'description'),
            'iconAssetName' => data_get($item, 'iconAssetName'),
        ];
    }

    private function craftingTargetDetailPayload(BitjitaClient $bitjita, array $payload, string $kind): array
    {
        $detail = $this->normalizeCraftingTargetDetail($payload, $kind);
        $targetKey = $this->craftingTargetKey($detail['item']);
        $detail['recipeTree'] = $this->recipeTree($bitjita, $detail, 0, [$targetKey]);

        return $detail;
    }

    private function normalizeCraftingTargetDetail(array $payload, string $kind): array
    {
        $item = data_get($payload, $kind, data_get($payload, 'item', data_get($payload, 'cargo', [])));

        return [
            'item' => $this->normalizeCraftingTarget($item, $kind),
            'craftingRecipes' => $this->normalizeRecipes(data_get($payload, 'craftingRecipes', []), 'crafting'),
            'extractionRecipes' => $this->normalizeRecipes(data_get($payload, 'extractionRecipes', []), 'extraction'),
            'marketStats' => data_get($payload, 'marketStats', []),
        ];
    }

    private function recipeTargets(BitjitaClient $bitjita, string $query): array
    {
        return collect([
            ...$this->normalizeItems($bitjita->items($query)),
            ...$this->normalizeCargo($bitjita->cargo($query)),
        ])
            ->filter(fn (array $item) => $this->craftingTargetHasRecipes($bitjita, $item))
            ->sortBy([
                fn (array $item) => strtolower((string) $item['name']),
                fn (array $item) => $item['kind'],
            ])
            ->values()
            ->all();
    }

    private function craftingTargetHasRecipes(BitjitaClient $bitjita, array $item): bool
    {
        $detail = $this->normalizeCraftingTargetDetail(
            $this->craftingTargetDetail($bitjita, (string) $item['kind'], (int) $item['id']),
            (string) $item['kind'],
        );

        return $detail['craftingRecipes'] !== [] || $detail['extractionRecipes'] !== [];
    }

    private function craftingTargetDetail(BitjitaClient $bitjita, string $kind, int $itemId): array
    {
        if ($kind === 'cargo') {
            return $bitjita->cargoItem($itemId);
        }

        return $bitjita->item($itemId);
    }

    private function recipeTree(BitjitaClient $bitjita, array $detail, int $depth, array $seen): array
    {
        if ($depth >= self::CRAFTING_TREE_MAX_DEPTH) {
            return [];
        }

        return collect($this->preferredRecipeOptions($detail))
            ->map(fn (array $recipe) => [
                ...$recipe,
                'ingredients' => collect($recipe['ingredients'])
                    ->map(fn (array $ingredient) => [
                        ...$ingredient,
                        'recipes' => $this->ingredientRecipeTree($bitjita, $ingredient, $depth + 1, $seen),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function ingredientRecipeTree(BitjitaClient $bitjita, array $ingredient, int $depth, array $seen): array
    {
        if ($depth >= self::CRAFTING_TREE_MAX_DEPTH || blank($ingredient['id'])) {
            return [];
        }

        $target = [
            'id' => $ingredient['id'],
            'kind' => $ingredient['kind'],
        ];
        $targetKey = $this->craftingTargetKey($target);

        if (in_array($targetKey, $seen, true)) {
            return [];
        }

        try {
            $detail = $this->normalizeCraftingTargetDetail(
                $this->craftingTargetDetail($bitjita, (string) $target['kind'], (int) $target['id']),
                (string) $target['kind'],
            );
        } catch (Throwable) {
            return [];
        }

        return $this->recipeTree($bitjita, $detail, $depth, [...$seen, $targetKey]);
    }

    private function preferredRecipeOptions(array $detail): array
    {
        $recipes = collect([
            ...$detail['craftingRecipes'],
            ...$detail['extractionRecipes'],
        ]);

        if ($recipes->isEmpty()) {
            return [];
        }

        $preferredRecipes = $recipes
            ->reject(fn (array $recipe) => $this->isExcludedCraftingRoute($recipe));

        if ($preferredRecipes->isEmpty()) {
            return [];
        }

        return $preferredRecipes
            ->sort(fn (array $left, array $right) => $this->recipePreferenceTuple($left) <=> $this->recipePreferenceTuple($right))
            ->take(1)
            ->values()
            ->all();
    }

    private function recipePreferenceTuple(array $recipe): array
    {
        return [
            $this->recipeOutputQuantity($recipe),
            $this->recipeInputCount($recipe),
            strtolower((string) $recipe['name']),
        ];
    }

    private function isExcludedCraftingRoute(array $recipe): bool
    {
        $haystack = strtolower(collect([
            $recipe['name'],
            ...collect($recipe['ingredients'])->pluck('name')->all(),
        ])->filter()->join(' '));

        return str_contains($haystack, 'package') || str_contains($haystack, 'hexite');
    }

    private function recipeOutputQuantity(array $recipe): float
    {
        return max(1, (float) ($recipe['outputQuantity'] ?? 1));
    }

    private function recipeInputCount(array $recipe): int
    {
        return count($recipe['ingredients']);
    }

    private function craftingTargetKey(array $target): string
    {
        return $target['kind'].':'.$target['id'];
    }

    private function normalizeRecipes(array $recipes, ?string $source = null): array
    {
        return collect($recipes)
            ->map(fn (array $recipe) => [
                'id' => data_get($recipe, 'id', data_get($recipe, 'recipeId')),
                'source' => $source,
                'name' => data_get($recipe, 'name', data_get($recipe, 'recipeName', data_get($recipe, 'craftingStation', 'Recipe'))),
                'station' => $this->normalizeCraftingStationName(data_get($recipe, 'buildingName', data_get($recipe, 'craftingStation', data_get($recipe, 'stationName')))),
                'skill' => data_get($recipe, 'skillName', data_get($recipe, 'levelRequirements.0.skill.name', data_get($recipe, 'skill'))),
                'duration' => data_get($recipe, 'timeRequirement', data_get($recipe, 'duration', data_get($recipe, 'craftDuration'))),
                'outputQuantity' => data_get($recipe, 'outputQuantity', data_get($recipe, 'craftedItems.0.quantity', data_get($recipe, 'quantity'))),
                'ingredients' => $this->normalizeIngredients($recipe),
            ])
            ->values()
            ->all();
    }

    private function normalizeCraftingStationName(mixed $station): ?string
    {
        if (! is_string($station) || $station === '') {
            return null;
        }

        return preg_replace('/^(Ancient|Rough|Simple|Sturdy|Fine|Exquisite|Peerless|Ornate|Pristine|Magnificent|Flawless)\s+/i', '', $station);
    }

    private function normalizeIngredients(array $recipe): array
    {
        $stacks = data_get($recipe, 'consumedItemStacks');
        $consumedItems = data_get($recipe, 'consumedItems');

        if (is_array($stacks)) {
            return collect($stacks)
                ->map(function (array $ingredient, int $index) use ($consumedItems) {
                    $displayIngredient = is_array($consumedItems) ? data_get($consumedItems, $index, []) : [];
                    $type = data_get($ingredient, 'item_type', data_get($displayIngredient, 'itemType', data_get($ingredient, 'type')));

                    return [
                        'id' => data_get($ingredient, 'item_id', data_get($ingredient, 'id', data_get($ingredient, 'itemId'))),
                        'name' => data_get($displayIngredient, 'name', data_get($ingredient, 'name', data_get($ingredient, 'itemName', data_get($ingredient, 'cargoName', 'Unknown')))),
                        'quantity' => data_get($ingredient, 'quantity', data_get($ingredient, 'amount')),
                        'type' => $type,
                        'kind' => $this->itemKind($type),
                    ];
                })
                ->values()
                ->all();
        }

        $ingredients = Arr::first([
            data_get($recipe, 'consumedItems'),
            data_get($recipe, 'ingredients'),
            data_get($recipe, 'inputItems'),
            data_get($recipe, 'inputs'),
            data_get($recipe, 'requirements'),
            data_get($recipe, 'consumedItemStacks'),
        ], fn ($value) => is_array($value));

        return collect($ingredients ?? [])
            ->map(function (array $ingredient) {
                $type = data_get($ingredient, 'itemType', data_get($ingredient, 'type'));

                return [
                    'id' => data_get($ingredient, 'id', data_get($ingredient, 'itemId')),
                    'name' => data_get($ingredient, 'name', data_get($ingredient, 'itemName', data_get($ingredient, 'cargoName', 'Unknown'))),
                    'quantity' => data_get($ingredient, 'quantity', data_get($ingredient, 'amount')),
                    'type' => $type,
                    'kind' => $this->itemKind($type),
                ];
            })
            ->values()
            ->all();
    }
}
