<?php

namespace Tests\Feature\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\System\Models\NotionNote;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\System\Models\NotionSyncMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionIdentitySyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_sync_imports_notion_entities_and_subresources(): void
    {
        config()->set('notion.api_token', 'test-token');
        config()->set('notion.dataverse.resources.entities', 'entities-db');
        config()->set('notion.dataverse.resources.entity_aliases', 'aliases-db');
        config()->set('notion.dataverse.resources.entity_notes', 'notes-db');
        config()->set('notion.dataverse.resources.entity_questions', 'questions-db');

        $entityPageId = '11111111-1111-1111-1111-111111111111';
        $aliasPageId = '22222222-2222-2222-2222-222222222222';
        $notePageId = '33333333-3333-3333-3333-333333333333';
        $questionPageId = '44444444-4444-4444-4444-444444444444';

        Http::fake([
            'https://api.notion.com/v1/databases/entities-db/query' => Http::response([
                'results' => [[
                    'id' => $entityPageId,
                    'last_edited_time' => '2026-06-22T00:00:00.000Z',
                    'properties' => [
                        'Entity Name' => $this->titleProperty('Seraphine Vale'),
                        'Entity Type' => $this->selectProperty('character'),
                        'Universe / Origin' => $this->richTextProperty('Harry Potter | native'),
                        'Source Universes' => $this->multiSelectProperty(['Harry Potter']),
                        'Origin Type' => $this->selectProperty('native'),
                        'Visibility' => $this->selectProperty('public_knowledge'),
                        'Summary' => $this->richTextProperty('A central character in the setting.'),
                        'Published' => ['type' => 'checkbox', 'checkbox' => false],
                        'Sync State' => ['type' => 'select', 'select' => ['name' => 'ready']],
                        'Site Record ID' => $this->richTextProperty(null),
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/databases/aliases-db/query' => Http::response([
                'results' => [[
                    'id' => $aliasPageId,
                    'last_edited_time' => '2026-06-22T00:01:00.000Z',
                    'properties' => [
                        'Alias' => $this->titleProperty('Silent Heir'),
                        'Entity' => ['type' => 'relation', 'relation' => [['id' => $entityPageId]]],
                        'Alias Type' => $this->selectProperty('title'),
                        'Summary' => $this->richTextProperty('Court title used before the transformation.'),
                        'Sync State' => ['type' => 'select', 'select' => ['name' => 'ready']],
                        'Site Record ID' => $this->richTextProperty(null),
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/databases/notes-db/query' => Http::response([
                'results' => [[
                    'id' => $notePageId,
                    'last_edited_time' => '2026-06-22T00:02:00.000Z',
                    'properties' => [
                        'Note Title' => $this->titleProperty('Transformation thoughts'),
                        'Entity' => ['type' => 'relation', 'relation' => [['id' => $entityPageId]]],
                        'Content' => $this->richTextProperty('This is the scratch pad note body.'),
                        'Sync State' => ['type' => 'select', 'select' => ['name' => 'ready']],
                        'Site Record ID' => $this->richTextProperty(null),
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/databases/questions-db/query' => Http::response([
                'results' => [[
                    'id' => $questionPageId,
                    'last_edited_time' => '2026-06-22T00:03:00.000Z',
                    'properties' => [
                        'Question' => $this->titleProperty('Does Seraphine still feel grief after the transformation?'),
                        'Entity' => ['type' => 'relation', 'relation' => [['id' => $entityPageId]]],
                        'Answer' => $this->richTextProperty('Not in the same way she once did.'),
                        'Question Status' => $this->selectProperty('resolved'),
                        'Notes' => $this->richTextProperty('This blocks later characterization work.'),
                        'Sync State' => ['type' => 'select', 'select' => ['name' => 'ready']],
                        'Site Record ID' => $this->richTextProperty(null),
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$entityPageId}/children*" => Http::response([
                'results' => [
                    $this->headingBlock('The Hook ✨', 'heading_2'),
                    $this->paragraphBlockFragments([
                        $this->textFragment('Seraphine keeps a '),
                        $this->textFragment('second ledger', ['bold' => true]),
                        $this->textFragment(' in her own head 😈.'),
                    ]),
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$aliasPageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$notePageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$questionPageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages/*' => Http::response([
                'object' => 'page',
                'id' => 'patched-page',
            ]),
        ]);

        $this->artisan('notion:sync-dataverse identity')
            ->assertExitCode(0);

        $entity = Entity::where('name', 'Seraphine Vale')->first();

        $this->assertNotNull($entity);
        $this->assertSame('character', $entity->entity_type);
        $this->assertSame(VisibilityLevel::PUBLIC_KNOWLEDGE, $entity->visibility);
        $this->assertNotNull($entity->published_at);
        $this->assertSame(['Harry Potter'], $entity->source_universes);

        $this->assertDatabaseHas('entity_aliases', [
            'entity_id' => $entity->id,
            'alias' => 'Silent Heir',
            'alias_type' => 'title',
        ]);

        $this->assertDatabaseHas('entity_notes', [
            'entity_id' => $entity->id,
            'note_label' => 'Transformation thoughts',
            'content' => 'This is the scratch pad note body.',
        ]);

        $this->assertDatabaseHas('entity_questions', [
            'entity_id' => $entity->id,
            'question' => 'Does Seraphine still feel grief after the transformation?',
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('notion_notes', [
            'noteable_type' => Entity::class,
            'noteable_id' => $entity->id,
            'sync_resource' => 'entities',
        ]);
        $this->assertStringContainsString(
            '## The Hook ✨',
            (string) NotionNote::query()->first()?->content,
        );
        $this->assertStringContainsString(
            '**second ledger**',
            (string) NotionNote::query()->first()?->content,
        );
        $this->assertStringContainsString(
            '😈',
            (string) NotionNote::query()->first()?->content,
        );

        $this->assertSame(4, NotionSyncMapping::count());
        $this->assertTrue($entity->fresh()->has_aliases);

        Http::assertSentCount(12);
        Http::assertSent(function (Request $request) use ($entityPageId, $entity) {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), "/pages/{$entityPageId}")) {
                return false;
            }

            return data_get($request->data(), 'properties.Site Record ID.rich_text.0.text.content') === (string) $entity->id
                && data_get($request->data(), 'properties.Sync State.select.name') === 'synced';
        });
    }

    public function test_identity_sync_accepts_required_suffix_on_notion_property_names(): void
    {
        config()->set('notion.api_token', 'test-token');
        config()->set('notion.dataverse.resources.entities', 'entities-db');

        $entityPageId = 'aaaaaaaa-1111-1111-1111-111111111111';

        Http::fake([
            'https://api.notion.com/v1/databases/entities-db/query' => Http::response([
                'results' => [[
                    'id' => $entityPageId,
                    'last_edited_time' => '2026-06-22T00:00:00.000Z',
                    'properties' => [
                        'Entity Name (Required)' => $this->titleProperty('Lyra Ashdown'),
                        'Entity Type (Required)' => $this->selectProperty('character'),
                        'Origin Type (Required)' => $this->selectProperty('native'),
                        'Summary' => $this->richTextProperty('A test entity imported through renamed Notion fields.'),
                        'Sync State (Required)' => ['type' => 'select', 'select' => ['name' => 'ready']],
                        'Site Record ID (Required)' => $this->richTextProperty(null),
                        'Last Synced (Required)' => ['type' => 'date', 'date' => null],
                    ],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            "https://api.notion.com/v1/blocks/{$entityPageId}/children*" => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'https://api.notion.com/v1/pages/*' => Http::response([
                'object' => 'page',
                'id' => 'patched-page',
            ]),
        ]);

        $this->artisan('notion:sync-dataverse entities')
            ->assertExitCode(0);

        $entity = Entity::where('name', 'Lyra Ashdown')->first();

        $this->assertNotNull($entity);
        $this->assertSame('character', $entity->entity_type);

        Http::assertSent(function (Request $request) use ($entityPageId, $entity) {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), "/pages/{$entityPageId}")) {
                return false;
            }

            return data_get($request->data(), 'properties.Site Record ID (Required).rich_text.0.text.content') === (string) $entity->id
                && data_get($request->data(), 'properties.Sync State (Required).select.name') === 'synced'
                && data_get($request->data(), 'properties.Last Synced (Required).date.start') !== null;
        });
    }

    private function titleProperty(?string $value): array
    {
        return [
            'type' => 'title',
            'title' => $this->textFragments($value),
        ];
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

    private function multiSelectProperty(array $values): array
    {
        return [
            'type' => 'multi_select',
            'multi_select' => collect($values)
                ->map(static fn (string $value) => ['name' => $value])
                ->all(),
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

    private function headingBlock(string $value, string $type = 'heading_2'): array
    {
        return [
            'id' => (string) fake()->uuid(),
            'type' => $type,
            'has_children' => false,
            $type => [
                'rich_text' => $this->textFragments($value),
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
