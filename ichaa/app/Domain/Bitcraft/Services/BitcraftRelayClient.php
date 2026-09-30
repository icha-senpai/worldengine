<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BitcraftRelayClient
{
    public function isEnabled(): bool
    {
        if (! (bool) config('services.bitcraft_relay.enabled', true)) {
            return false;
        }

        if (app()->environment('testing') && ! (bool) config('services.bitcraft_relay.enabled_in_tests', false)) {
            return false;
        }

        return true;
    }

    public function players(string $query): array
    {
        return $this->get('player', [
            'name' => $query,
        ]);
    }

    public function player(string $playerEntityId): array
    {
        return $this->get("player/{$playerEntityId}");
    }

    public function playerInventory(string $playerEntityId): array
    {
        return $this->get("player/{$playerEntityId}/inventory");
    }

    public function playerCrafts(string $playerEntityId, ?bool $completed = null): array
    {
        return $this->get("player/{$playerEntityId}/crafts", [
            'completed' => $completed === null ? null : ($completed ? 'true' : 'false'),
        ]);
    }

    public function claim(string $claimEntityId): array
    {
        return $this->get("claim/{$claimEntityId}");
    }

    private function get(string $path, array $query = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $query = $this->filledQuery($query);
        $cacheSeconds = (int) config('services.bitcraft_relay.cache_seconds', 5);

        if ($cacheSeconds <= 0) {
            return $this->request()->get($path, $query)->throw()->json() ?? [];
        }

        return Cache::remember(
            $this->cacheKey($path, $query),
            now()->addSeconds($cacheSeconds),
            fn (): array => $this->request()->get($path, $query)->throw()->json() ?? [],
        );
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.bitcraft_relay.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.bitcraft_relay.timeout', 8));
    }

    private function filledQuery(array $query): array
    {
        return collect($query)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();
    }

    private function cacheKey(string $path, array $query = []): string
    {
        ksort($query);

        return 'bitcraft-relay:'.md5(implode('|', [
            (string) config('services.bitcraft_relay.base_url'),
            $path,
            http_build_query($query),
        ]));
    }
}
