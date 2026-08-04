<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToolInventoryService
{
    public function __construct(private ConnectedRealmsPlayerService $players) {}

    /**
     * @return array<string, mixed>
     */
    public function equip(User $user, int $toolId): array
    {
        return DB::transaction(function () use ($user, $toolId): array {
            $player = $this->lockedPlayerFor($user);
            $tool = ConnectedRealmsTool::query()
                ->where('player_id', $player->id)
                ->whereKey($toolId)
                ->lockForUpdate()
                ->first();

            if ($tool === null || $tool->status !== ConnectedRealmsTool::STATUS_INVENTORY) {
                throw ValidationException::withMessages([
                    'tool_id' => 'That stored tool is not available to equip.',
                ]);
            }

            $equipment = $this->players->equipToolInstance($player, $tool);

            return [
                'type' => 'tool_equip',
                'label' => $equipment->item_name,
                'slot' => $equipment->slot,
                'slot_label' => str($equipment->slot)->headline()->toString(),
                'tool' => $this->players->toolPayload($equipment),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function unequip(User $user, string $slot): array
    {
        return DB::transaction(function () use ($user, $slot): array {
            $player = $this->lockedPlayerFor($user);
            $equipment = ConnectedRealmsEquipmentSlot::query()
                ->where('player_id', $player->id)
                ->where('slot', $slot)
                ->lockForUpdate()
                ->first();

            if ($equipment === null) {
                throw ValidationException::withMessages([
                    'slot' => 'That equipped tool slot is not available.',
                ]);
            }

            $tool = $this->players->ensureToolInstanceForEquipment($equipment);

            if ($tool->origin === 'starter') {
                throw ValidationException::withMessages([
                    'slot' => 'Field kit tools stay equipped until another tool replaces them.',
                ]);
            }

            $storedToolName = $tool->item_name;
            $tool->forceFill([
                'status' => ConnectedRealmsTool::STATUS_INVENTORY,
            ])->save();

            $starterEquipment = $this->players->equipStarterToolForSlot($player, $slot);

            return [
                'type' => 'tool_unequip',
                'label' => $storedToolName,
                'slot' => $slot,
                'slot_label' => str($slot)->headline()->toString(),
                'stored_tool_name' => $storedToolName,
                'tool' => $this->players->toolPayload($starterEquipment),
            ];
        });
    }

    private function lockedPlayerFor(User $user): ConnectedRealmsPlayer
    {
        $player = $this->players->playerForUser($user);

        return ConnectedRealmsPlayer::query()
            ->whereKey($player->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
