<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ToolTierUpgradeService
{
    private const RARITY_ORDER = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

    public function __construct(
        private ConnectedRealmsPlayerService $players,
        private ToolCatalogService $tools,
        private ItemCatalogService $items,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(ConnectedRealmsPlayer $player): array
    {
        $equipment = $player->relationLoaded('equipmentSlots')
            ? $player->equipmentSlots
            : $player->equipmentSlots()->orderBy('slot')->get();

        $options = $equipment
            ->map(fn (ConnectedRealmsEquipmentSlot $slot): array => $this->optionFor($slot, $player))
            ->values()
            ->all();

        return [
            'options' => $options,
            'upgradeable_count' => collect($options)->where('is_max_tier', false)->count(),
            'ready_count' => collect($options)->where('can_upgrade', true)->count(),
            'maxed_count' => collect($options)->where('is_max_tier', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function upgrade(User $user, string $slot): array
    {
        return DB::transaction(function () use ($user, $slot): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            $equipment = ConnectedRealmsEquipmentSlot::query()
                ->where('player_id', $player->id)
                ->where('slot', $slot)
                ->lockForUpdate()
                ->first();

            if ($equipment === null) {
                throw ValidationException::withMessages([
                    'slot' => 'That equipped tool is not available.',
                ]);
            }

            $tool = $this->players->ensureToolInstanceForEquipment($equipment);
            $family = $this->tools->familyForSlot($slot);

            if ($family === null) {
                throw ValidationException::withMessages([
                    'slot' => 'That tool does not have a tier path.',
                ]);
            }

            $currentTierLevel = $this->effectiveTierLevel($tool);
            $tier = $this->tools->nextTierFor($family['skill'], $currentTierLevel);

            if ($tier === null) {
                throw ValidationException::withMessages([
                    'slot' => "{$tool->item_name} is already at the highest tier.",
                ]);
            }

            $craftLevel = $this->players->currentSkillLevel($player, $family['craft']);

            if ($craftLevel < $tier['level']) {
                throw ValidationException::withMessages([
                    'slot' => "You need level {$tier['level']} {$family['craft']} to upgrade that tool.",
                ]);
            }

            if ($player->gold < $tier['gold_cost']) {
                throw ValidationException::withMessages([
                    'slot' => "You need {$tier['gold_cost']} gold for that tier upgrade.",
                ]);
            }

            $ingredients = $this->tools->tierIngredients($family, $tier, $tier['extra']);
            $consumed = $this->consumeIngredients($player, $ingredients);
            $itemName = "{$tier['prefix']} {$family['noun']}";
            $itemKey = Str::of($itemName)->slug('_')->toString();
            $previousName = $tool->item_name;

            $player->forceFill([
                'gold' => $player->gold - $tier['gold_cost'],
            ])->save();

            $tool->forceFill([
                'item_key' => $itemKey,
                'item_name' => $itemName,
                'rarity' => $this->higherRarity($tool->rarity, $tier['rarity']),
                'bonuses' => [
                    'skill' => $family['skill'],
                    'experience' => max((int) ($tool->bonuses['experience'] ?? 0), (int) $tier['experience_bonus']),
                    'yield' => max((int) ($tool->bonuses['yield'] ?? 0), (int) $tier['yield_bonus']),
                ],
                'tier_level' => $tier['level'],
                'origin' => $tool->origin === 'starter' ? 'upgraded' : $tool->origin,
                'status' => ConnectedRealmsTool::STATUS_EQUIPPED,
                'upgrade_count' => (int) $tool->upgrade_count + 1,
                'tier_upgrade_count' => (int) $tool->tier_upgrade_count + 1,
            ])->save();

            $this->players->syncEquipmentSlotFromTool($equipment, $tool);
            $this->players->awardSkillExperience($player, $family['craft'], (int) $tier['xp']);

            return [
                'type' => 'tool_tier_upgrade',
                'label' => $tool->item_name,
                'slot' => $tool->slot,
                'slot_label' => str($tool->slot)->headline()->toString(),
                'item_key' => $tool->item_key,
                'item_name' => $tool->item_name,
                'previous_item_name' => $previousName,
                'rarity' => $tool->rarity,
                'tier_level' => $tool->tier_level,
                'skill' => $family['craft'],
                'skill_label' => str($family['craft'])->headline()->toString(),
                'items_consumed' => $consumed,
                'experience_awarded' => (int) $tier['xp'],
                'gold_spent' => (int) $tier['gold_cost'],
                'tool' => $this->players->toolPayload($equipment),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function optionFor(ConnectedRealmsEquipmentSlot $equipment, ConnectedRealmsPlayer $player): array
    {
        $family = $this->tools->familyForSlot($equipment->slot);

        if ($family === null) {
            return [
                'slot' => $equipment->slot,
                'is_max_tier' => true,
                'can_upgrade' => false,
                'status' => 'No tier path',
            ];
        }

        $tool = $equipment->tool ?? $this->players->ensureToolInstanceForEquipment($equipment);
        $tier = $this->tools->nextTierFor($family['skill'], $this->effectiveTierLevel($tool));
        $craftLevel = $this->players->currentSkillLevel($player, $family['craft']);

        if ($tier === null) {
            return [
                'slot' => $equipment->slot,
                'item_name' => $equipment->item_name,
                'current_tier_level' => $tool->tier_level,
                'is_max_tier' => true,
                'can_upgrade' => false,
                'status' => 'Max tier',
            ];
        }

        $ingredients = $this->tools->tierIngredients($family, $tier, $tier['extra']);
        $hasMaterials = $this->hasIngredients($player, $ingredients);
        $hasLevel = $craftLevel >= $tier['level'];
        $hasGold = $player->gold >= $tier['gold_cost'];

        return [
            'slot' => $equipment->slot,
            'item_name' => $equipment->item_name,
            'current_tier_level' => $tool->tier_level,
            'next_item_name' => "{$tier['prefix']} {$family['noun']}",
            'target_tier_level' => $tier['level'],
            'target_rarity' => $tier['rarity'],
            'craft_skill' => $family['craft'],
            'craft_skill_label' => str($family['craft'])->headline()->toString(),
            'craft_skill_level' => $craftLevel,
            'required_level' => $tier['level'],
            'gold_cost' => $tier['gold_cost'],
            'experience_awarded' => $tier['xp'],
            'ingredients' => $this->items->enrichMany($ingredients),
            'can_upgrade' => $hasLevel && $hasGold && $hasMaterials,
            'is_max_tier' => false,
            'status' => $this->statusLabel($hasLevel, $hasGold, $hasMaterials, $tier, $family),
        ];
    }

    private function effectiveTierLevel(ConnectedRealmsTool $tool): int
    {
        return $tool->origin === 'starter' ? 0 : (int) $tool->tier_level;
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     * @return list<array<string, mixed>>
     */
    private function consumeIngredients(ConnectedRealmsPlayer $player, array $ingredients): array
    {
        $itemKeys = collect($ingredients)->pluck('item_key')->all();
        $stacks = ConnectedRealmsInventoryStack::query()
            ->where('player_id', $player->id)
            ->whereIn('item_key', $itemKeys)
            ->lockForUpdate()
            ->get()
            ->keyBy('item_key');

        foreach ($ingredients as $ingredient) {
            $stack = $stacks->get($ingredient['item_key']);

            if ($stack === null || $stack->quantity < $ingredient['quantity']) {
                throw ValidationException::withMessages([
                    'slot' => "You need {$ingredient['quantity']} {$ingredient['item_name']} for that tier upgrade.",
                ]);
            }
        }

        foreach ($ingredients as $ingredient) {
            $stack = $stacks->get($ingredient['item_key']);
            $stack->quantity -= $ingredient['quantity'];

            if ($stack->quantity <= 0) {
                $stack->delete();

                continue;
            }

            $stack->save();
        }

        return $this->items->enrichMany($ingredients);
    }

    /**
     * @param  list<array{item_key: string, item_name: string, quantity: int}>  $ingredients
     */
    private function hasIngredients(ConnectedRealmsPlayer $player, array $ingredients): bool
    {
        $quantities = ConnectedRealmsInventoryStack::query()
            ->where('player_id', $player->id)
            ->whereIn('item_key', collect($ingredients)->pluck('item_key')->all())
            ->pluck('quantity', 'item_key');

        return collect($ingredients)
            ->every(fn (array $ingredient): bool => (int) ($quantities[$ingredient['item_key']] ?? 0) >= $ingredient['quantity']);
    }

    private function higherRarity(string $current, string $target): string
    {
        return array_search($current, self::RARITY_ORDER, true) >= array_search($target, self::RARITY_ORDER, true)
            ? $current
            : $target;
    }

    /**
     * @param  array<string, mixed>  $tier
     * @param  array<string, mixed>  $family
     */
    private function statusLabel(bool $hasLevel, bool $hasGold, bool $hasMaterials, array $tier, array $family): string
    {
        if (! $hasLevel) {
            return "Need {$tier['level']} {$family['craft']}";
        }

        if (! $hasGold) {
            return "Need {$tier['gold_cost']}g";
        }

        if (! $hasMaterials) {
            return 'Need materials';
        }

        return 'Ready';
    }
}
