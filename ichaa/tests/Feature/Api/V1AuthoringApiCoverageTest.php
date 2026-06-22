<?php

namespace Tests\Feature\Api;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\Document;
use App\Domain\Organization\Models\Collection;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Models\Revision;
use App\Domain\System\Services\NotionDataverseSyncService;
use App\Domain\World\Models\TravelRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class V1AuthoringApiCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_entity_version_endpoints_support_manual_and_version_zero_snapshots(): void
    {
        $entity = app(EntityService::class)->create([
            'name' => 'Version API Entity',
            'entity_type' => 'character',
            'summary' => 'Initial state.',
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $this->withToken($token)
            ->withHeader('X-Request-Id', 'versions-index')
            ->getJson("/api/v1/entities/{$entity->id}/versions")
            ->assertOk()
            ->assertJsonPath('meta.request_id', 'versions-index')
            ->assertJsonCount(1, 'data');

        $manual = $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/versions", [
            'data' => [
                'attributes' => [
                    'version_label' => 'Post-Fracture',
                    'what_changed' => 'Canon bent on purpose.',
                    'why_changed' => 'API coverage test.',
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'save manual version',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.attributes.version_label', 'Post-Fracture')
            ->assertJsonPath('data.attributes.is_version_zero', false);

        $manualVersionId = (int) $manual->json('data.id');
        $entityRevisionId = (int) Revision::query()
            ->forResource('entities', $entity->id)
            ->max('id');

        $this->withToken($token)->getJson("/api/v1/entities/{$entity->id}/versions/{$manualVersionId}")
            ->assertOk()
            ->assertJsonPath('data.attributes.version_label', 'Post-Fracture');

        $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/versions", [
            'data' => [
                'attributes' => [
                    'version_label' => 'Source Canon Capture',
                    'is_version_zero' => true,
                ],
            ],
            'meta' => [
                'base_revision_id' => $entityRevisionId,
                'source' => 'phpunit',
                'reason' => 'save version zero',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.attributes.version_label', 'Source Canon Capture')
            ->assertJsonPath('data.attributes.is_version_zero', true)
            ->assertJsonPath('data.attributes.version_number', 0);
    }

    public function test_group_relationship_faction_membership_and_collection_flows_work_via_api(): void
    {
        $character = Entity::factory()->create([
            'entity_type' => 'character',
        ]);
        $faction = Entity::factory()->create([
            'entity_type' => 'faction',
        ]);
        $member = Entity::factory()->create();
        $otherFaction = Entity::factory()->create([
            'entity_type' => 'faction',
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $groupCreate = $this->withToken($token)->postJson('/api/v1/group-relationships', [
            'data' => [
                'attributes' => [
                    'name' => 'API Quiet Accord',
                    'relationship_type' => 'alliance',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create group relationship',
            ],
        ])->assertCreated();

        $groupId = (int) $groupCreate->json('data.id');
        $groupRevisionId = (int) $groupCreate->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/group-relationships/{$groupId}", [
            'data' => [
                'attributes' => [
                    'current_tension_charge' => 'volatile',
                    'charge_change_reason' => 'API pressure spike',
                ],
            ],
            'meta' => [
                'base_revision_id' => $groupRevisionId,
                'source' => 'phpunit',
                'reason' => 'update group charge',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.current_tension_charge', 'volatile');

        $membershipCreate = $this->withToken($token)->postJson("/api/v1/group-relationships/{$groupId}/members", [
            'data' => [
                'attributes' => [
                    'role_in_group' => 'Mediator',
                    'joined_era' => 'Year 5',
                ],
                'relationships' => [
                    'entity_id' => $member->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => Revision::query()->forResource('group-relationships', $groupId)->max('id'),
                'source' => 'phpunit',
                'reason' => 'add group member',
            ],
        ])->assertCreated();

        $membershipId = (int) $membershipCreate->json('data.id');
        $membershipRevisionId = (int) Revision::query()
            ->forResource('group-relationship-memberships', $membershipId)
            ->max('id');

        $this->withToken($token)->deleteJson("/api/v1/group-relationships/{$groupId}/members/{$membershipId}", [
            'data' => [
                'attributes' => [
                    'left_era' => 'Year 8',
                ],
            ],
            'meta' => [
                'base_revision_id' => $membershipRevisionId,
                'source' => 'phpunit',
                'reason' => 'remove group member',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.is_active_member', false);

        $this->assertDatabaseHas('group_relationship_entities', [
            'id' => $membershipId,
            'is_active_member' => false,
        ]);

        $factionMembership = $this->withToken($token)->postJson('/api/v1/faction-memberships', [
            'data' => [
                'attributes' => [
                    'rank_or_role' => 'Archivist',
                    'membership_status' => 'active',
                    'is_undercover' => true,
                    'public_membership_known' => false,
                ],
                'relationships' => [
                    'faction_entity_id' => $faction->id,
                    'member_entity_id' => $member->id,
                    'true_loyalty_entity_id' => $otherFaction->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create faction membership',
            ],
        ])->assertCreated();

        $factionMembershipId = (int) $factionMembership->json('data.id');
        $factionMembershipRevisionId = (int) $factionMembership->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/faction-memberships/{$factionMembershipId}/terminate", [
            'data' => [
                'attributes' => [
                    'left_era' => 'Arc End',
                    'departure_reason' => ['type' => 'doc', 'content' => []],
                ],
            ],
            'meta' => [
                'base_revision_id' => $factionMembershipRevisionId,
                'source' => 'phpunit',
                'reason' => 'terminate faction membership',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.membership_status', 'former');

        $collection = $this->withToken($token)->postJson('/api/v1/collections', [
            'data' => [
                'attributes' => [
                    'name' => 'API Character Roster',
                    'collection_type' => Collection::TYPES[0],
                    'collection_mode' => 'smart',
                    'rules' => [
                        ['field' => 'entity_type', 'operator' => 'equals', 'value' => 'character'],
                    ],
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create smart collection',
            ],
        ])->assertCreated();

        $collectionId = (int) $collection->json('data.id');

        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collectionId,
            'entity_id' => $character->id,
            'added_by_rule' => true,
        ]);

        $collectionRevisionId = (int) $collection->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/collections/{$collectionId}/sync", [
            'meta' => [
                'base_revision_id' => $collectionRevisionId,
                'source' => 'phpunit',
                'reason' => 'resync collection',
            ],
        ])->assertOk()
            ->assertJsonPath('meta.synced_count', 2);
    }

    public function test_power_knowledge_perception_and_secret_actions_work_via_api(): void
    {
        $systemA = Entity::factory()->create(['entity_type' => 'power_system']);
        $systemB = Entity::factory()->create(['entity_type' => 'magic_system']);
        $event = Entity::factory()->create(['entity_type' => 'event']);
        $knower = Entity::factory()->create();
        $subject = Entity::factory()->create();
        $immune = Entity::factory()->create();

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $interaction = $this->withToken($token)->postJson('/api/v1/power-interactions', [
            'data' => [
                'attributes' => [
                    'interaction_name' => 'API Pairing',
                    'directionality' => 'contextual',
                    'knowledge_state' => 'unknown',
                    'danger_rating' => 'existential_risk',
                ],
                'relationships' => [
                    'system_a_entity_id' => $systemA->id,
                    'system_b_entity_id' => $systemB->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create power interaction',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.attributes.unresolved_flag', true);

        $interactionId = (int) $interaction->json('data.id');
        $interactionRevisionId = (int) $interaction->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/power-interactions/{$interactionId}/instances", [
            'data' => [
                'attributes' => [
                    'outcome_match' => 'contradicted',
                    'outcome_notes' => ['type' => 'doc', 'content' => []],
                    'observed_at_era' => 'Year 0',
                ],
                'relationships' => [
                    'event_entity_id' => $event->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $interactionRevisionId,
                'source' => 'phpunit',
                'reason' => 'record power interaction instance',
            ],
        ])->assertCreated();

        $this->withToken($token)->postJson("/api/v1/power-interactions/{$interactionId}/resolve", [
            'data' => [
                'attributes' => [
                    'resolution_notes' => ['type' => 'doc', 'content' => []],
                    'knowledge_state' => 'established',
                ],
            ],
            'meta' => [
                'base_revision_id' => $interactionRevisionId,
                'source' => 'phpunit',
                'reason' => 'resolve power interaction',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.unresolved_flag', false);

        $knowledge = $this->withToken($token)->postJson('/api/v1/knowledge-states', [
            'data' => [
                'attributes' => [
                    'knowledge_type' => 'secret',
                    'accuracy' => 'true',
                    'current_belief_state' => 'believes',
                    'acquired_through' => 'told_by',
                ],
                'relationships' => [
                    'knower_entity_id' => $knower->id,
                    'subject_entity_id' => $subject->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create knowledge state',
            ],
        ])->assertCreated();

        $knowledgeId = (int) $knowledge->json('data.id');
        $knowledgeRevisionId = (int) $knowledge->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/knowledge-states/{$knowledgeId}/act-on", [
            'data' => [
                'attributes' => [
                    'action_notes' => ['type' => 'doc', 'content' => []],
                ],
            ],
            'meta' => [
                'base_revision_id' => $knowledgeRevisionId,
                'source' => 'phpunit',
                'reason' => 'mark knowledge acted on',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.acted_on', true);

        $perception = $this->withToken($token)->postJson('/api/v1/perception-states', [
            'data' => [
                'attributes' => [
                    'subject_type' => 'entity',
                    'subject_id' => $subject->id,
                    'true_state' => ['type' => 'doc', 'content' => []],
                    'perceived_state' => ['type' => 'doc', 'content' => []],
                    'divergence_level' => 'complete',
                    'maintained_by_entity_ids' => [$knower->id],
                    'revelation_risk' => 'medium',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create perception state',
            ],
        ])->assertCreated();

        $perceptionId = (int) $perception->json('data.id');
        $perceptionRevisionId = (int) $perception->json('data.meta.current_revision_id');

        $addImmune = $this->withToken($token)->postJson("/api/v1/perception-states/{$perceptionId}/immune", [
            'data' => [
                'relationships' => [
                    'entity_id' => $immune->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $perceptionRevisionId,
                'source' => 'phpunit',
                'reason' => 'add immune entity',
            ],
        ])->assertOk()
            ->assertJsonFragment([
                'immune_entity_ids' => [$immune->id],
            ]);

        $perceptionRevisionId = (int) $addImmune->json('data.meta.current_revision_id');

        $removeImmune = $this->withToken($token)->deleteJson("/api/v1/perception-states/{$perceptionId}/immune/{$immune->id}", [
            'meta' => [
                'base_revision_id' => $perceptionRevisionId,
                'source' => 'phpunit',
                'reason' => 'remove immune entity',
            ],
        ])->assertOk();

        $perceptionRevisionId = (int) $removeImmune->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/perception-states/{$perceptionId}/collapse", [
            'data' => [
                'attributes' => [
                    'era' => 'Year 2000',
                ],
            ],
            'meta' => [
                'base_revision_id' => $perceptionRevisionId,
                'source' => 'phpunit',
                'reason' => 'collapse perception state',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.is_current', false);

        $secret = Secret::create([
            'title' => 'API Holder Secret',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [],
            'exposure_risk' => 'medium',
            'status' => 'active',
        ]);

        $this->withToken($token)->postJson("/api/v1/secrets/{$secret->id}/holders", [
            'data' => [
                'relationships' => [
                    'entity_id' => $immune->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'add secret holder',
            ],
        ])->assertOk()
            ->assertJsonFragment([
                'holder_entity_ids' => [$immune->id],
            ]);

        $secretRevisionId = (int) Revision::query()->forResource('secrets', $secret->id)->max('id');

        $this->withToken($token)->deleteJson("/api/v1/secrets/{$secret->id}/holders/{$immune->id}", [
            'meta' => [
                'base_revision_id' => $secretRevisionId,
                'source' => 'phpunit',
                'reason' => 'remove secret holder',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.holder_entity_ids', []);
    }

    public function test_document_travel_route_and_notion_mapping_resources_work_via_api(): void
    {
        $origin = Entity::factory()->create(['entity_type' => 'location']);
        $destination = Entity::factory()->create(['entity_type' => 'location']);
        $author = Entity::factory()->create();
        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $document = $this->withToken($token)->postJson('/api/v1/documents', [
            'data' => [
                'attributes' => [
                    'title' => 'API Lore Document',
                    'document_type' => Document::DOCUMENT_TYPES[0],
                ],
                'relationships' => [
                    'official_author_entity_id' => $author->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create lore document',
            ],
        ])->assertCreated();

        $documentId = (int) $document->json('data.id');
        $documentRevisionId = (int) $document->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/documents/{$documentId}", [
            'data' => [
                'attributes' => [
                    'document_status' => 'public',
                ],
            ],
            'meta' => [
                'base_revision_id' => $documentRevisionId,
                'source' => 'phpunit',
                'reason' => 'publish lore document',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.document_status', 'public');

        $route = $this->withToken($token)->postJson('/api/v1/travel-routes', [
            'data' => [
                'attributes' => [
                    'route_type' => TravelRoute::ROUTE_TYPES[0],
                    'standard_duration' => 'Two nights',
                ],
                'relationships' => [
                    'origin_location_entity_id' => $origin->id,
                    'destination_location_entity_id' => $destination->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create travel route',
            ],
        ])->assertCreated();

        $routeId = (int) $route->json('data.id');
        $routeRevisionId = (int) $route->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/travel-routes/{$routeId}", [
            'data' => [
                'attributes' => [
                    'is_active' => false,
                    'hazards' => [['hazard_type' => 'storm', 'severity' => 'high']],
                ],
            ],
            'meta' => [
                'base_revision_id' => $routeRevisionId,
                'source' => 'phpunit',
                'reason' => 'update travel route',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.is_active', false);

        $mapping = $this->withToken($token)->postJson('/api/v1/notion-sync-mappings', [
            'data' => [
                'attributes' => [
                    'sync_resource' => 'documents',
                    'notion_page_id' => 'page-api-doc',
                    'notion_parent_database_id' => 'db-main',
                    'local_model_type' => Document::class,
                    'local_model_id' => $documentId,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create notion mapping',
            ],
        ])->assertCreated();

        $mappingId = (int) $mapping->json('data.id');
        $mappingRevisionId = (int) $mapping->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/notion-sync-mappings/{$mappingId}", [
            'data' => [
                'attributes' => [
                    'last_payload_hash' => sha1('mapping-refresh'),
                ],
            ],
            'meta' => [
                'base_revision_id' => $mappingRevisionId,
                'source' => 'phpunit',
                'reason' => 'update notion mapping',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.last_payload_hash', sha1('mapping-refresh'));

        $this->withToken($token)->deleteJson("/api/v1/documents/{$documentId}", [
            'meta' => [
                'base_revision_id' => Revision::query()->forResource('documents', $documentId)->max('id'),
                'source' => 'phpunit',
                'reason' => 'delete lore document',
            ],
        ])->assertOk()
            ->assertJsonPath('meta.deleted', true);
    }

    public function test_notion_sync_endpoint_uses_service_and_non_soft_delete_resources_are_rejected(): void
    {
        $mock = Mockery::mock(NotionDataverseSyncService::class);
        $mock->shouldReceive('sync')
            ->once()
            ->with('documents', true, true)
            ->andReturn([
                'created' => 1,
                'updated' => 2,
                'skipped' => 3,
            ]);

        $this->app->instance(NotionDataverseSyncService::class, $mock);

        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*', 'sync:notion']);

        $this->withToken($token)
            ->withHeader('X-Request-Id', 'sync-request')
            ->postJson('/api/v1/notion-sync/documents?include_drafts=1&dry_run=1')
            ->assertOk()
            ->assertJsonPath('meta.request_id', 'sync-request')
            ->assertJsonPath('data.stats.created', 1)
            ->assertJsonPath('data.stats.updated', 2);

        $mapping = NotionSyncMapping::create([
            'sync_resource' => 'documents',
            'notion_page_id' => 'page-non-soft',
            'local_model_type' => Document::class,
            'local_model_id' => 1,
        ]);

        $this->withToken($token)->deleteJson("/api/v1/notion-sync-mappings/{$mapping->id}", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'attempt non-soft delete',
            ],
        ])->assertStatus(409);
    }

    private function assistantToken(array $abilities): string
    {
        $user = User::factory()->create();

        return $user->createToken('assistant', $abilities)->plainTextToken;
    }
}
