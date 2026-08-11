<?php

namespace App\Domain\ConnectedRealms\Services;

class ItemPurposeService
{
    public function __construct(private ItemCatalogService $items) {}

    /**
     * @var list<string>
     */
    private const REQUISITION_ITEM_KEYS = [];

    /**
     * @var list<string>|null
     */
    private ?array $requisitionItemKeysCache = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $requisitionSourceCache = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $requisitionCache = [];

    /**
     * @var array<string, array{type: string, label: string, required_level: int, context: string}>
     */
    private array $vendorSinkCache = [];

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
     */
    public function isRequisitionEligible(array $item): bool
    {
        $itemKey = (string) ($item['item_key'] ?? $item['key'] ?? '');

        return in_array($itemKey, $this->requisitionItemKeys(), true);
    }

    /**
     * @return list<string>
     */
    public function requisitionItemKeys(): array
    {
        if ($this->requisitionItemKeysCache !== null) {
            return $this->requisitionItemKeysCache;
        }

        $this->requisitionItemKeysCache = array_keys($this->requisitionSources());

        return $this->requisitionItemKeysCache;
    }

    /**
     * @return array<string, string>
     */
    private function requisitionSources(): array
    {
        if ($this->requisitionSourceCache !== null) {
            return $this->requisitionSourceCache;
        }

        $gatheringRewardKeys = collect(GatheringActionService::baseActionDefinitions())
            ->flatMap(fn (array $action): array => $action['loot'] ?? [])
            ->map(fn (array $item): mixed => $item['item_key'] ?? $item['key'] ?? null)
            ->filter(fn (mixed $itemKey): bool => is_string($itemKey) && trim($itemKey) !== '')
            ->all();
        $activityRewardKeys = collect(app(ConnectedRealmsContentService::class)->apply('skill_activities', SkillActivityService::baseActivities()))
            ->flatMap(fn (array $activity): array => $activity['loot'] ?? [])
            ->pluck('item_key')
            ->filter(fn (mixed $itemKey): bool => is_string($itemKey) && trim($itemKey) !== '')
            ->all();
        $expeditionRewardKeys = collect(app(ConnectedRealmsContentService::class)->apply('expeditions', ExpeditionService::baseExpeditions()))
            ->flatMap(fn (array $expedition): array => $expedition['rewards'] ?? [])
            ->pluck('item_key')
            ->filter(fn (mixed $itemKey): bool => is_string($itemKey) && trim($itemKey) !== '')
            ->all();
        $craftOutputKeys = collect(CraftingService::baseRecipes())
            ->flatMap(fn (array $recipe): array => $recipe['outputs'] ?? [])
            ->filter(fn (array $output): bool => ! isset($output['equipment_skill']))
            ->pluck('item_key')
            ->filter(fn (mixed $itemKey): bool => is_string($itemKey) && trim($itemKey) !== '')
            ->all();

        $sources = collect(self::REQUISITION_ITEM_KEYS)
            ->mapWithKeys(fn (string $itemKey): array => [$itemKey => 'manual'])
            ->merge(collect($gatheringRewardKeys)->mapWithKeys(fn (string $itemKey): array => [$itemKey => 'gathering_reward']))
            ->merge(collect($activityRewardKeys)->mapWithKeys(fn (string $itemKey): array => [$itemKey => 'skill_activity_reward']))
            ->merge(collect($expeditionRewardKeys)->mapWithKeys(fn (string $itemKey): array => [$itemKey => 'expedition_reward']))
            ->merge(collect($craftOutputKeys)->mapWithKeys(fn (string $itemKey): array => [$itemKey => 'craft_output']))
            ->sortKeys()
            ->all();

        $this->requisitionSourceCache = $sources;

        return $this->requisitionSourceCache;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function requisitionFor(array $item): array
    {
        $cacheKey = $this->itemCacheKey($item);

        if (array_key_exists($cacheKey, $this->requisitionCache)) {
            return $this->requisitionCache[$cacheKey];
        }

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
        $source = $this->requisitionSources()[$itemKey] ?? 'manual';

        return $this->requisitionCache[$cacheKey] = [
            'key' => $this->requisitionJobKey($itemKey),
            'label' => $label,
            'category' => $this->categoryFor($payload),
            'skill' => $skill,
            'required_level' => $requiredLevel,
            'demand_channel' => $this->demandChannelFor($source),
            'rotation' => 'daily',
            'completion_cap' => $this->completionCapFor($source),
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
            'world_consumer' => $this->worldConsumerFor($payload, $skill, $source),
            'purpose' => $this->purposeFor($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{type: string, label: string, required_level: int, context: string}
     */
    public function vendorSinkFor(array $item): array
    {
        $cacheKey = $this->vendorCacheKey($item);

        if (array_key_exists($cacheKey, $this->vendorSinkCache)) {
            return $this->vendorSinkCache[$cacheKey];
        }

        $itemKey = (string) ($item['item_key'] ?? $item['key'] ?? '');
        $itemName = (string) ($item['item_name'] ?? $item['name'] ?? str($itemKey)->headline()->toString());

        return $this->vendorSinkCache[$cacheKey] = [
            'type' => 'NPC Vendor',
            'label' => 'Sell '.$itemName,
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

    private function demandChannelFor(string $source): string
    {
        return match ($source) {
            'expedition_reward' => 'expedition_research',
            'craft_output' => 'craft_commission',
            default => 'local_procurement',
        };
    }

    private function completionCapFor(string $source): int
    {
        return match ($source) {
            'expedition_reward' => 1,
            'gathering_reward' => 3,
            'craft_output' => 2,
            default => 2,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function worldConsumerFor(array $item, string $skill, string $source): string
    {
        $family = (string) $item['material_family'];

        if ($source === 'expedition_reward') {
            return str($skill)->headline()->toString().' Expedition Research Desk';
        }

        if ($source === 'craft_output') {
            return str($skill)->headline()->toString().' Craft Commission Desk';
        }

        return match ((string) $item['item_class']) {
            'resource' => str($skill)->headline()->toString().' Field Office '.$family.' Reserve',
            'material' => str($skill)->headline()->toString().' Workshop '.$family.' Reserve',
            'cargo' => str($skill)->headline()->toString().' Logistics Desk',
            'consumable' => str($skill)->headline()->toString().' Expedition Stores',
            'equipment', 'tool', 'trinket' => str($skill)->headline()->toString().' Guild Appraisers',
            'housing', 'settlement_good', 'structure' => str($skill)->headline()->toString().' Settlement Works',
            default => str($skill)->headline()->toString().' Ledger Office',
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

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemCacheKey(array $item): string
    {
        return md5(json_encode([
            'item_key' => $item['item_key'] ?? $item['key'] ?? '',
            'item_name' => $item['item_name'] ?? $item['name'] ?? '',
            'rarity' => $item['rarity'] ?? 'common',
            'item_class' => $item['item_class'] ?? null,
            'material_family' => $item['material_family'] ?? null,
            'quality_score' => $item['quality_score'] ?? null,
            'vendor_value' => $item['vendor_value'] ?? null,
            'npc_buy_price' => $item['npc_buy_price'] ?? null,
            'tags' => $item['tags'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function vendorCacheKey(array $item): string
    {
        return (string) ($item['item_key'] ?? $item['key'] ?? $item['item_name'] ?? $item['name'] ?? '');
    }
}
