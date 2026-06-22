<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Services\WorldService;
use App\Support\Validation\DataverseRules;

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
        $validated = $request->validate(DataverseRules::web('power-interactions', 'store'));

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

        return $this->pageWithNotionNote('World/PowerInteractions/Show', $powerInteraction, 'power_interactions', [
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
        $this->service->updatePowerInteraction($powerInteraction, $request->validate(
            DataverseRules::web('power-interactions', 'update')
        ));

        return $this->back('Interaction updated.');
    }

    public function destroy(PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $powerInteraction->delete();

        return $this->to('power-interactions.index', [], 'Interaction deleted.');
    }

    public function resolve(Request $request, PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $this->service->resolveInteraction($powerInteraction, $request->validate(
            DataverseRules::webAction('power-interaction-resolve')
        ));

        return $this->back('Interaction resolved.');
    }

    public function recordInstance(Request $request, PowerInteraction $powerInteraction): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::webAction('power-interaction-instance'));

        $event = Entity::findOrFail($validated['event_entity_id']);
        $this->service->recordInstance($powerInteraction, $event, $validated);

        return $this->back('Instance recorded.');
    }
}
