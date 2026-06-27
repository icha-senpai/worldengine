<?php

namespace App\Http\Controllers\Production;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Organization\Models\Collection;
use App\Domain\Production\Models\SessionLog;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

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

    public function store(Request $request): RedirectResponse
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
            'editDrawer' => $this->editFormProps(),
        ]);
    }

    public function update(Request $request, SessionLog $sessionLog): RedirectResponse
    {
        $sessionLog->update($this->normalizePayload(
            $request->validate(DataverseRules::web('session-logs', 'update'))
        ));

        return $this->to('session-logs.show', [$sessionLog], 'Session updated.');
    }

    public function destroy(SessionLog $sessionLog): RedirectResponse
    {
        $sessionLog->delete();

        return $this->to('session-logs.index', [], 'Session deleted.');
    }

    private function stats(): array
    {
        $cutoff = now()->subDays(30)->toDateString();
        $sessions = SessionLog::where('session_date', '>=', $cutoff)
            ->whereNull('deleted_at')
            ->get(['session_significance']);

        return [
            'session_count' => $sessions->count(),
            'major_count' => $sessions->where('session_significance', 'major')->count(),
        ];
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = SessionLog::query()->latestFirst();

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('focus_description', 'like', "%{$term}%");
            });
        }

        if ($request->filled('external_tool')) {
            $query->where('external_tool', $request->string('external_tool')->toString());
        }

        if ($request->filled('significance')) {
            $query->where('session_significance', $request->string('significance')->toString());
        }

        return $this->page('Production/Sessions/Index', array_merge([
            'sessions' => $query->paginate(30)->withQueryString(),
            'stats' => $this->stats(),
            'filters' => $request->only(['q', 'external_tool', 'significance']),
            'externalTools' => SessionLog::EXTERNAL_TOOLS,
            'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'groupRelationships' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'collections' => Collection::query()
                ->select('id', 'name', 'collection_type')
                ->orderBy('name')
                ->get(),
            'significanceLevels' => SessionLog::SIGNIFICANCE_LEVELS,

        ];
    }

    private function editFormProps(): array
    {
        return $this->createFormProps();
    }

    private function showPage(SessionLog $sessionLog, array $props = []): Response
    {
        $focusEntityIds = $sessionLog->focus_entity_ids ?? [];
        $focusGroupIds = $sessionLog->focus_group_relationship_ids ?? [];
        $focusCollectionIds = $sessionLog->focus_collection_ids ?? [];

        $focusEntities = Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('id', $focusEntityIds)
            ->get()
            ->keyBy('id');

        $focusGroups = GroupRelationship::query()
            ->select('id', 'name', 'relationship_type')
            ->whereIn('id', $focusGroupIds)
            ->get()
            ->keyBy('id');

        $focusCollections = Collection::query()
            ->select('id', 'name', 'collection_type')
            ->whereIn('id', $focusCollectionIds)
            ->get()
            ->keyBy('id');

        $sessionLog->load(['entityQuestions.entity:id,name']);

        return $this->pageWithNotionNote('Production/Sessions/Show', $sessionLog, 'session_logs', array_merge([
            'session' => $sessionLog,
            'focusEntities' => collect($focusEntityIds)
                ->map(function ($id) use ($focusEntities) {
                    $entity = $focusEntities->get($id);

                    if (! $entity) {
                        return [
                            'id' => (int) $id,
                            'label' => "Unknown entity #{$id}",
                        ];
                    }

                    return [
                        'id' => $entity->id,
                        'label' => "{$entity->name}".($entity->entity_type ? " ({$entity->entity_type})" : ''),
                        'href' => route('entities.show', [$entity]),
                    ];
                })
                ->values()
                ->all(),
            'focusGroupRelationships' => collect($focusGroupIds)
                ->map(function ($id) use ($focusGroups) {
                    $group = $focusGroups->get($id);

                    if (! $group) {
                        return [
                            'id' => (int) $id,
                            'label' => "Unknown group relationship #{$id}",
                        ];
                    }

                    return [
                        'id' => $group->id,
                        'label' => "{$group->name}".($group->relationship_type ? " ({$group->relationship_type})" : ''),
                        'href' => route('group-relationships.show', [$group]),
                    ];
                })
                ->values()
                ->all(),
            'focusCollections' => collect($focusCollectionIds)
                ->map(function ($id) use ($focusCollections) {
                    $collection = $focusCollections->get($id);

                    if (! $collection) {
                        return [
                            'id' => (int) $id,
                            'label' => "Unknown collection #{$id}",
                        ];
                    }

                    return [
                        'id' => $collection->id,
                        'label' => "{$collection->name}".($collection->collection_type ? " ({$collection->collection_type})" : ''),
                        'href' => route('collections.show', [$collection]),
                    ];
                })
                ->values()
                ->all(),
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
