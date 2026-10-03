<?php

// Read only the public game definitions already exposed by ICHAA's tools.
// No player tables, runtime captures, account profiles, or credentials are exported.
require __DIR__.'/../../ichaa/vendor/autoload.php';
$app = require __DIR__.'/../../ichaa/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = $app->make(App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData::class);
$items = $data->catalogSearch('', 100000);
$details = [];
foreach ($items as $item) {
    $detail = $data->detail($item['kind'], (int) $item['id']);
    if ($detail) $details[$item['kind'].':'.$item['id']] = $detail;
}
$metadata = $data->metadata();
$result = [
    'generatedAt' => $metadata['generatedAt'],
    'items' => $items,
    'details' => $details,
    'skills' => $data->skillMap(),
    'entries' => $data->toolRateEntries(),
];
$path = __DIR__.'/../bitcraft/src/content/catalog.json';
file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo count($items).' public catalog items; '.count($details).' details; '.count($result['entries'])." gathering entries\n";
