<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DataverseMcpOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://example.test');
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');
    }

    public function test_oauth_metadata_endpoints_are_exposed_for_the_mcp_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/dataverse')
            ->assertOk()
            ->assertJson([
                'resource' => 'https://example.test/mcp/dataverse',
                'authorization_servers' => ['https://example.test'],
                'scopes_supported' => ['mcp:use'],
            ]);

        $this->getJson('/.well-known/oauth-authorization-server/mcp/dataverse')
            ->assertOk()
            ->assertJson([
                'issuer' => 'https://example.test',
                'authorization_endpoint' => 'https://example.test/oauth/authorize',
                'token_endpoint' => 'https://example.test/oauth/token',
                'registration_endpoint' => 'https://example.test/oauth/register',
            ]);
    }

    public function test_unauthenticated_mcp_requests_advertise_resource_metadata_for_oauth_clients(): void
    {
        $this->postJson('/mcp/dataverse', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-05',
                'clientInfo' => [
                    'name' => 'notion-test',
                    'version' => '1.0.0',
                ],
                'capabilities' => [],
            ],
        ])
            ->assertUnauthorized()
            ->assertHeader(
                'WWW-Authenticate',
                'Bearer realm="mcp", resource_metadata="https://example.test/.well-known/oauth-protected-resource/mcp/dataverse"'
            );
    }

    public function test_oauth_client_registration_endpoint_accepts_public_redirect_uris(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Notion AI',
            'redirect_uris' => [
                'https://www.notion.so/callback/example',
            ],
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'client_id',
                'grant_types',
                'response_types',
                'redirect_uris',
                'scope',
                'token_endpoint_auth_method',
            ])
            ->assertJson([
                'scope' => 'mcp:use',
                'token_endpoint_auth_method' => 'none',
            ]);
    }

    public function test_passport_authorize_route_redirects_guests_to_login(): void
    {
        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Notion AI',
            'redirect_uris' => [
                'https://www.notion.so/callback/example',
            ],
        ])->json('client_id');

        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://www.notion.so/callback/example',
            'scope' => 'mcp:use',
            'state' => 'test-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]))
            ->assertRedirect(route('login'));
    }
}
