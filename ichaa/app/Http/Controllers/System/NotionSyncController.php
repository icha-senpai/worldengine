<?php

namespace App\Http\Controllers\System;

use App\Domain\System\Services\NotionDataverseSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Throwable;

class NotionSyncController extends Controller
{
    public function store(string $resource, NotionDataverseSyncService $syncService): RedirectResponse
    {
        try {
            $stats = $syncService->sync($resource);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Notion sync finished for %s. %d created, %d updated, %d skipped.',
            Str::of($resource)->replace(['_', '-'], ' ')->title(),
            $stats['created'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['skipped'] ?? 0,
        ));
    }
}
