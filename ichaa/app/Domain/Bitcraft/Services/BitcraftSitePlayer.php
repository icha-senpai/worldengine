<?php

namespace App\Domain\Bitcraft\Services;

use Illuminate\Http\Request;

class BitcraftSitePlayer
{
    public function selected(Request $request): ?array
    {
        return $request->user() ? $request->session()->get($this->key($request)) : null;
    }

    public function select(Request $request, ?array $player): void
    {
        if ($player === null) {
            $request->session()->forget($this->key($request));

            return;
        }

        $request->session()->put($this->key($request), [
            'entityId' => (string) $player['entityId'],
            'username' => (string) $player['username'],
        ]);
    }

    private function key(Request $request): string
    {
        return 'bitcraft.site_player.'.$request->user()->getKey();
    }
}
