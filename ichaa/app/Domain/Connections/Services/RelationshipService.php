<?php

namespace App\Domain\Connections\Services;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Listeners\FlipEntityCompletionFlags;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use Illuminate\Support\Facades\DB;

class RelationshipService
{
    public function __construct(
        private readonly FlipEntityCompletionFlags $flagFlipper,
    ) {}

    // --- PAIRWISE RELATIONSHIPS ---

    public function create(Entity $from, Entity $to, array $data): Relationship
    {
        return DB::transaction(function () use ($from, $to, $data) {
            $relationship = Relationship::create(array_merge($data, [
                'from_entity_id' => $from->id,
                'to_entity_id' => $to->id,
                'direction' => filled($data['direction'] ?? null) ? $data['direction'] : 'one_way',
                'current_tension_charge' => $data['current_tension_charge'] ?? TensionCharge::NEUTRAL,
                'visibility' => filled($data['visibility'] ?? null) ? $data['visibility'] : VisibilityLevel::PRIVATE,
                'content_classification' => filled($data['content_classification'] ?? null)
                    ? $data['content_classification']
                    : ContentClassification::RESTRICTED,
                'is_active' => true,
            ]));

            // Flip has_relationships on both entities
            $this->flagFlipper->flipRelationships($from);
            $this->flagFlipper->flipRelationships($to);

            return $relationship;
        });
    }

    public function update(Relationship $relationship, array $data): Relationship
    {
        $relationship->update($data);

        return $relationship->fresh();
    }

    // Record a tension charge change with history entry
    public function updateTensionCharge(
        Relationship $relationship,
        string $newCharge,
        ?string $reason = null
    ): Relationship {
        // Validate new charge
        TensionCharge::from($newCharge);

        $history = $relationship->charge_history ?? [];

        $history[] = [
            'previous_charge' => $relationship->current_tension_charge,
            'new_charge' => $newCharge,
            'reason' => $reason,
            'recorded_at' => now()->toISOString(),
        ];

        $relationship->update([
            'current_tension_charge' => $newCharge,
            'charge_history' => $history,
        ]);

        return $relationship->fresh();
    }

    public function deactivate(Relationship $relationship): Relationship
    {
        $relationship->update(['is_active' => false]);

        return $relationship->fresh();
    }

    public function delete(Relationship $relationship): void
    {
        $fromEntity = $relationship->fromEntity;
        $toEntity = $relationship->toEntity;

        $relationship->delete();

        $this->flagFlipper->flipRelationships($fromEntity);
        $this->flagFlipper->flipRelationships($toEntity);
    }

    // --- GROUP RELATIONSHIPS ---

    public function createGroup(array $data, array $memberData = []): GroupRelationship
    {
        return DB::transaction(function () use ($data, $memberData) {
            $group = GroupRelationship::create(array_merge($data, [
                'current_tension_charge' => $data['current_tension_charge'] ?? TensionCharge::NEUTRAL,
                'visibility' => filled($data['visibility'] ?? null) ? $data['visibility'] : VisibilityLevel::PRIVATE,
                'content_classification' => filled($data['content_classification'] ?? null)
                    ? $data['content_classification']
                    : ContentClassification::RESTRICTED,
                'is_active' => true,
            ]));

            // Attach initial members if provided
            foreach ($memberData as $member) {
                $this->addMemberToGroup($group, Entity::findOrFail($member['entity_id']), $member);
            }

            return $group->fresh();
        });
    }

    public function addMemberToGroup(
        GroupRelationship $group,
        Entity $entity,
        array $data = []
    ): GroupRelationshipEntity {
        return GroupRelationshipEntity::create([
            'group_relationship_id' => $group->id,
            'entity_id' => $entity->id,
            'role_in_group' => $data['role_in_group'] ?? null,
            'participation_notes' => $data['participation_notes'] ?? null,
            'is_active_member' => true,
            'joined_era' => $data['joined_era'] ?? null,
        ]);
    }

    public function removeMemberFromGroup(
        GroupRelationshipEntity $membership,
        array $data = []
    ): GroupRelationshipEntity {
        $membership->update([
            'is_active_member' => false,
            'left_era' => $data['left_era'] ?? null,
            'departure_notes' => $data['departure_notes'] ?? null,
        ]);

        return $membership->fresh();
    }

    public function updateGroupTensionCharge(
        GroupRelationship $group,
        string $newCharge,
        ?string $reason = null
    ): GroupRelationship {
        TensionCharge::from($newCharge);

        $history = $group->charge_history ?? [];
        $history[] = [
            'previous_charge' => $group->current_tension_charge,
            'new_charge' => $newCharge,
            'reason' => $reason,
            'recorded_at' => now()->toISOString(),
        ];

        $group->update([
            'current_tension_charge' => $newCharge,
            'charge_history' => $history,
        ]);

        return $group->fresh();
    }

    // --- FACTION MEMBERSHIPS ---

    public function createFactionMembership(
        Entity $faction,
        Entity $member,
        array $data = []
    ): FactionMembership {
        return FactionMembership::create(array_merge($data, [
            'faction_entity_id' => $faction->id,
            'member_entity_id' => $member->id,
            'membership_status' => $data['membership_status'] ?? 'active',
        ]));
    }

    public function updateFactionMembership(FactionMembership $membership, array $data): FactionMembership
    {
        $membership->update($data);

        return $membership->fresh();
    }

    public function terminateFactionMembership(
        FactionMembership $membership,
        array $data = []
    ): FactionMembership {
        $membership->update([
            'membership_status' => 'former',
            'left_era' => $data['left_era'] ?? null,
            'departure_reason' => $data['departure_reason'] ?? null,
        ]);

        return $membership->fresh();
    }
}
