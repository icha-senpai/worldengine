<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Services\WorldService;
use App\Support\Validation\DataverseRules;

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
        return $this->pageWithNotionNote('World/LocationControl/Edit', $locationControl, 'location_control', [
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
        $validated = $request->validate(DataverseRules::web('location-control-records', 'store'));

        $location   = Entity::findOrFail($validated['location_entity_id']);
        $controller = Entity::findOrFail($validated['controlling_entity_id']);

        $this->service->recordControlChange($location, $controller, $validated['control_type'], $validated);

        return $this->back('Control change recorded.');
    }

    public function update(Request $request, LocationControlHistory $locationControl): \Illuminate\Http\RedirectResponse
    {
        $locationControl->update($request->validate(
            DataverseRules::web('location-control-records', 'update')
        ));

        return $this->back('Control record updated.');
    }

    public function destroy(LocationControlHistory $locationControl): \Illuminate\Http\RedirectResponse
    {
        $locationControl->delete();

        return $this->back('Control record deleted.');
    }
}
