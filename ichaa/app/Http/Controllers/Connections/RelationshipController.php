<?php

namespace App\Http\Controllers\Connections;

use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Http\Controllers\Controller;
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
        $query = Relationship::with(['fromEntity:id,name,entity_type', 'toEntity:id,name,entity_type'])
            ->latest();

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('charge')) {
            $query->where('current_tension_charge', $request->charge);
        }

        if ($request->boolean('volatile')) {
            $query->volatile();
        }

        if ($request->boolean('masked')) {
            $query->masked();
        }

        return $this->page('Relationships/Index', [
            'relationships' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['type', 'charge', 'volatile', 'masked']),
            'relationshipTypes' => RelationshipType::ALL,
            'tensionCharges' => TensionCharge::ALL,
        ]);
    }

    public function create(): Response
    {
        return $this->page('Relationships/Create', [
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'relationshipTypes' => RelationshipType::ALL,
            'tensionCharges' => TensionCharge::ALL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_entity_id' => ['required', 'integer', 'exists:entities,id'],
            'to_entity_id' => ['required', 'integer', 'exists:entities,id', 'different:from_entity_id'],
            'relationship_type' => ['required', 'string', 'in:'.implode(',', RelationshipType::ALL)],
            'direction' => ['nullable', 'string'],
            'perspective_a' => ['nullable', 'array'],
            'perspective_b' => ['nullable', 'array'],
            'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
            'is_active' => ['boolean'],
            'perceived_type' => ['nullable', 'string'],
            'true_type' => ['nullable', 'string'],
            'visibility' => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

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
        $relationship->load([
            'fromEntity:id,name,entity_type',
            'toEntity:id,name,entity_type',
            'stateRelationships.characterState',
        ]);

        return $this->page('Relationships/Show', [
            'relationship' => $relationship,
        ]);
    }

    public function edit(Relationship $relationship): Response
    {
        return $this->page('Relationships/Edit', [
            'relationship' => $relationship->load(['fromEntity:id,name', 'toEntity:id,name']),
            'relationshipTypes' => RelationshipType::ALL,
            'tensionCharges' => TensionCharge::ALL,
        ]);
    }

    public function update(Request $request, Relationship $relationship): RedirectResponse
    {
        $validated = $request->validate([
            'relationship_type' => ['sometimes', 'string'],
            'direction' => ['nullable', 'string'],
            'perspective_a' => ['nullable', 'array'],
            'perspective_b' => ['nullable', 'array'],
            'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
            'charge_change_reason' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'perceived_type' => ['nullable', 'string'],
            'true_type' => ['nullable', 'string'],
        ]);

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
}
