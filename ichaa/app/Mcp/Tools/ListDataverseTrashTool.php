<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_dataverse_trash')]
#[Description('List soft-deleted records across Dataverse resources.')]
class ListDataverseTrashTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $request->validate([
            'include' => ['nullable', 'string'],
        ]);

        return $this->response(
            $this->gateway()->send('GET', 'trash', $this->queryArray($request))
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include' => $schema->string()->description('Optional comma-separated include list for trashed records.'),
        ];
    }
}
