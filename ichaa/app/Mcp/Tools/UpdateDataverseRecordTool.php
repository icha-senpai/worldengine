<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_dataverse_record')]
#[Description('Update an existing Dataverse record through the canonical /api/v1 resource endpoint.')]
class UpdateDataverseRecordTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => $this->resourceRule(DataverseMcpCatalog::resources()),
            'id' => $this->recordRule(),
            'attributes' => ['nullable', 'array'],
            'relationships' => ['nullable', 'array'],
            'base_revision_id' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'PATCH',
                "{$validated['resource']}/{$validated['id']}",
                $this->queryArray($request),
                $this->writePayload($request, true),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Top-level API resource slug.')->required(),
            'id' => $schema->string()->description('Public integer identifier for the record.')->required(),
            'attributes' => $schema->object()->description('Attribute changes to apply.'),
            'relationships' => $schema->object()->description('Relationship changes to apply.'),
            'base_revision_id' => $schema->integer()->description('Latest current_revision_id for optimistic concurrency.')->required(),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the update request.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the updated record response.'),
        ];
    }
}
