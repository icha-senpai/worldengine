<?php

namespace App\Http\Controllers\Connections;

use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class RelationshipController extends Controller
{
    public function __construct(
        private readonly RelationshipService $service,
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
        $validated = $request->validate(DataverseRules::web('relationships', 'store'));

        $from = Entity::findOrFail($validated['from_entity_id']);
        $to = Entity::findOrFail($validated['to_entity_id']);
        $validated['direction'] = filled($validated['direction'] ?? null)
            ? $validated['direction']
            : 'one_way';

        $relationship = $this->service->create($from, $to, $validated);

        return $this->to('relationships.show', [$relationship], 'Relationship created.');
    }

    public function show(Relationship $relationship): Response
    {
        return $this->showPage($relationship);
    }

    public function edit(Relationship $relationship): Response
    {
        return $this->showPage($relationship, [
            'editDrawer' => $this->createFormProps(),
        ]);
    }

    public function update(Request $request, Relationship $relationship): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('relationships', 'update'));

        // If tension charge changed, use service method to append history
        if (
            isset($validated['current_tension_charge']) &&
            $validated['current_tension_charge'] !== $relationship->current_tension_charge
        ) {
            $this->service->updateTensionCharge(
                $relationship,
                $validated['current_tension_charge'],
                $validated['charge_change_reason'] ?? null
            );
            unset($validated['current_tension_charge'], $validated['charge_change_reason']);
        }

        $this->service->update($relationship, $validated);

        return $this->to('relationships.show', [$relationship], 'Relationship updated.');
    }

    public function destroy(Relationship $relationship): RedirectResponse
    {
        $this->service->delete($relationship);

        return $this->to('relationships.index', [], 'Relationship deleted.');
    }

    private function indexPage(Request $request, array $props = []): Response
    {
        $query = Relationship::with(['fromEntity:id,name,entity_type', 'toEntity:id,name,entity_type'])
            ->latest();

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('charge')) {
            $query->where('current_tension_charge', $request->charge);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($inner) use ($term) {
                $inner->whereHas('fromEntity', fn ($from) => $from->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('toEntity', fn ($to) => $to->where('name', 'like', "%{$term}%"))
                    ->orWhere('relationship_type', 'like', "%{$term}%")
                    ->orWhere('direction', 'like', "%{$term}%");
            });
        }

        if ($request->boolean('volatile')) {
            $query->volatile();
        }

        if ($request->boolean('masked')) {
            $query->masked();
        }

        return $this->page('Relationships/Index', array_merge([
            'relationships' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['q', 'type', 'charge', 'volatile', 'masked']),
            'relationshipTypes' => RelationshipType::ALL,
            'tensionCharges' => TensionCharge::ALL,
        ], $props));

    }

    private function createFormProps(): array
    {
        return [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'relationshipTypes' => RelationshipType::ALL,
            'tensionCharges' => TensionCharge::ALL,

        ];
    }

    private function showPage(Relationship $relationship, array $props = []): Response
    {
        $relationship->load([
            'fromEntity:id,name,entity_type',
            'toEntity:id,name,entity_type',
            'stateRelationships.characterState',
        ]);

        return $this->pageWithNotionNote('Relationships/Show', $relationship, 'relationships', array_merge([
            'relationship' => $relationship,
        ], $props));
    }
}
