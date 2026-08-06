<?php

namespace App\Domain\ConnectedRealms\Services;

class ItemGuideService
{
    public function __construct(private ItemCatalogService $items, private ToolCatalogService $tools, private ItemPurposeService $purposes) {}

    /**
     * @param  list<array<string, mixed>>  $inventory
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $activities
     * @param  list<array<string, mixed>>  $recipes
     * @param  list<array<string, mixed>>  $jobs
     * @param  list<array<string, mixed>>  $expeditions
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $marketplace
     * @return array<string, mixed>
     */
    public function snapshot(array $inventory, array $actions, array $activities, array $recipes, array $jobs, array $expeditions, array $shop, array $marketplace): array
    {
        $items = [];

        foreach ($inventory as $item) {
            $this->touch($items, $item, ownedQuantity: (int) ($item['quantity'] ?? 0));
        }

        foreach ($actions as $action) {
            foreach ($action['loot_preview'] ?? [] as $item) {
                $this->addSource($items, $item, 'Gathering', (string) $action['label'], (int) ($action['required_level'] ?? 1), (string) ($action['skill_label'] ?? 'Gathering'));
            }
        }

        foreach ($activities as $activity) {
            foreach ($activity['loot_preview'] ?? [] as $item) {
                $this->addSource($items, $item, 'Activity', (string) $activity['label'], (int) ($activity['required_level'] ?? 1), (string) ($activity['skill_label'] ?? 'Activity'));
            }
        }

        foreach ($recipes as $recipe) {
            foreach ($recipe['ingredients'] ?? [] as $item) {
                $this->addSink($items, $item, 'Recipe Ingredient', (string) $recipe['label'], (int) ($recipe['required_level'] ?? 1), (string) ($recipe['skill_label'] ?? 'Crafting'));
            }

            foreach ($recipe['outputs'] ?? [] as $item) {
                $this->addSource($items, $item, 'Crafted Output', (string) $recipe['label'], (int) ($recipe['required_level'] ?? 1), (string) ($recipe['skill_label'] ?? 'Crafting'));
            }
        }

        foreach ($jobs as $job) {
            foreach ($this->materialRows($job) as $item) {
                $this->addSink($items, $item, 'Board Request', (string) $job['label'], (int) ($job['required_level'] ?? 1), (string) ($job['skill_label'] ?? 'Commission'));
            }

            foreach ($this->rewardRows($job) as $item) {
                $this->addSource($items, $item, 'Board Reward', (string) $job['label'], (int) ($job['required_level'] ?? 1), (string) ($job['skill_label'] ?? 'Commission'));
            }
        }

        foreach ($expeditions as $expedition) {
            foreach ($this->materialRows($expedition) as $item) {
                $this->addSink($items, $item, 'Expedition Supply', (string) $expedition['label'], (int) ($expedition['required_level'] ?? 1), (string) ($expedition['skill_label'] ?? 'Expedition'));
            }

            foreach ($this->rewardRows($expedition) as $item) {
                $this->addSource($items, $item, 'Expedition Reward', (string) $expedition['label'], (int) ($expedition['required_level'] ?? 1), (string) ($expedition['skill_label'] ?? 'Expedition'));
            }
        }

        foreach ($shop['offers'] ?? [] as $offer) {
            $this->addSource($items, $offer, 'Market Shop', (string) $offer['label'], (int) ($offer['required_level'] ?? 1), (string) ($offer['group'] ?? 'Shop'));
        }

        foreach ($marketplace['active_listings'] ?? [] as $listing) {
            $this->addSource($items, $listing, 'Player Market', (string) $listing['item_name'], 1, (string) ($listing['seller_name'] ?? 'Market'));
        }

        $this->addToolUpgradeSinks($items);
        $this->addPurposeSinks($items);

        $rows = collect($items)
            ->map(function (array $record): array {
                $payload = $this->items->enrich([
                    ...$record['item'],
                    'quantity' => max(1, (int) $record['owned_quantity']),
                ]);
                $requisition = $this->purposes->requisitionFor($payload);
                $purpose = $record['sinks'][0] ?? $requisition['sink'];

                return [
                    ...$payload,
                    'purpose' => $requisition['purpose'],
                    'purpose_context' => (string) $purpose['context'],
                    'owned_quantity' => (int) $record['owned_quantity'],
                    'source_count' => (int) $record['source_count'],
                    'sink_count' => (int) $record['sink_count'],
                    'sources' => $record['sources'],
                    'sinks' => $record['sinks'],
                    'best_source' => $record['sources'][0] ?? null,
                    'best_sink' => $record['sinks'][0] ?? null,
                    'has_use' => (int) $record['sink_count'] > 0,
                    'has_source' => (int) $record['source_count'] > 0,
                ];
            })
            ->sortBy([
                ['owned_quantity', 'desc'],
                ['sink_count', 'desc'],
                ['source_count', 'desc'],
                ['item_name', 'asc'],
            ])
            ->values();

        return [
            'summary' => [
                'tracked_items' => $rows->count(),
                'owned_items' => $rows->where('owned_quantity', '>', 0)->count(),
                'items_with_sources' => $rows->where('source_count', '>', 0)->count(),
                'items_with_sinks' => $rows->where('sink_count', '>', 0)->count(),
                'items_without_sinks' => $rows->where('sink_count', 0)->count(),
            ],
            'categories' => $rows
                ->groupBy('item_class')
                ->map(fn ($entries, string $itemClass): array => [
                    'key' => $itemClass,
                    'label' => str($itemClass)->headline()->toString(),
                    'count' => $entries->count(),
                ])
                ->values()
                ->all(),
            'items' => $rows->take(240)->all(),
            'owned' => $rows->where('owned_quantity', '>', 0)->take(80)->values()->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, mixed>  $item
     */
    private function touch(array &$items, array $item, int $ownedQuantity = 0): void
    {
        $key = $this->itemKey($item);

        if ($key === '') {
            return;
        }

        $items[$key] ??= [
            'item' => [
                'item_key' => $key,
                'item_name' => $this->itemName($item),
                'rarity' => (string) ($item['rarity'] ?? 'common'),
            ],
            'owned_quantity' => 0,
            'source_count' => 0,
            'sink_count' => 0,
            'sources' => [],
            'sinks' => [],
            'sink_keys' => [],
        ];

        $items[$key]['owned_quantity'] += $ownedQuantity;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, mixed>  $item
     */
    private function addSource(array &$items, array $item, string $type, string $label, int $requiredLevel, string $context): void
    {
        $this->touch($items, $item);
        $key = $this->itemKey($item);

        if ($key !== '') {
            $items[$key]['source_count']++;

            if (count($items[$key]['sources']) < 8) {
                $items[$key]['sources'][] = $this->guideRow($type, $label, $requiredLevel, $context);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, mixed>  $item
     */
    private function addSink(array &$items, array $item, string $type, string $label, int $requiredLevel, string $context): void
    {
        $this->touch($items, $item);
        $key = $this->itemKey($item);

        if ($key !== '') {
            $row = $this->guideRow($type, $label, $requiredLevel, $context);

            $this->appendSink($items[$key], $row);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     */
    private function addToolUpgradeSinks(array &$items): void
    {
        foreach (['common', 'uncommon', 'rare', 'epic', 'legendary'] as $rarity) {
            foreach ($this->tools->rarityMaterials($rarity) as $item) {
                $this->addSink(
                    $items,
                    $item,
                    'Tool Rarity Upgrade',
                    str($rarity)->headline()->toString().' Tool Attunement',
                    1,
                    'Equipment',
                );
            }
        }

        foreach ($this->tools->families() as $family) {
            foreach ($this->tools->tierPath() as $tier) {
                foreach ($this->tools->tierIngredients($family, $tier, $tier['extra']) as $item) {
                    $this->addSink(
                        $items,
                        $item,
                        'Tool Tier Upgrade',
                        $this->tools->tierToolName($family, $tier).' Upgrade',
                        (int) $tier['level'],
                        (string) $family['label'],
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     */
    private function addPurposeSinks(array &$items): void
    {
        foreach ($items as $key => $record) {
            $requisition = $this->purposes->requisitionFor($record['item']);

            foreach ([
                $requisition['sink'],
                $this->purposes->vendorSinkFor($record['item']),
            ] as $sink) {
                $this->appendSink($items[$key], $sink);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $sink
     */
    private function appendSink(array &$record, array $sink): void
    {
        $sinkKey = implode('|', [
            (string) ($sink['type'] ?? ''),
            (string) ($sink['label'] ?? ''),
            (string) ($sink['required_level'] ?? ''),
            (string) ($sink['context'] ?? ''),
        ]);

        if (isset($record['sink_keys'][$sinkKey])) {
            return;
        }

        $record['sink_keys'][$sinkKey] = true;
        $record['sink_count']++;

        if (count($record['sinks']) < 8) {
            $record['sinks'][] = $sink;
        }
    }

    /**
     * @return array{type: string, label: string, required_level: int, item_tier: int, context: string}
     */
    private function guideRow(string $type, string $label, int $requiredLevel, string $context): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'required_level' => $requiredLevel,
            'item_tier' => EvergatherTierCatalog::itemTierForLevel($requiredLevel),
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function materialRows(array $entry): array
    {
        foreach (['materials', 'supplies', 'ingredients', 'required_items', 'inputs'] as $key) {
            if (is_array($entry[$key] ?? null)) {
                return $entry[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function rewardRows(array $entry): array
    {
        foreach (['rewards', 'outputs', 'loot_preview', 'items_awarded', 'items_created'] as $key) {
            if (is_array($entry[$key] ?? null)) {
                return $entry[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemKey(array $item): string
    {
        return (string) ($item['item_key'] ?? $item['key'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemName(array $item): string
    {
        return (string) ($item['item_name'] ?? $item['name'] ?? str($this->itemKey($item))->headline()->toString());
    }
}
