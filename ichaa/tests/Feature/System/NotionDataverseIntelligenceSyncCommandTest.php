<?php

namespace Tests\Feature\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\System\Models\NotionSyncMapping;
use App\Domain\System\Services\NotionDataverseSyncService;
use App\Domain\Temporal\Models\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionDataverseIntelligenceSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_intelligence_sync_imports_event_perception_states_by_matching_timeline_entry(): void
    {
        config()->set('notion.api_token', 'test-token');
        config()->set('notion.dataverse.resources.perception_states', 'perception-db');
        config()->set('notion.dataverse.resources.timelines', 'timelines-db');

        $eventEntity = Entity::factory()->create([
            'name' => 'The Night the Veil Broke',
            'entity_type' => 'event',
        ]);
        $mainTimeline = Entity::factory()->create([
            'name' => 'Main Timeline',
            'entity_type' => 'timeline',
        ]);
        $mirrorTimeline = Entity::factory()->create([
            'name' => 'Mirror Timeline',
            'entity_type' => 'timeline',
        ]);

        $wrongEntry = Timeline::create([
            'timeline_id' => $mirrorTimeline->id,
            'event_entity_id' => $eventEntity->id,
            'entry_label' => 'Mirror version',
        ]);
        $expectedEntry = Timeline::create([
            'timeline_id' => $mainTimeline->id,
            'event_entity_id' => $eventEntity->id,
            'entry_label' => 'Main timeline version',
        ]);

        $eventPageId = '11111111-1111-1111-1111-111111111111';
        $timelinePageId = '22222222-2222-2222-2222-222222222222';
        $perceptionPageId = '33333333-3333-3333-3333-333333333333';

        NotionSyncMapping::create([
            'sync_resource' => 'entities',
            'notion_page_id' => $eventPageId,
            'notion_parent_database_id' => 'entities-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $eventEntity->id,
            'last_synced_at' => now(),
        ]);

        NotionSyncMapping::create([
            'sync_resource' => 'timelines',
            'notion_page_id' => $timelinePageId,
            'notion_parent_database_id' => 'timelines-db',
            'local_model_type' => Entity::class,
            'local_model_id' => $mainTimeline->id,
            'last_synced_at' => now(),
        ]);

        Http::fake([
            'https://api.notion.com/v1/databases/perception-db/query' => Http::response([
                'results' => [[
                    'id' => $perceptionPageId,
                    'last_edited_time' => '2026-06-22T00:00:00.000Z',
                    'properties' => [
                        'Subject Type (Required)' => $this->selectProperty('event'),
                        'Subject Entity' => ['type' => 'relation', 'relation' => [['id' => $eventPageId]]],
                        'Timeline' => ['type' => 'relation', 'relation' => [['id' => $timelinePageId]]],
                        'Divergence Level (Required)' => $this->selectProperty('significant'),
                        'True State (Required)' => $this->richTextProperty('The event was engineered by the crown.'),
                        'Perceived State (Required)' => $this->richTextProperty('Everyone thinks it was a natural collapse.'),
                        'Sync State (Required)' => $this->selectProperty('ready'),
                        'Site Record ID (Required)' => $this->richTextProperty(null),
                        'Last Synced (Required)' => ['type' => 'date', 'date' => null],
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$perceptionPageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages/*' => Http::response([
                'object' => 'page',
                'id' => 'patched-page',
            ]),
        ]);

        $stats = app(NotionDataverseSyncService::class)->sync('perception_states');

        $this->assertSame([], $stats['warnings']);
        $this->assertSame(1, $stats['created']);

        $state = PerceptionState::query()->first();

        $this->assertNotNull($state);
        $this->assertSame('event', $state->subject_type);
        $this->assertSame($expectedEntry->id, $state->subject_id);
        $this->assertNotSame($wrongEntry->id, $state->subject_id);
        $this->assertSame('significant', $state->divergence_level);

        Http::assertSent(function (Request $request) use ($perceptionPageId, $state) {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), "/pages/{$perceptionPageId}")) {
                return false;
            }

            return data_get($request->data(), 'properties.Site Record ID (Required).rich_text.0.text.content') === (string) $state->id
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
}
