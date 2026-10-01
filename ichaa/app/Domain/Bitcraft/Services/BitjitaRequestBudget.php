<?php

namespace App\Domain\Bitcraft\Services;

use App\Domain\Bitcraft\Exceptions\BitjitaRefreshDelayed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class BitjitaRequestBudget
{
    public function reserve(): void
    {
        try {
            Cache::lock($this->key().':lock', 10)->block(2, function (): void {
                $state = $this->state();
                $now = now()->getTimestamp();
                $subject = $this->subject();
                $userCalls = array_values(array_filter($state['calls'], fn (array $call): bool => $call['subject'] === $subject));
                $retryAfter = $state['cooldownUntil'] - $now;

                if (count($state['calls']) >= $this->globalLimit()) {
                    $retryAfter = max($retryAfter, $state['calls'][0]['at'] + 60 - $now);
                }

                if (count($userCalls) >= $this->userLimit()) {
                    $retryAfter = max($retryAfter, $userCalls[0]['at'] + 60 - $now);
                }

                if ($retryAfter > 0) {
                    throw new BitjitaRefreshDelayed($retryAfter);
                }

                $tool = request()->route()?->getName() ?? request()->route()?->uri() ?? 'background';
                $state['calls'][] = ['at' => $now, 'subject' => $subject, 'tool' => $tool];
                $state['byTool'][$tool] = ($state['byTool'][$tool] ?? 0) + 1;
                Cache::put($this->key(), $state, 86400);
            });
        } catch (LockTimeoutException) {
            throw new BitjitaRefreshDelayed(2);
        }
    }

    public function coolDown(int $seconds): void
    {
        Cache::lock($this->key().':lock', 10)->block(2, function () use ($seconds): void {
            $state = $this->state();
            $state['cooldownUntil'] = max($state['cooldownUntil'], now()->getTimestamp() + max(1, $seconds));
            Cache::put($this->key(), $state, 86400);
        });
    }

    public function usage(): array
    {
        $state = $this->state();

        return [
            'limit' => $this->globalLimit(),
            'userLimit' => $this->userLimit(),
            'lastMinute' => count($state['calls']),
            'today' => array_sum($state['byTool']),
            'byTool' => $state['byTool'],
            'retryAfter' => max(0, $state['cooldownUntil'] - now()->getTimestamp()),
        ];
    }

    private function state(): array
    {
        $state = Cache::get($this->key(), []);
        $state['calls'] = array_values(array_filter($state['calls'] ?? [], fn (array $call): bool => $call['at'] > now()->getTimestamp() - 60));
        $state['cooldownUntil'] ??= 0;

        if (($state['day'] ?? null) !== now()->toDateString()) {
            $state['day'] = now()->toDateString();
            $state['byTool'] = [];
        }

        return $state;
    }

    private function subject(): string
    {
        $user = request()->user();

        return $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.md5((string) request()->ip());
    }

    private function key(): string
    {
        return 'bitjita:budget:'.md5((string) config('services.bitjita.base_url'));
    }

    private function globalLimit(): int
    {
        return max(1, (int) config('services.bitjita.requests_per_minute', 200));
    }

    private function userLimit(): int
    {
        return max(1, (int) config('services.bitjita.user_requests_per_minute', 150));
    }
}
