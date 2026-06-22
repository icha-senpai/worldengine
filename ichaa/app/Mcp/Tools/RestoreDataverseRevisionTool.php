<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_dataverse_revision')]
#[Description('Restore a record to the after_payload state captured by a specific revision.')]
class RestoreDataverseRevisionTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'revision_id' => ['required', 'integer', 'min:1'],
            'base_revision_id' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'POST',
                'revisions/'.$validated['revision_id'].'/restore',
                $this->queryArray($request),
                $this->writePayload($request, true),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'revision_id' => $schema->integer()->description('Revision id to restore from.')->required(),
            'base_revision_id' => $schema->integer()->description('Latest current_revision_id for optimistic concurrency.')->required(),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the restore request.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the restored record response.'),
        ];
    }
}
