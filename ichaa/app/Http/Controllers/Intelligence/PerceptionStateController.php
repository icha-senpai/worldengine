<?php

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Services\IntelligenceService;

class PerceptionStateController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = PerceptionState::current()->latest();

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->boolean('critical_maintenance')) {
            $query->criticalMaintenance();
        }

        return $this->page('Intelligence/PerceptionStates/Index', [
            'states'  => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['high_risk', 'critical_maintenance']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Intelligence/PerceptionStates/Create', [
            'subjectTypes'       => PerceptionState::SUBJECT_TYPES,
            'divergenceLevels'   => PerceptionState::DIVERGENCE_LEVELS,
            'maintenanceMethods' => PerceptionState::MAINTENANCE_METHODS,
            'maintenanceEfforts' => PerceptionState::MAINTENANCE_EFFORTS,
            'revelationRisks'    => PerceptionState::REVELATION_RISKS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'subject_type'             => ['required', 'string', 'in:' . implode(',', PerceptionState::SUBJECT_TYPES)],
            'subject_id'               => ['required', 'integer'],
            'true_state'               => ['required', 'array'],
            'perceived_state'          => ['required', 'array'],
            'divergence_level'         => ['required', 'string'],
            'maintained_by_entity_ids' => ['nullable', 'array'],
            'maintenance_method'       => ['nullable', 'string'],
            'maintenance_effort'       => ['nullable', 'string'],
            'revelation_risk'          => ['nullable', 'string'],
        ]);

        $state = $this->service->createPerceptionGap($validated);

        return $this->to('perception-states.show', [$state], 'Perception gap created.');
    }

    public function show(PerceptionState $perceptionState): Response
    {
        return $this->page('Intelligence/PerceptionStates/Show', [
            'state' => $perceptionState,
        ]);
    }

    public function edit(PerceptionState $perceptionState): Response
    {
        return $this->page('Intelligence/PerceptionStates/Edit', [
            'state'              => $perceptionState,
            'maintenanceMethods' => PerceptionState::MAINTENANCE_METHODS,
            'maintenanceEfforts' => PerceptionState::MAINTENANCE_EFFORTS,
            'revelationRisks'    => PerceptionState::REVELATION_RISKS,
        ]);
    }

    public function update(Request $request, PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $perceptionState->update($request->validate([
            'true_state'         => ['nullable', 'array'],
            'perceived_state'    => ['nullable', 'array'],
            'divergence_level'   => ['nullable', 'string'],
            'maintenance_effort' => ['nullable', 'string'],
            'revelation_risk'    => ['nullable', 'string'],
        ]));

        return $this->to('perception-states.show', [$perceptionState], 'Perception state updated.');
    }

    public function destroy(PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $perceptionState->delete();

        return $this->to('perception-states.index', [], 'Perception state deleted.');
    }

    public function addImmune(PerceptionState $perceptionState, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $this->service->addImmuneEntity($perceptionState, $entity->id);

        return $this->back("{$entity->name} added to immune list.");
    }

    public function collapse(Request $request, PerceptionState $perceptionState): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'era' => ['required', 'string'],
        ]);

        $this->service->collapsePerceptionGap($perceptionState, $validated['era']);

        return $this->back('Perception gap collapsed.');
    }
}
