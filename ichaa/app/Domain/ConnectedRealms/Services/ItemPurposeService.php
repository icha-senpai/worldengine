<?php

namespace App\Domain\ConnectedRealms\Services;

class ItemPurposeService
{
    public function __construct(private ItemCatalogService $items) {}

    public function requisitionJobKey(string $itemKey): string
    {
        return 'item_requisition_'.str($itemKey)->slug('_')->toString();
    }

    public function requisitionItemKey(string $jobKey): ?string
    {
        if (! str_starts_with($jobKey, 'item_requisition_')) {
            return null;
        }

        return str($jobKey)->after('item_requisition_')->toString();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function requisitionFor(array $item): array
    {
        $payload = $this->items->enrich([
            ...$item,
            'quantity' => 1,
        ]);
        $itemKey = (string) $payload['item_key'];
        $itemName = (string) $payload['item_name'];
        $skill = $this->skillFor($payload);
        $requiredLevel = $this->requiredLevelFor($payload);
        $label = $this->labelFor($payload);
        $gold = $this->goldFor($payload);
        $experience = $this->experienceFor($payload, $requiredLevel);

        return [
            'key' => $this->requisitionJobKey($itemKey),
            'label' => $label,
            'category' => $this->categoryFor($payload),
            'skill' => $skill,
            'required_level' => $requiredLevel,
            'experience' => $experience,
            'gold' => $gold,
            'requirements' => [[
                'item_key' => $itemKey,
                'item_name' => $itemName,
                'quantity' => 1,
            ]],
            'rewards' => [
                ['type' => 'gold', 'label' => 'Gold', 'quantity' => $gold],
                ['type' => 'experience', 'label' => str($skill)->headline()->toString().' XP', 'quantity' => $experience],
            ],
            'sink' => [
                'type' => 'Oathhall Claim',
                'label' => $label,
                'required_level' => $requiredLevel,
                'context' => str($skill)->headline()->toString(),
            ],
            'purpose' => $this->purposeFor($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{type: string, label: string, required_level: int, context: string}
     */
    public function vendorSinkFor(array $item): array
    {
        $payload = $this->items->enrich([
            ...$item,
            'quantity' => 1,
        ]);

        return [
            'type' => 'NPC Vendor',
            'label' => 'Sell '.$payload['item_name'],
            'required_level' => EvergatherTierCatalog::nextTierLevelFor(1),
            'context' => 'Ledger Steward',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function labelFor(array $item): string
    {
        $itemName = (string) $item['item_name'];

        $suffix = match ((string) $item['item_class']) {
            'resource' => 'Field Sample',
            'material' => 'Workshop Reserve',
            'cargo' => 'Cargo Delivery',
            'consumable' => 'Supply Crate',
            'equipment', 'tool', 'trinket' => 'Appraisal',
            'housing', 'settlement_good', 'structure' => 'Settlement Order',
            default => 'Market Appraisal',
        };

        return "{$itemName} {$suffix}";
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function purposeFor(array $item): string
    {
        $family = (string) $item['material_family'];

        return match ((string) $item['item_class']) {
            'resource' => "{$family} stock can be turned in as a field sample for guild standing, gold, and skill progress.",
            'material' => "{$family} stock feeds workshop reserves when it is not already claimed by a recipe or upgrade.",
            'cargo' => "{$family} shipments can be delivered through the market floor when they are not claimed by expeditions or contracts.",
            'consumable' => "{$family} supplies can be requisitioned into expedition stores for gold and progression.",
            'equipment', 'tool', 'trinket' => "{$family} pieces can be appraised by the guild when they are not better used as equipment.",
            'housing', 'settlement_good', 'structure' => "{$family} pieces can be routed into settlement work orders.",
            default => "{$family} goods can be converted through the guild ledger instead of sitting idle.",
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function categoryFor(array $item): string
    {
        return match ((string) $item['item_class']) {
            'resource' => 'Field Requisitions',
            'material' => 'Workshop Requisitions',
            'cargo' => 'Cargo Requisitions',
            'consumable' => 'Supply Requisitions',
            'equipment', 'tool', 'trinket' => 'Appraisals',
            'housing', 'settlement_good', 'structure' => 'Settlement Requisitions',
            default => 'Market Appraisals',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function skillFor(array $item): string
    {
        $tags = $item['tags'] ?? [];
        $family = str((string) $item['material_family'])->lower()->toString();
        $needle = str(((string) $item['item_key']).' '.((string) $item['item_name']).' '.$family.' '.implode(' ', $tags))->lower()->toString();

        foreach (SkillCatalogService::keys() as $skill) {
            if (str_contains($needle, $skill)) {
                return $skill;
            }
        }

        return match (true) {
            str_contains($needle, 'fish') || str_contains($needle, 'shellfish') || str_contains($needle, 'aquatic') => 'fishing',
            str_contains($needle, 'ore') || str_contains($needle, 'metal') || str_contains($needle, 'fuel') || str_contains($needle, 'gem') || str_contains($needle, 'crystal') => 'mining',
            str_contains($needle, 'wood') || str_contains($needle, 'lumber') || str_contains($needle, 'resin') || str_contains($needle, 'bark') => 'woodcutting',
            str_contains($needle, 'herb') || str_contains($needle, 'mushroom') || str_contains($needle, 'flower') || str_contains($needle, 'bloom') => 'foraging',
            str_contains($needle, 'hide') || str_contains($needle, 'meat') || str_contains($needle, 'sinew') || str_contains($needle, 'bone') || str_contains($needle, 'fang') || str_contains($needle, 'claw') || str_contains($needle, 'feather') => 'hunting',
            str_contains($needle, 'crop') || str_contains($needle, 'seed') || str_contains($needle, 'grain') || str_contains($needle, 'fruit') => 'farming',
            str_contains($needle, 'relic') || str_contains($needle, 'rune') || str_contains($needle, 'tablet') || str_contains($needle, 'clay') || str_contains($needle, 'stone') => 'excavation',
            str_contains($needle, 'food') || str_contains($needle, 'meal') || str_contains($needle, 'supply') => 'cooking',
            str_contains($needle, 'potion') || str_contains($needle, 'oil') => 'alchemy',
            str_contains($needle, 'cloth') || str_contains($needle, 'thread') || str_contains($needle, 'fiber') => 'weaving',
            str_contains($needle, 'trade') || str_contains($needle, 'document') || str_contains($needle, 'map') => 'trading',
            default => 'reputation',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function requiredLevelFor(array $item): int
    {
        return EvergatherTierCatalog::nextTierLevelFor(match ((string) $item['rarity']) {
            'mythic' => 100,
            'legendary' => 80,
            'epic' => 65,
            'rare' => 30,
            'uncommon' => 10,
            default => 1,
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function goldFor(array $item): int
    {
        return max(8, (int) $item['npc_buy_price'] * 3, (int) ceil((int) $item['vendor_value'] * 0.75));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function experienceFor(array $item, int $requiredLevel): int
    {
        return max(18, (int) ceil(((int) $item['quality_score'] / 2) + $requiredLevel));
    }
}
