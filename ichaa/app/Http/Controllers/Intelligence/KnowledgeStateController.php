<?php

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Services\IntelligenceService;

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
            'knowledgeTypes'     => KnowledgeState::KNOWLEDGE_TYPES,
            'accuracyLevels'     => KnowledgeState::ACCURACY_LEVELS,
            'beliefStates'       => KnowledgeState::BELIEF_STATES,
            'acquisitionMethods' => KnowledgeState::ACQUISITION_METHODS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'knower_entity_id'              => ['required', 'integer', 'exists:entities,id'],
            'subject_entity_id'             => ['nullable', 'integer', 'exists:entities,id'],
            'subject_secret_id'             => ['nullable', 'integer', 'exists:secrets,id'],
            'subject_relationship_id'       => ['nullable', 'integer'],
            'subject_group_relationship_id' => ['nullable', 'integer'],
            'subject_event_id'              => ['nullable', 'integer'],
            'knowledge_type'                => ['required', 'string', 'in:' . implode(',', KnowledgeState::KNOWLEDGE_TYPES)],
            'knowledge_content'             => ['nullable', 'array'],
            'accuracy'                      => ['required', 'string', 'in:' . implode(',', KnowledgeState::ACCURACY_LEVELS)],
            'current_belief_state'          => ['required', 'string', 'in:' . implode(',', KnowledgeState::BELIEF_STATES)],
            'acquired_through'              => ['required', 'string', 'in:' . implode(',', KnowledgeState::ACQUISITION_METHODS)],
            'acquired_from_entity_id'       => ['nullable', 'integer', 'exists:entities,id'],
            'acquired_at_era'               => ['nullable', 'string'],
        ]);

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

        return $this->page('Intelligence/KnowledgeStates/Show', [
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
        $knowledgeState->update($request->validate([
            'knowledge_content'    => ['nullable', 'array'],
            'accuracy'             => ['sometimes', 'string'],
            'current_belief_state' => ['sometimes', 'string', 'in:' . implode(',', KnowledgeState::BELIEF_STATES)],
        ]));

        return $this->back('Knowledge state updated.');
    }

    public function destroy(KnowledgeState $knowledgeState): \Illuminate\Http\RedirectResponse
    {
        $knowledgeState->delete();

        return $this->to('knowledge-states.index', [], 'Knowledge state deleted.');
    }

    public function markActedOn(Request $request, KnowledgeState $knowledgeState): \Illuminate\Http\RedirectResponse
    {
        $this->service->markActedOn($knowledgeState, $request->input('action_notes'));

        return $this->back('Marked as acted on.');
    }
}
