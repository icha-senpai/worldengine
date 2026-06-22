<?php

namespace Tests\Feature\System;

use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Services\NotionDataverseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionDataverseConnectionsSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_connections_sync_imports_relationships_from_notion(): void
    {
        config()->set('notion.api_token', 'test-token');
        config()->set('notion.dataverse.resources.relationships', 'relationships-db');

        $fromEntity = Entity::factory()->create(['name' => 'Mara Voss']);
        $toEntity = Entity::factory()->create(['name' => 'Aster Vale']);

        $fromPageId = '11111111-1111-1111-1111-111111111111';
        $toPageId = '22222222-2222-2222-2222-222222222222';
        $relationshipPageId = '33333333-3333-3333-3333-333333333333';

        NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => $fromPageId,
            'notion_parent_database_id' => 'entities-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $fromEntity->id,
            'last_synced_at' => now(),
        ]);

        NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => $toPageId,
            'notion_parent_database_id' => 'entities-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $toEntity->id,
            'last_synced_at' => now(),
        ]);

        Http::fake([
            'https://api.notion.com/v1/databases/relationships-db/query' => Http::response([
                'results' => [[
                    'id' => $relationshipPageId,
                    'last_edited_time' => '2026-06-22T00:00:00.000Z',
                    'properties' => [
                        'From Entity' => ['type' => 'relation', 'relation' => [['id' => $fromPageId]]],
                        'To Entity' => ['type' => 'relation', 'relation' => [['id' => $toPageId]]],
                        'Relationship Type' => $this->selectProperty('familial'),
                        'Tension Charge' => $this->selectProperty('negative'),
                        'Direction' => $this->selectProperty('mutual'),
                        'Notes' => $this->richTextProperty('They still move like siblings even when they are furious with each other.'),
                        'Sync State' => $this->selectProperty('ready'),
                        'Site Record ID' => $this->richTextProperty(null),
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$relationshipPageId}/children*" => Http::response([
                'results' => [
                    $this->paragraphBlockFragments([
                        $this->textFragment('This page body should land in '),
                        $this->textFragment('Notion Notes', ['italic' => true]),
                        $this->textFragment(', not relationship.notes 👀.'),
                    ]),
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages/*' => Http::response([
                'object' => 'page',
                'id' => 'patched-page',
            ]),
        ]);

        $stats = app(NotionDataverseSyncService::class)->sync('relationships');

        $this->assertSame([], $stats['warnings']);

        $relationship = Relationship::first();

        $this->assertNotNull($relationship);
        $this->assertSame($fromEntity->id, $relationship->from_entity_id);
        $this->assertSame($toEntity->id, $relationship->to_entity_id);
        $this->assertSame('familial', $relationship->relationship_type);
        $this->assertSame('negative', $relationship->current_tension_charge);
        $this->assertSame('mutual', $relationship->direction);
        $this->assertSame('doc', $relationship->notes['type'] ?? null);
        $this->assertDatabaseHas('notion_notes', [
            'noteable_type' => Relationship::class,
            'noteable_id' => $relationship->id,
            'sync_resource' => 'relationships',
        ]);
        $this->assertStringContainsString(
            '*Notion Notes*',
            (string) NotionNote::query()->first()?->content,
        );
        $this->assertStringContainsString(
            '👀',
            (string) NotionNote::query()->first()?->content,
        );
        $this->assertTrue($fromEntity->fresh()->has_relationships);
        $this->assertTrue($toEntity->fresh()->has_relationships);

        $this->assertSame(3, NotionSyncMapping::count());

        Http::assertSent(function (Request $request) use ($relationshipPageId, $relationship) {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), "/pages/{$relationshipPageId}")) {
                return false;
            }

            return data_get($request->data(), 'properties.Site Record ID.rich_text.0.text.content') === (string) $relationship->id
                && data_get($request->data(), 'properties.Sync State.select.name') === 'synced';
        });
    }

    public function test_connections_sync_accepts_required_suffix_on_notion_property_names(): void
    {
        config()->set('notion.api_token', 'test-token');
        config()->set('notion.dataverse.resources.relationships', 'relationships-db');

        $fromEntity = Entity::factory()->create(['name' => 'Nora Flint']);
        $toEntity = Entity::factory()->create(['name' => 'Iris March']);

        $fromPageId = 'aaaa1111-1111-1111-1111-111111111111';
        $toPageId = 'bbbb2222-2222-2222-2222-222222222222';
        $relationshipPageId = 'cccc3333-3333-3333-3333-333333333333';

        NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => $fromPageId,
            'notion_parent_database_id' => 'entities-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $fromEntity->id,
            'last_synced_at' => now(),
        ]);

        NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => $toPageId,
            'notion_parent_database_id' => 'entities-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $toEntity->id,
            'last_synced_at' => now(),
        ]);

        Http::fake([
            'https://api.notion.com/v1/databases/relationships-db/query' => Http::response([
                'results' => [[
                    'id' => $relationshipPageId,
                    'last_edited_time' => '2026-06-22T00:00:00.000Z',
                    'properties' => [
                        'From Entity (Required)' => ['type' => 'relation', 'relation' => [['id' => $fromPageId]]],
                        'To Entity (Required)' => ['type' => 'relation', 'relation' => [['id' => $toPageId]]],
                        'Relationship Type' => $this->selectProperty('familial'),
                        'Sync State (Required)' => $this->selectProperty('ready'),
                        'Site Record ID (Required)' => $this->richTextProperty(null),
                        'Last Synced (Required)' => ['type' => 'date', 'date' => null],
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$relationshipPageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages/*' => Http::response([
                'object' => 'page',
                'id' => 'patched-page',
            ]),
        ]);

        $stats = app(NotionDataverseSyncService::class)->sync('relationships');

        $this->assertSame([], $stats['warnings']);

        $relationship = Relationship::first();

        $this->assertNotNull($relationship);
        $this->assertSame($fromEntity->id, $relationship->from_entity_id);
        $this->assertSame($toEntity->id, $relationship->to_entity_id);
        $this->assertSame('familial', $relationship->relationship_type);

        Http::assertSent(function (Request $request) use ($relationshipPageId, $relationship) {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), "/pages/{$relationshipPageId}")) {
                return false;
            }

            return data_get($request->data(), 'properties.Site Record ID (Required).rich_text.0.text.content') === (string) $relationship->id
                && data_get($request->data(), 'properties.Sync State (Required).select.name') === 'synced'
                && data_get($request->data(), 'properties.Last Synced (Required).date.start') !== null;
        });
    }

    private function richTextProperty(?string $value): array
    {
        return [
            'type' => 'rich_text',
            'rich_text' => $this->textFragments($value),
        ];
    }

    private function selectProperty(?string $value): array
    {
        return [
            'type' => 'select',
            'select' => $value ? ['name' => $value] : null,
        ];
    }

    private function textFragments(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return [$this->textFragment($value)];
    }

    private function textFragment(string $value, array $annotations = []): array
    {
        return [
            'type' => 'text',
            'plain_text' => $value,
            'annotations' => array_merge([
                'bold' => false,
                'italic' => false,
                'strikethrough' => false,
                'underline' => false,
                'code' => false,
                'color' => 'default',
            ], $annotations),
            'text' => [
                'content' => $value,
            ],
        ];
    }

    private function paragraphBlock(string $value): array
    {
        return $this->paragraphBlockFragments($this->textFragments($value));
    }

    private function paragraphBlockFragments(array $fragments): array
    {
        return [
            'id' => (string) fake()->uuid(),
            'type' => 'paragraph',
            'has_children' => false,
            'paragraph' => [
                'rich_text' => $fragments,
            ],
        ];
    }
}
