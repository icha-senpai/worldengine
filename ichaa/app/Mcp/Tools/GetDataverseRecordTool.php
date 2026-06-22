<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_dataverse_record')]
#[Description('Fetch one canonical Dataverse record by resource slug and integer id.')]
class GetDataverseRecordTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => $this->resourceRule(DataverseMcpCatalog::resources()),
            'id' => $this->recordRule(),
            'include' => ['nullable', 'string'],
            'with_trashed' => ['nullable', 'boolean'],
            'only_trashed' => ['nullable', 'boolean'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'GET',
                "{$validated['resource']}/{$validated['id']}",
                $this->queryArray($request),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Top-level API resource slug.')->required(),
            'id' => $schema->string()->description('Public integer identifier for the record.')->required(),
            'include' => $schema->string()->description('Optional comma-separated include list.'),
            'with_trashed' => $schema->boolean()->description('Allow resolving soft-deleted records too.'),
            'only_trashed' => $schema->boolean()->description('Require the resolved record to be soft deleted.'),
        ];
    }
}
