<?php

namespace App\Domain\Bitcraft\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class BitcraftPlayerData
{
    private array $status = [];

    public function __construct(
        private BitjuiceClient $bitjuice,
        private BitjitaClient $bitjita,
        private BitcraftSpacetimeStaticData $staticData,
    ) {}

    public function players(string $query): array
    {
        return $this->fetch(fn (): array => $this->bitjuice->players($query), fn (): array => $this->bitjita->players($query), 'players');
    }

    public function player(string $id): array
    {
        return $this->fetch(function () use ($id): array {
            $payload = $this->bitjuice->player($id);
            $skills = $this->staticData->skillMap();

            if ($skills === []) {
                throw new RuntimeException('Local skill metadata unavailable');
            }

            $payload['player']['skillMap'] = $skills;

            return $payload;
        }, fn (): array => $this->bitjita->player($id), 'player');
    }

    public function playerInventories(string $id): array
    {
        return $this->fetch(fn (): array => $this->bitjuice->playerInventories($id), fn (): array => $this->bitjita->playerInventories($id), 'inventories');
    }

    public function playerPassiveCrafts(string $id, string $status = 'all'): array
    {
        return $this->fetch(fn (): array => $this->bitjuice->playerPassiveCrafts($id), fn (): array => $this->bitjita->playerPassiveCrafts($id, $status), 'craftResults');
    }

    public function experienceLevels(): array
    {
        return $this->bitjita->experienceLevels();
    }

    public function crafts(): array
    {
        return $this->fetch(fn (): array => $this->bitjuice->crafts(), fn (): array => $this->bitjita->crafts(), 'craftResults');
    }

    public function items(?string $query = null): array
    {
        return $this->bitjita->items($query);
    }

    public function cargo(?string $query = null): array
    {
        return $this->bitjita->cargo($query);
    }

    public function usingRelay(): void
    {
        $this->status = ['provider' => 'relay', 'fallback' => false, 'delayed' => false, 'retryAfter' => 0, 'updatedAt' => null];
    }

    public function refreshStatus(): array
    {
        return $this->status ?: ['provider' => null, 'fallback' => false, 'delayed' => false, 'retryAfter' => 0, 'updatedAt' => null];
    }

    private function fetch(Closure $primary, Closure $fallback, string $field): array
    {
        $stale = null;
        $primaryStatus = [];

        if ($this->bitjuice->isEnabled()) {
            try {
                $stale = $primary();
                $primaryStatus = $this->bitjuice->refreshStatus();

                if (! $primaryStatus['delayed']) {
                    $this->status = ['provider' => 'bitjuice', 'fallback' => false, ...$primaryStatus];

                    return [...$stale, 'source' => 'bitjuice'];
                }
            } catch (Throwable) {
                $primaryStatus = $this->bitjuice->refreshStatus();
            }
        }

        try {
            $payload = $fallback();

            if (! isset($payload[$field]) || ! is_array($payload[$field])) {
                throw new UnexpectedValueException('Invalid Bitjita fallback response');
            }

            $this->status = [
                'provider' => 'bitjita',
                'fallback' => $this->bitjuice->isEnabled(),
                'primaryRetryAfter' => $primaryStatus['retryAfter'] ?? 0,
                ...$this->bitjita->refreshStatus(),
            ];

            if ($stale !== null && $this->status['delayed']
                && ($primaryStatus['updatedAt'] ?? '') > ($this->status['updatedAt'] ?? '')) {
                $this->status = ['provider' => 'bitjuice', 'fallback' => false, ...$primaryStatus];

                return [...$stale, 'source' => 'bitjuice'];
            }

            return [...$payload, 'source' => 'bitjita'];
        } catch (Throwable $exception) {
            $this->status = ['provider' => 'bitjita', 'fallback' => $this->bitjuice->isEnabled(), ...$this->bitjita->refreshStatus()];

            if ($exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError())
                || $exception instanceof UnexpectedValueException) {
                (new BitjitaRequestBudget)->coolDown(15);
                $this->status['delayed'] = true;
                $this->status['retryAfter'] = max(15, $this->status['retryAfter']);
            }

            if ($stale !== null) {
                $this->status = ['provider' => 'bitjuice', 'fallback' => false, ...$primaryStatus];

                return [...$stale, 'source' => 'bitjuice'];
            }

            throw $exception;
        }
    }
}
