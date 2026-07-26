<?php

namespace App\Http\Controllers\Bitcraft\Concerns;

use App\Domain\Bitcraft\Models\BitcraftWidgetProfile;
use Illuminate\Http\Request;

trait ScopesBitcraftWidgetProfiles
{
    /**
     * @param  array<string, mixed>  $validated
     */
    protected function bitcraftWidgetProfileUserId(Request $request, array $validated): ?int
    {
        if ($request->user()) {
            return (int) $request->user()->getKey();
        }

        $userId = (int) ($validated['user'] ?? 0);

        return $userId > 0 ? $userId : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bitcraftWidgetProfileSettings(string $widget, string $source, ?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return BitcraftWidgetProfile::query()
            ->where('user_id', $userId)
            ->where('widget', $widget)
            ->where('source', $source)
            ->first()
            ?->settings ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function bitcraftWidgetProfileRouteParameters(Request $request, array $filters): array
    {
        $userId = $request->user()
            ? (int) $request->user()->getKey()
            : (int) ($filters['user'] ?? 0);

        return collect([
            'source' => $filters['source'],
            'user' => $userId > 0 ? $userId : null,
        ])->reject(fn ($value): bool => blank($value))->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function saveBitcraftWidgetProfile(Request $request, string $widget, array $filters): void
    {
        $userId = $request->user()
            ? (int) $request->user()->getKey()
            : (int) ($filters['user'] ?? 0);

        if ($userId < 1) {
            return;
        }

        $profile = BitcraftWidgetProfile::query()
            ->where('user_id', $userId)
            ->where('widget', $widget)
            ->where('source', $filters['source']);

        if (! $request->user() && ! $profile->exists()) {
            return;
        }

        $profile->updateOrCreate(
            [
                'user_id' => $userId,
                'widget' => $widget,
                'source' => $filters['source'],
            ],
            [
                'settings' => collect($filters)->except('setup', 'user')->all(),
            ],
        );
    }
}
