<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Domain\Bitcraft\Services\BitcraftSitePlayer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bitcraft\SearchBitcraftPlayersRequest;
use App\Http\Requests\Bitcraft\UpdateBitcraftSitePlayerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class BitcraftSitePlayerController extends Controller
{
    public function search(SearchBitcraftPlayersRequest $request, BitcraftPlayerData $data): JsonResponse
    {
        try {
            $payload = $data->players(trim($request->validated('q')));

            return response()->json([
                'players' => collect($payload['players'])->take(20)->map(fn (array $player): array => [
                    'entityId' => (string) $player['entityId'],
                    'username' => (string) $player['username'],
                ])->values()->all(),
                'refresh' => $data->refreshStatus(),
            ]);
        } catch (Throwable) {
            return response()->json(['players' => [], 'error' => 'Player search is temporarily unavailable. Try again shortly.'], 503);
        }
    }

    public function update(UpdateBitcraftSitePlayerRequest $request, BitcraftPlayerData $data, BitcraftSitePlayer $selection): RedirectResponse
    {
        $id = $request->validated('entityId');
        $player = null;

        if ($id !== null) {
            try {
                $player = $data->player($id)['player'];
            } catch (Throwable) {
                throw ValidationException::withMessages(['entityId' => 'That player could not be loaded. Try again shortly.']);
            }

            if ((string) data_get($player, 'entityId') !== $id || blank(data_get($player, 'username'))) {
                throw ValidationException::withMessages(['entityId' => 'That player could not be found.']);
            }
        }

        $selection->select($request, $player);

        return back();
    }
}
