<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToolRarityUpgradeService
{
    /**
     * @var array<string, array{target: string, success_chance: int, progress_min: int, progress_max: int, gold_cost: int}>
     */
    private const RULES = [
        'common' => ['target' => 'uncommon', 'success_chance' => 35, 'progress_min' => 12, 'progress_max' => 30, 'gold_cost' => 45],
        'uncommon' => ['target' => 'rare', 'success_chance' => 22, 'progress_min' => 8, 'progress_max' => 22, 'gold_cost' => 110],
        'rare' => ['target' => 'epic', 'success_chance' => 12, 'progress_min' => 5, 'progress_max' => 16, 'gold_cost' => 260],
        'epic' => ['target' => 'legendary', 'success_chance' => 6, 'progress_min' => 3, 'progress_max' => 10, 'gold_cost' => 620],
        'legendary' => ['target' => 'mythic', 'success_chance' => 3, 'progress_min' => 2, 'progress_max' => 7, 'gold_cost' => 1400],
    ];

    /**
     * @var array<string, array{experience: int, yield: int}>
     */
    private const RARITY_BONUSES = [
        'common' => ['experience' => 0, 'yield' => 0],
        'uncommon' => ['experience' => 3, 'yield' => 0],
        'rare' => ['experience' => 7, 'yield' => 1],
        'epic' => ['experience' => 14, 'yield' => 2],
        'legendary' => ['experience' => 24, 'yield' => 3],
        'mythic' => ['experience' => 38, 'yield' => 5],
    ];

    public function __construct(private ConnectedRealmsPlayerService $players, private ToolCatalogService $tools, private ItemCatalogService $items) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(ConnectedRealmsPlayer $player): array
    {
        $tools = $player->relationLoaded('equipmentSlots')
            ? $player->equipmentSlots
            : $player->equipmentSlots()->orderBy('slot')->get();

        $options = $tools
            ->map(fn (ConnectedRealmsEquipmentSlot $tool): array => $this->optionFor($tool, $player))
            ->values()
            ->all();

        return [
            'options' => $options,
            'upgradeable_count' => collect($options)->where('is_max_rarity', false)->count(),
            'ready_count' => collect($options)->where('can_upgrade', true)->count(),
            'maxed_count' => collect($options)->where('is_max_rarity', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attempt(User $user, string $slot): array
    {
        return DB::transaction(function () use ($user, $slot): array {
            $player = $this->players->playerForUser($user);
            $player = ConnectedRealmsPlayer::query()
                ->whereKey($player->id)
                ->lockForUpdate()
                ->firstOrFail();

            $tool = ConnectedRealmsEquipmentSlot::query()
                ->where('player_id', $player->id)
                ->where('slot', $slot)
                ->lockForUpdate()
                ->first();

            if ($tool === null) {
                throw ValidationException::withMessages([
                    'slot' => 'That equipped tool is not available.',
                ]);
            }

            $toolInstance = $this->players->ensureToolInstanceForEquipment($tool);
            $family = $this->tools->familyForSlot($tool->slot);
            $rule = self::RULES[$tool->rarity] ?? null;

            if ($rule === null) {
                throw ValidationException::withMessages([
                    'slot' => "{$tool->item_name} is already at the highest rarity.",
                ]);
            }

            if ($player->gold < $rule['gold_cost']) {
                throw ValidationException::withMessages([
                    'slot' => "You need {$rule['gold_cost']} gold for that rarity attempt.",
                ]);
            }

            $materials = $this->tools->rarityMaterials($tool->rarity);
            $consumed = $this->consumeIngredients($player, $materials);
            $craftLevel = $family === null ? 1 : $this->players->currentSkillLevel($player, $family['craft']);
            $successChance = min(75, $rule['success_chance'] + $this->successBonus($craftLevel));
            $progressMin = $rule['progress_min'] + $this->progressBonus($craftLevel);
            $progressMax = $rule['progress_max'] + $this->progressBonus($craftLevel);

            $player->forceFill([
                'gold' => $player->gold - $rule['gold_cost'],
            ])->save();

            $roll = random_int(1, 100);
            $criticalSuccess = $roll <= $successChance;
            $progressGain = $criticalSuccess ? 0 : random_int($progressMin, $progressMax);
            $progressAfterAttempt = $criticalSuccess
                ? (int) $tool->rarity_progress
                : min(100, (int) $tool->rarity_progress + $progressGain);
            $succeeded = $criticalSuccess || $progressAfterAttempt >= 100;
            $previousRarity = $tool->rarity;
            $targetRarity = $rule['target'];

            $tool->forceFill([
                'rarity_upgrade_attempts' => (int) $tool->rarity_upgrade_attempts + 1,
            ]);

            if ($succeeded) {
                $tool->forceFill([
                    'rarity' => $targetRarity,
                    'rarity_progress' => 0,
                    'bonuses' => $this->upgradedBonuses($tool->bonuses ?? [], $previousRarity, $targetRarity),
                    'upgrade_count' => (int) $tool->upgrade_count + 1,
                ])->save();
            } else {
                $tool->forceFill([
                    'rarity_progress' => $progressAfterAttempt,
                ])->save();
            }

            $toolInstance->forceFill([
                'rarity' => $tool->rarity,
                'rarity_progress' => $tool->rarity_progress,
                'bonuses' => $tool->bonuses,
                'upgrade_count' => $tool->upgrade_count,
                'rarity_upgrade_attempts' => $tool->rarity_upgrade_attempts,
            ])->save();

            return [
                'type' => 'tool_rarity_upgrade',
                'label' => $tool->item_name,
                'slot' => $tool->slot,
                'slot_label' => str($tool->slot)->headline()->toString(),
                'item_key' => $tool->item_key,
                'item_name' => $tool->item_name,
                'previous_rarity' => $previousRarity,
                'rarity' => $tool->rarity,
                'target_rarity' => $targetRarity,
                'success' => $succeeded,
                'critical_success' => $criticalSuccess,
                'roll' => $roll,
                'success_chance' => $successChance,
                'base_success_chance' => $rule['success_chance'],
                'craft_skill' => $family['craft'] ?? null,
                'craft_skill_level' => $craftLevel,
                'progress_gained' => $progressGain,
                'rarity_progress' => (int) $tool->rarity_progress,
                'gold_spent' => $rule['gold_cost'],
                'items_consumed' => $consumed,
                'tool' => $this->players->toolPayload($tool),
                'message' => $this->resultMessage($tool, $previousRarity, $targetRarity, $succeeded, $criticalSuccess, $progressGain),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function optionFor(ConnectedRealmsEquipmentSlot $tool, ConnectedRealmsPlayer $player): array
    {
        $rule = self::RULES[$tool->rarity] ?? null;
        $progress = (int) $tool->rarity_progress;
        $family = $this->tools->familyForSlot($tool->slot);
        $craftLevel = $family === null ? 1 : $this->players->currentSkillLevel($player, $family['craft']);
        $materials = $this->tools->rarityMaterials($tool->rarity);
        $hasMaterials = $this->hasIngredients($player, $materials);
        $successChance = $rule === null ? 0 : min(75, $rule['success_chance'] + $this->successBonus($craftLevel));
        $progressBonus = $this->progressBonus($craftLevel);

        return [
            'slot' => $tool->slot,
            'item_key' => $tool->item_key,
            'item_name' => $tool->item_name,
            'current_rarity' => $tool->rarity,
            'target_rarity' => $rule['target'] ?? null,
            'rarity_progress' => $progress,
            'success_chance' => $successChance,
            'base_success_chance' => $rule['success_chance'] ?? 0,
            'progress_gain_min' => $rule === null ? 0 : $rule['progress_min'] + $progressBonus,
            'progress_gain_max' => $rule === null ? 0 : $rule['progress_max'] + $progressBonus,
            'gold_cost' => $rule['gold_cost'] ?? 0,
            'craft_skill' => $family['craft'] ?? null,
            'craft_skill_label' => isset($family['craft']) ? str($family['craft'])->headline()->toString() : null,
            'craft_skill_level' => $craftLevel,
            'materials' => $this->items->enrichMany($materials),
            'can_upgrade' => $rule !== null && $player->gold >= $rule['gold_cost'] && $hasMaterials,
            'is_max_rarity' => $rule === null,
            'status' => $this->statusLabel($rule, (int) $player->gold, $hasMaterials),
        ];
    }

    /**
     * @param  array<string, mixed>  $bonuses
     * @return array<string, mixed>
     */
    private function upgradedBonuses(array $bonuses, string $previousRarity, string $targetRarity): array
    {
        $previous = self::RARITY_BONUSES[$previousRarity] ?? self::RARITY_BONUSES['common'];
        $target = self::RARITY_BONUSES[$targetRarity] ?? $previous;

        return [
            ...$bonuses,
            'experience' => max(0, (int) ($bonuses['experience'] ?? 0) + ($target['experience'] - $previous['experience'])),
            'yield' => max(0, (int) ($bonuses['yield'] ?? 0) + ($target['yield'] - $previous['yield'])),
        ];
    }

    /**
     * @param  array{target: string, success_chance: int, progress_min: int, progress_max: int, gold_cost: int}|null  $rule
     */
    private function statusLabel(?array $rule, int $playerGold, bool $hasMaterials): string
    {
        if ($rule === null) {
            return 'Max rarity';
        }

        if ($playerGold < $rule['gold_cost']) {
            return "Need {$rule['gold_cost']}g";
        }

        if (! $hasMaterials) {
            return 'Need materials';
        }

        return 'Ready';
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
                    'slot' => "You need {$ingredient['quantity']} {$ingredient['item_name']} for that rarity attempt.",
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

    private function successBonus(int $craftLevel): int
    {
        return min(10, intdiv(max(1, $craftLevel), 10));
    }

    private function progressBonus(int $craftLevel): int
    {
        return min(5, intdiv(max(1, $craftLevel), 20));
    }

    private function resultMessage(ConnectedRealmsEquipmentSlot $tool, string $previousRarity, string $targetRarity, bool $succeeded, bool $criticalSuccess, int $progressGain): string
    {
        if ($succeeded && $criticalSuccess) {
            return "{$tool->item_name} jumped from {$previousRarity} to {$targetRarity} on a critical success.";
        }

        if ($succeeded) {
            return "{$tool->item_name} reached {$targetRarity} rarity through banked progress.";
        }

        return "{$tool->item_name} stayed {$previousRarity}, but gained {$progressGain} rarity progress.";
    }
}
