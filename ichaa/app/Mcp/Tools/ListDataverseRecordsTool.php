<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_dataverse_records')]
#[Description('List Dataverse records for a top-level resource with filters, search, sort, pagination, and trashed controls.')]
class ListDataverseRecordsTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => $this->resourceRule(DataverseMcpCatalog::resources()),
            'include' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_trashed' => ['nullable', 'boolean'],
            'only_trashed' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
        ]);

        return $this->response(
            $this->gateway()->send('GET', $validated['resource'], $this->queryArray($request))
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Top-level API resource slug.')->required(),
            'include' => $schema->string()->description('Optional comma-separated include list.'),
            'search' => $schema->string()->description('Optional text search term within the resource.'),
            'sort' => $schema->string()->description('Optional sort string such as name,-updated_at.'),
            'page' => $schema->integer()->description('Pagination page number.'),
            'per_page' => $schema->integer()->description('Page size between 1 and 100.'),
            'with_trashed' => $schema->boolean()->description('Include soft-deleted records alongside live records.'),
            'only_trashed' => $schema->boolean()->description('Return only soft-deleted records.'),
            'filters' => $schema->object()->description('Exact-match filter object keyed by allowed API fields.'),
        ];
    }
}
