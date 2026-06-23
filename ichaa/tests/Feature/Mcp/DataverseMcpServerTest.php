<?php

namespace Tests\Feature\Mcp;

use App\Domain\Identity\Models\Entity;
use App\Domain\System\Models\Revision;
use App\Domain\System\Services\NotionDataverseSyncService;
use App\Domain\System\Services\RevisionService;
use App\Mcp\Resources\DataverseCatalogResource;
use App\Mcp\Servers\DataverseServer;
use App\Mcp\Tools\CreateDataverseRecordTool;
use App\Mcp\Tools\DeleteDataverseRecordTool;
use App\Mcp\Tools\InspectDataverseRevisionTool;
use App\Mcp\Tools\ListDataverseTrashTool;
use App\Mcp\Tools\RestoreDataverseRecordTool;
use App\Mcp\Tools\RestoreDataverseRevisionTool;
use App\Mcp\Tools\RunDataverseActionTool;
use App\Mcp\Tools\SearchDataverseTool;
use App\Mcp\Tools\SyncDataverseNotionTool;
use App\Mcp\Tools\UpdateDataverseRecordTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Facades\Mcp;
use Mockery;
use Tests\TestCase;

class DataverseMcpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dataverse_mcp.source', 'mcp');
    }

    public function test_http_mcp_route_initializes_and_local_server_is_registered(): void
    {
        $token = $this->assistantToken();

        $this->assertNotNull(Mcp::getLocalServer('dataverse'));

        $this->withToken($token)->postJson('/mcp/dataverse', [
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'clientInfo' => [
                    'name' => 'phpunit',
                    'version' => '1.0.0',
                ],
                'capabilities' => [],
            ],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'Dataverse Authoring MCP')
            ->assertJsonPath('result.instructions', fn (string $instructions) => str_contains($instructions, 'base_revision_id'));
    }

    public function test_catalog_resource_exposes_resources_actions_and_sync_surfaces(): void
    {
        DataverseServer::resource(DataverseCatalogResource::class)
            ->assertOk()
            ->assertSee([
                'entities',
                'entity.publish',
                'notion_sync_resources',
            ]);
    }

    public function test_mcp_tools_can_create_update_delete_restore_and_search_entities(): void
    {
        config()->set('services.dataverse_mcp.token', $this->assistantToken());

        DataverseServer::tool(CreateDataverseRecordTool::class, [
            'resource' => 'entities',
            'attributes' => [
                'name' => 'MCP Created Entity',
                'entity_type' => 'character',
                'summary' => 'Created through the MCP server.',
                'public_title' => 'MCP Created Entity',
                'status' => 'draft',
            ],
            'reason' => 'Create through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 201)
                ->where('body.data.type', 'entities')
                ->where('body.data.attributes.name', 'MCP Created Entity')
                ->etc();
        });

        $entity = Entity::query()->where('name', 'MCP Created Entity')->firstOrFail();
        $revisionService = app(RevisionService::class);

        DataverseServer::tool(SearchDataverseTool::class, [
            'search' => 'MCP Created Entity',
            'resource' => 'entities',
        ])->assertOk()->assertStructuredContent(function ($json) use ($entity) {
            $json->where('status', 200)
                ->where('body.data.0.id', $entity->id)
                ->etc();
        });

        DataverseServer::tool(UpdateDataverseRecordTool::class, [
            'resource' => 'entities',
            'id' => (string) $entity->id,
            'attributes' => [
                'summary' => 'Updated through the MCP server.',
            ],
            'base_revision_id' => $revisionService->currentRevisionId('entities', $entity->id),
            'reason' => 'Update through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.attributes.summary.type', 'doc')
                ->where('body.data.attributes.summary.content.0.content.0.text', 'Updated through the MCP server.')
                ->etc();
        });

        DataverseServer::tool(DeleteDataverseRecordTool::class, [
            'resource' => 'entities',
            'id' => (string) $entity->id,
            'base_revision_id' => $revisionService->currentRevisionId('entities', $entity->id),
            'reason' => 'Delete through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.meta.deleted', true)
                ->etc();
        });

        $this->assertSoftDeleted('entities', ['id' => $entity->id]);

        DataverseServer::tool(ListDataverseTrashTool::class)
            ->assertOk()
            ->assertSee('MCP Created Entity');

        DataverseServer::tool(RestoreDataverseRecordTool::class, [
            'resource' => 'entities',
            'id' => (string) $entity->id,
            'base_revision_id' => $revisionService->currentRevisionId('entities', $entity->id),
            'reason' => 'Restore through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) use ($entity) {
            $json->where('status', 200)
                ->where('body.data.id', $entity->id)
                ->etc();
        });

        $this->assertDatabaseHas('entities', [
            'id' => $entity->id,
            'deleted_at' => null,
        ]);
    }

    public function test_mcp_action_and_revision_tools_operate_against_the_authoring_api(): void
    {
        config()->set('services.dataverse_mcp.token', $this->assistantToken());

        $entity = Entity::factory()->publishable()->create([
            'name' => 'Revision Actor',
        ]);

        DataverseServer::tool(RunDataverseActionTool::class, [
            'action' => 'entity.publish',
            'record_id' => (string) $entity->id,
            'base_revision_id' => 0,
            'reason' => 'Publish through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.attributes.visibility', 'public_knowledge')
                ->etc();
        });

        DataverseServer::tool(RunDataverseActionTool::class, [
            'action' => 'entity.unpublish',
            'record_id' => (string) $entity->id,
            'base_revision_id' => app(RevisionService::class)->currentRevisionId('entities', $entity->id),
            'reason' => 'Unpublish through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.attributes.visibility', 'private')
                ->etc();
        });

        $publishRevision = Revision::query()
            ->where('resource_type', 'entities')
            ->where('resource_id', (string) $entity->id)
            ->where('action', 'publish')
            ->firstOrFail();

        $unpublishRevision = Revision::query()
            ->where('resource_type', 'entities')
            ->where('resource_id', (string) $entity->id)
            ->where('action', 'unpublish')
            ->firstOrFail();

        DataverseServer::tool(InspectDataverseRevisionTool::class, [
            'mode' => 'show',
            'revision_id' => $publishRevision->id,
        ])->assertOk()->assertStructuredContent(function ($json) use ($publishRevision) {
            $json->where('status', 200)
                ->where('body.data.id', $publishRevision->id)
                ->where('body.data.action', 'publish')
                ->etc();
        });

        DataverseServer::tool(InspectDataverseRevisionTool::class, [
            'mode' => 'compare',
            'left_revision_id' => $publishRevision->id,
            'right_revision_id' => $unpublishRevision->id,
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.comparison.after.visibility', 'private')
                ->etc();
        });

        DataverseServer::tool(RestoreDataverseRevisionTool::class, [
            'revision_id' => $publishRevision->id,
            'base_revision_id' => app(RevisionService::class)->currentRevisionId('entities', $entity->id),
            'reason' => 'Restore publish state through MCP',
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.attributes.visibility', 'public_knowledge')
                ->etc();
        });
    }

    public function test_mcp_sync_tool_uses_the_canonical_sync_endpoint(): void
    {
        config()->set('services.dataverse_mcp.token', $this->assistantToken());

        $mock = Mockery::mock(NotionDataverseSyncService::class);
        $mock->shouldReceive('sync')
            ->once()
            ->with('documents', false, true)
            ->andReturn([
                'created' => 0,
                'updated' => 2,
                'skipped' => 5,
            ]);

        $this->app->instance(NotionDataverseSyncService::class, $mock);

        DataverseServer::tool(SyncDataverseNotionTool::class, [
            'resource' => 'documents',
            'dry_run' => true,
        ])->assertOk()->assertStructuredContent(function ($json) {
            $json->where('status', 200)
                ->where('body.data.resource', 'documents')
                ->where('body.data.stats.updated', 2)
                ->etc();
        });
    }

    private function assistantToken(): string
    {
        $user = User::factory()->create();

        return $user->createToken('assistant', ['*'])->plainTextToken;
    }
}
