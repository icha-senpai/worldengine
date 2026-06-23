<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Services\WorldService;
use App\Support\Validation\DataverseRules;

class LocationContainmentController extends Controller
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

    public function edit(LocationContainment $locationContainment): Response
    {
        return $this->indexPage([
            'editDrawer' => [
                'containment' => $locationContainment->load([
                    'childLocation:id,name',
                    'parentLocation:id,name',
                ]),
            ],
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('location-containment', 'store'));

        $child  = Entity::findOrFail($validated['child_location_entity_id']);
        $parent = Entity::findOrFail($validated['parent_location_entity_id']);

        $this->service->contain($child, $parent, $validated['containment_type'], $validated);

        return $this->to('location-containment.index', [], 'Location containment created.');
    }

    public function update(Request $request, LocationContainment $locationContainment): \Illuminate\Http\RedirectResponse
    {
        $locationContainment->update($request->validate(
            DataverseRules::web('location-containment', 'update')
        ));

        return $this->to('location-containment.index', [], 'Containment updated.');
    }

    public function destroy(LocationContainment $locationContainment): \Illuminate\Http\RedirectResponse
    {
        $locationContainment->delete();

        return $this->to('location-containment.index', [], 'Containment deleted.');
    }

    private function indexPage(array $props = []): Response
    {
        return $this->page('World/LocationContainment/Index', array_merge([
            'containments' => LocationContainment::active()
                ->with(['childLocation:id,name', 'parentLocation:id,name'])
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
            'containmentTypes' => LocationContainment::CONTAINMENT_TYPES,
        ];
    }
}
