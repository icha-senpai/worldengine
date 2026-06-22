<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('sync_dataverse_notion')]
#[Description('Trigger one of the Dataverse Notion sync pipelines through the canonical sync endpoint.')]
class SyncDataverseNotionTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'resource' => ['required', 'string', \Illuminate\Validation\Rule::in(DataverseMcpCatalog::notionSyncResources())],
            'include_drafts' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $query = [];

        if ($request->boolean('include_drafts')) {
            $query['include_drafts'] = 1;
        }

        if ($request->boolean('dry_run')) {
            $query['dry_run'] = 1;
        }

        return $this->response(
            $this->gateway()->send('POST', 'notion-sync/'.$validated['resource'], $query)
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(DataverseMcpCatalog::notionSyncResources())->description('Sync surface exposed by the Dataverse Notion sync service.')->required(),
            'include_drafts' => $schema->boolean()->description('Include draft data in the sync run.'),
            'dry_run' => $schema->boolean()->description('Run the sync without persisting changes.'),
        ];
    }
}
