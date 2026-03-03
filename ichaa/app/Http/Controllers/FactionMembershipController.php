<?php

namespace App\Http\Controllers\Connections;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Services\RelationshipService;

class FactionMembershipController extends Controller
{
    public function __construct(
        private readonly RelationshipService $service,
    ) {}

    // POST /faction-memberships
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'faction_entity_id'      => ['required', 'integer', 'exists:entities,id'],
            'member_entity_id'       => ['required', 'integer', 'exists:entities,id'],
            'rank_or_role'           => ['nullable', 'string'],
            'membership_status'      => ['nullable', 'string'],
            'joined_era'             => ['nullable', 'string'],
            'true_loyalty_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'is_undercover'          => ['boolean'],
            'public_membership_known'=> ['boolean'],
            'recruited_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
        ]);

        $faction = Entity::findOrFail($validated['faction_entity_id']);
        $member  = Entity::findOrFail($validated['member_entity_id']);

        $this->service->createFactionMembership($faction, $member, $validated);

        return $this->back('Membership created.');
    }

    // PUT /faction-memberships/{factionMembership}
    public function update(Request $request, FactionMembership $factionMembership): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'rank_or_role'           => ['nullable', 'string'],
            'membership_status'      => ['nullable', 'string'],
            'true_loyalty_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'is_undercover'          => ['boolean'],
            'public_membership_known'=> ['boolean'],
        ]);

        $this->service->updateFactionMembership($factionMembership, $validated);

        return $this->back('Membership updated.');
    }

    // DELETE /faction-memberships/{factionMembership}
    public function destroy(FactionMembership $factionMembership): \Illuminate\Http\RedirectResponse
    {
        $factionMembership->delete();

        return $this->back('Membership removed.');
    }
}
