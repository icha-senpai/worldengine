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
        $returnEntity = $this->resolveReturnEntity($request);

        if ($returnEntity) {
            return $this->showEntityPage($returnEntity, [
                'factionMembershipCreateDrawer' => [
                    'factionEntities' => $this->factionEntityOptions(),
                    'entities' => $this->entityOptions(),
                    'initialFactionEntityId' => $request->integer('faction_entity_id') ?: null,
                    'initialMemberEntityId' => $request->integer('member_entity_id') ?: null,
                    'initialFactionEntityName' => $request->integer('faction_entity_id')
                        ? Entity::query()->whereKey($request->integer('faction_entity_id'))->value('name')
                        : '',
                    'returnContext' => $request->string('return_context')->toString(),
                    'returnEntityId' => $returnEntity->id,
                    'returnEntityName' => $returnEntity->name,
                ],
            ]);
        }

        return $this->page('FactionMemberships/Create', [
            'factionEntities' => $this->factionEntityOptions(),
            'entities' => $this->entityOptions(),
            'initialFactionEntityId' => $request->integer('faction_entity_id') ?: null,
            'initialMemberEntityId' => $request->integer('member_entity_id') ?: null,
            'initialFactionEntityName' => null,
            'returnContext' => $request->string('return_context')->toString(),
            'returnEntityId' => null,
            'returnEntityName' => '',
        ]);
    }

    public function edit(Request $request, FactionMembership $factionMembership): Response
    {
        $membership = $factionMembership->load([
            'faction:id,name,entity_type,status,public_title,summary,public_summary,completion_score,visibility,content_classification,entity_sub_type,type_status,source_universes,origin_type,canon_deviation,origin_notes,power_tier_ceiling,power_tier_operating,power_tier_influence,persona_divergence,control_state,has_attributes,has_relationships,has_timeline_entries,has_aliases,has_media',
            'member:id,name',
            'trueLoyalty:id,name',
            'recruitedBy:id,name',
        ]);

        $returnEntity = $this->resolveReturnEntity($request)
            ?? $membership->faction
            ?? $membership->member;

        abort_unless($returnEntity, 404);

        return $this->showEntityPage($returnEntity, [
            'factionMembershipEditDrawer' => [
                'entities' => $this->entityOptions(),
                'membership' => $membership,
                'returnContext' => $request->string('return_context')->toString(),
                'returnEntityId' => $returnEntity->id,
                'returnEntityName' => $returnEntity->name,
            ],
        ]);
    }

    // POST /faction-memberships
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('faction-memberships', 'store'));

        $faction = Entity::findOrFail($validated['faction_entity_id']);
        $member  = Entity::findOrFail($validated['member_entity_id']);
        $returnEntityId = $this->resolveReturnEntityId($request, $faction->id, null, $member->id);

        $this->service->createFactionMembership($faction, $member, $validated);

        return $this->to('entities.show', [
            'entity' => $returnEntityId,
            'tab' => 'memberships',
        ], 'Membership created.');
    }

    // PUT /faction-memberships/{factionMembership}
    public function update(Request $request, FactionMembership $factionMembership): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(DataverseRules::web('faction-memberships', 'update'));
        $returnEntityId = $this->resolveReturnEntityId($request, $factionMembership->faction_entity_id, $factionMembership->member_entity_id);

        $this->service->updateFactionMembership($factionMembership, $validated);

        return $this->to('entities.show', [
            'entity' => $returnEntityId,
            'tab' => 'memberships',
        ], 'Membership updated.');
    }

    // DELETE /faction-memberships/{factionMembership}
    public function destroy(FactionMembership $factionMembership): \Illuminate\Http\RedirectResponse
    {
        $returnEntityId = $this->resolveReturnEntityId(request(), $factionMembership->faction_entity_id, $factionMembership->member_entity_id);
        $factionMembership->delete();

        return $this->to('entities.show', [
            'entity' => $returnEntityId,
            'tab' => 'memberships',
        ], 'Membership removed.');
    }

    private function factionEntityOptions()
    {
        return Entity::query()
            ->select('id', 'name', 'entity_type')
            ->whereIn('entity_type', EntityType::FACTION_TYPES)
            ->orderBy('name')
            ->get();
    }

    private function entityOptions()
    {
        return Entity::query()
            ->select('id', 'name', 'entity_type')
            ->orderBy('name')
            ->get();
    }

    private function resolveReturnEntity(Request $request): ?Entity
    {
        $returnContext = $this->requestString($request, 'return_context');

        if ($returnContext === 'member' && $this->requestInt($request, 'member_entity_id')) {
            return Entity::query()->find($this->requestInt($request, 'member_entity_id'));
        }

        if ($returnContext === 'faction' && $this->requestInt($request, 'faction_entity_id')) {
            return Entity::query()->find($this->requestInt($request, 'faction_entity_id'));
        }

        foreach (['return_entity_id', 'faction_entity_id', 'member_entity_id'] as $key) {
            $entityId = $this->requestInt($request, $key);

            if ($entityId) {
                return Entity::query()->find($entityId);
            }
        }

        return null;
    }

    private function resolveReturnEntityId(Request $request, int $fallbackId, ?int $memberId = null, ?int $requestMemberId = null): int
    {
        $returnContext = $this->requestString($request, 'return_context');

        if ($returnContext === 'member') {
            return $requestMemberId
                ?: $this->requestInt($request, 'member_entity_id')
                ?: $memberId
                ?: $fallbackId;
        }

        if ($returnContext === 'faction') {
            return $this->requestInt($request, 'faction_entity_id') ?: $fallbackId;
        }

        $entityId = $this->requestInt($request, 'return_entity_id');

        if ($entityId && Entity::query()->whereKey($entityId)->exists()) {
            return $entityId;
        }

        return $fallbackId;
    }

    private function requestInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            $value = $this->refererParams($request)[$key] ?? null;
        }

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function requestString(Request $request, string $key): string
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            $value = $this->refererParams($request)[$key] ?? '';
        }

        return is_string($value) ? $value : '';
    }

    private function refererParams(Request $request): array
    {
        static $cached = [];

        $cacheKey = spl_object_id($request);

        if (array_key_exists($cacheKey, $cached)) {
            return $cached[$cacheKey];
        }

        $referer = $request->headers->get('referer');
        $query = $referer ? parse_url($referer, PHP_URL_QUERY) : null;

        if (! is_string($query) || $query === '') {
            return $cached[$cacheKey] = [];
        }

        parse_str($query, $params);

        return $cached[$cacheKey] = is_array($params) ? $params : [];
    }

    private function showEntityPage(Entity $entity, array $props = []): Response
    {
        $entity->load([
            'aliases',
            'notes' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at'),
            'questions' => fn ($q) => $q->orderByRaw("CASE priority WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('created_at'),
            'controlledFactions' => fn ($q) => $q
                ->with([
                    'member:id,name,entity_type,public_title,status,visibility,completion_score',
                    'trueLoyalty:id,name',
                    'recruitedBy:id,name',
                ])
                ->orderByRaw("CASE membership_status WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 WHEN 'former' THEN 3 ELSE 4 END")
                ->orderBy('rank_or_role')
                ->orderBy('created_at'),
            'factionMemberships' => fn ($q) => $q
                ->with([
                    'faction:id,name,entity_type,public_title,status,visibility,completion_score',
                    'trueLoyalty:id,name',
                    'recruitedBy:id,name',
                ])
                ->orderByRaw("CASE membership_status WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 WHEN 'former' THEN 3 ELSE 4 END")
                ->orderBy('created_at'),
        ]);

        return $this->pageWithNotionNote('Entities/Show', $entity, 'entities', array_merge([
            'entity' => $entity,
            'factionRoster' => $entity->controlledFactions,
            'memberMemberships' => $entity->factionMemberships,
            'isFactionEntity' => in_array($entity->entity_type, EntityType::FACTION_TYPES, true),
        ], $props));
    }
}
