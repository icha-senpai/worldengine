<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('upload_dataverse_media')]
#[Description('Create a media-reference record from a base64 media payload through the canonical /api/v1 upload endpoint.')]
class UploadDataverseMediaTool extends DataverseTool
{
    public function handle(Request $request): \Laravel\Mcp\ResponseFactory
    {
        $request->validate([
            'attributes' => ['required', 'array'],
            'relationships' => ['nullable', 'array'],
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
                'media-references/upload',
                $this->queryArray($request),
                [
                    'data' => [
                        'attributes' => is_array($request->get('attributes')) ? $request->get('attributes') : [],
                        'relationships' => is_array($request->get('relationships')) ? $request->get('relationships') : [],
                        'file' => [
                            'name' => (string) $request->get('file_name'),
                            'content_base64' => (string) $request->get('content_base64'),
                            'mime_type' => $request->get('mime_type'),
                        ],
                    ],
                    'meta' => [
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
            'attributes' => $schema->object()->description('Media-reference attributes such as title, description, media_type, purpose, visibility, and content_classification.')->required(),
            'relationships' => $schema->object()->description('Attachment target relationships such as entity_id, meta_id, collection_id, or timeline_entry_id.'),
            'file_name' => $schema->string()->description('Original file name to preserve on the media record.')->required(),
            'content_base64' => $schema->string()->description('Base64 file content. Plain base64 and full data URLs are both accepted.')->required(),
            'mime_type' => $schema->string()->description('Optional MIME type hint such as image/png.'),
            'reason' => $schema->string()->description('Optional human reason recorded into revisions.'),
            'source' => $schema->string()->description('Optional source override. Defaults to DATAVERSE_MCP_SOURCE or mcp.'),
            'validate_only' => $schema->boolean()->description('Validate without persisting the media record.'),
            'include' => $schema->string()->description('Optional comma-separated include list for the created record response.'),
        ];
    }
}
