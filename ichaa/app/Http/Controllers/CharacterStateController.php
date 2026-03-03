<?php

namespace App\Http\Controllers\Temporal;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Services\TemporalService;

class CharacterStateController extends Controller
{
    public function __construct(
        private readonly TemporalService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = CharacterStateTracker::with('entity:id,name,entity_type')->chronological();

        if ($request->filled('entity')) {
            $query->forEntity($request->integer('entity'));
        }

        if ($request->boolean('breaking')) {
            $query->breaking();
        }

        return $this->page('Temporal/CharacterStates/Index', [
            'states'  => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['entity', 'breaking']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('Temporal/CharacterStates/Create', [
            'stabilityLevels'    => CharacterStateTracker::STABILITY_LEVELS,
            'maskIntegrityLevels'=> CharacterStateTracker::MASK_INTEGRITY_LEVELS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'entity_id'                     => ['required', 'integer', 'exists:entities,id'],
            'timeline_id'                   => ['nullable', 'integer', 'exists:entities,id'],
            'era_entity_id'                 => ['nullable', 'integer', 'exists:entities,id'],
            'snapshot_label'                => ['nullable', 'string', 'max:255'],
            'snapshot_significance'         => ['nullable', 'string'],
            'current_stability_level'       => ['nullable', 'string'],
            'mask_integrity'                => ['nullable', 'string'],
            'current_trauma_profile'        => ['nullable', 'string'],
            'active_psychological_patterns' => ['nullable', 'string'],
            'core_wound'                    => ['nullable', 'string'],
            'current_desire'                => ['nullable', 'string'],
            'current_fear'                  => ['nullable', 'string'],
            'shadow_self'                   => ['nullable', 'string'],
            'true_self'                     => ['nullable', 'string'],
            'performed_self'                => ['nullable', 'string'],
            'current_power_tier_operating'  => ['nullable', 'string'],
            'timeline_position'             => ['nullable', 'numeric'],
        ]);

        $entity = Entity::findOrFail($validated['entity_id']);
        $state  = $this->service->createStateSnapshot($entity, $validated);

        return $this->to('character-states.show', [$state], 'State snapshot created.');
    }

    public function show(CharacterStateTracker $characterState): Response
    {
        $characterState->load([
            'entity:id,name,entity_type',
            'era:id,name',
            'stateRelationships.relationship',
        ]);

        return $this->page('Temporal/CharacterStates/Show', [
            'state' => $characterState,
        ]);
    }

    public function edit(CharacterStateTracker $characterState): Response
    {
        return $this->page('Temporal/CharacterStates/Edit', [
            'state'              => $characterState,
            'stabilityLevels'    => CharacterStateTracker::STABILITY_LEVELS,
            'maskIntegrityLevels'=> CharacterStateTracker::MASK_INTEGRITY_LEVELS,
        ]);
    }

    public function update(Request $request, CharacterStateTracker $characterState): \Illuminate\Http\RedirectResponse
    {
        $this->service->updateStateSnapshot($characterState, $request->validate([
            'snapshot_label'                => ['nullable', 'string'],
            'current_stability_level'       => ['nullable', 'string'],
            'mask_integrity'                => ['nullable', 'string'],
            'current_trauma_profile'        => ['nullable', 'string'],
            'active_psychological_patterns' => ['nullable', 'string'],
            'core_wound'                    => ['nullable', 'string'],
            'current_desire'                => ['nullable', 'string'],
            'current_fear'                  => ['nullable', 'string'],
            'current_power_tier_operating'  => ['nullable', 'string'],
        ]));

        return $this->back('State snapshot updated.');
    }

    public function destroy(CharacterStateTracker $characterState): \Illuminate\Http\RedirectResponse
    {
        $this->service->deleteStateSnapshot($characterState);

        return $this->to('character-states.index', [], 'Snapshot deleted.');
    }
}
