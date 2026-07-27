<?php

namespace App\Http\Controllers\Bitcraft;

use App\Http\Controllers\Bitcraft\Concerns\NormalizesBitcraftWidgetTheme;
use App\Http\Controllers\Bitcraft\Concerns\ScopesBitcraftWidgetProfiles;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BitcraftTaskTrackerController extends Controller
{
    use NormalizesBitcraftWidgetTheme;
    use ScopesBitcraftWidgetProfiles;

    private const DEFAULT_TITLE = 'Stream Tasks';

    private const DEFAULT_ICONS = '✅ ✨';

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($request->has('source') && $this->hasProfileInput($request)) {
            $this->saveBitcraftWidgetProfile($request, 'task-tracker', $filters);

            if (! $filters['setup']) {
                return redirect()->route('bitcraft.task-tracker', $this->bitcraftWidgetProfileRouteParameters($request, $filters));
            }
        }

        return Inertia::render('Bitcraft/TaskTracker', [
            'filters' => $filters,
        ]);
    }

    public function setup(Request $request): InertiaResponse|RedirectResponse
    {
        $request->merge(['setup' => true]);

        return $this->show($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:80'],
            'icons' => ['nullable', 'string', 'max:40'],
            'taskText' => ['nullable', 'string', 'max:160'],
            'tasks' => ['nullable', 'string', 'max:4000'],
            'setup' => ['nullable', 'boolean'],
            'user' => ['nullable', 'integer', 'min:1'],
            ...$this->widgetThemeValidationRules(),
        ]);
        $source = trim((string) ($validated['source'] ?? 'default')) ?: 'default';
        $userId = $this->bitcraftWidgetProfileUserId($request, $validated);
        $stored = $request->has('source') && ! $this->hasProfileInput($request)
            ? $this->bitcraftWidgetProfileSettings('task-tracker', $source, $userId)
            : [];

        return [
            'user' => $userId,
            'source' => $source,
            'title' => trim((string) ($validated['title'] ?? data_get($stored, 'title', self::DEFAULT_TITLE))) ?: self::DEFAULT_TITLE,
            'icons' => $request->has('icons')
                ? trim((string) ($validated['icons'] ?? ''))
                : trim((string) data_get($stored, 'icons', self::DEFAULT_ICONS)),
            'taskText' => trim((string) ($validated['taskText'] ?? data_get($stored, 'taskText', ''))),
            'tasks' => $this->tasks($validated['tasks'] ?? data_get($stored, 'tasks', [])),
            ...$this->widgetThemeSettings($validated, $stored),
            'setup' => $request->has('setup') ? $request->boolean('setup') : false,
        ];
    }

    private function hasProfileInput(Request $request): bool
    {
        return collect([
            'title',
            'icons',
            'taskText',
            'tasks',
            ...$this->widgetThemeInputKeys(),
        ])->contains(fn (string $key): bool => $request->has($key));
    }

    /**
     * @param  array<int, mixed>|string  $tasks
     * @return array<int, array{id: string, text: string, done: bool}>
     */
    private function tasks(array|string $tasks): array
    {
        if (is_string($tasks)) {
            $decoded = json_decode($tasks, true);
            $tasks = is_array($decoded) ? $decoded : explode("\n", $tasks);
        }

        $usedIds = [];
        $normalized = [];

        foreach ($tasks as $index => $task) {
            $text = is_array($task) ? (string) data_get($task, 'text', '') : (string) $task;
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $id = is_array($task) ? trim((string) data_get($task, 'id', '')) : '';
            $id = preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) === 1
                ? $id
                : 'task-'.$index.'-'.substr(sha1($text), 0, 8);

            if (isset($usedIds[$id])) {
                $id .= '-'.$index;
            }

            $usedIds[$id] = true;
            $normalized[] = [
                'id' => $id,
                'text' => mb_substr($text, 0, 160),
                'done' => is_array($task) && (bool) data_get($task, 'done', false),
            ];

            if (count($normalized) >= 20) {
                break;
            }
        }

        return $normalized;
    }
}
