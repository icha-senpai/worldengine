<?php

namespace App\Domain\Bitcraft\Services;

use UnexpectedValueException;

class BitcraftOpenCrafts
{
    public function rows(array $payload, ?array $player, array $levels, array $skills): array
    {
        $crafts = $payload['craftResults'] ?? null;
        if (! is_array($crafts) || ! array_is_list($crafts)) {
            throw new UnexpectedValueException('Invalid open crafts feed');
        }

        $catalog = [];
        foreach (['items' => 'item', 'cargos' => 'cargo'] as $field => $type) {
            foreach ($payload[$field] ?? [] as $item) {
                $catalog[$type.':'.$item['id']] = $item;
            }
        }
        $skillMap = collect($skills ?: ($player['skillMap'] ?? $payload['skillMap'] ?? []))->keyBy('id')->all();
        $levels = collect($levels)->filter(fn ($row): bool => is_array($row) && is_numeric($row['xp'] ?? null) && ($row['level'] ?? 0) > 0)
            ->sortBy('xp')->values()->all();
        $rows = [];

        foreach ($crafts as $craft) {
            if (! is_array($craft) || ! ctype_digit((string) ($craft['entityId'] ?? ''))
                || ! is_bool($craft['completed'] ?? null) || ! is_bool($craft['isPublic'] ?? null)
                || ! is_numeric($craft['progress'] ?? null) || ! is_numeric($craft['totalActionsRequired'] ?? null)) {
                throw new UnexpectedValueException('Invalid open craft');
            }
            $total = max(0, (int) $craft['totalActionsRequired']);
            $progress = max(0, min($total, (int) $craft['progress']));
            if (! $craft['isPublic'] || $craft['completed'] || $total === 0 || $progress >= $total) {
                continue;
            }

            // Crafting awards the first XP skill; critical progress is not extra XP.
            $experience = $craft['experiencePerProgress'][0] ?? [];
            $skillId = (int) ($experience['skill_id'] ?? $experience[0] ?? $craft['skillId'] ?? 0);
            $perProgress = $experience['quantity'] ?? $experience[1] ?? null;
            $perProgress = is_numeric($perProgress) && $perProgress >= 0 ? round((float) $perProgress, 6) : null;
            $remainingXp = $perProgress === null ? null : round(($total - $progress) * $perProgress, 2);
            $fullXp = $perProgress === null ? null : round($total * $perProgress, 2);
            $currentXp = $player === null ? null : (float) data_get(collect($player['experience'] ?? [])
                ->first(fn (array $entry): bool => (int) ($entry['skill_id'] ?? $entry['skillId'] ?? 0) === $skillId), 'quantity', 0);
            $currentLevel = $currentXp === null || $levels === [] || $skillId < 2 ? null : $this->level($currentXp, $levels);
            $afterLevel = $currentLevel === null || $remainingXp === null ? null : $this->level($currentXp + $remainingXp, $levels);
            $fullLevel = $currentLevel === null || $fullXp === null ? null : $this->level($currentXp + $fullXp, $levels);
            $requirements = [];
            $meetsLevel = $player === null || $levels === [] ? null : true;
            foreach ($craft['levelRequirements'] ?? [] as $requirement) {
                $id = (int) ($requirement['skill_id'] ?? $requirement[0] ?? 0);
                $required = (int) ($requirement['level'] ?? $requirement[1] ?? 0);
                $requirements[] = ['skill' => $this->skillName($id, $skillMap), 'level' => $required];
                if ($meetsLevel !== null) {
                    $xp = (float) data_get(collect($player['experience'] ?? [])->first(fn (array $entry): bool => (int) ($entry['skill_id'] ?? $entry['skillId'] ?? 0) === $id), 'quantity', 0);
                    $meetsLevel = $meetsLevel && $this->level($xp, $levels) >= $required;
                }
            }
            $outputs = [];
            foreach ($craft['craftedItem'] ?? [] as $output) {
                $item = $catalog[($output['item_type'] ?? 'item').':'.($output['item_id'] ?? 0)] ?? [];
                $outputs[] = [
                    'name' => $item['name'] ?? 'Item '.($output['item_id'] ?? '?'),
                    'iconAssetName' => $item['iconAssetName'] ?? null,
                    'quantity' => (int) ($output['quantity'] ?? 1) * (int) ($craft['craftCount'] ?? 1),
                ];
            }
            $rows[] = [
                'id' => (string) $craft['entityId'],
                'name' => implode(', ', array_column($outputs, 'name')) ?: 'Recipe '.($craft['recipeId'] ?? '?'),
                'outputs' => $outputs,
                'claim' => $craft['claimName'] ?? 'Unknown claim',
                'owner' => $craft['ownerUsername'] ?? 'Unknown player',
                'ownerId' => (string) ($craft['ownerEntityId'] ?? ''),
                'building' => $craft['buildingName'] ?? '',
                'region' => (int) ($craft['regionId'] ?? 0),
                'x' => $craft['claimLocationX'] ?? null,
                'z' => $craft['claimLocationZ'] ?? null,
                'count' => (int) ($craft['craftCount'] ?? 1),
                'progressPercent' => round($progress / $total * 100, 1),
                'remainingProgress' => $total - $progress,
                'skillId' => $skillId,
                'skill' => $this->skillName($skillId, $skillMap),
                'remainingXp' => $remainingXp,
                'fullXp' => $fullXp,
                'currentXp' => $currentXp,
                'currentLevel' => $currentLevel,
                'afterLevel' => $afterLevel,
                'fullLevel' => $fullLevel,
                'levelsGained' => $afterLevel === null ? null : $afterLevel - $currentLevel,
                'requirements' => $requirements,
                'meetsLevel' => $meetsLevel,
                'toolRequirements' => $craft['toolRequirements'] ?? [],
            ];
        }

        return $rows;
    }

    private function level(float $xp, array $levels): int
    {
        $level = 1;
        foreach ($levels as $threshold) {
            if ($threshold['xp'] > $xp) {
                break;
            }
            $level = (int) $threshold['level'];
        }

        return $level;
    }

    private function skillName(int $id, array $skills): string
    {
        return filled(data_get($skills, $id.'.name')) ? (string) data_get($skills, $id.'.name') : 'Skill '.$id;
    }
}
