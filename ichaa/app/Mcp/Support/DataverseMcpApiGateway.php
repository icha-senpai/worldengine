<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class DataverseMcpApiGateway
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function send(string $method, string $path, array $query = [], array $payload = []): array
    {
        $token = (string) config('services.dataverse_mcp.token', '');

        if ($token === '') {
            throw new RuntimeException('DATAVERSE_MCP_TOKEN is not configured.');
        }

        $uri = rtrim((string) config('services.dataverse_mcp.api_base', '/api/v1'), '/')
            . '/'
            . ltrim($path, '/');

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $content = in_array(strtoupper($method), ['GET', 'DELETE'], true) && $payload === []
            ? null
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $request = Request::create(
            $uri,
            strtoupper($method),
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'HTTP_X_REQUEST_ID' => (string) Str::uuid(),
            ],
            $content,
        );

        $response = $this->kernel->handle($request);

        try {
            return $this->normalize($method, $uri, $query, $payload, $response);
        } finally {
            $this->kernel->terminate($request, $response);
        }
    }

    private function normalize(string $method, string $uri, array $query, array $payload, Response $response): array
    {
        $raw = $response->getContent();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);

        return [
            'ok' => $response->isSuccessful(),
            'status' => $response->getStatusCode(),
            'method' => strtoupper($method),
            'uri' => $uri,
            'query' => $query,
            'payload' => $payload,
            'body' => $isJson ? $decoded : null,
            'raw_body' => $isJson ? null : $raw,
        ];
    }
}
