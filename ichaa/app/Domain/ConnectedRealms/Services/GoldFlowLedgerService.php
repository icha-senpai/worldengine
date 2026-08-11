<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsGoldFlow;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use Illuminate\Database\Eloquent\Model;

class GoldFlowLedgerService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestroyed(ConnectedRealmsPlayer $player, string $flowKey, int $gold, string $sourceSystem, ?Model $subject = null, array $context = []): ?ConnectedRealmsGoldFlow
    {
        return $this->record($player, ConnectedRealmsGoldFlow::DIRECTION_DESTROYED, $flowKey, $gold, $sourceSystem, $subject, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(ConnectedRealmsPlayer $player, string $direction, string $flowKey, int $gold, string $sourceSystem, ?Model $subject, array $context): ?ConnectedRealmsGoldFlow
    {
        if ($gold <= 0) {
            return null;
        }

        return ConnectedRealmsGoldFlow::query()->create([
            'player_id' => $player->id,
            'flow_key' => $flowKey,
            'direction' => $direction,
            'source_system' => $sourceSystem,
            'gold' => $gold,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
