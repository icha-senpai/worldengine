<?php

namespace App\Domain\Bitcraft\Services;

use App\Domain\Bitcraft\Exceptions\BitjitaRefreshDelayed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BitjitaTransport
{
    private int $retryAfter = 0;

    private ?string $updatedAt = null;

    public function __construct(private BitjitaRequestBudget $budget) {}

    public function get(string $path, array $query, int $cacheSeconds, string $cacheKey): array
    {
        return $this->getMany(['single' => compact('path', 'query', 'cacheSeconds', 'cacheKey')])['single'];
    }

    /**
     * @param  array<string|int, array{path: string, query: array, cacheSeconds: int, cacheKey: string}>  $requests
     */
    public function getMany(array $requests): array
    {
        $payloads = [];
        $concurrency = max(1, (int) config('services.bitjita.pool_concurrency', 8));
        // Warm new pages first so retries of large searches can make progress.
        $requests = collect($requests)->sortBy(fn (array $request): int => Cache::has('bitjita:responses.v1:'.$request['cacheKey']) ? 1 : 0)->all();

        foreach (array_chunk($requests, $concurrency, true) as $chunk) {
            $pending = [];
            $locks = [];
            $failure = null;

            try {
                foreach ($chunk as $key => $request) {
                    $cacheKey = 'bitjita:responses.v1:'.$request['cacheKey'];
                    $cached = Cache::get($cacheKey);

                    if ($cached && $cached['expiresAt'] > now()->getTimestamp()) {
                        $payloads[$key] = $this->cachedPayload($request['path'], $cached);

                        continue;
                    }

                    $lock = Cache::lock($cacheKey.':lock', max(1, (int) config('services.bitjita.timeout', 12)) + 10);

                    if (! $lock->get()) {
                        $cached = Cache::get($cacheKey);
                        $exception = new BitjitaRefreshDelayed(2);
                        $this->retryAfter = max($this->retryAfter, $exception->retryAfter);

                        if ($cached) {
                            $payloads[$key] = $this->cachedPayload($request['path'], $cached, $exception->retryAfter);
                        } else {
                            $failure ??= $exception;
                        }

                        continue;
                    }

                    $locks[] = $lock;
                    // Another worker may have filled this key before the lock was acquired.
                    $cached = Cache::get($cacheKey);

                    if ($cached && $cached['expiresAt'] > now()->getTimestamp()) {
                        $payloads[$key] = $this->cachedPayload($request['path'], $cached);

                        continue;
                    }

                    try {
                        $this->budget->reserve();
                        $pending[$key] = [...$request, 'storedKey' => $cacheKey, 'cached' => $cached];
                    } catch (BitjitaRefreshDelayed $exception) {
                        $this->retryAfter = max($this->retryAfter, $exception->retryAfter);

                        if ($cached) {
                            $payloads[$key] = $this->cachedPayload($request['path'], $cached, $exception->retryAfter);
                        } else {
                            $failure ??= $exception;
                        }
                    }
                }

                $responses = $pending === [] ? [] : Http::pool(
                    fn (Pool $pool): array => collect($pending)
                        ->map(fn (array $request, string|int $key) => $this->request($pool->as((string) $key))
                            ->get($request['path'], $request['query']))
                        ->all(),
                    concurrency: $concurrency,
                );

                foreach ($pending as $key => $request) {
                    $response = $responses[$key];

                    try {
                        if ($response instanceof Throwable) {
                            throw $response;
                        }

                        if ($response->status() === 429) {
                            $retryAfter = $this->responseRetryAfter($response);
                            $this->budget->coolDown($retryAfter);
                            throw new BitjitaRefreshDelayed($retryAfter);
                        }

                        $response->throw();
                        $cached = [
                            'payload' => $response->json() ?? [],
                            'fetchedAt' => now()->toIso8601String(),
                            'expiresAt' => now()->getTimestamp() + $request['cacheSeconds'],
                        ];

                        if ($request['cacheSeconds'] > 0) {
                            Cache::put($request['storedKey'], $cached, $request['cacheSeconds'] + max(0, (int) config('services.bitjita.stale_cache_seconds', 300)));
                        }

                        $payloads[$key] = $this->cachedPayload($request['path'], $cached);
                    } catch (Throwable $exception) {
                        $canUseStale = $exception instanceof BitjitaRefreshDelayed
                            || $response instanceof Throwable
                            || $response->serverError();
                        $retryAfter = $exception instanceof BitjitaRefreshDelayed ? $exception->retryAfter : 15;
                        $this->retryAfter = max($this->retryAfter, $retryAfter);

                        if ($request['cached'] && $canUseStale) {
                            $payloads[$key] = $this->cachedPayload($request['path'], $request['cached'], $retryAfter);
                        } else {
                            $failure ??= $exception;
                        }
                    }
                }

                if ($failure) {
                    throw $failure;
                }
            } finally {
                foreach ($locks as $lock) {
                    $lock->release();
                }
            }
        }

        return $payloads;
    }

    public function refreshStatus(): array
    {
        return ['delayed' => $this->retryAfter > 0, 'retryAfter' => $this->retryAfter, 'updatedAt' => $this->updatedAt];
    }

    private function cachedPayload(string $path, array $cached, int $retryAfter = 0): array
    {
        $this->retryAfter = max($this->retryAfter, $retryAfter);

        if ((str_starts_with($path, 'api/players/') || str_starts_with($path, 'api/market')
            || $path === 'api/stalls' || str_ends_with($path, '/market/listings'))
            && ($this->updatedAt === null || $cached['fetchedAt'] < $this->updatedAt)) {
            $this->updatedAt = $cached['fetchedAt'];
        }

        return $cached['payload'];
    }

    private function request(PendingRequest $request): PendingRequest
    {
        $request = $request->baseUrl(rtrim((string) config('services.bitjita.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.bitjita.timeout', 12))
            ->connectTimeout(5)
            ->withHeader('x-app-identifier', (string) config('services.bitjita.app_identifier', 'Dataverse Bitcraft Tools'));

        if (filled(config('services.bitjita.identity'))) {
            $request = $request->withHeader('x-bitjita-identity', (string) config('services.bitjita.identity'));
        }

        if (filled(config('services.bitjita.token'))) {
            $request = $request->withToken((string) config('services.bitjita.token'));
        }

        return $request;
    }

    private function responseRetryAfter(Response $response): int
    {
        $header = trim((string) $response->header('Retry-After'));

        if (ctype_digit($header)) {
            return max(1, (int) $header);
        }

        try {
            return $header === '' ? 60 : max(1, CarbonImmutable::parse($header)->getTimestamp() - now()->getTimestamp());
        } catch (Throwable) {
            return 60;
        }
    }
}
