<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToolInventoryService
{
    public function __construct(
        private ConnectedRealmsPlayerService $players,
        private ToolCatalogService $tools,
        private GoldFlowLedgerService $goldFlows,
    ) {}

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

    /**
     * @return array<string, mixed>
     */
    public function repair(User $user, int $toolId): array
    {
        return DB::transaction(function () use ($user, $toolId): array {
            $player = $this->lockedPlayerFor($user);
            $tool = $this->ownedLifecycleTool($player, $toolId);
            $repair = $this->tools->repairCost($this->toolCostPayload($tool));

            if (! $repair['can_repair']) {
                throw ValidationException::withMessages([
                    'tool_id' => 'That tool does not need repair.',
                ]);
            }

            $this->consumeLifecycleCost($player, (int) $repair['gold_cost'], $repair['materials'], 'repair');

            $tool->forceFill(['durability' => 100])->save();
            $equipment = $this->syncEquippedToolIfNeeded($tool);
            $this->goldFlows->recordDestroyed($player, 'tool_repair', (int) $repair['gold_cost'], 'tool_lifecycle', $tool, [
                'tool_id' => (int) $tool->id,
                'item_key' => $tool->item_key,
                'item_name' => $tool->item_name,
                'skill' => $tool->skill,
                'previous_durability' => 100 - (int) $repair['missing_durability'],
                'materials_spent' => $repair['materials'],
            ]);

            return [
                'type' => 'tool_repair',
                'label' => $tool->item_name,
                'tool_id' => $tool->id,
                'gold_spent' => (int) $repair['gold_cost'],
                'materials_spent' => $repair['materials'],
                'tool' => $equipment === null ? $this->players->toolInstancePayload($tool) : $this->players->toolPayload($equipment),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function salvage(User $user, int $toolId): array
    {
        return DB::transaction(function () use ($user, $toolId): array {
            $player = $this->lockedPlayerFor($user);
            $tool = $this->ownedLifecycleTool($player, $toolId);

            if ($tool->origin === 'starter') {
                throw ValidationException::withMessages([
                    'tool_id' => 'Field kit tools cannot be salvaged.',
                ]);
            }

            $materials = $this->tools->salvageMaterials($this->toolCostPayload($tool));

            if ($materials === []) {
                throw ValidationException::withMessages([
                    'tool_id' => 'That tool cannot be salvaged.',
                ]);
            }

            $replacement = $this->replaceEquippedToolIfNeeded($player, $tool);
            $toolName = $tool->item_name;
            $tool->delete();
            $this->grantMaterials($player, $materials);

            return [
                'type' => 'tool_salvage',
                'label' => $toolName,
                'tool_id' => $toolId,
                'materials_awarded' => $materials,
                'replacement_tool' => $replacement === null ? null : $this->players->toolPayload($replacement),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function retire(User $user, int $toolId): array
    {
        return DB::transaction(function () use ($user, $toolId): array {
            $player = $this->lockedPlayerFor($user);
            $tool = $this->ownedLifecycleTool($player, $toolId);

            if ($tool->origin === 'starter') {
                throw ValidationException::withMessages([
                    'tool_id' => 'Field kit tools cannot be retired.',
                ]);
            }

            $replacement = $this->replaceEquippedToolIfNeeded($player, $tool);
            $toolName = $tool->item_name;
            $tool->delete();

            return [
                'type' => 'tool_retire',
                'label' => $toolName,
                'tool_id' => $toolId,
                'replacement_tool' => $replacement === null ? null : $this->players->toolPayload($replacement),
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

    private function ownedLifecycleTool(ConnectedRealmsPlayer $player, int $toolId): ConnectedRealmsTool
    {
        $tool = ConnectedRealmsTool::query()
            ->where('player_id', $player->id)
            ->whereKey($toolId)
            ->lockForUpdate()
            ->first();

        if ($tool === null || ! in_array($tool->status, [ConnectedRealmsTool::STATUS_EQUIPPED, ConnectedRealmsTool::STATUS_INVENTORY], true)) {
            throw ValidationException::withMessages([
                'tool_id' => 'That tool is not available for lifecycle work.',
            ]);
        }

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCostPayload(ConnectedRealmsTool $tool): array
    {
        return [
            'skill' => $tool->skill,
            'durability' => (int) $tool->durability,
            'tier_level' => (int) $tool->tier_level,
            'origin' => $tool->origin,
        ];
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $materials
     */
    private function consumeLifecycleCost(ConnectedRealmsPlayer $player, int $goldCost, array $materials, string $action): void
    {
        if ($player->gold < $goldCost) {
            throw ValidationException::withMessages([
                'tool_id' => "You need {$goldCost}g for that tool {$action}.",
            ]);
        }

        foreach ($materials as $material) {
            $stack = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->where('item_key', $material['item_key'])
                ->lockForUpdate()
                ->first();

            if ($stack === null || $stack->quantity < $material['quantity']) {
                throw ValidationException::withMessages([
                    'tool_id' => "You need {$material['quantity']} {$material['item_name']} for that tool {$action}.",
                ]);
            }
        }

        foreach ($materials as $material) {
            $stack = ConnectedRealmsInventoryStack::query()
                ->where('player_id', $player->id)
                ->where('item_key', $material['item_key'])
                ->lockForUpdate()
                ->firstOrFail();

            $stack->quantity -= $material['quantity'];

            if ($stack->quantity <= 0) {
                $stack->delete();
            } else {
                $stack->save();
            }
        }

        $player->forceFill(['gold' => $player->gold - $goldCost])->save();
    }

    private function syncEquippedToolIfNeeded(ConnectedRealmsTool $tool): ?ConnectedRealmsEquipmentSlot
    {
        if ($tool->status !== ConnectedRealmsTool::STATUS_EQUIPPED) {
            return null;
        }

        $equipment = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $tool->player_id)
            ->where('tool_id', $tool->id)
            ->lockForUpdate()
            ->first();

        return $equipment === null ? null : $this->players->syncEquipmentSlotFromTool($equipment, $tool);
    }

    private function replaceEquippedToolIfNeeded(ConnectedRealmsPlayer $player, ConnectedRealmsTool $tool): ?ConnectedRealmsEquipmentSlot
    {
        if ($tool->status !== ConnectedRealmsTool::STATUS_EQUIPPED) {
            return null;
        }

        return $this->players->equipStarterToolForSlot($player, $tool->slot);
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $materials
     */
    private function grantMaterials(ConnectedRealmsPlayer $player, array $materials): void
    {
        foreach ($materials as $material) {
            $stack = ConnectedRealmsInventoryStack::query()->firstOrNew([
                'player_id' => $player->id,
                'item_key' => $material['item_key'],
            ]);

            $stack->fill([
                'item_name' => $material['item_name'],
                'rarity' => 'common',
                'quantity' => (int) $stack->quantity + $material['quantity'],
            ])->save();
        }
    }
}
