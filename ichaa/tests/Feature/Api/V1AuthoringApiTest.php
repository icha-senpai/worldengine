<?php

namespace Tests\Feature\Api;

use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\Document;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Models\Revision;
use App\Domain\System\Services\NotionDataverseSyncService;
use App\Domain\Temporal\Models\Timeline;
use App\Support\Api\ApiResourceRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class V1AuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_entities_endpoint_requires_token_auth(): void
    {
        $this->getJson('/api/v1/entities')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_entities_endpoint_enforces_token_abilities(): void
    {
        $this->withToken($this->assistantToken(['write:*']))
            ->getJson('/api/v1/entities')
            ->assertForbidden();
    }

    public function test_api_returns_json_not_found_and_validation_error_shapes(): void
    {
        $this->withToken($this->assistantToken(['read:*']))
            ->getJson('/api/v1/entities/999999')
            ->assertNotFound()
            ->assertJson([
                'message' => 'Resource not found.',
            ]);

        $this->withToken($this->assistantToken(['write:*']))
            ->postJson('/api/v1/entities', [
                'data' => [
                    'attributes' => [
                        'summary' => 'Missing required name and type.',
                    ],
                ],
                'meta' => [
                    'source' => 'phpunit',
                    'reason' => 'validation shape check',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'data.attributes.name',
                    'data.attributes.entity_type',
                ],
            ]);
    }

    public function test_entity_crud_uses_revisions_and_restore_flow(): void
    {
        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'restore:*', 'history:*']);

        $create = $this->withToken($token)->postJson('/api/v1/entities', [
            'data' => [
                'attributes' => [
                    'name' => 'API Test Entity',
                    'entity_type' => 'character',
                    'summary' => 'Created through the MCP authoring API.',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'initial create',
            ],
        ]);

        $create->assertCreated();
        $entityId = $create->json('data.id');
        $createRevisionId = (int) $create->json('data.meta.current_revision_id');

        $update = $this->withToken($token)->patchJson("/api/v1/entities/{$entityId}", [
            'data' => [
                'attributes' => [
                    'summary' => 'Updated summary.',
                ],
            ],
            'meta' => [
                'base_revision_id' => $createRevisionId,
                'source' => 'phpunit',
                'reason' => 'update summary',
            ],
        ]);

        $update->assertOk()
            ->assertJsonPath('data.attributes.summary.type', 'doc')
            ->assertJsonPath('data.attributes.summary.content.0.content.0.text', 'Updated summary.')
            ->assertJsonPath('data.meta.current_revision_id', Revision::query()->forResource('entities', $entityId)->max('id'));

        $this->withToken($token)->patchJson("/api/v1/entities/{$entityId}", [
            'data' => [
                'attributes' => [
                    'summary' => 'Stale write',
                ],
            ],
            'meta' => [
                'base_revision_id' => $createRevisionId,
                'source' => 'phpunit',
                'reason' => 'stale write',
            ],
        ])->assertConflict();

        $updateRevisionId = (int) Revision::query()->forResource('entities', $entityId)->max('id');

        $this->withToken($token)->deleteJson("/api/v1/entities/{$entityId}", [
            'meta' => [
                'base_revision_id' => $updateRevisionId,
                'source' => 'phpunit',
                'reason' => 'soft delete',
            ],
        ])->assertOk();

        $this->assertSoftDeleted('entities', ['id' => $entityId]);

        $deleteRevisionId = (int) Revision::query()->forResource('entities', $entityId)->max('id');

        $this->withToken($token)->postJson("/api/v1/trash/entities/{$entityId}/restore", [
            'meta' => [
                'base_revision_id' => $deleteRevisionId,
                'source' => 'phpunit',
                'reason' => 'restore entity',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.deleted_at', null)
            ->assertJsonPath('data.meta.current_revision_id', Revision::query()->forResource('entities', $entityId)->max('id'));

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'entities',
            'resource_id' => (string) $entityId,
            'action' => 'restore',
        ]);
    }

    public function test_entity_publish_action_records_revision(): void
    {
        $entity = Entity::factory()->publishable()->create([
            'published_at' => null,
            'visibility' => 'private',
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/publish", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'publish entity',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.visibility', 'public_knowledge');

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'entities',
            'resource_id' => (string) $entity->id,
            'action' => 'publish',
        ]);
    }

    public function test_entity_unpublish_and_archive_actions_work_via_api(): void
    {
        $entity = Entity::factory()->publishable()->create([
            'published_at' => now(),
            'visibility' => 'public_knowledge',
            'status' => 'active',
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/unpublish", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'unpublish entity',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.visibility', 'private')
            ->assertJsonPath('data.attributes.published_at', null);

        $entityRevisionId = $this->currentRevisionId('entities', $entity->id);

        $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/archive", [
            'meta' => [
                'base_revision_id' => $entityRevisionId,
                'source' => 'phpunit',
                'reason' => 'archive entity',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.status', 'archived');

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'entities',
            'resource_id' => (string) $entity->id,
            'action' => 'unpublish',
        ]);
        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'entities',
            'resource_id' => (string) $entity->id,
            'action' => 'archive',
        ]);
    }

    public function test_validate_only_allows_entity_preview_without_mutating(): void
    {
        $token = $this->assistantToken(['read:*', 'write:*']);

        $this->withToken($token)->postJson('/api/v1/entities', [
            'data' => [
                'attributes' => [
                    'name' => 'Preview Only Entity',
                    'entity_type' => 'character',
                    'summary' => 'Should never persist.',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'validate_only' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('meta.validated', true)
            ->assertJsonPath('meta.validate_only', true)
            ->assertJsonPath('data.attributes.name', 'Preview Only Entity');

        $this->assertDatabaseMissing('entities', [
            'name' => 'Preview Only Entity',
        ]);
    }

    public function test_api_write_preserves_whitespace_inside_rich_text_json_nodes(): void
    {
        $token = $this->assistantToken(['read:*', 'write:*']);

        $richText = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Normal '],
                    ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold'],
                    ['type' => 'text', 'text' => ' and '],
                    ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'italic'],
                    ['type' => 'text', 'text' => '.'],
                ],
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/v1/secrets', [
            'data' => [
                'attributes' => [
                    'title' => 'Whitespace Secret',
                    'secret_type' => 'plan',
                    'secret_content' => $richText,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'preserve rich text whitespace',
            ],
        ])->assertCreated();

        $secret = Secret::findOrFail((int) $response->json('data.id'));

        $this->assertEquals($richText, $secret->secret_content);
        $this->assertSame('Normal ', data_get($secret->secret_content, 'content.0.content.0.text'));
        $this->assertSame(' and ', data_get($secret->secret_content, 'content.0.content.2.text'));
    }

    public function test_revision_compare_and_restore_endpoints_work_for_entities(): void
    {
        $token = $this->assistantToken(['read:*', 'write:*', 'history:*', 'restore:*']);

        $create = $this->withToken($token)->postJson('/api/v1/entities', [
            'data' => [
                'attributes' => [
                    'name' => 'Revision Check Entity',
                    'entity_type' => 'character',
                    'summary' => 'Before revision compare.',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create revision fixture',
            ],
        ])->assertCreated();

        $entityId = (int) $create->json('data.id');
        $createRevisionId = (int) $create->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/entities/{$entityId}", [
            'data' => [
                'attributes' => [
                    'summary' => 'After revision compare.',
                ],
            ],
            'meta' => [
                'base_revision_id' => $createRevisionId,
                'source' => 'phpunit',
                'reason' => 'update revision fixture',
            ],
        ])->assertOk();

        $updateRevisionId = Revision::query()
            ->where('resource_type', 'entities')
            ->where('resource_id', (string) $entityId)
            ->latest('id')
            ->value('id');

        $this->withToken($token)->getJson("/api/v1/revisions/compare?left={$createRevisionId}&right={$updateRevisionId}")
            ->assertOk()
            ->assertJsonPath('data.left.id', $createRevisionId)
            ->assertJsonPath('data.right.id', $updateRevisionId)
            ->assertJsonPath('data.comparison.before.summary.type', 'doc')
            ->assertJsonPath('data.comparison.before.summary.content.0.content.0.text', 'Before revision compare.')
            ->assertJsonPath('data.comparison.after.summary.type', 'doc')
            ->assertJsonPath('data.comparison.after.summary.content.0.content.0.text', 'After revision compare.');

        $this->withToken($token)->postJson("/api/v1/revisions/{$createRevisionId}/restore", [
            'meta' => [
                'base_revision_id' => $updateRevisionId,
                'source' => 'phpunit',
                'reason' => 'restore older revision',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.summary.type', 'doc')
            ->assertJsonPath('data.attributes.summary.content.0.content.0.text', 'Before revision compare.');

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'entities',
            'resource_id' => (string) $entityId,
            'action' => 'restore_revision',
            'restored_from_revision_id' => $createRevisionId,
        ]);
    }

    public function test_relationship_create_and_tension_charge_action_work_via_api(): void
    {
        $from = Entity::factory()->create();
        $to = Entity::factory()->create();
        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $create = $this->withToken($token)->postJson('/api/v1/relationships', [
            'data' => [
                'attributes' => [
                    'relationship_type' => RelationshipType::POWER,
                ],
                'relationships' => [
                    'from_entity_id' => $from->id,
                    'to_entity_id' => $to->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create relationship',
            ],
        ])->assertCreated();

        $relationshipId = (int) $create->json('data.id');
        $relationshipRevisionId = (int) $create->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/relationships/{$relationshipId}/tension-charge", [
            'data' => [
                'attributes' => [
                    'new_charge' => TensionCharge::COMPLEX,
                    'reason' => 'API action test',
                ],
            ],
            'meta' => [
                'base_revision_id' => $relationshipRevisionId,
                'source' => 'phpunit',
                'reason' => 'charge relationship',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.current_tension_charge', TensionCharge::COMPLEX);

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'relationships',
            'resource_id' => (string) $relationshipId,
            'action' => 'tension_charge',
        ]);
    }

    public function test_timeline_event_actions_work_via_api(): void
    {
        $timeline = Entity::factory()->create([
            'entity_type' => 'timeline',
        ]);
        $event = Entity::factory()->create([
            'entity_type' => 'event',
        ]);
        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $place = $this->withToken($token)->postJson("/api/v1/timelines/{$timeline->id}/events", [
            'data' => [
                'attributes' => [
                    'entry_label' => 'Placed by API',
                ],
                'relationships' => [
                    'event_entity_id' => $event->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'place timeline event',
            ],
        ])->assertCreated();

        $entryId = (int) $place->json('data.id');
        $entryRevisionId = (int) $place->json('data.meta.current_revision_id');

        $this->withToken($token)->patchJson("/api/v1/timelines/{$timeline->id}/events/{$entryId}", [
            'data' => [
                'attributes' => [
                    'entry_label' => 'Updated by API',
                    'timeline_position' => 25,
                ],
            ],
            'meta' => [
                'base_revision_id' => $entryRevisionId,
                'source' => 'phpunit',
                'reason' => 'update timeline event',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.entry_label', 'Updated by API')
            ->assertJsonPath('data.attributes.timeline_position', 25);

        $updatedEntryRevisionId = Revision::query()
            ->forResource('timeline-entries', $entryId)
            ->max('id');

        $this->withToken($token)->deleteJson("/api/v1/timelines/{$timeline->id}/events/{$entryId}", [
            'meta' => [
                'base_revision_id' => $updatedEntryRevisionId,
                'source' => 'phpunit',
                'reason' => 'remove timeline event',
            ],
        ])->assertOk()
            ->assertJsonPath('meta.deleted', true);

        $this->assertSoftDeleted('timeline', ['id' => $entryId]);
    }

    public function test_secret_meta_and_pipeline_actions_work_via_api(): void
    {
        $entity = Entity::factory()->create();
        $secret = Secret::create([
            'title' => 'API Secret',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [],
            'exposure_risk' => 'medium',
            'status' => 'active',
        ]);
        $meta = Meta::create([
            'title' => 'API Meta',
            'category' => Meta::CATEGORIES[0],
            'meta_note_type' => 'active_task',
            'priority' => 'medium',
            'action_status' => 'pending',
        ]);
        $pipeline = PipelineItem::create([
            'title' => 'API Pipeline',
            'pipeline_type' => 'scene',
            'pipeline_stage' => 'concept',
            'sort_order' => 1,
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $expose = $this->withToken($token)->postJson("/api/v1/secrets/{$secret->id}/expose", [
            'data' => [
                'attributes' => [
                    'era' => 'Year 1',
                    'exposure_level' => 'fully_exposed',
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'expose secret',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.status', 'fully_exposed');

        $secretRevisionId = (int) $expose->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/secrets/{$secret->id}/known-by", [
            'data' => [
                'relationships' => [
                    'entity_id' => $entity->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $secretRevisionId,
                'source' => 'phpunit',
                'reason' => 'add known by',
            ],
        ])->assertOk()
            ->assertJsonFragment([
                'known_by_entity_ids' => [$entity->id],
            ]);

        $resolveMeta = $this->withToken($token)->postJson("/api/v1/meta/{$meta->id}/resolve", [
            'data' => [
                'attributes' => [
                    'resolution_notes' => ['type' => 'doc', 'content' => []],
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'resolve meta',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.action_status', 'resolved');

        $metaRevisionId = (int) $resolveMeta->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/meta/{$meta->id}/entities", [
            'data' => [
                'relationships' => [
                    'entity_id' => $entity->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $metaRevisionId,
                'source' => 'phpunit',
                'reason' => 'link meta entity',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('meta_entities', [
            'meta_id' => $meta->id,
            'entity_id' => $entity->id,
        ]);

        $advance = $this->withToken($token)->postJson("/api/v1/pipeline-items/{$pipeline->id}/advance", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'advance pipeline',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.pipeline_stage', 'outlined');

        $pipelineRevisionId = (int) $advance->json('data.meta.current_revision_id');

        $this->withToken($token)->postJson("/api/v1/pipeline-items/{$pipeline->id}/resolve", [
            'data' => [
                'attributes' => [
                    'resolution_notes' => ['type' => 'doc', 'content' => []],
                ],
            ],
            'meta' => [
                'base_revision_id' => $pipelineRevisionId,
                'source' => 'phpunit',
                'reason' => 'resolve pipeline',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.pipeline_stage', 'complete');
    }

    public function test_search_returns_notion_note_match_context(): void
    {
        $entity = Entity::factory()->create([
            'name' => 'Grey Lantern',
        ]);

        NotionNote::query()->create([
            'sync_resource' => 'entities',
            'notion_page_id' => 'page-grey-lantern',
            'noteable_type' => Entity::class,
            'noteable_id' => $entity->id,
            'content' => 'Grey ritual lattice hidden behind devotional language.',
            'content_hash' => sha1('Grey ritual lattice hidden behind devotional language.'),
            'last_synced_at' => now(),
        ]);

        $this->withToken($this->assistantToken(['read:*']))->getJson('/api/v1/search?search=ritual')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'entities')
            ->assertJsonFragment([
                'match_context' => 'Grey ritual lattice hidden behind devotional language.',
            ]);
    }

    public function test_media_upload_endpoint_creates_managed_media_for_assistant_tokens(): void
    {
        Storage::fake('public');

        $entity = Entity::query()->create([
            'name' => 'Grey Archive',
            'entity_type' => 'location',
        ]);

        $response = $this->withToken($this->assistantToken(['read:*', 'write:*', 'history:*']))
            ->postJson('/api/v1/media-references/upload', [
                'data' => [
                    'attributes' => [
                        'title' => 'Grey Archive Diagram',
                        'description' => 'Uploaded through the MCP-ready API.',
                        'media_type' => 'image',
                        'purpose' => 'reference',
                        'visibility' => 'private',
                        'content_classification' => 'restricted',
                    ],
                    'relationships' => [
                        'entity_id' => $entity->id,
                    ],
                    'file' => [
                        'name' => 'grey-archive-dot.png',
                        'mime_type' => 'image/png',
                        'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9l9o8AAAAASUVORK5CYII=',
                    ],
                ],
                'meta' => [
                    'source' => 'phpunit',
                    'reason' => 'create managed media upload',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'media-references')
            ->assertJsonPath('data.attributes.title', 'Grey Archive Diagram')
            ->assertJsonPath('data.attributes.url', null)
            ->assertJsonPath('data.attributes.file_name', 'grey-archive-dot.png')
            ->assertJsonPath('data.attributes.mime_type', 'image/png');

        $mediaId = (int) $response->json('data.id');
        $media = MediaReference::query()->findOrFail($mediaId);

        $this->assertTrue($media->isManagedUpload());
        $this->assertSame(1, $media->width_px);
        $this->assertSame(1, $media->height_px);
        Storage::disk('public')->assertExists('media-library/'.basename((string) $media->file_path));

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'media-references',
            'resource_id' => (string) $mediaId,
            'action' => 'create',
        ]);
    }

    public function test_media_replace_endpoint_swaps_existing_managed_upload_bytes(): void
    {
        Storage::fake('public');

        $entity = Entity::query()->create([
            'name' => 'Grey Archive',
            'entity_type' => 'location',
        ]);

        Storage::disk('public')->put(
            'media-library/original-grey-dot.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9l9o8AAAAASUVORK5CYII=', true),
        );

        $media = MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Grey Archive Diagram',
            'description' => 'Original upload.',
            'media_type' => 'image',
            'purpose' => 'reference',
            'file_path' => Storage::disk('public')->path('media-library/original-grey-dot.png'),
            'file_name' => 'original-grey-dot.png',
            'file_extension' => 'png',
            'file_size_bytes' => Storage::disk('public')->size('media-library/original-grey-dot.png'),
            'mime_type' => 'image/png',
            'width_px' => 1,
            'height_px' => 1,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $previousPath = $media->file_path;
        $baseRevisionId = Revision::query()->create([
            'resource_type' => 'media-references',
            'resource_id' => (string) $media->id,
            'action' => 'create',
            'after_payload' => $media->attributesToArray(),
            'source' => 'phpunit',
        ])->id;

        $response = $this->withToken($this->assistantToken(['read:*', 'write:*', 'history:*']))
            ->postJson("/api/v1/media-references/{$media->id}/replace-file", [
                'data' => [
                    'file' => [
                        'name' => 'replaced-grey-dot.png',
                        'mime_type' => 'image/png',
                        'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAQAAADZc7J/AAAADElEQVR42mNk+M8AAAICAQCSfQKJAAAAAElFTkSuQmCC',
                    ],
                ],
                'meta' => [
                    'base_revision_id' => $baseRevisionId,
                    'source' => 'phpunit',
                    'reason' => 'replace managed media upload',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'media-references')
            ->assertJsonPath('data.attributes.file_name', 'replaced-grey-dot.png')
            ->assertJsonPath('data.attributes.mime_type', 'image/png');

        $media->refresh();

        $this->assertTrue($media->isManagedUpload());
        $this->assertNotSame($previousPath, $media->file_path);
        Storage::disk('public')->assertMissing('media-library/original-grey-dot.png');
        Storage::disk('public')->assertExists('media-library/'.basename((string) $media->file_path));

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'media-references',
            'resource_id' => (string) $media->id,
            'action' => 'replace_file',
        ]);
    }

    public function test_resource_registry_contains_the_full_expected_v1_surface(): void
    {
        $expected = [
            'entities',
            'entity-aliases',
            'entity-notes',
            'entity-questions',
            'media-references',
            'entity-versions',
            'relationships',
            'group-relationships',
            'group-relationship-memberships',
            'faction-memberships',
            'collections',
            'collection-entities',
            'collection-documents',
            'glossary',
            'documents',
            'document-entities',
            'canon-references',
            'canon-reference-entities',
            'crossover-entry-points',
            'timelines',
            'timeline-entries',
            'timeline-placements',
            'character-states',
            'state-relationships',
            'concurrency-groups',
            'power-interactions',
            'power-interaction-instances',
            'location-containment',
            'location-control-records',
            'travel-routes',
            'galactic-regions',
            'knowledge-states',
            'secrets',
            'perception-states',
            'meta',
            'pipeline-items',
            'session-logs',
            'notion-notes',
            'notion-sync-mappings',
            'revisions',
        ];

        $actual = ApiResourceRegistry::slugs();
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_read_entities_resource_ability_is_supported(): void
    {
        Entity::factory()->create(['name' => 'Ability Fixture']);

        $this->withToken($this->assistantToken(['read:entities']))
            ->getJson('/api/v1/entities')
            ->assertOk();
    }

    public function test_write_secrets_resource_ability_is_supported(): void
    {
        $this->withToken($this->assistantToken(['write:secrets']))
            ->postJson('/api/v1/secrets', [
                'data' => [
                    'attributes' => [
                        'title' => 'Scoped Secret',
                        'secret_content' => ['type' => 'doc', 'content' => []],
                        'secret_type' => 'plan',
                    ],
                ],
                'meta' => [
                    'source' => 'phpunit',
                    'reason' => 'create scoped secret',
                ],
            ])
            ->assertCreated();
    }

    public function test_sync_notion_requires_the_explicit_sync_ability(): void
    {
        $this->withToken($this->assistantToken(['read:*']))
            ->postJson('/api/v1/notion-sync/documents?dry_run=1')
            ->assertForbidden();
    }

    public function test_entity_version_revision_show_and_secret_remove_known_by_endpoints_work_via_api(): void
    {
        $entity = Entity::factory()->publishable()->create([
            'name' => 'Versioned API Entity',
        ]);
        $knower = Entity::factory()->create();
        $secret = Secret::create([
            'title' => 'Known By Secret',
            'secret_content' => ['type' => 'doc', 'content' => []],
            'secret_type' => 'plan',
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [$knower->id],
            'exposure_risk' => 'medium',
            'status' => 'active',
        ]);

        $token = $this->assistantToken(['read:*', 'write:*', 'history:*']);

        $versionCreate = $this->withToken($token)->postJson("/api/v1/entities/{$entity->id}/versions", [
            'data' => [
                'attributes' => [
                    'version_label' => 'API Canon Drift',
                    'what_changed' => 'Assistant-authored delta.',
                    'why_changed' => 'Workflow validation.',
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'save entity version',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'entity-versions');

        $entityRevisionId = (int) Revision::query()
            ->where('resource_type', 'entities')
            ->where('resource_id', (string) $entity->id)
            ->where('action', 'save_version')
            ->latest('id')
            ->value('id');
        $versionId = (int) $versionCreate->json('data.id');

        $this->withToken($token)
            ->getJson("/api/v1/entities/{$entity->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $versionId);

        $this->withToken($token)
            ->getJson("/api/v1/entities/{$entity->id}/versions/{$versionId}")
            ->assertOk()
            ->assertJsonPath('data.attributes.version_label', 'API Canon Drift');

        $this->withToken($token)
            ->getJson("/api/v1/revisions/{$entityRevisionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $entityRevisionId)
            ->assertJsonPath('data.action', 'save_version');

        $this->withToken($token)->deleteJson("/api/v1/secrets/{$secret->id}/known-by/{$knower->id}", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'remove known by',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.known_by_entity_ids', []);
    }

    public function test_search_resource_filter_and_list_query_contracts_work(): void
    {
        $author = Entity::factory()->create();

        $alpha = Document::create([
            'title' => 'Alpha File',
            'document_type' => 'intelligence_report',
            'document_status' => 'public',
            'official_author_entity_id' => $author->id,
        ]);
        $beta = Document::create([
            'title' => 'Beta File',
            'document_type' => 'intelligence_report',
            'document_status' => 'suppressed',
            'official_author_entity_id' => $author->id,
        ]);

        $token = $this->assistantToken(['read:*', 'delete:*', 'write:*', 'history:*']);

        $this->withToken($token)
            ->getJson('/api/v1/search?search=Alpha&filter[resource]=documents')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'documents')
            ->assertJsonPath('data.0.attributes.title', 'Alpha File');

        $this->withToken($token)
            ->getJson('/api/v1/documents?filter[document_status]=suppressed&sort=title&page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.title', 'Beta File')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.filters.document_status', 'suppressed')
            ->assertJsonPath('meta.sort', 'title');

        $this->withToken($token)->deleteJson("/api/v1/documents/{$beta->id}", [
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'trash beta document',
            ],
        ])->assertOk();

        $this->withToken($token)
            ->getJson("/api/v1/documents/{$beta->id}?only_trashed=1")
            ->assertOk()
            ->assertJsonPath('data.id', $beta->id);

        $this->withToken($token)
            ->getJson('/api/v1/documents?only_trashed=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $beta->id);

        $this->withToken($token)
            ->getJson('/api/v1/documents?with_trashed=1&sort=title')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $alpha->id,
            ])
            ->assertJsonFragment([
                'id' => $beta->id,
            ]);
    }

    public function test_includes_are_returned_in_the_top_level_envelope(): void
    {
        $entity = Entity::factory()->create([
            'name' => 'Envelope Entity',
        ]);

        EntityAlias::query()->create([
            'entity_id' => $entity->id,
            'alias' => 'Cipher Name',
            'alias_type' => 'codename',
            'is_active' => true,
        ]);

        NotionNote::query()->create([
            'sync_resource' => 'entities',
            'notion_page_id' => 'page-envelope-entity',
            'noteable_type' => Entity::class,
            'noteable_id' => $entity->id,
            'content' => 'Top level include envelope note.',
            'content_hash' => sha1('Top level include envelope note.'),
            'last_synced_at' => now(),
        ]);

        $token = $this->assistantToken(['read:*']);

        $show = $this->withToken($token)
            ->getJson("/api/v1/entities/{$entity->id}?include=aliases,notion_note")
            ->assertOk();

        $showPayload = $show->json();

        $this->assertArrayNotHasKey('included', $showPayload['data']);
        $this->assertSame('Cipher Name', data_get($showPayload, 'included.aliases.0.alias'));
        $this->assertSame('Notion Notes', data_get($showPayload, 'included.notion_note.label'));

        $index = $this->withToken($token)
            ->getJson('/api/v1/entities?search=Envelope%20Entity&include=notion_note')
            ->assertOk();

        $indexPayload = $index->json();

        $this->assertArrayNotHasKey('included', $indexPayload['data'][0]);
        $this->assertSame('Notion Notes', data_get($indexPayload, "included.notion_note.{$entity->id}.label"));
    }

    public function test_non_soft_deletable_resources_return_json_conflicts(): void
    {
        $mapping = NotionSyncMapping::query()->create([
            'sync_resource' => 'documents',
            'notion_page_id' => 'page-non-soft-delete',
            'local_model_type' => Document::class,
            'local_model_id' => 1,
        ]);

        $this->withToken($this->assistantToken(['delete:*']))
            ->deleteJson("/api/v1/notion-sync-mappings/{$mapping->id}", [
                'meta' => [
                    'base_revision_id' => 0,
                    'source' => 'phpunit',
                    'reason' => 'attempt unsupported delete',
                ],
            ])
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Resource [notion-sync-mappings] does not support soft deletes in v1.',
            ]);
    }

    public function test_show_supports_only_trashed_and_promotes_current_revision_meta(): void
    {
        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $create = $this->withToken($token)->postJson('/api/v1/documents', [
            'data' => [
                'attributes' => [
                    'title' => 'Trashed API Record',
                    'document_type' => 'other',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create trashed show fixture',
            ],
        ])->assertCreated();

        $documentId = (int) $create->json('data.id');

        $this->withToken($token)->deleteJson("/api/v1/documents/{$documentId}", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('documents', $documentId),
                'source' => 'phpunit',
                'reason' => 'trash show fixture',
            ],
        ])->assertOk();

        $this->withToken($token)
            ->getJson("/api/v1/documents/{$documentId}?only_trashed=1")
            ->assertOk()
            ->assertJsonPath('data.id', $documentId)
            ->assertJsonPath('meta.current_revision_id', $this->currentRevisionId('documents', $documentId))
            ->assertJsonPath('meta.includes', []);

        $this->withToken($token)
            ->getJson("/api/v1/documents/{$documentId}")
            ->assertNotFound();
    }

    public function test_session_logs_are_searchable_through_the_api(): void
    {
        SessionLog::query()->create([
            'title' => 'Mirror Archive Debrief',
            'session_date' => now()->toDateString(),
            'external_tool' => 'notion',
            'focus_description' => 'Grey line follow-up and archive sweep.',
        ]);

        $this->withToken($this->assistantToken(['read:*']))
            ->getJson('/api/v1/session-logs?search=Mirror%20Archive')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.title', 'Mirror Archive Debrief');
    }

    public function test_group_relationship_faction_membership_and_collection_actions_work_via_api(): void
    {
        $faction = Entity::factory()->create(['entity_type' => 'faction']);
        $member = Entity::factory()->create(['entity_type' => 'character']);
        $loyalty = Entity::factory()->create(['entity_type' => 'faction']);
        $rosterMatch = Entity::factory()->create(['entity_type' => 'character']);

        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $groupCreate = $this->withToken($token)->postJson('/api/v1/group-relationships', [
            'data' => [
                'attributes' => [
                    'name' => 'API Circle',
                    'relationship_type' => 'alliance',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create group relationship',
            ],
        ])->assertCreated();

        $groupId = (int) $groupCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/group-relationships/{$groupId}/tension-charge", [
            'data' => [
                'attributes' => [
                    'new_charge' => TensionCharge::VOLATILE,
                    'reason' => 'group pressure',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('group-relationships', $groupId),
                'source' => 'phpunit',
                'reason' => 'raise group tension',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.current_tension_charge', TensionCharge::VOLATILE);

        $membershipCreate = $this->withToken($token)->postJson("/api/v1/group-relationships/{$groupId}/members", [
            'data' => [
                'attributes' => [
                    'role_in_group' => 'anchor',
                    'joined_era' => 'Year 1',
                ],
                'relationships' => [
                    'entity_id' => $member->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('group-relationships', $groupId),
                'source' => 'phpunit',
                'reason' => 'add member',
            ],
        ])->assertCreated();

        $membershipId = (int) $membershipCreate->json('data.id');

        $this->withToken($token)->deleteJson("/api/v1/group-relationships/{$groupId}/members/{$membershipId}", [
            'data' => [
                'attributes' => [
                    'left_era' => 'Year 2',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('group-relationship-memberships', $membershipId),
                'source' => 'phpunit',
                'reason' => 'remove member',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.is_active_member', false);

        $this->assertDatabaseHas('revisions', [
            'resource_type' => 'group-relationship-memberships',
            'resource_id' => (string) $membershipId,
            'action' => 'deactivate',
        ]);

        $membershipCreate = $this->withToken($token)->postJson('/api/v1/faction-memberships', [
            'data' => [
                'attributes' => [
                    'rank_or_role' => 'operative',
                    'membership_status' => 'active',
                ],
                'relationships' => [
                    'faction_entity_id' => $faction->id,
                    'member_entity_id' => $member->id,
                    'true_loyalty_entity_id' => $loyalty->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create faction membership',
            ],
        ])->assertCreated();

        $factionMembershipId = (int) $membershipCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/faction-memberships/{$factionMembershipId}/terminate", [
            'data' => [
                'attributes' => [
                    'left_era' => 'Year 3',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('faction-memberships', $factionMembershipId),
                'source' => 'phpunit',
                'reason' => 'terminate faction membership',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.membership_status', 'former');

        $collectionCreate = $this->withToken($token)->postJson('/api/v1/collections', [
            'data' => [
                'attributes' => [
                    'name' => 'API Character Roster',
                    'collection_type' => 'character_roster',
                    'collection_mode' => 'smart',
                    'rules' => [[
                        'field' => 'entity_type',
                        'operator' => 'equals',
                        'value' => 'character',
                    ]],
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create smart collection',
            ],
        ])->assertCreated();

        $collectionId = (int) $collectionCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/collections/{$collectionId}/sync", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('collections', $collectionId),
                'source' => 'phpunit',
                'reason' => 'sync smart collection',
            ],
        ])->assertOk()
            ->assertJsonPath('meta.synced_count', 2);

        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collectionId,
            'entity_id' => $member->id,
        ]);
        $this->assertDatabaseHas('collection_entities', [
            'collection_id' => $collectionId,
            'entity_id' => $rosterMatch->id,
        ]);
    }

    public function test_world_intelligence_and_meta_actions_cover_remaining_verbs(): void
    {
        $systemA = Entity::factory()->create(['entity_type' => 'power']);
        $systemB = Entity::factory()->create(['entity_type' => 'power']);
        $event = Entity::factory()->create(['entity_type' => 'event']);
        $observer = Entity::factory()->create(['entity_type' => 'character']);

        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*']);

        $interactionCreate = $this->withToken($token)->postJson('/api/v1/power-interactions', [
            'data' => [
                'attributes' => [
                    'interaction_name' => 'API Collision',
                    'knowledge_state' => 'rumored',
                    'danger_rating' => 'catastrophic',
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

        $interactionId = (int) $interactionCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/power-interactions/{$interactionId}/resolve", [
            'data' => [
                'attributes' => [
                    'resolution_notes' => ['type' => 'doc', 'content' => []],
                    'knowledge_state' => 'established',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('power-interactions', $interactionId),
                'source' => 'phpunit',
                'reason' => 'resolve interaction',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.unresolved_flag', false)
            ->assertJsonPath('data.attributes.knowledge_state', 'established');

        $this->withToken($token)->postJson("/api/v1/power-interactions/{$interactionId}/instances", [
            'data' => [
                'attributes' => [
                    'outcome_match' => 'confirmed',
                    'outcome_notes' => ['type' => 'doc', 'content' => []],
                    'observed_at_era' => 'Year 5',
                    'involved_entity_ids' => [$observer->id],
                ],
                'relationships' => [
                    'event_entity_id' => $event->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('power-interactions', $interactionId),
                'source' => 'phpunit',
                'reason' => 'record power interaction instance',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'power-interaction-instances');

        $knowledgeCreate = $this->withToken($token)->postJson('/api/v1/knowledge-states', [
            'data' => [
                'attributes' => [
                    'knowledge_type' => 'secret',
                    'accuracy' => 'true',
                    'current_belief_state' => 'believes',
                    'acquired_through' => 'observation',
                ],
                'relationships' => [
                    'knower_entity_id' => $observer->id,
                    'subject_entity_id' => $systemA->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create knowledge state',
            ],
        ])->assertCreated();

        $knowledgeId = (int) $knowledgeCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/knowledge-states/{$knowledgeId}/act-on", [
            'data' => [
                'attributes' => [
                    'action_notes' => ['type' => 'doc', 'content' => []],
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('knowledge-states', $knowledgeId),
                'source' => 'phpunit',
                'reason' => 'mark knowledge acted on',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.acted_on', true);

        $secretCreate = $this->withToken($token)->postJson('/api/v1/secrets', [
            'data' => [
                'attributes' => [
                    'title' => 'Remaining Secret',
                    'secret_content' => ['type' => 'doc', 'content' => []],
                    'secret_type' => 'plan',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create secret',
            ],
        ])->assertCreated();

        $secretId = (int) $secretCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/secrets/{$secretId}/holders", [
            'data' => [
                'relationships' => [
                    'entity_id' => $observer->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('secrets', $secretId),
                'source' => 'phpunit',
                'reason' => 'add secret holder',
            ],
        ])->assertOk()
            ->assertJsonFragment([
                'holder_entity_ids' => [$observer->id],
            ]);

        $this->withToken($token)->deleteJson("/api/v1/secrets/{$secretId}/holders/{$observer->id}", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('secrets', $secretId),
                'source' => 'phpunit',
                'reason' => 'remove secret holder',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.holder_entity_ids', []);

        $perceptionCreate = $this->withToken($token)->postJson('/api/v1/perception-states', [
            'data' => [
                'attributes' => [
                    'subject_type' => 'entity',
                    'subject_id' => $systemA->id,
                    'true_state' => ['status' => 'ruined'],
                    'perceived_state' => ['status' => 'stable'],
                    'divergence_level' => 'complete',
                    'revelation_risk' => 'medium',
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create perception state',
            ],
        ])->assertCreated();

        $perceptionId = (int) $perceptionCreate->json('data.id');

        $this->withToken($token)->postJson("/api/v1/perception-states/{$perceptionId}/immune", [
            'data' => [
                'relationships' => [
                    'entity_id' => $observer->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('perception-states', $perceptionId),
                'source' => 'phpunit',
                'reason' => 'add immune entity',
            ],
        ])->assertOk();

        $this->withToken($token)->deleteJson("/api/v1/perception-states/{$perceptionId}/immune/{$observer->id}", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('perception-states', $perceptionId),
                'source' => 'phpunit',
                'reason' => 'remove immune entity',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.immune_entity_ids', []);

        $this->withToken($token)->postJson("/api/v1/perception-states/{$perceptionId}/collapse", [
            'data' => [
                'attributes' => [
                    'era' => 'Year 6',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('perception-states', $perceptionId),
                'source' => 'phpunit',
                'reason' => 'collapse perception state',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.is_current', false)
            ->assertJsonPath('data.attributes.revealed_at_era', 'Year 6');

        $meta = Meta::query()->create([
            'title' => 'Meta Primary',
            'category' => Meta::CATEGORIES[0],
            'meta_note_type' => Meta::NOTE_TYPES[1],
        ]);
        $replacement = Meta::query()->create([
            'title' => 'Meta Replacement',
            'category' => Meta::CATEGORIES[1],
            'meta_note_type' => Meta::NOTE_TYPES[2],
        ]);

        $this->withToken($token)->postJson("/api/v1/meta/{$meta->id}/supersede", [
            'data' => [
                'attributes' => [
                    'supersession_reason' => 'API supersession',
                ],
                'relationships' => [
                    'superseded_by_meta_id' => $replacement->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => 0,
                'source' => 'phpunit',
                'reason' => 'supersede meta',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.superseded_by_meta_id', $replacement->id);

        $this->withToken($token)->postJson("/api/v1/meta/{$meta->id}/entities", [
            'data' => [
                'relationships' => [
                    'entity_id' => $observer->id,
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('meta', $meta->id),
                'source' => 'phpunit',
                'reason' => 'link meta entity',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('meta_entities', [
            'meta_id' => $meta->id,
            'entity_id' => $observer->id,
        ]);

        $this->withToken($token)->deleteJson("/api/v1/meta/{$meta->id}/entities/{$observer->id}", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('meta', $meta->id),
                'source' => 'phpunit',
                'reason' => 'unlink meta entity',
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('meta_entities', [
            'meta_id' => $meta->id,
            'entity_id' => $observer->id,
        ]);
    }

    public function test_lore_system_and_notion_sync_endpoints_work_via_api(): void
    {
        $author = Entity::factory()->create(['entity_type' => 'character']);
        $token = $this->assistantToken(['read:*', 'write:*', 'delete:*', 'history:*', 'sync:notion']);

        $documentCreate = $this->withToken($token)->postJson('/api/v1/documents', [
            'data' => [
                'attributes' => [
                    'title' => 'API Dossier',
                    'document_type' => 'intelligence_report',
                    'official_narrative' => ['type' => 'doc', 'content' => []],
                ],
                'relationships' => [
                    'official_author_entity_id' => $author->id,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create document',
            ],
        ])->assertCreated();

        $documentId = (int) $documentCreate->json('data.id');

        $this->withToken($token)
            ->getJson('/api/v1/documents?search=API%20Dossier&sort=title')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.title', 'API Dossier');

        $this->withToken($token)->patchJson("/api/v1/documents/{$documentId}", [
            'data' => [
                'attributes' => [
                    'document_status' => 'suppressed',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('documents', $documentId),
                'source' => 'phpunit',
                'reason' => 'suppress document',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.document_status', 'suppressed');

        $mappingCreate = $this->withToken($token)->postJson('/api/v1/notion-sync-mappings', [
            'data' => [
                'attributes' => [
                    'sync_resource' => 'documents',
                    'notion_page_id' => 'page-api-dossier',
                    'local_model_type' => Document::class,
                    'local_model_id' => $documentId,
                ],
            ],
            'meta' => [
                'source' => 'phpunit',
                'reason' => 'create notion sync mapping',
            ],
        ])->assertCreated();

        $mappingId = (int) $mappingCreate->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/notion-sync-mappings/{$mappingId}", [
            'data' => [
                'attributes' => [
                    'last_payload_hash' => 'hash-updated-by-api',
                ],
            ],
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('notion-sync-mappings', $mappingId),
                'source' => 'phpunit',
                'reason' => 'update notion sync mapping',
            ],
        ])->assertOk()
            ->assertJsonPath('data.attributes.last_payload_hash', 'hash-updated-by-api');

        $syncMock = Mockery::mock(NotionDataverseSyncService::class);
        $syncMock->shouldReceive('sync')
            ->once()
            ->with('documents', false, true)
            ->andReturn([
                'created' => 1,
                'updated' => 2,
                'skipped' => 3,
                'warnings' => [],
            ]);

        $this->app->instance(NotionDataverseSyncService::class, $syncMock);

        $this->withToken($token)
            ->postJson('/api/v1/notion-sync/documents?dry_run=1')
            ->assertOk()
            ->assertJsonPath('data.resource', 'documents')
            ->assertJsonPath('data.stats.updated', 2);

        $this->withToken($token)->deleteJson("/api/v1/documents/{$documentId}", [
            'meta' => [
                'base_revision_id' => $this->currentRevisionId('documents', $documentId),
                'source' => 'phpunit',
                'reason' => 'trash document',
            ],
        ])->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/documents?only_trashed=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentId);

        $this->withToken($token)
            ->getJson('/api/v1/trash')
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'documents',
                'id' => $documentId,
            ]);
    }

    private function assistantToken(array $abilities): string
    {
        $user = User::factory()->create();

        return $user->createToken('assistant', $abilities)->plainTextToken;
    }

    private function currentRevisionId(string $resource, int $recordId): int
    {
        return (int) Revision::query()
            ->forResource($resource, $recordId)
            ->max('id');
    }
}
