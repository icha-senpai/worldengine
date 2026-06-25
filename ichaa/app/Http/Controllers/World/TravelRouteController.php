<?php

namespace App\Http\Controllers\World;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class TravelRouteController extends Controller
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('travel-routes', 'store'));

        $origin = Entity::findOrFail($validated['origin_location_entity_id']);
        $destination = Entity::findOrFail($validated['destination_location_entity_id']);

        if ($request->boolean('bidirectional')) {
            $this->service->createBidirectionalRoute($origin, $destination, $validated['route_type'], $validated);

            return $this->to('travel-routes.index', [], 'Routes created.');
        }

        $route = $this->service->createRoute($origin, $destination, $validated['route_type'], $validated);

        return $this->to('travel-routes.show', [$route], 'Route created.');
    }

    public function show(TravelRoute $travelRoute): Response
    {
        return $this->showPage($travelRoute);
    }

    public function edit(TravelRoute $travelRoute): Response
    {
        return $this->showPage($travelRoute, [
            'editDrawer' => $this->createFormProps(),
        ]);
    }

    public function update(Request $request, TravelRoute $travelRoute): RedirectResponse
    {
        $travelRoute = $this->service->updateRoute(
            $travelRoute,
            $request->validate(DataverseRules::web('travel-routes', 'update'))
        );

        return $this->to('travel-routes.show', [$travelRoute], 'Route updated.');
    }

    public function destroy(TravelRoute $travelRoute): RedirectResponse
    {
        $travelRoute->delete();

        return $this->to('travel-routes.index', [], 'Route deleted.');
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        return $this->page('World/TravelRoutes/Index', array_merge([
            'routes' => TravelRoute::active()
                ->with(['origin:id,name', 'destination:id,name'])
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
            'routeTypes' => TravelRoute::ROUTE_TYPES,
        ];
    }

    private function showPage(TravelRoute $travelRoute, array $props = []): Response
    {
        return $this->pageWithNotionNote('World/TravelRoutes/Show', $travelRoute, 'travel_routes', array_merge([
            'routeRecord' => $travelRoute->load(['origin:id,name', 'destination:id,name', 'controlledBy:id,name']),
        ], $props));
    }
}
