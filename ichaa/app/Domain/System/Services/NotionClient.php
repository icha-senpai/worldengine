<?php

namespace App\Domain\System\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\CarbonInterface;
use RuntimeException;

class NotionClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('notion.api_token'));
    }

    public function queryDatabase(string $databaseId): array
    {
        $pages = [];
        $cursor = null;

        do {
            $payload = [
                'page_size' => 100,
            ];

            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $response = $this->request()
                ->post("/databases/{$databaseId}/query", $payload)
                ->throw()
                ->json();

            $pages = array_merge($pages, $response['results'] ?? []);
            $cursor = $response['next_cursor'] ?? null;
        } while (($response['has_more'] ?? false) && $cursor);

        return $pages;
    }

    public function updatePageProperties(string $pageId, array $properties): void
    {
        $this->request()
            ->patch("/pages/{$pageId}", [
                'properties' => $properties,
            ])
            ->throw();
    }

    public function retrieveBlockChildren(string $blockId): array
    {
        $blocks = [];
        $cursor = null;

        do {
            $response = $this->request()
                ->get("/blocks/{$blockId}/children", array_filter([
                    'page_size' => 100,
                    'start_cursor' => $cursor,
                ], static fn ($value) => filled($value)))
                ->throw()
                ->json();

            $blocks = array_merge($blocks, $response['results'] ?? []);
            $cursor = $response['next_cursor'] ?? null;
        } while (($response['has_more'] ?? false) && $cursor);

        return $blocks;
    }

    public function richTextProperty(?string $value): array
    {
        return [
            'rich_text' => blank($value)
                ? []
                : [[
                    'type' => 'text',
                    'text' => [
                        'content' => $value,
                    ],
                ]],
        ];
    }

    public function selectProperty(?string $value): array
    {
        return [
            'select' => blank($value) ? null : ['name' => $value],
        ];
    }

    public function dateProperty(CarbonInterface|string|null $value): array
    {
        return [
            'date' => blank($value)
                ? null
                : [
                    'start' => $value instanceof CarbonInterface
                        ? $value->toDateString()
                        : (string) $value,
                ],
        ];
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('NOTION_API_TOKEN is not configured.');
        }

        return $this->http
            ->baseUrl(rtrim((string) config('notion.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('notion.api_token'))
            ->withHeaders([
                'Notion-Version' => (string) config('notion.version'),
            ]);
    }
}
