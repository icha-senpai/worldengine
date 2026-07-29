<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftLiveCompanionState;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BitcraftLiveCompanionController extends Controller
{
    public function show(BitcraftLiveCompanionState $companion): InertiaResponse
    {
        return Inertia::render('Bitcraft/LiveCompanion', [
            'enabled' => $companion->enabled(),
            'snapshot' => $companion->snapshot(),
            'snapshotUrl' => route('bitcraft.live-companion.snapshot', absolute: false),
        ]);
    }

    public function snapshot(BitcraftLiveCompanionState $companion): JsonResponse
    {
        return response()
            ->json($companion->snapshot())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
