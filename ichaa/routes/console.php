<?php

use App\Domain\ConnectedRealms\Services\ConnectedRealmsSimulationService;
use App\Domain\ConnectedRealms\Services\EconomyAuditService;
use App\Domain\System\Services\DemoLoreSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dataverse:seed-demo-lore', function () {
    try {
        $stats = app(DemoLoreSeeder::class)->seed();
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return Command::FAILURE;
    }

    foreach ($stats['resources'] as $resource => $resourceStats) {
        $parts = collect($resourceStats)
            ->map(fn ($count, $label) => "{$count} {$label}")
            ->implode(', ');

        $this->line("{$resource}: {$parts}");
    }

    $this->info('Demo lore seed finished.');

    return Command::SUCCESS;
})->purpose('Seed rich Harry Potter, Stormlight, and original crossover content into Dataverse.');

Artisan::command('evergather:simulate-users {--hours=2 : Number of in-game hours to simulate} {--fresh : Reset Rico and Kye Evergather data before simulating}', function (ConnectedRealmsSimulationService $simulation) {
    $hours = (float) $this->option('hours');

    if ($hours <= 0) {
        $this->error('Hours must be greater than zero.');

        return Command::FAILURE;
    }

    $summary = $simulation->simulateRicoAndKye($hours, (bool) $this->option('fresh'));

    $this->info("Evergather simulation complete for {$summary['hours']} hours.");

    foreach ($summary['personas'] as $persona) {
        $this->line("{$persona['display_name']}: {$persona['actions_completed']} actions, {$persona['gold']} gold, ".count($persona['active_listings']).' active listings.');
        $this->line('  Tools: '.collect($persona['tools_bought'])->implode(', '));
        $this->line('  Listings: '.collect($persona['active_listings'])->map(fn (array $listing): string => "{$listing['quantity']} {$listing['item_name']} @ {$listing['unit_price']}g")->implode('; '));
    }

    return Command::SUCCESS;
})->purpose('Simulate Rico and Kye through Evergather gameplay and list their spoils on the marketplace.');

Artisan::command('evergather:economy-export {--out= : Write the deterministic JSON export to this path}', function (EconomyAuditService $audit) {
    $json = json_encode($audit->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        $this->error('Failed to encode the Evergather economy export.');

        return Command::FAILURE;
    }

    $out = $this->option('out');

    if (blank($out)) {
        $this->line($json);

        return Command::SUCCESS;
    }

    $path = (string) $out;
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($path, $json.PHP_EOL);

    $this->info("Evergather economy export written to {$path}.");

    return Command::SUCCESS;
})->purpose('Export deterministic Evergather economy catalog counts, graph edges, prices, and guardrail violations.');

Artisan::command('evergather:migrate-inventory {--force : Apply the migration instead of printing the dry-run summary}', function (EconomyAuditService $audit) {
    $result = (bool) $this->option('force')
        ? $audit->applyInventoryMigration()
        : $audit->previewInventoryMigration();

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if (! (bool) $this->option('force')) {
        $this->warn('Dry run only. Re-run with --force to apply the inventory migration.');
    }

    return Command::SUCCESS;
})->purpose('Preview or apply the idempotent Evergather inventory key migration.');

$syncBitcraftCraftingSnapshot = function () {
    $token = (string) config('services.bitcraft_spacetime.auth_token');

    if (blank($token)) {
        $this->error('BITCRAFT_AUTH_TOKEN is required.');

        return Command::FAILURE;
    }

    $timeout = (int) ($this->option('timeout') ?: config('services.bitcraft_spacetime.sync_timeout', 45));
    $outputPath = (string) ($this->option('out') ?: config('services.bitcraft_spacetime.static_snapshot_path'));
    $tables = $this->option('table') ?: config('services.bitcraft_spacetime.tables', []);

    $arguments = [
        'node',
        base_path('scripts/bitcraft-spacetime-dump.mjs'),
        '--host',
        (string) config('services.bitcraft_spacetime.host'),
        '--database',
        (string) config('services.bitcraft_spacetime.region_database'),
        '--out',
        $outputPath,
        '--timeout',
        (string) $timeout,
    ];

    foreach ($tables as $table) {
        $arguments[] = '--table';
        $arguments[] = (string) $table;
    }

    $process = new Process($arguments, base_path(), [
        'BITCRAFT_AUTH_TOKEN' => $token,
    ], null, $timeout + 15);

    $process->run(function (string $type, string $buffer) {
        if ($type === Process::ERR) {
            $this->error(trim($buffer));

            return;
        }

        $this->line(trim($buffer));
    });

    if (! $process->isSuccessful()) {
        $this->error('SpacetimeDB sync failed.');

        return Command::FAILURE;
    }

    $this->info('BitCraft SpacetimeDB snapshot synced.');

    return Command::SUCCESS;
};

Artisan::command('bitcraft:spacetime-sync {--table=* : Limit the sync to specific SpacetimeDB tables} {--out= : Override the snapshot output path} {--timeout= : Timeout in seconds}', $syncBitcraftCraftingSnapshot)
    ->purpose('Snapshot BitCraft SpacetimeDB static tables for the crafting calculator.');

Artisan::command('bitcraft:crafting-sync {--table=* : Limit the sync to specific SpacetimeDB tables} {--out= : Override the snapshot output path} {--timeout= : Timeout in seconds}', $syncBitcraftCraftingSnapshot)
    ->purpose('Sync the BitCraft crafting calculator static snapshot.');
