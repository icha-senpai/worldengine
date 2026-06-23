<?php

namespace App\Http\Controllers\Connections;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
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
        $validated = $request->validate(DataverseRules::web('group-relationships', 'store'));

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $group = $this->service->createGroup($validated);

        return $this->to('group-relationships.show', [$group], "Group '{$group->name}' created.");
    }

    public function show(GroupRelationship $groupRelationship): Response
    {
        return $this->showPage($groupRelationship);
    }

    public function edit(GroupRelationship $groupRelationship): Response
    {
        return $this->showPage($groupRelationship, [
            'editDrawer' => [
                'tensionCharges' => TensionCharge::ALL,
            ],
        ]);
    }

    public function update(Request $request, GroupRelationship $groupRelationship): RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('group-relationships', 'update'));

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
        $validated = $request->validate(DataverseRules::webAction('group-relationship-add-member'));

        $entity = Entity::findOrFail($validated['entity_id']);

        $this->service->addMemberToGroup($groupRelationship, $entity, $validated);

        return $this->back('Member added.');
    }

    // DELETE /group-relationships/{groupRelationship}/members/{entry}
    public function removeMember(Request $request, GroupRelationship $groupRelationship, GroupRelationshipEntity $entry): RedirectResponse
    {
        abort_unless((int) $entry->group_relationship_id === (int) $groupRelationship->id, 404);

        $validated = $request->validate(DataverseRules::webAction('group-relationship-remove-member'));

        $this->service->removeMemberFromGroup($entry, $validated);

        return $this->back('Member removed.');
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $query = GroupRelationship::withCount('activeMembers')->latest();

        if ($request->boolean('volatile')) {
            $query->volatile();
        }

        if ($request->boolean('masked')) {
            $query->masked();
        }

                return $this->page('GroupRelationships/Index', array_merge([
            'groups' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['volatile', 'masked']),
        ], $props));
    
    }

    private function createFormProps(): array
    {
        return [
            'tensionCharges' => TensionCharge::ALL,
        
        ];
    }

    private function showPage(GroupRelationship $groupRelationship, array $props = []): Response
    {
        $groupRelationship->load([
            'activeMembers:id,name,entity_type',
            'memberEntries.entity:id,name,entity_type',
        ]);

        return $this->pageWithNotionNote('GroupRelationships/Show', $groupRelationship, 'group_relationships', array_merge([
            'group' => $groupRelationship,
            'entities' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
        ], $props));
    }
}
