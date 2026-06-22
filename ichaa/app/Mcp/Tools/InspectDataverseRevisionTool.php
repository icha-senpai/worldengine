<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('inspect_dataverse_revision')]
#[Description('Show one revision or compare two revisions through the Dataverse revision endpoints.')]
class InspectDataverseRevisionTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(['show', 'compare'])],
            'revision_id' => ['required_if:mode,show', 'nullable', 'integer', 'min:1'],
            'left_revision_id' => ['required_if:mode,compare', 'nullable', 'integer', 'min:1'],
            'right_revision_id' => ['required_if:mode,compare', 'nullable', 'integer', 'min:1'],
        ]);

        $result = $validated['mode'] === 'show'
            ? $this->gateway()->send('GET', 'revisions/'.$validated['revision_id'])
            : $this->gateway()->send('GET', 'revisions/compare', [
                'left' => (int) $validated['left_revision_id'],
                'right' => (int) $validated['right_revision_id'],
            ]);

        return $this->response($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(['show', 'compare'])->description('Inspect one revision or compare two revisions.')->required(),
            'revision_id' => $schema->integer()->description('Revision id for show mode.'),
            'left_revision_id' => $schema->integer()->description('Left revision id for compare mode.'),
            'right_revision_id' => $schema->integer()->description('Right revision id for compare mode.'),
        ];
    }
}
