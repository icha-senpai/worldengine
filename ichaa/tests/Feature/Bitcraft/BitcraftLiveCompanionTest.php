<?php

namespace Tests\Feature\Bitcraft;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BitcraftLiveCompanionTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_companion_page_reads_current_bridge_snapshot(): void
    {
        $path = $this->writeBridgeSnapshot([
            'schemaVersion' => 1,
            'capturedAt' => '2026-07-29T12:00:00+00:00',
            'source' => [
                'kind' => 'bepinex-il2cpp',
                'modVersion' => '0.1.0',
            ],
            'player' => [
                'name' => 'Should not leak',
            ],
            'biome' => [
                'name' => 'Calm Forest',
                'confidence' => 'exact',
                'source' => 'terrain',
            ],
            'inventory' => [[
                'kind' => 'item',
                'id' => 123,
                'name' => 'Simple Stick',
                'quantity' => 4,
                'slot' => 2,
            ]],
            'deployables' => [[
                'entityId' => 'deployable-1',
                'name' => 'Icha\'s Skiff',
                'localX' => 1.5,
                'localY' => 2.5,
                'localZ' => 3.5,
                'inventory' => [[
                    'kind' => 'item',
                    'id' => 456,
                    'name' => 'Greenhorn Guppi',
                    'quantity' => 10,
                ]],
            ]],
            'diagnostics' => [[
                'name' => 'Should not leak',
            ]],
        ]);

        config([
            'services.bitcraft_live_companion.state_path' => $path,
            'services.bitcraft_live_companion.stale_after_seconds' => 60,
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('bitcraft.live-companion'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/LiveCompanion')
                ->where('snapshot.online', true)
                ->where('snapshot.stale', false)
                ->where('snapshot.state.biome.name', 'Calm Forest')
                ->where('snapshot.state.inventory.0.name', 'Simple Stick')
                ->where('snapshot.state.deployables.0.inventory.0.name', 'Greenhorn Guppi')
                ->missing('snapshot.state.player')
                ->missing('snapshot.state.diagnostics')
                ->where('snapshotUrl', route('bitcraft.live-companion.snapshot', absolute: false))
            );
    }

    public function test_live_companion_snapshot_marks_old_bridge_files_as_stale(): void
    {
        $path = $this->writeBridgeSnapshot([
            'schemaVersion' => 1,
            'capturedAt' => '2026-07-29T12:00:00+00:00',
            'source' => ['kind' => 'bepinex-il2cpp'],
            'inventory' => [[
                'kind' => 'item',
                'id' => 123,
                'name' => 'Simple Stick',
                'quantity' => 4,
            ]],
        ]);
        touch($path, time() - 30);

        config([
            'services.bitcraft_live_companion.state_path' => $path,
            'services.bitcraft_live_companion.stale_after_seconds' => 10,
        ]);

        $response = $this->actingAs($this->createVerifiedAdminUser())
            ->getJson(route('bitcraft.live-companion.snapshot'));

        $response->assertOk()
            ->assertJsonPath('online', false)
            ->assertJsonPath('stale', true)
            ->assertJsonPath('state', null)
            ->assertJsonPath('lastCapturedAt', '2026-07-29T12:00:00+00:00');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeBridgeSnapshot(array $payload): string
    {
        $path = storage_path('framework/testing/bitcraft-live-companion.json');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));

        return $path;
    }
}
