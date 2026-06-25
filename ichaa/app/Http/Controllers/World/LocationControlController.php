<?php

namespace App\Http\Controllers\World;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Services\WorldService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class LocationControlController extends Controller
{
    public function __construct(
        private readonly WorldService $service,
    ) {}

    public function index(): Response
    {
        return $this->indexPage();
    }

    public function create(): Response
    {
        return $this->indexPage([
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    public function edit(LocationControlHistory $locationControl): Response
    {
        return $this->indexPage([
            'editDrawer' => array_merge($this->createFormProps(), [
                'record' => $locationControl->load([
                    'location:id,name',
                    'controllingEntity:id,name',
                    'resistanceEntity:id,name',
                ]),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('location-control-records', 'store'));

        $location = Entity::findOrFail($validated['location_entity_id']);
        $controller = Entity::findOrFail($validated['controlling_entity_id']);

        $this->service->recordControlChange($location, $controller, $validated['control_type'], $validated);

        return $this->to('location-control.index', [], 'Control change recorded.');
    }

    public function update(Request $request, LocationControlHistory $locationControl): RedirectResponse
    {
        $locationControl->update($request->validate(
            DataverseRules::web('location-control-records', 'update')
        ));

        return $this->to('location-control.index', [], 'Control record updated.');
    }

    public function destroy(LocationControlHistory $locationControl): RedirectResponse
    {
        $locationControl->delete();

        return $this->to('location-control.index', [], 'Control record deleted.');
    }

    private function indexPage(array $props = []): Response
    {
        return $this->page('World/LocationControl/Index', array_merge([
            'records' => LocationControlHistory::current()
                ->with(['location:id,name', 'controllingEntity:id,name'])
                ->get(),
        ], $props));
    }

    private function createFormProps(): array
    {
        return [
            'locationEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'controlTypes' => LocationControlHistory::CONTROL_TYPES,
            'resistanceLevels' => LocationControlHistory::RESISTANCE_LEVELS,
        ];
    }
}
