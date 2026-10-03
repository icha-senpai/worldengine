<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BitcraftHuntingCalculatorController extends Controller
{
    public function index(BitcraftPlayerData $data): Response
    {
        $levels = [];
        $error = null;

        try {
            $levels = $data->experienceLevels();
            if ($levels === []) {
                $error = 'Level thresholds are unavailable. Reload to try again.';
            }
        } catch (Throwable) {
            $error = 'Level thresholds are unavailable. Reload to try again.';
        }

        return Inertia::render('Bitcraft/HuntingCalculator', [
            'levels' => $levels,
            'error' => $error,
        ]);
    }
}
