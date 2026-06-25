<?php

namespace App\Http\Controllers\World;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Services\WorldService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class LocationContainmentController extends Controller
{
    public function __construct(
        private readonly WorldService $service,
    ) {}

    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    public function show(LocationContainment $locationContainment): Response
    {
        return $this->showPage($locationContainment);
    }

    public function edit(LocationContainment $locationContainment): Response
    {
        return $this->showPage($locationContainment, [
            'editDrawer' => array_merge($this->createFormProps(), [
                'containment' => $locationContainment->load([
                    'childLocation:id,name',
                    'parentLocation:id,name',
                ]),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('location-containment', 'store'));

        $child = Entity::findOrFail($validated['child_location_entity_id']);
        $parent = Entity::findOrFail($validated['parent_location_entity_id']);

        $containment = $this->service->contain($child, $parent, $validated['containment_type'], $validated);

        return $this->to('location-containment.show', [$containment], 'Location containment created.');
    }

    public function update(Request $request, LocationContainment $locationContainment): RedirectResponse
    {
        $locationContainment->update($request->validate(
            DataverseRules::web('location-containment', 'update')
        ));

        return $this->to('location-containment.show', [$locationContainment], 'Containment updated.');
    }

    public function destroy(LocationContainment $locationContainment): RedirectResponse
    {
        $locationContainment->delete();

        return $this->to('location-containment.index', [], 'Containment deleted.');
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = LocationContainment::active()
            ->with(['childLocation:id,name', 'parentLocation:id,name']);

        if ($request->filled('containment_type')) {
            $query->ofType($request->string('containment_type')->toString());
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->whereHas('childLocation', fn ($child) => $child->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('parentLocation', fn ($parent) => $parent->where('name', 'like', "%{$term}%"))
                    ->orWhere('era_start', 'like', "%{$term}%")
                    ->orWhere('era_end', 'like', "%{$term}%");
            });
        }

        return $this->page('World/LocationContainment/Index', array_merge([
            'containments' => $query->get(),
            'filters' => $request->only(['q', 'containment_type']),
            'containmentTypes' => LocationContainment::CONTAINMENT_TYPES,
        ], $props));
    }

    private function showPage(LocationContainment $locationContainment, array $props = []): Response
    {
        return $this->pageWithNotionNote('World/LocationContainment/Show', $locationContainment, 'location_containment', array_merge([
            'containment' => $locationContainment->load([
                'childLocation:id,name',
                'parentLocation:id,name',
            ]),
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
