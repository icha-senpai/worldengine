<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Support\Facades\Cache;

class BitjitaClient
{
    private const CLAIMS_LIMIT = 100;

    private const CLAIM_MARKET_LISTINGS_LIMIT = 200;

    private const STALLS_LIMIT = 100;

    public function __construct(private BitjitaTransport $transport) {}

    public function refreshStatus(): array
    {
        return $this->transport->refreshStatus();
    }

    public function market(array $filters = []): array
    {
        return $this->get('api/market', $filters);
    }

    public function claimMarketListings(string $claimEntityId, array $filters = []): array
    {
        return $this->claimMarketListingsMany([$claimEntityId], $filters)[$claimEntityId] ?? [
            'listings' => [],
            'count' => 0,
            'page' => 1,
            'limit' => self::CLAIM_MARKET_LISTINGS_LIMIT,
            'totalPages' => 1,
        ];
    }

    public function claimMarketListingsMany(array $claimEntityIds, array $filters = []): array
    {
        $ids = collect($claimEntityIds)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $firstPages = $this->claimMarketListingPages($ids->all(), $filters, 1);
        $payloads = [];
        $remainingPageRequests = [];

        foreach ($ids as $id) {
            $firstPage = $firstPages[$id] ?? [];
            $totalPages = max(1, (int) data_get($firstPage, 'totalPages', 1));

            $payloads[$id] = [
                'firstPage' => $firstPage,
                'pages' => [],
                'totalPages' => $totalPages,
            ];

            for ($page = 2; $page <= $totalPages; $page++) {
                $remainingPageRequests[] = [
                    'claimEntityId' => $id,
                    'page' => $page,
                ];
            }
        }

        if ($remainingPageRequests !== []) {
            $remainingPages = $this->claimMarketListingPages(
                collect($remainingPageRequests)
                    ->map(fn (array $request): string => $request['claimEntityId'].':'.$request['page'])
                    ->all(),
                $filters,
                null,
            );

            foreach ($remainingPageRequests as $request) {
                $id = $request['claimEntityId'];
                $page = $request['page'];
                $payloads[$id]['pages'][$page] = $remainingPages["{$id}:{$page}"] ?? [];
            }
        }

        return collect($payloads)
            ->mapWithKeys(function (array $payload, string $id): array {
                $firstPage = $payload['firstPage'];
                $listings = data_get($firstPage, 'listings', []);

                for ($page = 2; $page <= $payload['totalPages']; $page++) {
                    $listings = array_merge($listings, data_get($payload['pages'][$page] ?? [], 'listings', []));
                }

                return [$id => [
                    ...$firstPage,
                    'listings' => $listings,
                    'count' => count($listings),
                    'page' => 1,
                    'limit' => self::CLAIM_MARKET_LISTINGS_LIMIT,
                    'totalPages' => $payload['totalPages'],
                ]];
            })
            ->all();
    }

    public function marketOrders(string $itemKind, int|string $itemId, array $filters = []): array
    {
        return $this->get("api/market/{$itemKind}/{$itemId}", [
            'claimEntityId' => data_get($filters, 'claimEntityId'),
            'regionId' => data_get($filters, 'regionId'),
        ]);
    }

    public function claims(array $filters = []): array
    {
        $firstPage = $this->get('api/claims', $this->claimsQuery($filters, 1));
        $count = (int) data_get($firstPage, 'count', count(data_get($firstPage, 'claims', [])));
        $totalPages = max(1, (int) ceil($count / self::CLAIMS_LIMIT));
        $claims = data_get($firstPage, 'claims', []);

        for ($page = 2; $page <= $totalPages; $page++) {
            $nextPage = $this->get('api/claims', $this->claimsQuery($filters, $page));

            $claims = array_merge($claims, data_get($nextPage, 'claims', []));
        }

        return [
            ...$firstPage,
            'claims' => $claims,
            'count' => $count,
            'page' => 1,
            'limit' => self::CLAIMS_LIMIT,
            'totalPages' => $totalPages,
        ];
    }

    public function claim(string $claimEntityId): array
    {
        return $this->get("api/claims/{$claimEntityId}");
    }

    public function stalls(): array
    {
        return Cache::remember(
            $this->cacheKey('stalls.all'),
            now()->addSeconds((int) config('services.bitjita.stalls_cache_seconds', 600)),
            fn () => $this->fetchStalls(),
        );
    }

    public function regions(): array
    {
        return $this->get('api/regions');
    }

    public function empires(?string $query = null): array
    {
        return $this->get('api/empires', [
            'q' => $query,
        ]);
    }

    public function empireClaims(string $empireEntityId): array
    {
        return $this->get("api/empires/{$empireEntityId}/claims");
    }

    public function claimBuildings(string $claimEntityId): array
    {
        return $this->get("api/claims/{$claimEntityId}/buildings");
    }

    public function claimBuildingsMany(array $claimEntityIds): array
    {
        $ids = collect($claimEntityIds)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $requests = [];

        foreach ($ids as $id) {
            $path = "api/claims/{$id}/buildings";
            $requests[$id] = [
                'path' => $path,
                'query' => [],
                'cacheKey' => $this->getCacheKey($path),
                'cacheSeconds' => $this->cacheSecondsFor($path),
            ];
        }

        return $this->transport->getMany($requests);
    }

    public function items(?string $query = null): array
    {
        return $this->get('api/items', [
            'q' => $query,
        ]);
    }

    public function item(int $itemId): array
    {
        return $this->get("api/items/{$itemId}");
    }

    public function cargo(?string $query = null): array
    {
        return $this->get('api/cargo', [
            'q' => $query,
        ]);
    }

    public function cargoItem(int $cargoId): array
    {
        return $this->get("api/cargo/{$cargoId}");
    }

    public function players(string $query): array
    {
        return $this->get('api/players', [
            'q' => $query,
        ]);
    }

    public function player(string $playerEntityId): array
    {
        return $this->get("api/players/{$playerEntityId}");
    }

    public function playerInventories(string $playerEntityId, ?string $query = null): array
    {
        return $this->get("api/players/{$playerEntityId}/inventories", [
            'q' => $query,
        ]);
    }

    public function playerPassiveCrafts(string $playerEntityId, string $status = 'all'): array
    {
        return $this->get("api/players/{$playerEntityId}/passive-crafts", [
            'status' => $status,
        ]);
    }

    public function experienceLevels(): array
    {
        return $this->get('static/experience/levels.json');
    }

    public function crafts(): array
    {
        return $this->get('api/crafts');
    }

    public function applicationCacheKey(string $key): string
    {
        return $this->cacheKey($key);
    }

    private function claimMarketListingPages(array $keys, array $filters, ?int $page): array
    {
        $requests = [];

        foreach ($keys as $key) {
            [$claimEntityId, $requestPage] = $this->claimMarketListingPageParts($key, $page);
            $requests[$key] = $this->claimMarketListingPageRequest($claimEntityId, $filters, $requestPage);
        }

        return $this->transport->getMany($requests);
    }

    private function get(string $path, array $query = []): array
    {
        $query = $this->filledQuery($query);

        return $this->transport->get(
            $path,
            $query,
            $this->cacheSecondsFor($path),
            $this->getCacheKey($path, $query),
        );
    }

    private function fetchStalls(): array
    {
        $firstPage = $this->get('api/stalls', [
            'page' => 1,
            'limit' => self::STALLS_LIMIT,
        ]);

        $totalPages = max(1, (int) data_get($firstPage, 'totalPages', 1));
        $stalls = data_get($firstPage, 'stalls', []);

        if ($totalPages > 1) {
            $pages = range(2, $totalPages);
            $path = 'api/stalls';
            $requests = [];

            foreach ($pages as $page) {
                $query = ['page' => $page, 'limit' => self::STALLS_LIMIT];
                $requests[$page] = [
                    'path' => $path,
                    'query' => $query,
                    'cacheSeconds' => $this->cacheSecondsFor($path),
                    'cacheKey' => $this->getCacheKey($path, $query),
                ];
            }

            $payloads = $this->transport->getMany($requests);

            foreach ($pages as $page) {
                $stalls = array_merge($stalls, data_get($payloads[$page], 'stalls', []));
            }
        }

        return [
            ...$firstPage,
            'stalls' => $stalls,
            'page' => 1,
            'limit' => self::STALLS_LIMIT,
            'totalPages' => $totalPages,
        ];
    }

    private function claimMarketListingQuery(array $filters, int $page): array
    {
        return [
            'page' => $page,
            'limit' => self::CLAIM_MARKET_LISTINGS_LIMIT,
            'side' => data_get($filters, 'side'),
            'itemType' => data_get($filters, 'itemType'),
            'itemId' => data_get($filters, 'itemId'),
        ];
    }

    /**
     * @return array{path: string, query: array<string, mixed>, cacheKey: string, cacheSeconds: int}
     */
    private function claimMarketListingPageRequest(string $claimEntityId, array $filters, int $page): array
    {
        $path = "api/claims/{$claimEntityId}/market/listings";
        $query = $this->claimMarketListingQuery($filters, $page);

        return [
            'path' => $path,
            'query' => $this->filledQuery($query),
            'cacheKey' => $this->getCacheKey($path, $query),
            'cacheSeconds' => $this->cacheSecondsFor($path),
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function claimMarketListingPageParts(string $key, ?int $page): array
    {
        if ($page !== null) {
            return [$key, $page];
        }

        [$claimEntityId, $requestPage] = explode(':', $key, 2);

        return [$claimEntityId, (int) $requestPage];
    }

    private function claimsQuery(array $filters, int $page): array
    {
        return [
            'q' => data_get($filters, 'q'),
            'page' => $page,
            'limit' => self::CLAIMS_LIMIT,
            'sort' => data_get($filters, 'sort', 'name'),
            'order' => data_get($filters, 'order', 'asc'),
            'regionId' => data_get($filters, 'regionId'),
        ];
    }

    private function cacheKey(string $key): string
    {
        return 'bitjita:'.md5(implode('|', [
            (string) config('services.bitjita.base_url'),
            (string) config('services.bitjita.identity'),
            (string) config('services.bitjita.token'),
        ])).':'.$key;
    }

    private function getCacheKey(string $path, array $query = []): string
    {
        return $this->cacheKey('get.'.$path.'.'.$this->queryFingerprint($this->filledQuery($query)));
    }

    private function cacheSecondsFor(string $path): int
    {
        return match (true) {
            $path === 'api/players' => (int) config('services.bitjita.players_cache_seconds', 60),
            preg_match('#^api/players/[^/]+$#', $path) === 1 => (int) config('services.bitjita.player_cache_seconds', 15),
            preg_match('#^api/players/[^/]+/inventories$#', $path) === 1 => (int) config('services.bitjita.player_inventories_cache_seconds', 15),
            preg_match('#^api/players/[^/]+/passive-crafts$#', $path) === 1 => (int) config('services.bitjita.player_passive_crafts_cache_seconds', 15),
            $path === 'api/stalls' => (int) config('services.bitjita.stalls_cache_seconds', 300),
            $path === 'api/crafts' => (int) config('services.bitjita.crafts_cache_seconds', 60),
            $path === 'api/regions' => (int) config('services.bitjita.regions_cache_seconds', 86400),
            $path === 'api/market' => (int) config('services.bitjita.market_cache_seconds', 60),
            preg_match('#^api/market/(item|cargo)/[^/]+$#', $path) === 1 => (int) config('services.bitjita.market_orders_cache_seconds', 30),
            $path === 'api/claims' => (int) config('services.bitjita.claims_cache_seconds', 300),
            preg_match('#^api/claims/[^/]+$#', $path) === 1 => (int) config('services.bitjita.claim_details_cache_seconds', 300),
            preg_match('#^api/claims/[^/]+/market/listings$#', $path) === 1 => (int) config('services.bitjita.claim_market_listings_cache_seconds', 30),
            preg_match('#^api/claims/[^/]+/buildings$#', $path) === 1 => (int) config('services.bitjita.claim_buildings_cache_seconds', 300),
            $path === 'api/empires',
            preg_match('#^api/empires/[^/]+/claims$#', $path) === 1 => (int) config('services.bitjita.empires_cache_seconds', 600),
            $path === 'api/items',
            preg_match('#^api/items/[^/]+$#', $path) === 1 => (int) config('services.bitjita.items_cache_seconds', 3600),
            $path === 'api/cargo',
            preg_match('#^api/cargo/[^/]+$#', $path) === 1 => (int) config('services.bitjita.items_cache_seconds', 3600),
            $path === 'static/experience/levels.json' => 86400,
            default => 0,
        };
    }

    private function queryFingerprint(array $query): string
    {
        ksort($query);

        return md5(http_build_query($query));
    }

    private function filledQuery(array $query): array
    {
        return collect($query)
            ->reject(fn ($value) => $value === null || $value === '' || $value === false)
            ->map(fn ($value) => $value === true ? 'true' : $value)
            ->all();
    }
}
