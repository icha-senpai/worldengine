<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftWidgetProfile;
use App\Domain\Bitcraft\Services\BitjitaClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class BitcraftActivityController extends Controller
{
    private const DEFAULT_CHARACTER = 'icha';

    private const DEFAULT_SKILL = 'all';

    private const DEFAULT_TITLE = 'XP Goals';

    private const DEFAULT_ICONS = '✨ 🏆';

    public function show(Request $request, BitjitaClient $bitjita): InertiaResponse|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($request->has('source') && $this->hasProfileInput($request)) {
            $this->saveProfile($filters);

            if (! $filters['setup']) {
                return redirect()->route('bitcraft.activity', ['source' => $filters['source']]);
            }
        }

        $snapshot = $this->trackerSnapshot($bitjita, $filters);
        $pollFilters = $this->pollFilters($filters);

        if (filled(data_get($snapshot, 'tracker.player.entityId'))) {
            $pollFilters['character'] = (string) data_get($snapshot, 'tracker.player.entityId');
        }

        return Inertia::render('Bitcraft/Activity', [
            'filters' => $filters,
            'snapshot' => $snapshot,
            'pollUrl' => route('bitcraft.activity.snapshot', $pollFilters, false),
        ]);
    }

    public function snapshot(Request $request, BitjitaClient $bitjita): JsonResponse
    {
        return response()
            ->json($this->trackerSnapshot($bitjita, $this->filters($request)))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'character' => ['nullable', 'string', 'max:80'],
            'skill' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:80'],
            'icons' => ['nullable', 'string', 'max:40'],
            'skillSearch' => ['nullable', 'string', 'max:120'],
            'skillKeys' => ['nullable', 'string', 'max:500'],
            'skillGoalLevels' => ['nullable', 'string', 'max:1000'],
            'skillGoalXp' => ['nullable', 'string', 'max:1000'],
            'setup' => ['nullable', 'boolean'],
        ]);
        $source = trim((string) ($validated['source'] ?? 'default')) ?: 'default';
        $stored = $request->has('source') && ! $this->hasProfileInput($request)
            ? $this->profileSettings('activity', $source)
            : [];

        return [
            'source' => $source,
            'character' => trim((string) ($validated['character'] ?? data_get($stored, 'character', self::DEFAULT_CHARACTER))) ?: self::DEFAULT_CHARACTER,
            'skill' => trim((string) ($validated['skill'] ?? data_get($stored, 'skill', self::DEFAULT_SKILL))) ?: self::DEFAULT_SKILL,
            'title' => trim((string) ($validated['title'] ?? data_get($stored, 'title', self::DEFAULT_TITLE))) ?: self::DEFAULT_TITLE,
            'icons' => $request->has('icons')
                ? trim((string) ($validated['icons'] ?? ''))
                : trim((string) data_get($stored, 'icons', self::DEFAULT_ICONS)),
            'skillSearch' => trim((string) ($validated['skillSearch'] ?? data_get($stored, 'skillSearch', ''))),
            'skillKeys' => $this->selectedSkillKeys($validated['skillKeys'] ?? data_get($stored, 'skillKeys', '')),
            'skillGoalLevels' => $this->selectedSkillGoals($validated['skillGoalLevels'] ?? data_get($stored, 'skillGoalLevels', '')),
            'skillGoalXp' => $this->selectedSkillGoals($validated['skillGoalXp'] ?? data_get($stored, 'skillGoalXp', '')),
            'setup' => $request->has('setup') ? $request->boolean('setup') : false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{character: string, skill: string}
     */
    private function pollFilters(array $filters): array
    {
        return [
            'character' => (string) $filters['character'],
            'skill' => (string) $filters['skill'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{tracker: ?array<string, mixed>, error: ?string, sampledAt: string}
     */
    private function trackerSnapshot(BitjitaClient $bitjita, array $filters): array
    {
        try {
            $player = $this->resolvePlayer($bitjita, $filters['character']);

            if ($player === null) {
                return $this->snapshotError("No Bitjita player matched '{$filters['character']}'.");
            }

            $levels = $this->normalizeLevels($bitjita->experienceLevels());
            $skills = $this->resolveSkills($player, $filters['skill'], $levels);

            if ($skills === []) {
                return $this->snapshotError("No Bitjita skill matched '{$filters['skill']}'.");
            }

            $primarySkill = collect($skills)
                ->sortByDesc(fn (array $skill): int => (int) data_get($skill, 'xp'))
                ->first();

            return [
                'tracker' => [
                    'scope' => $this->tracksAllSkills($filters['skill']) ? 'all' : 'skill',
                    'player' => [
                        'entityId' => (string) data_get($player, 'entityId'),
                        'username' => (string) data_get($player, 'username', 'Unknown'),
                        'signedIn' => (bool) data_get($player, 'signedIn', false),
                        'updatedAt' => data_get($player, 'updatedAt'),
                    ],
                    'skill' => [
                        'id' => $primarySkill['id'],
                        'name' => $primarySkill['name'],
                        'title' => $primarySkill['title'],
                    ],
                    'xp' => $primarySkill['xp'],
                    'skills' => $skills,
                    'levels' => $levels,
                    ...collect($primarySkill)
                        ->only([
                            'level',
                            'nextLevel',
                            'nextLevelXp',
                            'xpIntoLevel',
                            'xpForLevel',
                            'xpRemaining',
                            'progressPercent',
                        ])
                        ->all(),
                ],
                'error' => null,
                'sampledAt' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->snapshotError('Bitjita did not respond cleanly. The tracker will try again shortly.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlayer(BitjitaClient $bitjita, string $character): ?array
    {
        if (ctype_digit($character)) {
            return data_get($bitjita->player($character), 'player');
        }

        $players = data_get($bitjita->players($character), 'players', []);
        $selected = collect($players)->first(
            fn (array $player): bool => strcasecmp((string) data_get($player, 'username'), $character) === 0,
        ) ?? collect($players)->first();

        if (! $selected || blank(data_get($selected, 'entityId'))) {
            return null;
        }

        return data_get($bitjita->player((string) data_get($selected, 'entityId')), 'player');
    }

    /**
     * @param  array<string, mixed>  $player
     * @param  array<int, array{level: int, xp: int}>  $levels
     * @return array<int, array<string, mixed>>
     */
    private function resolveSkills(array $player, string $skill, array $levels): array
    {
        $skillMap = collect(data_get($player, 'skillMap', []))
            ->filter(fn (array $entry): bool => (int) data_get($entry, 'id') > 1
                && strtoupper((string) data_get($entry, 'name')) !== 'ANY');

        if ($this->tracksAllSkills($skill)) {
            return $skillMap
                ->map(fn (array $entry): array => $this->skillSnapshot($player, $entry, $levels))
                ->sortBy(fn (array $entry): string => strtolower((string) data_get($entry, 'name')))
                ->values()
                ->all();
        }

        $skillRecord = $this->resolveSkillRecord($skillMap, $skill);

        return is_array($skillRecord)
            ? [$this->skillSnapshot($player, $skillRecord, $levels)]
            : [];
    }

    private function tracksAllSkills(string $skill): bool
    {
        return in_array(strtolower(trim($skill)), ['', 'all', '*'], true);
    }

    private function hasProfileInput(Request $request): bool
    {
        return collect([
            'character',
            'skill',
            'title',
            'icons',
            'skillSearch',
            'skillKeys',
            'skillGoalLevels',
            'skillGoalXp',
        ])->contains(fn (string $key): bool => $request->has($key));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSettings(string $widget, string $source): array
    {
        return BitcraftWidgetProfile::query()
            ->where('widget', $widget)
            ->where('source', $source)
            ->first()
            ?->settings ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function saveProfile(array $filters): void
    {
        BitcraftWidgetProfile::query()->updateOrCreate(
            [
                'widget' => 'activity',
                'source' => $filters['source'],
            ],
            [
                'settings' => collect($filters)->except('setup')->all(),
            ],
        );
    }

    /**
     * @param  array<int, string>|string  $skillKeys
     * @return array<int, string>
     */
    private function selectedSkillKeys(array|string $skillKeys): array
    {
        $keys = is_array($skillKeys) ? $skillKeys : explode(',', $skillKeys);

        return collect($keys)
            ->map(fn (string $key): string => trim($key))
            ->filter(fn (string $key): bool => ctype_digit($key))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>|string  $skillGoals
     * @return array<string, int>
     */
    private function selectedSkillGoals(array|string $skillGoals): array
    {
        if (is_array($skillGoals)) {
            return collect($skillGoals)
                ->mapWithKeys(fn (int|string $goal, int|string $key): array => ctype_digit((string) $key) && (int) $goal > 0
                    ? [(string) $key => (int) $goal]
                    : [])
                ->all();
        }

        return collect(explode(',', $skillGoals))
            ->mapWithKeys(function (string $entry): array {
                $separator = strpos($entry, '=');

                if ($separator === false) {
                    return [];
                }

                $key = trim(substr($entry, 0, $separator));
                $goal = (int) trim(substr($entry, $separator + 1));

                if (! ctype_digit($key) || $goal < 1) {
                    return [];
                }

                return [$key => $goal];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSkillRecord($skillMap, string $skill): ?array
    {
        return ctype_digit($skill)
            ? $skillMap->get($skill)
            : (
                $skillMap->first(fn (array $entry): bool => strcasecmp((string) data_get($entry, 'name'), $skill) === 0)
                ?? $skillMap->first(fn (array $entry): bool => strcasecmp((string) data_get($entry, 'title'), $skill) === 0)
            );
    }

    /**
     * @param  array<string, mixed>  $player
     * @param  array<string, mixed>  $skillRecord
     * @param  array<int, array{level: int, xp: int}>  $levels
     * @return array<string, mixed>
     */
    private function skillSnapshot(array $player, array $skillRecord, array $levels): array
    {
        $skillId = (int) data_get($skillRecord, 'id');
        $experience = collect(data_get($player, 'experience', []))
            ->first(fn (array $entry): bool => (int) data_get($entry, 'skill_id', data_get($entry, 'skillId')) === $skillId);
        $xp = (int) data_get($experience, 'quantity', 0);

        return [
            'id' => $skillId,
            'name' => (string) data_get($skillRecord, 'name', 'Unknown skill'),
            'title' => filled(data_get($skillRecord, 'title')) ? (string) data_get($skillRecord, 'title') : null,
            'xp' => $xp,
            ...$this->levelProgress($xp, $levels),
        ];
    }

    /**
     * @return array<int, array{level: int, xp: int}>
     */
    private function normalizeLevels(array $payload): array
    {
        return collect($payload)
            ->map(fn (array $level): array => [
                'level' => (int) data_get($level, 'level'),
                'xp' => (int) data_get($level, 'xp'),
            ])
            ->filter(fn (array $level): bool => $level['level'] > 0)
            ->sortBy('xp')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{level: int, xp: int}>  $levels
     * @return array<string, mixed>
     */
    private function levelProgress(int $xp, array $levels): array
    {
        $current = collect($levels)
            ->filter(fn (array $level): bool => $level['xp'] <= $xp)
            ->last() ?? ['level' => 1, 'xp' => 0];
        $next = collect($levels)
            ->first(fn (array $level): bool => $level['xp'] > $xp);

        if (! $next) {
            return [
                'level' => $current['level'],
                'nextLevel' => null,
                'nextLevelXp' => null,
                'xpIntoLevel' => $xp - $current['xp'],
                'xpForLevel' => null,
                'xpRemaining' => null,
                'progressPercent' => 100.0,
            ];
        }

        $xpIntoLevel = max(0, $xp - $current['xp']);
        $xpForLevel = max(1, $next['xp'] - $current['xp']);

        return [
            'level' => $current['level'],
            'nextLevel' => $next['level'],
            'nextLevelXp' => $next['xp'],
            'xpIntoLevel' => $xpIntoLevel,
            'xpForLevel' => $xpForLevel,
            'xpRemaining' => max(0, $next['xp'] - $xp),
            'progressPercent' => round(min(100, ($xpIntoLevel / $xpForLevel) * 100), 1),
        ];
    }

    /**
     * @return array{tracker: null, error: string, sampledAt: string}
     */
    private function snapshotError(string $message): array
    {
        return [
            'tracker' => null,
            'error' => $message,
            'sampledAt' => now()->toIso8601String(),
        ];
    }
}
