<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('run_dataverse_action')]
#[Description('Run one of Dataverse custom authoring actions such as publish, tension-charge, sync, timeline event placement, exposure, or pipeline advance.')]
class RunDataverseActionTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'action' => ['required', 'string', \Illuminate\Validation\Rule::in(DataverseMcpCatalog::actionNames())],
            'record_id' => $this->recordRule(),
            'secondary_id' => ['nullable', 'string'],
            'attributes' => ['nullable', 'array'],
            'relationships' => ['nullable', 'array'],
            'base_revision_id' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string'],
        ]);

        $action = DataverseMcpCatalog::action($validated['action']);
        $path = str_replace('{record}', $validated['record_id'], $action['path']);

        if (str_contains($path, '{secondary}')) {
            $secondaryId = $validated['secondary_id'] ?? null;

            if (! is_string($secondaryId) || trim($secondaryId) === '') {
                throw ValidationException::withMessages([
                    'secondary_id' => 'This action requires '.$action['secondary_key'].'.',
                ]);
            }

            $path = str_replace('{secondary}', $secondaryId, $path);
        }

        return $this->response(
            $this->gateway()->send(
                $action['method'],
                $path,
                $this->queryArray($request),
                $this->writePayload($request, true),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(DataverseMcpCatalog::actionNames())->description('Named custom action from the Dataverse MCP catalog.')->required(),
            'record_id' => $schema->string()->description('Primary record id used for the action route.')->required(),
            'secondary_id' => $schema->string()->description('Nested id for actions that target a membership, entity, or timeline entry.'),
            'attributes' => $schema->object()->description('Optional action attributes payload.'),
            'relationships' => $schema->object()->description('Optional action relationships payload.'),
            'base_revision_id' => $schema->integer()->description('Latest current_revision_id for optimistic concurrency.')->required(),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the action request.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the response record.'),
        ];
    }
}
