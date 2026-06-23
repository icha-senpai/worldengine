<?php

namespace App\Http\Controllers\Connections;

use Illuminate\Http\Request;
use Inertia\Response;
use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Services\RelationshipService;
use App\Support\Validation\DataverseRules;

class FactionMembershipController extends Controller
{
    public function __construct(
        private readonly RelationshipService $service,
    ) {}

    public function create(Request $request): Response
    {
        if ($request->integer('faction_entity_id')) {
            $faction = Entity::query()->find($request->integer('faction_entity_id'));

            if ($faction) {
                $faction->load([
                    'aliases',
                    'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
                    'questions' => fn ($q) => $q->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('created_at'),
                ]);

                return $this->page('Entities/Show', [
                    'entity' => $faction,
                    'factionMembershipCreateDrawer' => [
                        'factionEntities' => Entity::query()
                            ->select('id', 'name', 'entity_type')
                            ->whereIn('entity_type', EntityType::FACTION_TYPES)
                            ->orderBy('name')
                            ->get(),
                        'entities' => Entity::query()
                            ->select('id', 'name', 'entity_type')
                            ->orderBy('name')
                            ->get(),
                        'initialFactionEntityId' => $request->integer('faction_entity_id') ?: null,
                        'initialMemberEntityId' => $request->integer('member_entity_id') ?: null,
                    ],
                ]);
            }
        }

        return $this->page('FactionMemberships/Create', [
            'factionEntities'       => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->whereIn('entity_type', EntityType::FACTION_TYPES)
                ->orderBy('name')
                ->get(),
            'entities'              => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'initialFactionEntityId' => $request->integer('faction_entity_id') ?: null,
            'initialMemberEntityId'  => $request->integer('member_entity_id') ?: null,
        ]);
    }

    public function edit(FactionMembership $factionMembership): Response
    {
        $membership = $factionMembership->load([
            'faction:id,name,entity_type,status,public_title,summary,public_summary,completion_score,visibility,content_classification,entity_sub_type,type_status,source_universes,origin_type,canon_deviation,origin_notes,power_tier_ceiling,power_tier_operating,power_tier_influence,persona_divergence,control_state,has_attributes,has_relationships,has_timeline_entries,has_aliases,has_media',
            'member:id,name',
            'trueLoyalty:id,name',
            'recruitedBy:id,name',
        ]);

        $faction = $membership->faction?->load([
            'aliases',
            'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
            'questions' => fn ($q) => $q->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('created_at'),
        ]);

        abort_unless($faction, 404);

        return $this->page('Entities/Show', [
            'entity' => $faction,
            'factionMembershipEditDrawer' => [
                'entities' => Entity::query()
                    ->select('id', 'name', 'entity_type')
                    ->orderBy('name')
                    ->get(),
                'membership' => $membership,
            ],
        ]);
    }

    // POST /faction-memberships
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('faction-memberships', 'store'));

        $faction = Entity::findOrFail($validated['faction_entity_id']);
        $member  = Entity::findOrFail($validated['member_entity_id']);

        $this->service->createFactionMembership($faction, $member, $validated);

        return $this->back('Membership created.');
    }

    // PUT /faction-memberships/{factionMembership}
    public function update(Request $request, FactionMembership $factionMembership): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('faction-memberships', 'update'));

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
