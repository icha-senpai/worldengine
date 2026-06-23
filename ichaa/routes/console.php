<?php

use App\Domain\System\Services\DemoLoreSeeder;
use App\Domain\System\Services\NotionDataverseSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

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
