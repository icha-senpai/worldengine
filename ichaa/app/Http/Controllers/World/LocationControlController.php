<?php

namespace App\Http\Controllers\World;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
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

    public function show(LocationControlHistory $locationControl): Response
    {
        return $this->showPage($locationControl);
    }

    public function edit(LocationControlHistory $locationControl): Response
    {
        return $this->showPage($locationControl, [
            'editDrawer' => array_merge($this->createFormProps(), [
                'record' => $locationControl->load([
                    'location:id,name',
                    'controllingEntity:id,name',
                    'resistanceEntities:id,name,entity_type',
                ]),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('location-control-records', 'store'));

        $location = Entity::findOrFail($validated['location_entity_id']);
        $controller = Entity::findOrFail($validated['controlling_entity_id']);

        $record = $this->service->recordControlChange($location, $controller, $validated['control_type'], $validated);

        return $this->to('location-control.show', [$record], 'Control change recorded.');
    }

    public function update(Request $request, LocationControlHistory $locationControl): RedirectResponse
    {
        $locationControl = $this->service->updateControlHistory($locationControl, $request->validate(
            DataverseRules::web('location-control-records', 'update')
        ));

        return $this->to('location-control.show', [$locationControl], 'Control record updated.');
    }

    public function destroy(LocationControlHistory $locationControl): RedirectResponse
    {
        $locationControl->delete();

        return $this->to('location-control.index', [], 'Control record deleted.');
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = LocationControlHistory::current()
            ->with(['location:id,name', 'controllingEntity:id,name']);

        if ($request->filled('control_type')) {
            $query->ofType($request->string('control_type')->toString());
        }

        if ($request->filled('resistance_level')) {
            $query->where('resistance_level', $request->string('resistance_level')->toString());
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->whereHas('location', fn ($location) => $location->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('controllingEntity', fn ($controller) => $controller->where('name', 'like', "%{$term}%"))
                    ->orWhere('control_start_era', 'like', "%{$term}%")
                    ->orWhere('control_end_era', 'like', "%{$term}%");
            });
        }

        return $this->page('World/LocationControl/Index', array_merge([
            'records' => $query->get(),
            'filters' => $request->only(['q', 'control_type', 'resistance_level']),
            'controlTypes' => LocationControlHistory::CONTROL_TYPES,
            'resistanceLevels' => LocationControlHistory::RESISTANCE_LEVELS,
        ], $props));
    }

    private function showPage(LocationControlHistory $locationControl, array $props = []): Response
    {
        return $this->pageWithNotionNote('World/LocationControl/Show', $locationControl, 'location_control', array_merge([
            'record' => $locationControl->load([
                'location:id,name',
                'controllingEntity:id,name',
                'resistanceEntities:id,name,entity_type',
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
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'resistanceEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', array_merge(
                    EntityType::CATEGORIES['people'],
                    EntityType::CATEGORIES['groups'],
                    EntityType::CATEGORIES['supernatural'],
                ))
                ->orderBy('name')
                ->get(),
            'controlTypes' => LocationControlHistory::CONTROL_TYPES,
            'resistanceLevels' => LocationControlHistory::RESISTANCE_LEVELS,
            'visibilityLevels' => VisibilityLevel::ALL,
            'contentClassifications' => ContentClassification::ALL,
        ];
    }
}
