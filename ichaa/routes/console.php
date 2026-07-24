<?php

use App\Domain\System\Services\DemoLoreSeeder;
use App\Domain\System\Services\NotionDataverseSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notion:sync-dataverse {resource=all} {--include-drafts} {--dry-run}', function () {
    $resource = (string) $this->argument('resource');
    $includeDrafts = (bool) $this->option('include-drafts');
    $dryRun = (bool) $this->option('dry-run');

    try {
        $stats = app(NotionDataverseSyncService::class)->sync($resource, $includeDrafts, $dryRun);
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return Command::FAILURE;
    }

    if ($dryRun) {
        $this->line('Dry run only. No local rows or Notion pages were changed.');
    }

    foreach ($stats['resources'] ?: [$resource => $stats] as $name => $resourceStats) {
        $this->line(sprintf(
            '%s: %d created, %d updated, %d skipped',
            $name,
            $resourceStats['created'],
            $resourceStats['updated'],
            $resourceStats['skipped'],
        ));
    }

    foreach ($stats['warnings'] as $warning) {
        $this->warn($warning);
    }

    $this->info(sprintf(
        'Finished. %d created, %d updated, %d skipped.',
        $stats['created'],
        $stats['updated'],
        $stats['skipped'],
    ));

    return Command::SUCCESS;
})->purpose('Sync Dataverse records from the paired Notion workspace.');

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

Artisan::command('bitcraft:spacetime-sync {--table=* : Limit the sync to specific SpacetimeDB tables} {--out= : Override the snapshot output path} {--timeout= : Timeout in seconds}', function () {
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
})->purpose('Snapshot BitCraft SpacetimeDB static tables for the crafting calculator.');
