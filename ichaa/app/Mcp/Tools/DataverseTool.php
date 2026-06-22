<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\DataverseMcpApiGateway;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class DataverseTool extends Tool
{
    protected function gateway(): DataverseMcpApiGateway
    {
        return app(DataverseMcpApiGateway::class);
    }

    protected function response(array $result): ResponseFactory
    {
        return Response::structured($result)
            ->withMeta('http_status', $result['status'] ?? null);
    }

    protected function resourceRule(array $resources): array
    {
        return ['required', 'string', Rule::in($resources)];
    }

    protected function recordRule(): array
    {
        return ['required', 'string'];
    }

    protected function queryArray(Request $request, array $base = []): array
    {
        $query = $base;

        foreach (['include', 'search', 'sort'] as $key) {
            $value = $request->get($key);

            if (is_string($value) && trim($value) !== '') {
                $query[$key] = trim($value);
            }
        }

        foreach (['page', 'per_page'] as $key) {
            $value = $request->get($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (int) $value;
            }
        }

        if ($request->boolean('with_trashed')) {
            $query['with_trashed'] = 1;
        }

        if ($request->boolean('only_trashed')) {
            $query['only_trashed'] = 1;
        }

        $filters = $request->get('filters', []);

        if (is_array($filters) && $filters !== []) {
            $query['filter'] = $filters;
        }

        return $query;
    }

    protected function writePayload(Request $request, bool $requireBaseRevision): array
    {
        $meta = [
            'reason' => (string) $request->get('reason', ''),
            'source' => (string) $request->get('source', (string) config('services.dataverse_mcp.source', 'mcp')),
            'validate_only' => $request->boolean('validate_only'),
        ];

        if ($requireBaseRevision) {
            $meta['base_revision_id'] = (int) $request->integer('base_revision_id');
        }

        return [
            'data' => [
                'attributes' => is_array($request->get('attributes')) ? $request->get('attributes') : [],
                'relationships' => is_array($request->get('relationships')) ? $request->get('relationships') : [],
            ],
            'meta' => $meta,
        ];
    }
}
