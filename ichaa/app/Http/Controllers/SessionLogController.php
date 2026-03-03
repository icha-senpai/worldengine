<?php

namespace App\Http\Controllers\Production;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Production\Services\ProductionService;

class SessionLogController extends Controller
{
    public function __construct(
        private readonly ProductionService $service,
    ) {}

    public function index(): Response
    {
        return $this->page('Production/Sessions/Index', [
            'sessions' => SessionLog::latestFirst()->paginate(30),
            'stats'    => $this->service->getSessionStats(30),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Production/Sessions/Create', [
            'sessionTypes' => SessionLog::SESSION_TYPES,
            'energyLevels' => SessionLog::ENERGY_LEVELS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'session_title'    => ['nullable', 'string', 'max:255'],
            'session_type'     => ['required', 'string', 'in:' . implode(',', SessionLog::SESSION_TYPES)],
            'session_date'     => ['nullable', 'date'],
            'summary'          => ['nullable', 'array'],
            'decisions_made'   => ['nullable', 'array'],
            'questions_raised' => ['nullable', 'array'],
            'word_count_added' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer'],
            'mood_rating'      => ['nullable', 'integer', 'between:1,5'],
            'energy_level'     => ['nullable', 'string', 'in:' . implode(',', SessionLog::ENERGY_LEVELS)],
        ]);

        $session = $this->service->startSession($validated);

        return $this->to('session-logs.show', [$session], 'Session logged.');
    }

    public function show(SessionLog $sessionLog): Response
    {
        return $this->page('Production/Sessions/Show', [
            'session' => $sessionLog->load(['entityQuestions', 'pipelineItems']),
        ]);
    }

    public function edit(SessionLog $sessionLog): Response
    {
        return $this->page('Production/Sessions/Edit', [
            'session'      => $sessionLog,
            'sessionTypes' => SessionLog::SESSION_TYPES,
        ]);
    }

    public function update(Request $request, SessionLog $sessionLog): \Illuminate\Http\RedirectResponse
    {
        $this->service->updateSession($sessionLog, $request->validate([
            'summary'            => ['nullable', 'array'],
            'decisions_made'     => ['nullable', 'array'],
            'next_session_goals' => ['nullable', 'array'],
            'word_count_added'   => ['nullable', 'integer'],
            'duration_minutes'   => ['nullable', 'integer'],
            'mood_rating'        => ['nullable', 'integer', 'between:1,5'],
        ]));

        return $this->back('Session updated.');
    }

    public function destroy(SessionLog $sessionLog): \Illuminate\Http\RedirectResponse
    {
        $sessionLog->delete();

        return $this->to('session-logs.index', [], 'Session deleted.');
    }
}
