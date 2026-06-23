<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('replace_dataverse_media_file')]
#[Description('Replace the stored file bytes for an existing media-reference through the canonical /api/v1 media replace endpoint.')]
class ReplaceDataverseMediaFileTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $request->validate([
            'id' => $this->recordRule(),
            'base_revision_id' => ['required', 'integer', 'min:0'],
            'file_name' => ['required', 'string'],
            'content_base64' => ['required', 'string'],
            'mime_type' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'validate_only' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'POST',
                'media-references/'.(string) $request->get('id').'/replace-file',
                $this->queryArray($request),
                [
                    'data' => [
                        'file' => [
                            'name' => (string) $request->get('file_name'),
                            'content_base64' => (string) $request->get('content_base64'),
                            'mime_type' => $request->get('mime_type'),
                        ],
                    ],
                    'meta' => [
                        'base_revision_id' => (int) $request->integer('base_revision_id'),
                        'reason' => (string) $request->get('reason', ''),
                        'source' => (string) $request->get('source', (string) config('services.dataverse_mcp.source', 'mcp')),
                        'validate_only' => $request->boolean('validate_only'),
                    ],
                ],
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Existing media-reference id to update.')->required(),
            'base_revision_id' => $schema->integer()->description('Latest current_revision_id for optimistic concurrency.')->required(),
            'file_name' => $schema->string()->description('Original file name to preserve on the media record.')->required(),
            'content_base64' => $schema->string()->description('Base64 file content. Plain base64 and full data URLs are both accepted.')->required(),
            'mime_type' => $schema->string()->description('Optional MIME type hint such as image/png.'),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the file replacement.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the updated record response.'),
        ];
    }
}
