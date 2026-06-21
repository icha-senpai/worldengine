<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Services\WorldService;

class PowerInteractionController extends Controller
{
    public function __construct(
        private readonly WorldService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = PowerInteraction::with(['systemA:id,name', 'systemB:id,name'])->latest();

        if ($request->boolean('unresolved')) {
            $query->unresolved();
        }

        return $this->page('World/PowerInteractions/Index', [
            'interactions' => $query->paginate(40)->withQueryString(),
            'filters'      => $request->only(['unresolved']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('World/PowerInteractions/Create', [
            'entities'            => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'effectTypes'         => PowerInteraction::EFFECT_TYPES,
            'scaleTypes'          => PowerInteraction::SCALE_TYPES,
            'dangerRatings'       => PowerInteraction::DANGER_RATINGS,
            'knowledgeStates'     => PowerInteraction::KNOWLEDGE_STATES,
            'directionalityTypes' => PowerInteraction::DIRECTIONALITY_TYPES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'system_a_entity_id'  => ['required', 'integer', 'exists:entities,id'],
            'system_b_entity_id'  => ['required', 'integer', 'exists:entities,id', 'different:system_a_entity_id'],
            'interaction_name'    => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'array'],
            'directionality'      => ['nullable', 'string'],
            'effects'             => ['nullable', 'array'],
            'interaction_scale'   => ['nullable', 'string'],
            'knowledge_state'     => ['nullable', 'string'],
            'danger_rating'       => ['nullable', 'string'],
            'proximity_required'  => ['boolean'],
            'source_universe_a'   => ['nullable', 'string'],
            'source_universe_b'   => ['nullable', 'string'],
        ]);

        $interaction = $this->service->createPowerInteraction($validated);

        return $this->to('power-interactions.show', [$interaction], 'Power interaction created.');
    }

    public function show(PowerInteraction $powerInteraction): Response
    {
        $powerInteraction->load([
            'systemA:id,name,entity_type',
            'systemB:id,name,entity_type',
            'instances.eventEntity:id,name',
        ]);

        return $this->page('World/PowerInteractions/Show', [
            'interaction' => $powerInteraction,
        ]);
    }

    public function edit(PowerInteraction $powerInteraction): Response
    {
        return $this->page('World/PowerInteractions/Edit', [
            'interaction'     => $powerInteraction,
            'dangerRatings'   => PowerInteraction::DANGER_RATINGS,
            'knowledgeStates' => PowerInteraction::KNOWLEDGE_STATES,
        ]);
    }

    public function update(Request $request, PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $this->service->updatePowerInteraction($powerInteraction, $request->validate([
            'interaction_name' => ['sometimes', 'string'],
            'description'      => ['nullable', 'array'],
            'effects'          => ['nullable', 'array'],
            'knowledge_state'  => ['nullable', 'string'],
            'danger_rating'    => ['nullable', 'string'],
            'unresolved_flag'  => ['boolean'],
        ]));

        return $this->back('Interaction updated.');
    }

    public function destroy(PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $powerInteraction->delete();

        return $this->to('power-interactions.index', [], 'Interaction deleted.');
    }

    public function resolve(Request $request, PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $this->service->resolveInteraction($powerInteraction, $request->validate([
            'resolution_notes' => ['nullable', 'array'],
            'knowledge_state'  => ['nullable', 'string'],
        ]));

        return $this->back('Interaction resolved.');
    }

    public function recordInstance(Request $request, PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'event_entity_id'     => ['required', 'integer', 'exists:entities,id'],
            'involved_entity_ids' => ['nullable', 'array'],
            'outcome_match'       => ['required', 'string', 'in:' . implode(',', PowerInteractionInstance::OUTCOME_MATCHES)],
            'outcome_notes'       => ['nullable', 'array'],
            'observed_at_era'     => ['nullable', 'string'],
        ]);

        $event = Entity::findOrFail($validated['event_entity_id']);
        $this->service->recordInstance($powerInteraction, $event, $validated);

        return $this->back('Instance recorded.');
    }
}
