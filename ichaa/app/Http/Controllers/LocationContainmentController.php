<?php

namespace App\Http\Controllers\World;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Services\WorldService;

class LocationContainmentController extends Controller
{
    public function __construct(
        private readonly WorldService $service,
    ) {}

    public function index(): Response
    {
        return $this->page('World/LocationContainment/Index', [
            'containments' => LocationContainment::active()
                ->with(['childLocation:id,name', 'parentLocation:id,name'])
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('World/LocationContainment/Create', [
            'containmentTypes' => LocationContainment::CONTAINMENT_TYPES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'child_location_entity_id'  => ['required', 'integer', 'exists:entities,id'],
            'parent_location_entity_id' => ['required', 'integer', 'exists:entities,id'],
            'containment_type'          => ['required', 'string', 'in:' . implode(',', LocationContainment::CONTAINMENT_TYPES)],
            'era_start'                 => ['nullable', 'string'],
        ]);

        $child  = Entity::findOrFail($validated['child_location_entity_id']);
        $parent = Entity::findOrFail($validated['parent_location_entity_id']);

        $this->service->contain($child, $parent, $validated['containment_type'], $validated);

        return $this->back('Location containment created.');
    }

    public function update(Request $request, LocationContainment $locationContainment): \Illuminate\Http\RedirectResponse
    {
        $locationContainment->update($request->validate([
            'era_end'   => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));

        return $this->back('Containment updated.');
    }

    public function destroy(LocationContainment $locationContainment): \Illuminate\Http\RedirectResponse
    {
        $locationContainment->delete();

        return $this->back('Containment deleted.');
    }
}
