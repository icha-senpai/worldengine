<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_dataverse')]
#[Description('Search canonical Dataverse records and Notion-backed note bodies through the authoring API search index.')]
class SearchDataverseTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['required', 'string'],
            'resource' => ['nullable', 'string', \Illuminate\Validation\Rule::in(DataverseMcpCatalog::resources())],
            'include' => ['nullable', 'string'],
        ]);

        $query = [
            'search' => $validated['search'],
        ];

        if (! empty($validated['resource'])) {
            $query['filter'] = ['resource_type' => $validated['resource']];
        }

        if (! empty($validated['include'])) {
            $query['include'] = $validated['include'];
        }

        return $this->response($this->gateway()->send('GET', 'search', $query));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Full text term to search across canonical records and Notion notes.')->required(),
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Optional resource slug to narrow the search surface.'),
            'include' => $schema->string()->description('Optional comma-separated include list to expand related records.'),
        ];
    }
}
