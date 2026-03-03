<?php

namespace App\Http\Controllers\Production;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Production\Models\SessionLog;

class SessionLogController extends Controller
{
    public function index(): Response
    {
        return $this->page('Production/Sessions/Index', [
            'sessions' => SessionLog::latestFirst()->paginate(30),
            'stats'    => $this->stats(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Sessions/Create', [
            'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'title'                        => ['required', 'string', 'max:255'],
            'session_date'                 => ['nullable', 'date'],
            'external_tool'                => ['nullable', 'string', 'max:255'],
            'focus_entity_ids'             => ['nullable', 'array'],
            'focus_group_relationship_ids' => ['nullable', 'array'],
            'focus_collection_ids'         => ['nullable', 'array'],
            'focus_description'            => ['nullable', 'string', 'max:255'],
            'decisions_made'               => ['nullable', 'array'],
            'changes_applied'              => ['nullable', 'array'],
            'open_threads'                 => ['nullable', 'array'],
            'session_significance'         => ['nullable', 'string', 'in:' . implode(',', SessionLog::SIGNIFICANCE_LEVELS)],
            'notes'                        => ['nullable', 'array'],
        ]);

        $validated['session_date'] = $validated['session_date'] ?? now()->toDateString();

        $session = SessionLog::create($validated);

        return $this->to('session-logs.show', [$session], 'Session logged.');
    }

    public function show(SessionLog $sessionLog): Response
    {
        $sessionLog->load(['entityQuestions.entity:id,name']);

        return $this->page('Production/Sessions/Show', [
            'session' => $sessionLog,
        ]);
    }

    public function edit(SessionLog $sessionLog): Response
    {
        return $this->page('Production/Sessions/Edit', [
            'session'            => $sessionLog,
            'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,
        ]);
    }

    public function update(Request $request, SessionLog $sessionLog): \Illuminate\Http\RedirectResponse
    {
        $sessionLog->update($request->validate([
            'title'               => ['sometimes', 'string'],
            'session_date'        => ['nullable', 'date'],
            'external_tool'       => ['nullable', 'string'],
            'focus_description'   => ['nullable', 'string'],
            'decisions_made'      => ['nullable', 'array'],
            'changes_applied'     => ['nullable', 'array'],
            'open_threads'        => ['nullable', 'array'],
            'session_significance'=> ['nullable', 'string'],
            'notes'               => ['nullable', 'array'],
        ]));

        return $this->back('Session updated.');
    }

    public function destroy(SessionLog $sessionLog): \Illuminate\Http\RedirectResponse
    {
        $sessionLog->delete();

        return $this->to('session-logs.index', [], 'Session deleted.');
    }

    private function stats(): array
    {
        $cutoff   = now()->subDays(30)->toDateString();
        $sessions = SessionLog::where('session_date', '>=', $cutoff)
            ->whereNull('deleted_at')
            ->get(['session_significance']);

        return [
            'session_count' => $sessions->count(),
            'major_count'   => $sessions->where('session_significance', 'major')->count(),
        ];
    }
}