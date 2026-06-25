<?php

namespace Tests\Feature\System;

use App\Domain\System\Services\NotionDataverseSyncService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotionSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_route_calls_the_service_and_flashes_a_summary(): void
    {
        $mock = Mockery::mock(NotionDataverseSyncService::class);
        $mock->shouldReceive('sync')
            ->once()
            ->with('documents')
            ->andReturn([
                'created' => 2,
                'updated' => 3,
                'skipped' => 4,
                'warnings' => [],
            ]);

        $this->app->instance(NotionDataverseSyncService::class, $mock);

        $response = $this->actingAs($this->verifiedUser())
            ->from(route('documents.index'))
            ->post(route('notion.sync', ['resource' => 'documents']));

        $response
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('success', 'Notion sync finished for Documents. 2 created, 3 updated, 4 skipped.');
    }

    public function test_sync_route_flashes_the_error_when_sync_fails(): void
    {
        $mock = Mockery::mock(NotionDataverseSyncService::class);
        $mock->shouldReceive('sync')
            ->once()
            ->with('all')
            ->andThrow(new RuntimeException('NOTION_API_TOKEN is not configured.'));

        $this->app->instance(NotionDataverseSyncService::class, $mock);

        $response = $this->actingAs($this->verifiedUser())
            ->from(route('dashboard'))
            ->post(route('notion.sync', ['resource' => 'all']));

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'NOTION_API_TOKEN is not configured.');
    }

    private function verifiedUser(): User
    {
        return $this->createVerifiedAdminUser();
    }
}
