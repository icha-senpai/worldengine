<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Services\WorldService;

class LocationControlController extends Controller
{
    public function __construct(
        private readonly WorldService $service,
    ) {}

    public function index(): Response
    {
        return $this->page('World/LocationControl/Index', [
            'records' => LocationControlHistory::current()
                ->with(['location:id,name', 'controllingEntity:id,name'])
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('World/LocationControl/Create', [
            'locationEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'entities'         => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'controlTypes'     => LocationControlHistory::CONTROL_TYPES,
            'resistanceLevels' => LocationControlHistory::RESISTANCE_LEVELS,
        ]);
    }

    public function edit(LocationControlHistory $locationControl): Response
    {
        return $this->page('World/LocationControl/Edit', [
            'record' => $locationControl->load([
                'location:id,name',
                'controllingEntity:id,name',
                'resistanceEntity:id,name',
            ]),
            'resistanceLevels' => LocationControlHistory::RESISTANCE_LEVELS,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'location_entity_id'    => ['required', 'integer', 'exists:entities,id'],
            'controlling_entity_id' => ['required', 'integer', 'exists:entities,id'],
            'control_type'          => ['required', 'string', 'in:' . implode(',', LocationControlHistory::CONTROL_TYPES)],
            'control_start_era'     => ['nullable', 'string'],
        ]);

        $location   = Entity::findOrFail($validated['location_entity_id']);
        $controller = Entity::findOrFail($validated['controlling_entity_id']);

        $this->service->recordControlChange($location, $controller, $validated['control_type'], $validated);

        return $this->back('Control change recorded.');
    }

    public function update(Request $request, LocationControlHistory $locationControl): \Illuminate\Http\RedirectResponse
    {
        $locationControl->update($request->validate([
            'resistance_level'  => ['nullable', 'string'],
            'control_end_era'   => ['nullable', 'string'],
            'how_control_ended' => ['nullable', 'array'],
        ]));

        return $this->back('Control record updated.');
    }

    public function destroy(LocationControlHistory $locationControl): \Illuminate\Http\RedirectResponse
    {
        $locationControl->delete();

        return $this->back('Control record deleted.');
    }
}
