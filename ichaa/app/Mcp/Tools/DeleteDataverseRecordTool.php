<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_dataverse_record')]
#[Description('Soft delete a Dataverse record through the canonical /api/v1 resource endpoint.')]
class DeleteDataverseRecordTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => $this->resourceRule(DataverseMcpCatalog::resources()),
            'id' => $this->recordRule(),
            'base_revision_id' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'DELETE',
                "{$validated['resource']}/{$validated['id']}",
                [],
                $this->writePayload($request, true),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Top-level API resource slug.')->required(),
            'id' => $schema->string()->description('Public integer identifier for the record.')->required(),
            'base_revision_id' => $schema->integer()->description('Latest current_revision_id for optimistic concurrency.')->required(),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the delete request.'),
        ];
    }
}
