<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use JsonException;

class BitcraftLiveCompanionState
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $path = $this->statePath();

        if ($path === '' || ! File::exists($path)) {
            return $this->offline($path);
        }

        $modifiedAt = File::lastModified($path);
        $ageMs = max(0, (int) round((microtime(true) - $modifiedAt) * 1000));
        $payload = $this->readPayload($path);

        if ($payload === null) {
            return [
                ...$this->offline($path),
                'error' => 'The live companion bridge file could not be parsed.',
            ];
        }

        if ($ageMs > $this->staleAfterMs()) {
            return [
                'online' => false,
                'stale' => true,
                'path' => $path,
                'ageMs' => $ageMs,
                'lastCapturedAt' => Arr::get($payload, 'capturedAt'),
                'state' => null,
                'error' => null,
            ];
        }

        return [
            'online' => true,
            'stale' => false,
            'path' => $path,
            'ageMs' => $ageMs,
            'lastCapturedAt' => Arr::get($payload, 'capturedAt'),
            'state' => $this->normalizeState($payload),
            'error' => null,
        ];
    }

    private function statePath(): string
    {
        return (string) config('services.bitcraft_live_companion.state_path', '');
    }

    private function staleAfterMs(): int
    {
        return max(1, (int) config('services.bitcraft_live_companion.stale_after_seconds', 10)) * 1000;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(string $path): ?array
    {
        try {
            $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeState(array $payload): array
    {
        return [
            'schemaVersion' => (int) Arr::get($payload, 'schemaVersion', 1),
            'capturedAt' => Arr::get($payload, 'capturedAt'),
            'source' => [
                'kind' => Arr::get($payload, 'source.kind', 'unknown'),
                'modVersion' => Arr::get($payload, 'source.modVersion'),
                'gameVersion' => Arr::get($payload, 'source.gameVersion'),
            ],
            'biome' => [
                'id' => Arr::get($payload, 'biome.id'),
                'name' => Arr::get($payload, 'biome.name'),
                'confidence' => Arr::get($payload, 'biome.confidence', 'unknown'),
                'source' => Arr::get($payload, 'biome.source', 'unknown'),
            ],
            'inventory' => $this->normalizeStacks(Arr::get($payload, 'inventory', [])),
            'deployables' => $this->normalizeDeployables(Arr::get($payload, 'deployables', [])),
            'target' => $this->normalizeTarget(Arr::get($payload, 'target')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStacks(mixed $stacks): array
    {
        if (! is_array($stacks)) {
            return [];
        }

        return collect($stacks)
            ->filter(fn (mixed $stack): bool => is_array($stack))
            ->map(fn (array $stack): array => [
                'kind' => (string) Arr::get($stack, 'kind', 'unknown'),
                'id' => Arr::get($stack, 'id'),
                'name' => Arr::get($stack, 'name'),
                'quantity' => (int) Arr::get($stack, 'quantity', 0),
                'slot' => Arr::get($stack, 'slot'),
                'durability' => Arr::get($stack, 'durability'),
                'tier' => Arr::get($stack, 'tier'),
            ])
            ->filter(fn (array $stack): bool => $stack['quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDeployables(mixed $deployables): array
    {
        if (! is_array($deployables)) {
            return [];
        }

        return collect($deployables)
            ->filter(fn (mixed $deployable): bool => is_array($deployable))
            ->map(fn (array $deployable): array => [
                'entityId' => (string) Arr::get($deployable, 'entityId', ''),
                'name' => Arr::get($deployable, 'name'),
                'locationX' => Arr::get($deployable, 'locationX'),
                'locationZ' => Arr::get($deployable, 'locationZ'),
                'localX' => Arr::get($deployable, 'localX'),
                'localY' => Arr::get($deployable, 'localY'),
                'localZ' => Arr::get($deployable, 'localZ'),
                'inventory' => $this->normalizeStacks(Arr::get($deployable, 'inventory', [])),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeTarget(mixed $target): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        return [
            'entityId' => (string) Arr::get($target, 'entityId', ''),
            'kind' => Arr::get($target, 'kind'),
            'name' => Arr::get($target, 'name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offline(string $path): array
    {
        return [
            'online' => false,
            'stale' => false,
            'path' => $path,
            'ageMs' => null,
            'lastCapturedAt' => null,
            'state' => null,
            'error' => null,
        ];
    }
}
