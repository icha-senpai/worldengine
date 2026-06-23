<?php

namespace App\Http\Controllers\Production;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Organization\Models\Collection;
use App\Domain\Production\Models\SessionLog;
use App\Support\Validation\DataverseRules;

class SessionLogController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->normalizePayload(
            $request->validate(DataverseRules::web('session-logs', 'store'))
        );

        $validated['session_date'] = $validated['session_date'] ?? now()->toDateString();

        $session = SessionLog::create($validated);

        return $this->to('session-logs.show', [$session], 'Session logged.');
    }

    public function show(SessionLog $sessionLog): Response
    {
        return $this->showPage($sessionLog);
    }

    public function edit(SessionLog $sessionLog): Response
    {
        return $this->showPage($sessionLog, [
            'editDrawer' => [
                'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,
            ],
        ]);
    }

    public function update(Request $request, SessionLog $sessionLog): \Illuminate\Http\RedirectResponse
    {
        $sessionLog->update($this->normalizePayload(
            $request->validate(DataverseRules::web('session-logs', 'update'))
        ));

        return $this->to('session-logs.show', [$sessionLog], 'Session updated.');
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



    private function indexPage(Request $request, array $props = []): Response
    {
                return $this->page('Production/Sessions/Index', array_merge([
            'sessions' => SessionLog::latestFirst()->paginate(30),
            'stats'    => $this->stats(),
        ], $props));
    
    }

    private function createFormProps(): array
    {
        return [
            'entities'           => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'groupRelationships' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'collections'        => Collection::query()
                ->select('id', 'name', 'collection_type')
                ->orderBy('name')
                ->get(),
            'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,
        
        ];
    }

    private function showPage(SessionLog $sessionLog, array $props = []): Response
    {
        $sessionLog->load(['entityQuestions.entity:id,name']);

        return $this->pageWithNotionNote('Production/Sessions/Show', $sessionLog, 'session_logs', array_merge([
            'session' => $sessionLog,
        ], $props));
    }

    private function normalizePayload(array $payload): array
    {
        if (($payload['session_significance'] ?? null) === null || $payload['session_significance'] === '') {
            $payload['session_significance'] = 'minor';
        }

        return $payload;
    }
}
