<?php

// Read-only migration of published guides; excludes accounts and draft content.
require __DIR__.'/../../ichaa/vendor/autoload.php';
$app = require __DIR__.'/../../ichaa/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$guides = App\Domain\Bitcraft\Models\BitcraftGuide::query()->published()->with('author:id,name')
    ->orderByDesc('updated_at')->get()
    ->map(fn ($guide) => [...$guide->only(['id', 'title', 'summary', 'category', 'content']),
        'author' => ['name' => $guide->author?->name ?? 'Admin'],
        'published_at' => $guide->published_at?->toIso8601String(),
        'updated_at' => $guide->updated_at?->toIso8601String()])
    ->values()->all();
$directory = __DIR__.'/../bitcraft/src/content';
if (! is_dir($directory)) mkdir($directory, 0777, true);
file_put_contents($directory.'/guides.json', json_encode($guides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo count($guides)." published guides exported.\n";
