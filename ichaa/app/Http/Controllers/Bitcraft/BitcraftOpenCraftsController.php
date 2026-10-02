<?php

namespace App\Http\Controllers\Bitcraft;

use App\Domain\Bitcraft\Services\BitcraftOpenCrafts;
use App\Domain\Bitcraft\Services\BitcraftPlayerData;
use App\Domain\Bitcraft\Services\BitcraftSitePlayer;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bitcraft\ListBitcraftOpenCraftsRequest;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BitcraftOpenCraftsController extends Controller
{
    public function index(ListBitcraftOpenCraftsRequest $request, BitcraftPlayerData $data, BitcraftOpenCrafts $crafts, BitcraftSitePlayer $selection, BitcraftSpacetimeStaticData $static): Response
    {
        $filters = [...['q' => '', 'skill' => '', 'region' => '', 'levelUps' => false, 'meetsLevel' => false, 'mine' => false, 'sort' => 'xp', 'page' => 1], ...$request->validated()];
        foreach (['levelUps', 'meetsLevel', 'mine'] as $toggle) {
            $filters[$toggle] = $request->boolean($toggle);
        }
        $selected = $selection->selected($request);
        if ($selected === null) {
            $filters = [...$filters, 'levelUps' => false, 'meetsLevel' => false, 'mine' => false];
        }
        $player = null;
        $levels = [];
        $playerError = null;
        $playerRefresh = [];
        if ($selected) {
            try {
                $player = $data->player($selected['entityId'])['player'];
                $playerRefresh = $data->refreshStatus();
                if (! is_array(data_get($player, 'experience'))) {
                    $player = null;
                    throw new \UnexpectedValueException('Missing player XP');
                }
                $levels = $data->experienceLevels();
                if ($levels === []) {
                    $playerError = 'Level thresholds are unavailable. XP estimates are still shown.';
                }
            } catch (Throwable) {
                $playerError = 'Player XP or level thresholds are unavailable. Level projections will return when the provider recovers.';
            }
        }
        $error = null;
        $rows = [];
        try {
            $rows = $crafts->rows($data->crafts(), $player, $levels, $static->skillMap());
        } catch (Throwable) {
            $error = 'Open crafts are temporarily unavailable. Try again shortly.';
        }
        $skillOptions = collect($rows)->unique('skillId')->sortBy('skill')->map(fn (array $row): array => ['id' => $row['skillId'], 'name' => $row['skill']])->values()->all();
        $regionOptions = collect($rows)->pluck('region')->unique()->sort()->values()->all();
        $filtered = collect($rows)->filter(function (array $row) use ($filters, $selected): bool {
            return (blank($filters['q']) || str_contains(strtolower(implode(' ', [$row['name'], $row['claim'], $row['owner'], $row['building']])), strtolower(trim($filters['q']))))
                && (blank($filters['skill']) || $row['skillId'] === (int) $filters['skill'])
                && (blank($filters['region']) || $row['region'] === (int) $filters['region'])
                && (! $filters['levelUps'] || ($row['levelsGained'] ?? 0) > 0)
                && (! $filters['meetsLevel'] || $row['meetsLevel'] === true)
                && (! $filters['mine'] || ($selected && $row['ownerId'] === $selected['entityId']));
        });
        $filtered = match ($filters['sort']) {
            'levels' => $filtered->sortBy([['levelsGained', 'desc'], ['remainingXp', 'desc'], ['id', 'asc']]),
            'progress' => $filtered->sortBy([['progressPercent', 'desc'], ['id', 'asc']]),
            'name' => $filtered->sortBy([['name', 'asc'], ['id', 'asc']]),
            default => $filtered->sortBy([['remainingXp', 'desc'], ['id', 'asc']]),
        };
        $lastPage = max(1, (int) ceil($filtered->count() / 25));
        $page = max(1, min((int) $filters['page'], $lastPage));

        return Inertia::render('Bitcraft/OpenCrafts', [
            'filters' => [...$filters, 'page' => $page],
            'crafts' => $filtered->slice(($page - 1) * 25, 25)->values()->all(),
            'pagination' => ['page' => $page, 'lastPage' => $lastPage, 'total' => $filtered->count(), 'all' => count($rows)],
            'skillOptions' => $skillOptions,
            'regionOptions' => $regionOptions,
            'refresh' => $data->refreshStatus(),
            'error' => $error,
            'playerError' => $playerError,
            'playerRefresh' => $playerRefresh,
        ]);
    }
}
