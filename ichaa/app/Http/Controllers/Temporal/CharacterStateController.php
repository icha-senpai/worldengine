<?php

namespace App\Http\Controllers\Temporal;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Services\TemporalService;
use App\Support\Validation\DataverseRules;

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
            'entities'           => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::POWERED_TYPES)
                ->orderBy('name')
                ->get(),
            'timelineEntities'   => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->where('entity_type', EntityType::TIMELINE)
                ->orderBy('name')
                ->get(),
            'eraEntities'        => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', [EntityType::ERA, EntityType::CYCLE])
                ->orderBy('name')
                ->get(),
            'stabilityLevels'    => CharacterStateTracker::STABILITY_LEVELS,
            'maskIntegrityLevels'=> CharacterStateTracker::MASK_INTEGRITY_LEVELS,
            'significanceLevels' => CharacterStateTracker::SNAPSHOT_SIGNIFICANCE_LEVELS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('character-states', 'store'));

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

        return $this->pageWithNotionNote('Temporal/CharacterStates/Show', $characterState, 'character_states', [
            'state' => $characterState,
        ]);
    }

    public function edit(CharacterStateTracker $characterState): Response
    {
        return $this->page('Temporal/CharacterStates/Edit', [
            'state'              => $characterState,
            'stabilityLevels'    => CharacterStateTracker::STABILITY_LEVELS,
            'maskIntegrityLevels'=> CharacterStateTracker::MASK_INTEGRITY_LEVELS,
            'significanceLevels' => CharacterStateTracker::SNAPSHOT_SIGNIFICANCE_LEVELS,
        ]);
    }

    public function update(Request $request, CharacterStateTracker $characterState): \Illuminate\Http\RedirectResponse
    {
        $this->service->updateStateSnapshot($characterState, $request->validate(
            DataverseRules::web('character-states', 'update')
        ));

        return $this->back('State snapshot updated.');
    }

    public function destroy(CharacterStateTracker $characterState): \Illuminate\Http\RedirectResponse
    {
        $this->service->deleteStateSnapshot($characterState);

        return $this->to('character-states.index', [], 'Snapshot deleted.');
    }
}
