<?php

namespace App\Domain\Bitcraft\Services;

use App\Domain\Bitcraft\Exceptions\BitjitaRefreshDelayed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BitjuiceClient
{
    private BitcraftApiRequestBudget $budget;

    private int $retryAfter = 0;

    private ?string $updatedAt = null;

    public function __construct()
    {
        $this->budget = new BitcraftApiRequestBudget('bitjuice');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.bitjuice.enabled', true)
            && (! app()->environment('testing') || (bool) config('services.bitjuice.enabled_in_tests', false));
    }

    public function players(string $query): array
    {
        return $this->get('api/players', ['q' => $query], 'players', 'players_cache_seconds');
    }

    public function player(string $id): array
    {
        return $this->get('api/players/'.$id, [], 'player', 'player_cache_seconds');
    }

    public function playerInventories(string $id): array
    {
        return $this->get('api/players/'.$id.'/inventories', [], 'inventories', 'inventories_cache_seconds');
    }

    public function playerPassiveCrafts(string $id): array
    {
        return $this->get('api/players/'.$id.'/passive-crafts', [], 'craftResults', 'passive_crafts_cache_seconds');
    }

    public function crafts(): array
    {
        return $this->get('api/crafts', [], 'craftResults', 'crafts_cache_seconds');
    }

    public function refreshStatus(): array
    {
        return ['delayed' => $this->retryAfter > 0, 'retryAfter' => $this->retryAfter, 'updatedAt' => $this->updatedAt];
    }

    public function usage(): array
    {
        return $this->budget->usage();
    }

    private function get(string $path, array $query, string $field, string $setting): array
    {
        $this->retryAfter = 0;
        $this->updatedAt = null;
        $baseUrl = rtrim((string) config('services.bitjuice.base_url'), '/');
        $key = 'bitjuice:responses.v1:'.md5($baseUrl.'|'.$path.'|'.json_encode($query, JSON_THROW_ON_ERROR));
        $cached = Cache::get($key);

        if ($cached && $cached['expiresAt'] > now()->getTimestamp()) {
            return $this->cachedPayload($cached);
        }

        $lock = Cache::lock($key.':lock', max(1, (int) config('services.bitjuice.timeout', 5)) + 10);

        if (! $lock->get()) {
            return $this->staleOrThrow($cached, new BitjitaRefreshDelayed(2, 'bitjuice'));
        }

        try {
            $cached = Cache::get($key);

            if ($cached && $cached['expiresAt'] > now()->getTimestamp()) {
                return $this->cachedPayload($cached);
            }

            $retryAfter = (int) Cache::get($key.':cooldown', 0) - now()->getTimestamp();

            if ($retryAfter > 0) {
                return $this->staleOrThrow($cached, new BitjitaRefreshDelayed($retryAfter, 'bitjuice'));
            }

            try {
                $this->budget->reserve();
            } catch (BitjitaRefreshDelayed $exception) {
                return $this->staleOrThrow($cached, $exception);
            }

            $response = null;

            try {
                $response = Http::baseUrl($baseUrl)->acceptJson()
                    ->timeout(max(1, (int) config('services.bitjuice.timeout', 5)))
                    ->connectTimeout(3)
                    ->withHeader('User-Agent', 'Dataverse Bitcraft Tools/1.0')
                    ->get($path, $query);

                if ($response->status() === 429) {
                    throw new BitjitaRefreshDelayed($this->responseRetryAfter((string) $response->header('Retry-After')), 'bitjuice');
                }

                $response->throw();
                $payload = $response->json();
                $this->validatePayload($payload, $field, $path);
                $seconds = max(1, (int) config('services.bitjuice.'.$setting));
                $cached = [
                    'payload' => $payload,
                    'fetchedAt' => now()->toIso8601String(),
                    'expiresAt' => now()->getTimestamp() + $seconds,
                ];
                Cache::put($key, $cached, $seconds + max(0, (int) config('services.bitjuice.stale_cache_seconds', 300)));

                return $this->cachedPayload($cached);
            } catch (Throwable $exception) {
                $seconds = $exception instanceof BitjitaRefreshDelayed
                    ? $exception->retryAfter
                    : max(1, (int) config('services.bitjuice.failure_cooldown_seconds', 30));

                if ($response && $response->clientError() && $response->status() !== 429) {
                    Cache::put($key.':cooldown', now()->getTimestamp() + $seconds, $seconds);
                } else {
                    $this->budget->coolDown($seconds);
                }

                return $this->staleOrThrow($cached, new BitjitaRefreshDelayed($seconds, 'bitjuice'));
            }
        } finally {
            $lock->release();
        }
    }

    private function validatePayload(mixed $payload, string $field, string $path): void
    {
        if (! is_array($payload) || ! isset($payload[$field]) || ! is_array($payload[$field])) {
            throw new RuntimeException('Invalid BitJuice response for '.$field);
        }

        if ($field === 'player') {
            $player = $payload['player'];

            if ((string) data_get($player, 'entityId') !== basename($path)
                || ! is_string(data_get($player, 'username')) || ! is_array(data_get($player, 'experience'))) {
                throw new RuntimeException('Invalid BitJuice player');
            }

            return;
        }

        if (! array_is_list($payload[$field])) {
            throw new RuntimeException('Invalid BitJuice list');
        }

        foreach ($payload[$field] as $row) {
            if (! is_array($row) || ! ctype_digit((string) data_get($row, 'entityId'))) {
                throw new RuntimeException('Invalid BitJuice list entry');
            }

            if (($field === 'players' && (! is_string(data_get($row, 'username')) || blank($row['username'])))
                || ($field === 'inventories' && ! is_array(data_get($row, 'pockets')))
                || ($field === 'craftResults' && ((int) data_get($row, 'recipeId') < 1
                    || ($path !== 'api/crafts' && blank(data_get($row, 'timestamp')))
                    || ! is_array(data_get($row, 'craftedItem'))))) {
                throw new RuntimeException('Incomplete BitJuice list entry');
            }

            if ($path === 'api/crafts' && (! is_numeric(data_get($row, 'progress'))
                || ! is_numeric(data_get($row, 'totalActionsRequired'))
                || ! is_bool(data_get($row, 'completed')) || ! is_bool(data_get($row, 'isPublic')))) {
                throw new RuntimeException('Invalid BitJuice open craft');
            }
        }

        if ($field === 'inventories' && (! is_array(data_get($payload, 'items')) || ! is_array(data_get($payload, 'cargos')))) {
            throw new RuntimeException('Invalid BitJuice inventory catalog');
        }
    }

    private function staleOrThrow(?array $cached, BitjitaRefreshDelayed $exception): array
    {
        $this->retryAfter = $exception->retryAfter;

        if ($cached) {
            return $this->cachedPayload($cached);
        }

        throw $exception;
    }

    private function cachedPayload(array $cached): array
    {
        $this->updatedAt = $cached['fetchedAt'];

        return $cached['payload'];
    }

    private function responseRetryAfter(string $header): int
    {
        $header = trim($header);

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
