<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_dataverse_record')]
#[Description('Create a new Dataverse record through the canonical /api/v1 resource endpoint.')]
class CreateDataverseRecordTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => $this->resourceRule(DataverseMcpCatalog::resources()),
            'attributes' => ['required', 'array'],
            'relationships' => ['nullable', 'array'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'POST',
                $validated['resource'],
                $this->queryArray($request),
                $this->writePayload($request, false),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::resources())->description('Top-level API resource slug.')->required(),
            'attributes' => $schema->object()->description('Record attributes that match the resource write contract.')->required(),
            'relationships' => $schema->object()->description('Relationship payload keyed by relationship names or *_id fields.'),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the create request.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the created record response.'),
        ];
    }
}
