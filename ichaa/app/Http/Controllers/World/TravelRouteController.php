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

    public function index(): Response
    {
        return $this->page('World/TravelRoutes/Index', [
            'routes' => TravelRoute::active()
                ->with(['origin:id,name', 'destination:id,name'])
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->page('World/TravelRoutes/Create', [
            'locationEntities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::SPATIAL_TYPES)
                ->orderBy('name')
                ->get(),
            'routeTypes' => TravelRoute::ROUTE_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('travel-routes', 'store'));

        $origin = Entity::findOrFail($validated['origin_location_entity_id']);
        $destination = Entity::findOrFail($validated['destination_location_entity_id']);

        if ($request->boolean('bidirectional')) {
            $this->service->createBidirectionalRoute($origin, $destination, $validated['route_type'], $validated);
        } else {
            $this->service->createRoute($origin, $destination, $validated['route_type'], $validated);
        }

        return $this->back('Route created.');
    }

    public function show(TravelRoute $travelRoute): Response
    {
        return $this->pageWithNotionNote('World/TravelRoutes/Show', $travelRoute, 'travel_routes', [
            'routeRecord' => $travelRoute->load(['origin:id,name', 'destination:id,name', 'controlledBy:id,name']),
        ]);
    }

    public function edit(TravelRoute $travelRoute): Response
    {
        return $this->page('World/TravelRoutes/Edit', [
            'routeRecord' => $travelRoute,
        ]);
    }

    public function update(Request $request, TravelRoute $travelRoute): RedirectResponse
    {
        $travelRoute->update($request->validate(DataverseRules::web('travel-routes', 'update')));

        return $this->back('Route updated.');
    }

    public function destroy(TravelRoute $travelRoute): RedirectResponse
    {
        $travelRoute->delete();

        return $this->back('Route deleted.');
    }
}
