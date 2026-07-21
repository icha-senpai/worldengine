<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BitjitaClient
{
    private const CLAIMS_LIMIT = 100;

    private const CLAIM_MARKET_LISTINGS_LIMIT = 200;

    private const STALLS_LIMIT = 100;

    public function market(array $filters = []): array
    {
        return $this->get('api/market', $filters);
    }

    public function claimMarketListings(string $claimEntityId, array $filters = []): array
    {
        $firstPage = $this->get(
            "api/claims/{$claimEntityId}/market/listings",
            $this->claimMarketListingQuery($filters, 1),
        );

        $totalPages = max(1, (int) data_get($firstPage, 'totalPages', 1));
        $listings = data_get($firstPage, 'listings', []);

        for ($page = 2; $page <= $totalPages; $page++) {
            $nextPage = $this->get(
                "api/claims/{$claimEntityId}/market/listings",
                $this->claimMarketListingQuery($filters, $page),
            );

            $listings = array_merge($listings, data_get($nextPage, 'listings', []));
        }

        return [
            ...$firstPage,
            'listings' => $listings,
            'count' => count($listings),
            'page' => 1,
            'limit' => self::CLAIM_MARKET_LISTINGS_LIMIT,
            'totalPages' => $totalPages,
        ];
    }

    public function marketOrders(string $itemKind, int|string $itemId, array $filters = []): array
    {
        return $this->get("api/market/{$itemKind}/{$itemId}", [
            'claimEntityId' => data_get($filters, 'claimEntityId'),
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

    public function claimInventories(string $claimEntityId): array
    {
        return $this->get("api/claims/{$claimEntityId}/inventories");
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

    public function experienceLevels(): array
    {
        return $this->get('static/experience/levels.json');
    }

    private function get(string $path, array $query = []): array
    {
        $query = $this->filledQuery($query);
        $cacheSeconds = $this->cacheSecondsFor($path);

        if ($cacheSeconds <= 0) {
            return $this->fetch($path, $query);
        }

        return Cache::remember(
            $this->cacheKey('get.'.$path.'.'.$this->queryFingerprint($query)),
            now()->addSeconds($cacheSeconds),
            fn () => $this->fetch($path, $query),
        );
    }

    private function fetch(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $this->filledQuery($query));
        $response->throw();

        return $response->json() ?? [];
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
            $responses = Http::pool(fn (Pool $pool) => collect($pages)
                ->map(fn (int $page) => $this->poolRequest($pool, (string) $page)
                    ->get('api/stalls', [
                        'page' => $page,
                        'limit' => self::STALLS_LIMIT,
                    ]))
                ->all());

            foreach ($pages as $page) {
                /** @var Response $response */
                $response = $responses[(string) $page];
                $response->throw();
                $stalls = array_merge($stalls, data_get($response->json() ?? [], 'stalls', []));
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

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.bitjita.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.bitjita.timeout', 12))
            ->withHeaders([
                'x-app-identifier' => (string) config('services.bitjita.app_identifier', 'Dataverse Bitcraft Tools'),
            ]);

        if (filled(config('services.bitjita.identity'))) {
            $request = $request->withHeader('x-bitjita-identity', (string) config('services.bitjita.identity'));
        }

        if (filled(config('services.bitjita.token'))) {
            $request = $request->withToken((string) config('services.bitjita.token'));
        }

        return $request;
    }

    private function poolRequest(Pool $pool, string $key): PendingRequest
    {
        $request = $pool->as($key)
            ->baseUrl(rtrim((string) config('services.bitjita.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.bitjita.timeout', 12))
            ->withHeaders([
                'x-app-identifier' => (string) config('services.bitjita.app_identifier', 'Dataverse Bitcraft Tools'),
            ]);

        if (filled(config('services.bitjita.identity'))) {
            $request = $request->withHeader('x-bitjita-identity', (string) config('services.bitjita.identity'));
        }

        if (filled(config('services.bitjita.token'))) {
            $request = $request->withToken((string) config('services.bitjita.token'));
        }

        return $request;
    }

    private function cacheKey(string $key): string
    {
        return 'bitjita:'.md5(implode('|', [
            (string) config('services.bitjita.base_url'),
            (string) config('services.bitjita.identity'),
            (string) config('services.bitjita.token'),
        ])).':'.$key;
    }

    private function cacheSecondsFor(string $path): int
    {
        return match (true) {
            $path === 'api/regions' => (int) config('services.bitjita.regions_cache_seconds', 86400),
            $path === 'api/market' => (int) config('services.bitjita.market_cache_seconds', 60),
            preg_match('#^api/market/(item|cargo)/[^/]+$#', $path) === 1 => (int) config('services.bitjita.market_orders_cache_seconds', 30),
            $path === 'api/claims' => (int) config('services.bitjita.claims_cache_seconds', 300),
            preg_match('#^api/claims/[^/]+$#', $path) === 1 => (int) config('services.bitjita.claim_details_cache_seconds', 300),
            preg_match('#^api/claims/[^/]+/market/listings$#', $path) === 1 => (int) config('services.bitjita.claim_market_listings_cache_seconds', 30),
            preg_match('#^api/claims/[^/]+/buildings$#', $path) === 1 => (int) config('services.bitjita.claim_buildings_cache_seconds', 300),
            preg_match('#^api/claims/[^/]+/inventories$#', $path) === 1 => (int) config('services.bitjita.claim_inventories_cache_seconds', 300),
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
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();
    }
}
