<?php

namespace App\Http\Controllers\Connections;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GroupRelationshipController extends Controller
{
    public function __construct(
        private readonly RelationshipService $service,
    ) {}

    public function index(Request $request): Response
    {
        $query = GroupRelationship::withCount('activeMembers')->latest();

        if ($request->boolean('volatile')) {
            $query->volatile();
        }

        if ($request->boolean('masked')) {
            $query->masked();
        }

        return $this->page('GroupRelationships/Index', [
            'groups' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['volatile', 'masked']),
        ]);
    }

    public function create(): Response
    {
        return $this->page('GroupRelationships/Create', [
            'tensionCharges' => TensionCharge::ALL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship_type' => ['required', 'string'],
            'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
            'is_active' => ['boolean'],
            'visibility' => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $group = $this->service->createGroup($validated);

        return $this->to('group-relationships.show', [$group], "Group '{$group->name}' created.");
    }

    public function show(GroupRelationship $groupRelationship): Response
    {
        $groupRelationship->load([
            'activeMembers:id,name,entity_type',
            'memberEntries.entity:id,name,entity_type',
        ]);

        return $this->page('GroupRelationships/Show', [
            'group' => $groupRelationship,
        ]);
    }

    public function edit(GroupRelationship $groupRelationship): Response
    {
        return $this->page('GroupRelationships/Edit', [
            'group' => $groupRelationship,
            'tensionCharges' => TensionCharge::ALL,
        ]);
    }

    public function update(Request $request, GroupRelationship $groupRelationship): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'relationship_type' => ['sometimes', 'string'],
            'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
            'charge_change_reason' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        if (
            isset($validated['current_tension_charge']) &&
            $validated['current_tension_charge'] !== $groupRelationship->current_tension_charge
        ) {
            $this->service->updateGroupTensionCharge(
                $groupRelationship,
                $validated['current_tension_charge'],
                $validated['charge_change_reason'] ?? null
            );
            unset($validated['current_tension_charge'], $validated['charge_change_reason']);
        }

        $groupRelationship->update($validated);

        return $this->to('group-relationships.show', [$groupRelationship], 'Group updated.');
    }

    public function destroy(GroupRelationship $groupRelationship): RedirectResponse
    {
        $groupRelationship->delete();

        return $this->to('group-relationships.index', [], 'Group deleted.');
    }

    // POST /group-relationships/{groupRelationship}/members
    public function addMember(Request $request, GroupRelationship $groupRelationship): RedirectResponse
    {
        $validated = $request->validate([
            'entity_id' => ['required', 'integer', 'exists:entities,id'],
            'role_in_group' => ['nullable', 'string'],
            'joined_era' => ['nullable', 'string'],
            'participation_notes' => ['nullable', 'array'],
        ]);

        $entity = Entity::findOrFail($validated['entity_id']);

        $this->service->addMemberToGroup($groupRelationship, $entity, $validated);

        return $this->back('Member added.');
    }

    // DELETE /group-relationships/{groupRelationship}/members/{entry}
    public function removeMember(Request $request, GroupRelationship $groupRelationship, GroupRelationshipEntity $entry): RedirectResponse
    {
        abort_unless((int) $entry->group_relationship_id === (int) $groupRelationship->id, 404);

        $validated = $request->validate([
            'left_era' => ['nullable', 'string'],
            'departure_notes' => ['nullable', 'array'],
        ]);

        $this->service->removeMemberFromGroup($entry, $validated);

        return $this->back('Member removed.');
    }
}
