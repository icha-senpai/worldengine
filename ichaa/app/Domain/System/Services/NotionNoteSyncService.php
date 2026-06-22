<?php

namespace App\Domain\System\Services;

use App\Domain\System\Models\NotionNote;
use App\Domain\System\Models\NotionSyncMapping;
use Illuminate\Database\Eloquent\Model;

class NotionNoteSyncService
{
    private const CONTENT_HASH_VERSION = 'v2';

    public function __construct(
        private readonly NotionClient $client,
        private readonly NotionPropertyMapper $mapper,
    ) {}

    public function shouldSyncPageBody(array $page, ?NotionSyncMapping $mapping): bool
    {
        $pageId = $this->mapper->pageId($page);

        if (blank($pageId)) {
            return false;
        }

        $note = NotionNote::query()->forPage($pageId)->first();

        if (! $note) {
            return true;
        }

        if (! str_starts_with((string) $note->content_hash, self::CONTENT_HASH_VERSION.':')) {
            return true;
        }

        $pageLastEditedAt = $this->mapper->lastEditedAt($page);
        $mappedLastEditedAt = $mapping?->notion_last_edited_at;

        if (! $pageLastEditedAt || ! $mappedLastEditedAt) {
            return true;
        }

        return ! $pageLastEditedAt->equalTo($mappedLastEditedAt);
    }

    public function syncPageBody(string $resource, array $page, Model $model): bool
    {
        $pageId = $this->mapper->pageId($page);

        if (blank($pageId)) {
            return false;
        }

        $content = trim($this->renderBlocks(
            $this->client->retrieveBlockChildren($pageId)
        ));

        $note = NotionNote::query()->forPage($pageId)->first();

        if (blank($content)) {
            if (! $note) {
                return false;
            }

            $note->delete();

            return true;
        }

        $contentHash = $this->versionedContentHash($content);
        $payload = [
            'sync_resource' => $resource,
            'noteable_type' => $model::class,
            'noteable_id' => $model->getKey(),
            'content' => $content,
            'content_hash' => $contentHash,
            'notion_last_edited_at' => $this->mapper->lastEditedAt($page),
            'last_synced_at' => now(),
        ];

        if (
            $note
            && $note->noteable_type === $model::class
            && (int) $note->noteable_id === (int) $model->getKey()
            && $note->content_hash === $contentHash
        ) {
            $note->update([
                'sync_resource' => $resource,
                'notion_last_edited_at' => $payload['notion_last_edited_at'],
                'last_synced_at' => $payload['last_synced_at'],
            ]);

            return false;
        }

        NotionNote::query()->updateOrCreate(
            ['notion_page_id' => $pageId],
            $payload,
        );

        return true;
    }

    private function renderBlocks(array $blocks, int $depth = 0): string
    {
        $lines = [];

        foreach ($blocks as $index => $block) {
            $type = $block['type'] ?? null;

            if (! is_string($type)) {
                continue;
            }

            $line = $this->renderBlockLine($block, $type, $depth, $index);

            if ($line !== null && $line !== '') {
                $lines[] = $line;
            }

            if (($block['has_children'] ?? false) === true) {
                $children = $this->client->retrieveBlockChildren((string) ($block['id'] ?? ''));
                $childText = trim($this->renderBlocks($children, $depth + 1));

                if ($childText !== '') {
                    $lines[] = $childText;
                }
            }
        }

        return preg_replace("/\n{3,}/", "\n\n", trim(implode("\n", $lines))) ?? '';
    }

    private function renderBlockLine(array $block, string $type, int $depth, int $index): ?string
    {
        $text = trim($this->renderRichText($block[$type]['rich_text'] ?? []));
        $indent = str_repeat('  ', $depth);

        return match ($type) {
            'heading_1' => $text !== '' ? "{$indent}# {$text}" : null,
            'heading_2' => $text !== '' ? "{$indent}## {$text}" : null,
            'heading_3' => $text !== '' ? "{$indent}### {$text}" : null,
            'bulleted_list_item' => $text !== '' ? "{$indent}- {$text}" : null,
            'numbered_list_item' => $text !== '' ? "{$indent}".($index + 1).". {$text}" : null,
            'to_do' => $text !== '' ? "{$indent}[".(($block[$type]['checked'] ?? false) ? 'x' : ' ')."] {$text}" : null,
            'quote' => $text !== '' ? "{$indent}> {$text}" : null,
            'callout' => $text !== '' ? "{$indent}Note: {$text}" : null,
            'code' => $text !== '' ? "{$indent}```\n{$text}\n```" : null,
            'divider' => "{$indent}---",
            'paragraph', 'toggle' => $text !== '' ? "{$indent}{$text}" : null,
            default => $this->fallbackLine($block, $type, $indent),
        };
    }

    private function fallbackLine(array $block, string $type, string $indent): ?string
    {
        $caption = trim($this->renderRichText(data_get($block, "{$type}.caption", [])));

        if ($caption !== '') {
            return "{$indent}[{$this->labelFor($type)}] {$caption}";
        }

        return match ($type) {
            'image', 'video', 'file', 'pdf', 'bookmark', 'embed' => "{$indent}[{$this->labelFor($type)}]",
            default => null,
        };
    }

    private function plainText(array $chunks): string
    {
        return collect($chunks)
            ->map(static fn (array $chunk) => $chunk['plain_text'] ?? '')
            ->implode('');
    }

    private function renderRichText(array $chunks): string
    {
        return collect($chunks)
            ->map(function (array $chunk): string {
                $text = $chunk['plain_text'] ?? '';

                if ($text === '') {
                    return '';
                }

                $annotations = $chunk['annotations'] ?? [];
                $href = $chunk['href'] ?? data_get($chunk, 'text.link.url');

                if (($annotations['code'] ?? false) === true) {
                    $text = "`{$text}`";
                } else {
                    if (($annotations['bold'] ?? false) === true) {
                        $text = "**{$text}**";
                    }

                    if (($annotations['italic'] ?? false) === true) {
                        $text = "*{$text}*";
                    }

                    if (($annotations['strikethrough'] ?? false) === true) {
                        $text = "~~{$text}~~";
                    }
                }

                if (filled($href)) {
                    $text = "[{$text}]({$href})";
                }

                return $text;
            })
            ->implode('');
    }

    private function labelFor(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }

    private function versionedContentHash(string $content): string
    {
        return self::CONTENT_HASH_VERSION.':'.hash('sha256', $content);
    }
}
