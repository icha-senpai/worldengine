<?php

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Support\Validation\DataverseRules;

class KnowledgeStateController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = KnowledgeState::current()
            ->with(['knower:id,name', 'subjectEntity:id,name'])
            ->latest();

        if ($request->filled('knower')) {
            $query->forKnower($request->integer('knower'));
        }

        if ($request->filled('about')) {
            $query->aboutEntity($request->integer('about'));
        }

        if ($request->boolean('latent')) {
            $query->latentTension();
        }

        if ($request->boolean('compartmentalizing')) {
            $query->compartmentalizing();
        }

        return $this->page('Intelligence/KnowledgeStates/Index', [
            'states'  => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['knower', 'about', 'latent', 'compartmentalizing']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Intelligence/KnowledgeStates/Create', [
            'entities'           => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'secrets'            => Secret::query()
                ->select('id', 'title', 'secret_type')
                ->orderBy('title')
                ->get(),
            'relationships'      => Relationship::query()
                ->with(['fromEntity:id,name', 'toEntity:id,name'])
                ->orderByDesc('id')
                ->get(['id', 'from_entity_id', 'to_entity_id', 'relationship_type']),
            'groupRelationships' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'eventEntries'       => Timeline::query()
                ->with(['eventEntity:id,name,entity_type', 'timeline:id,name'])
                ->orderByDesc('id')
                ->get(['id', 'timeline_id', 'event_entity_id', 'entry_label', 'au_date']),
            'knowledgeTypes'     => KnowledgeState::KNOWLEDGE_TYPES,
            'accuracyLevels'     => KnowledgeState::ACCURACY_LEVELS,
            'beliefStates'       => KnowledgeState::BELIEF_STATES,
            'acquisitionMethods' => KnowledgeState::ACQUISITION_METHODS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('knowledge-states', 'store'));

        $knower = Entity::findOrFail($validated['knower_entity_id']);
        $state  = $this->service->recordKnowledge($knower, $validated);

        return $this->to('knowledge-states.show', [$state], 'Knowledge state recorded.');
    }

    public function show(KnowledgeState $knowledgeState): Response
    {
        $knowledgeState->load([
            'knower:id,name,entity_type',
            'subjectEntity:id,name,entity_type',
            'subjectSecret:id,title',
            'acquiredFrom:id,name',
        ]);

        return $this->pageWithNotionNote('Intelligence/KnowledgeStates/Show', $knowledgeState, 'knowledge_states', [
            'state' => $knowledgeState,
        ]);
    }

    public function edit(KnowledgeState $knowledgeState): Response
    {
        return $this->page('Intelligence/KnowledgeStates/Edit', [
            'state'          => $knowledgeState,
            'beliefStates'   => KnowledgeState::BELIEF_STATES,
            'accuracyLevels' => KnowledgeState::ACCURACY_LEVELS,
        ]);
    }

    public function update(Request $request, KnowledgeState $knowledgeState): \Illuminate\Http\RedirectResponse
    {
        $knowledgeState->update($request->validate(DataverseRules::web('knowledge-states', 'update')));

        return $this->back('Knowledge state updated.');
    }

    public function destroy(KnowledgeState $knowledgeState): \Illuminate\Http\RedirectResponse
    {
        $knowledgeState->delete();

        return $this->to('knowledge-states.index', [], 'Knowledge state deleted.');
    }

    public function markActedOn(Request $request, KnowledgeState $knowledgeState): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::webAction('knowledge-state-act-on'));

        $this->service->markActedOn($knowledgeState, $validated['action_notes'] ?? null);

        return $this->back('Marked as acted on.');
    }
}
