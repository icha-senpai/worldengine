<?php

namespace App\Domain\ConnectedRealms\Services;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsContentEntry;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsExpeditionRun;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsGoldFlow;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryMigration;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketTransaction;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsVendorSale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EconomyAuditService
{
    private const MIGRATION_VERSION = 'evergather-economy-rebuild-v1';

    public function __construct(
        private ConnectedRealmsContentService $content,
        private ItemCatalogService $items,
        private ItemPurposeService $purposes,
        private SkillCatalogService $skills,
        private ToolCatalogService $tools,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $catalogs = $this->catalogs();
        $graph = $this->economyGraph($catalogs);
        $canonicalRegistry = $this->canonicalRegistry($graph['items']);

        return [
            'schema_version' => 1,
            'catalog_counts' => $this->catalogCounts($catalogs),
            'catalog_entries' => $this->catalogEntries($catalogs),
            'content_overrides' => $this->contentOverrides(),
            'tiers' => $catalogs['tiers'],
            'prices' => $this->priceRows($catalogs),
            'currency_sinks' => $this->currencySinkRows(),
            'observed_gold_flows' => $this->observedGoldFlows(),
            'tier_coverage' => $this->tierCoverageRows($catalogs),
            'pilot_loops' => $this->pilotLoops($catalogs, $graph['items']),
            'player_state_fixtures' => $this->playerStateFixtures(),
            'migration_plan' => $this->migrationPlan($canonicalRegistry),
            'items' => $graph['items'],
            'canonical_registry_summary' => $this->canonicalRegistrySummary($canonicalRegistry),
            'canonical_registry' => $canonicalRegistry,
            'edges' => $graph['edges'],
            'violations' => $graph['violations'],
        ];
    }

    /**
     * @return array{
     *     tiers: list<array<string, mixed>>,
     *     skills: array<string, array<string, mixed>>,
     *     tool_families: array<string, array<string, mixed>>,
     *     tool_tiers: array<string, array<string, mixed>>,
     *     gathering_actions: array<string, array<string, mixed>>,
     *     skill_activities: array<string, array<string, mixed>>,
     *     crafting_recipes: array<string, array<string, mixed>>,
     *     job_contracts: array<string, array<string, mixed>>,
     *     expeditions: array<string, array<string, mixed>>,
     *     shop_offers: array<string, array<string, mixed>>
     * }
     */
    public function catalogs(): array
    {
        return [
            'tiers' => EvergatherTierCatalog::tiers(),
            'skills' => collect($this->skills->all())->keyBy('key')->all(),
            'tool_families' => $this->tools->families(),
            'tool_tiers' => collect($this->tools->tierPath())
                ->mapWithKeys(fn (array $tier): array => [$this->toolTierKey($tier) => $tier])
                ->all(),
            'gathering_actions' => $this->content->apply('gathering_actions', GatheringActionService::baseActionDefinitions()),
            'skill_activities' => $this->content->apply('skill_activities', SkillActivityService::baseActivities()),
            'crafting_recipes' => $this->content->apply('crafting_recipes', CraftingService::baseRecipes()),
            'job_contracts' => $this->content->apply('job_contracts', JobContractService::baseJobs()),
            'expeditions' => $this->content->apply('expeditions', ExpeditionService::baseExpeditions()),
            'shop_offers' => $this->content->apply('shop_offers', ShopService::baseOffers()),
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, int>
     */
    public function catalogCounts(array $catalogs): array
    {
        $toolFamilyCount = count($catalogs['tool_families']);
        $toolTierCount = count($catalogs['tool_tiers']);

        return [
            'skills' => count($catalogs['skills']),
            'tool_families' => $toolFamilyCount,
            'tool_tiers' => $toolTierCount,
            'craftable_tool_variants' => $toolFamilyCount * $toolTierCount,
            'gathering_actions' => count($catalogs['gathering_actions']),
            'skill_activities' => count($catalogs['skill_activities']),
            'recipes' => count($catalogs['crafting_recipes']),
            'jobs' => count($catalogs['job_contracts']),
            'expeditions' => count($catalogs['expeditions']),
            'shop_offers' => count($catalogs['shop_offers']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewInventoryMigration(): array
    {
        return $this->inventoryMigrationDryRun($this->itemMigrationRules());
    }

    /**
     * @return array<string, mixed>
     */
    public function applyInventoryMigration(): array
    {
        $migrationRules = $this->itemMigrationRules();
        $stackIds = ConnectedRealmsInventoryStack::query()
            ->whereIn('item_key', array_keys($migrationRules))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $summary = [
            'mode' => 'apply',
            'mutates_database' => true,
            'migration_version' => self::MIGRATION_VERSION,
            'stacks_seen' => count($stackIds),
            'stacks_migrated' => 0,
            'stacks_skipped' => 0,
            'players_affected' => [],
            'quantity_before' => 0,
            'quantity_after' => 0,
            'gold_compensation' => 0,
            'value_delta' => 0,
            'rows' => [],
        ];

        foreach ($stackIds as $stackId) {
            $result = DB::transaction(function () use ($stackId, $migrationRules): ?array {
                $stack = ConnectedRealmsInventoryStack::query()
                    ->whereKey($stackId)
                    ->lockForUpdate()
                    ->first();

                if ($stack === null || ConnectedRealmsInventoryMigration::query()
                    ->where('migration_version', self::MIGRATION_VERSION)
                    ->where('old_stack_id', $stackId)
                    ->exists()) {
                    return null;
                }

                $rule = $migrationRules[$stack->item_key] ?? null;

                if ($rule === null) {
                    return null;
                }

                $row = $this->inventoryMigrationDryRunRow($this->inventoryStackRow($stack), $rule);

                if (! in_array($row['action'], ['convert', 'remove'], true)) {
                    return null;
                }

                if ($row['new_item_key'] !== null && (int) $row['new_quantity'] > 0) {
                    $replacement = ConnectedRealmsInventoryStack::query()->firstOrNew([
                        'player_id' => $stack->player_id,
                        'item_key' => $row['new_item_key'],
                    ]);
                    $replacement->fill([
                        'item_name' => $row['new_item_name'],
                        'rarity' => $stack->rarity,
                        'quantity' => (int) $replacement->quantity + (int) $row['new_quantity'],
                    ]);
                    $replacement->save();
                }

                if ((int) $row['gold_compensation'] > 0) {
                    $player = ConnectedRealmsPlayer::query()
                        ->whereKey($stack->player_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $player->forceFill([
                        'gold' => $player->gold + (int) $row['gold_compensation'],
                    ])->save();

                    ConnectedRealmsGoldFlow::query()->create([
                        'player_id' => $player->id,
                        'flow_key' => 'inventory_migration_compensation',
                        'direction' => ConnectedRealmsGoldFlow::DIRECTION_CREATED,
                        'source_system' => 'inventory_migration',
                        'gold' => (int) $row['gold_compensation'],
                        'subject_type' => ConnectedRealmsInventoryStack::class,
                        'subject_id' => $stack->id,
                        'context' => [
                            'item_key' => $stack->item_key,
                            'migration_version' => self::MIGRATION_VERSION,
                        ],
                        'occurred_at' => now(),
                    ]);
                }

                ConnectedRealmsInventoryMigration::query()->create([
                    'player_id' => $stack->player_id,
                    'old_stack_id' => $stack->id,
                    'item_key' => $row['item_key'],
                    'item_name' => $row['item_name'],
                    'rarity' => $row['rarity'],
                    'old_quantity' => $row['old_quantity'],
                    'new_item_key' => $row['new_item_key'],
                    'new_item_name' => $row['new_item_name'],
                    'new_quantity' => $row['new_quantity'],
                    'conversion_ratio' => $row['conversion_ratio'],
                    'rounding' => $row['rounding'],
                    'quantity_remainder' => $row['quantity_remainder'],
                    'gold_compensation' => $row['gold_compensation'],
                    'value_before' => $row['value_before'],
                    'value_after' => $row['value_after'],
                    'value_delta' => $row['value_delta'],
                    'action' => $row['action'],
                    'migration_version' => self::MIGRATION_VERSION,
                    'reason' => $row['reason'],
                    'applied_at' => now(),
                ]);

                $stack->delete();

                return [
                    ...$row,
                    'player_id' => $stack->player_id,
                    'old_stack_id' => $stack->id,
                ];
            });

            if ($result === null) {
                $summary['stacks_skipped']++;

                continue;
            }

            $summary['stacks_migrated']++;
            $summary['players_affected'][] = (int) $result['player_id'];
            $summary['quantity_before'] += (int) $result['old_quantity'];
            $summary['quantity_after'] += (int) $result['new_quantity'];
            $summary['gold_compensation'] += (int) $result['gold_compensation'];
            $summary['value_delta'] += (int) $result['value_delta'];
            $summary['rows'][] = $result;
        }

        $summary['players_affected'] = count(array_unique($summary['players_affected']));

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array{items: list<array<string, mixed>>, edges: list<array<string, mixed>>, violations: array<string, mixed>}
     */
    public function economyGraph(array $catalogs): array
    {
        $items = [];
        $edges = [];

        foreach ($catalogs['gathering_actions'] as $key => $action) {
            foreach ($action['loot'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'source', 'gathering_action', $key, $action, $item, [
                    'quantity_min' => (int) ($item['min'] ?? $item['quantity'] ?? 1),
                    'quantity_max' => (int) ($item['max'] ?? $item['quantity'] ?? 1),
                ]);

                if ($this->purposes->isRequisitionEligible($item)) {
                    $this->recordItemRequisitionEdge($items, $edges, $item);
                }
            }
        }

        foreach ($catalogs['skill_activities'] as $key => $activity) {
            foreach ($activity['loot'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'source', 'skill_activity', $key, $activity, $item, [
                    'quantity_min' => (int) ($item['min'] ?? $item['quantity'] ?? 1),
                    'quantity_max' => (int) ($item['max'] ?? $item['quantity'] ?? 1),
                ]);

                if ($this->purposes->isRequisitionEligible($item)) {
                    $this->recordItemRequisitionEdge($items, $edges, $item);
                }
            }
        }

        foreach ($catalogs['crafting_recipes'] as $key => $recipe) {
            foreach ($recipe['ingredients'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'sink', 'recipe_ingredient', $key, $recipe, $item, [
                    'classification' => 'primary',
                    'recurrence' => 'recurring',
                ]);
            }

            foreach ($recipe['outputs'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'source', 'recipe_output', $key, $recipe, $item);

                if ($this->purposes->isRequisitionEligible($item)) {
                    $this->recordItemRequisitionEdge($items, $edges, $item);
                }

                if ($this->isLifecycleToolItem($item)) {
                    $this->recordEdge($items, $edges, 'sink', 'tool_lifecycle', $key, [
                        'label' => ((string) ($item['item_name'] ?? $recipe['label'] ?? str($key)->headline()->toString())).' Salvage or Retirement',
                        'required_level' => (int) ($recipe['required_level'] ?? 1),
                        'skill' => $recipe['skill'] ?? null,
                    ], $item, [
                        'classification' => 'maintenance',
                        'recurrence' => 'recurring',
                    ]);
                }
            }
        }

        foreach ($catalogs['job_contracts'] as $key => $job) {
            foreach ($job['requirements'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'sink', 'job_requirement', $key, $job, $item, [
                    'classification' => 'primary',
                    'recurrence' => 'recurring',
                ]);
            }

            foreach ($this->itemRewards($job) as $item) {
                $this->recordEdge($items, $edges, 'source', 'job_reward', $key, $job, $item);
            }
        }

        foreach ($catalogs['expeditions'] as $key => $expedition) {
            foreach ($expedition['supplies'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'sink', 'expedition_supply', $key, $expedition, $item, [
                    'classification' => 'primary',
                    'recurrence' => 'recurring',
                ]);
            }

            foreach ($expedition['rewards'] ?? [] as $item) {
                $this->recordEdge($items, $edges, 'source', 'expedition_reward', $key, $expedition, $item);

                if ($this->purposes->isRequisitionEligible($item)) {
                    $this->recordItemRequisitionEdge($items, $edges, $item);
                }
            }
        }

        foreach ($catalogs['shop_offers'] as $key => $offer) {
            if (($offer['kind'] ?? null) !== 'item') {
                continue;
            }

            $this->recordEdge($items, $edges, 'source', 'shop_offer', $key, $offer, $offer, [
                'quantity_min' => (int) ($offer['quantity'] ?? 1),
                'quantity_max' => (int) ($offer['quantity'] ?? 1),
            ]);
        }

        foreach (['common', 'uncommon', 'rare', 'epic', 'legendary'] as $rarity) {
            foreach ($this->tools->rarityMaterials($rarity) as $item) {
                $this->recordEdge($items, $edges, 'sink', 'tool_rarity_upgrade', $rarity, [
                    'label' => str($rarity)->headline()->toString().' Tool Attunement',
                    'required_level' => 1,
                ], $item, [
                    'classification' => 'maintenance',
                    'recurrence' => 'recurring',
                ]);
            }
        }

        foreach ($this->tools->families() as $skill => $family) {
            foreach ($this->tools->tierPath() as $tier) {
                $routeKey = "{$skill}:{$tier['level']}";

                foreach ($this->tools->tierIngredients($family, $tier, $tier['extra']) as $item) {
                    $this->recordEdge($items, $edges, 'sink', 'tool_tier_upgrade', $routeKey, [
                        'label' => $this->tools->tierToolName($family, $tier).' Upgrade',
                        'required_level' => (int) $tier['level'],
                        'skill' => $family['skill'],
                    ], $item, [
                        'classification' => 'maintenance',
                        'recurrence' => 'recurring',
                    ]);
                }

                $repair = $this->tools->repairCost([
                    'skill' => $family['skill'],
                    'durability' => 50,
                    'tier_level' => (int) $tier['level'],
                    'origin' => 'crafted',
                ]);

                foreach ($repair['materials'] as $item) {
                    $this->recordEdge($items, $edges, 'sink', 'tool_repair', $routeKey, [
                        'label' => $this->tools->tierToolName($family, $tier).' Repair',
                        'required_level' => (int) $tier['level'],
                        'skill' => $family['skill'],
                    ], $item, [
                        'classification' => 'maintenance',
                        'recurrence' => 'recurring',
                    ]);
                }
            }
        }

        foreach (array_keys($items) as $itemKey) {
            $record = $items[$itemKey];

            $this->recordEdge($items, $edges, 'sink', 'npc_vendor', 'ledger_steward', [
                'label' => 'Ledger Steward',
                'required_level' => 1,
            ], $record['item'], [
                'classification' => 'fallback',
                'recurrence' => 'recurring',
            ]);
        }

        $itemRows = $this->itemRows($items);

        return [
            'items' => $itemRows,
            'edges' => collect($edges)->sortBy([
                ['item_key', 'asc'],
                ['edge_type', 'asc'],
                ['system', 'asc'],
                ['route_key', 'asc'],
            ])->values()->all(),
            'violations' => $this->violations($itemRows, $catalogs, $edges),
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return list<array<string, mixed>>
     */
    private function catalogEntries(array $catalogs): array
    {
        $stored = $this->storedEntriesBySurface();

        return collect($catalogs)
            ->except(['tiers'])
            ->flatMap(function (array $entries, string $surface) use ($stored): array {
                return collect($entries)
                    ->map(function (array $entry, string $key) use ($stored, $surface): array {
                        return [
                            'surface' => $surface,
                            'entry_key' => $key,
                            'label' => (string) ($entry['label'] ?? $entry['mark'] ?? $entry['name_mark'] ?? str($key)->headline()->toString()),
                            'required_level' => $entry['required_level'] ?? $entry['level'] ?? null,
                            'source' => isset($stored[$surface][$key]) ? 'database' : 'code',
                        ];
                    })
                    ->all();
            })
            ->merge(collect($catalogs['tiers'])->map(fn (array $tier): array => [
                'surface' => 'tiers',
                'entry_key' => (string) $tier['key_slug'],
                'label' => (string) $tier['mark'],
                'required_level' => (int) $tier['level'],
                'source' => isset($stored['tiers'][$tier['key_slug']]) ? 'database' : 'code',
            ]))
            ->sortBy([
                ['surface', 'asc'],
                ['required_level', 'asc'],
                ['entry_key', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentOverrides(): array
    {
        try {
            return ConnectedRealmsContentEntry::query()
                ->orderBy('surface')
                ->orderBy('entry_key')
                ->get()
                ->map(fn (ConnectedRealmsContentEntry $entry): array => [
                    'surface' => $entry->surface,
                    'entry_key' => $entry->entry_key,
                    'label' => $entry->label,
                    'category' => $entry->category,
                    'required_level' => $entry->required_level,
                    'rarity' => $entry->rarity,
                    'enabled' => $entry->enabled,
                    'sort_order' => $entry->sort_order,
                    'payload' => $entry->payload ?? [],
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return list<array<string, mixed>>
     */
    private function priceRows(array $catalogs): array
    {
        return collect($catalogs['shop_offers'])
            ->map(function (array $offer, string $key): array {
                $item = ($offer['kind'] ?? null) === 'item'
                    ? $this->items->enrich($offer)
                    : null;

                return [
                    'offer_key' => $key,
                    'label' => (string) $offer['label'],
                    'kind' => (string) $offer['kind'],
                    'item_key' => $offer['item_key'],
                    'quantity' => (int) ($offer['quantity'] ?? 1),
                    'price' => (int) $offer['price'],
                    'npc_liquidation_value' => $item === null ? null : (int) $item['total_npc_buy_price'],
                ];
            })
            ->sortBy('offer_key')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currencySinkRows(): array
    {
        $marketPolicy = MarketplaceService::marketPolicy();
        $rows = [[
            'sink_key' => 'player_market_transaction_tax',
            'system' => 'marketplace',
            'classification' => 'transaction_tax',
            'recurrence' => 'recurring',
            'tier' => null,
            'skill' => null,
            'gold_cost' => null,
            'rate' => $marketPolicy['transaction_tax_rate'],
            'minimum_fee' => $marketPolicy['minimum_transaction_fee'],
        ]];

        foreach ($this->tools->families() as $skill => $family) {
            foreach ($this->tools->tierPath() as $tier) {
                $repair = $this->tools->repairCost([
                    'skill' => $family['skill'],
                    'durability' => 50,
                    'tier_level' => (int) $tier['level'],
                    'origin' => 'crafted',
                ]);

                if (! $repair['can_repair']) {
                    continue;
                }

                $rows[] = [
                    'sink_key' => "tool_repair:{$skill}:{$tier['level']}",
                    'system' => 'tool_repair',
                    'classification' => 'repair_fee',
                    'recurrence' => 'recurring',
                    'tier' => EvergatherTierCatalog::itemTierForLevel((int) $tier['level']),
                    'skill' => $family['skill'],
                    'gold_cost' => $repair['gold_cost'],
                    'rate' => null,
                    'minimum_fee' => null,
                ];
            }
        }

        return collect($rows)
            ->sortBy('sink_key')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function observedGoldFlows(): array
    {
        $rows = [
            $this->goldFlowRow(
                'gathering_and_activity_rewards',
                'created',
                'action_logs',
                ConnectedRealmsActionLog::class,
                'gold_awarded',
            ),
            $this->goldFlowRow(
                'job_rewards',
                'created',
                'job_completions',
                ConnectedRealmsJobCompletion::class,
                'gold_awarded',
            ),
            $this->goldFlowRow(
                'expedition_rewards',
                'created',
                'expedition_runs',
                ConnectedRealmsExpeditionRun::class,
                'gold_awarded',
            ),
            $this->goldFlowRow(
                'npc_vendor_sales',
                'created',
                'vendor_sales',
                ConnectedRealmsVendorSale::class,
                'total_price',
            ),
            $this->goldFlowRow(
                'crafting_costs',
                'destroyed',
                'crafting_logs',
                ConnectedRealmsCraftingLog::class,
                'gold_cost',
            ),
            $this->goldFlowRow(
                'player_market_transaction_tax',
                'destroyed',
                'market_transactions',
                ConnectedRealmsMarketTransaction::class,
                'market_fee',
            ),
            $this->goldFlowRow(
                'player_market_seller_payouts',
                'transferred',
                'market_transactions',
                ConnectedRealmsMarketTransaction::class,
                'seller_payout',
            ),
        ];
        $rows = collect($rows)
            ->merge($this->ledgerGoldFlowRows())
            ->values();
        $created = collect($rows)->where('direction', 'created')->sum('gold');
        $destroyed = collect($rows)->where('direction', 'destroyed')->sum('gold');

        return [
            'schema_version' => 1,
            'rows' => $rows->sortBy('flow_key')->values()->all(),
            'totals' => [
                'gold_created' => (int) $created,
                'gold_destroyed' => (int) $destroyed,
                'net_gold_created' => (int) $created - (int) $destroyed,
                'gold_transferred' => (int) collect($rows)->where('direction', 'transferred')->sum('gold'),
            ],
            'known_unmeasured_sinks' => [],
        ];
    }

    /**
     * @return list<array{flow_key: string, direction: string, ledger_table: string, record_count: int, gold: int}>
     */
    private function ledgerGoldFlowRows(): array
    {
        try {
            return ConnectedRealmsGoldFlow::query()
                ->selectRaw('flow_key, direction, COUNT(*) as record_count, COALESCE(SUM(gold), 0) as gold')
                ->groupBy('flow_key', 'direction')
                ->orderBy('flow_key')
                ->get()
                ->map(fn ($row): array => [
                    'flow_key' => (string) $row->flow_key,
                    'direction' => (string) $row->direction,
                    'ledger_table' => 'connected_realms_gold_flows',
                    'record_count' => (int) $row->record_count,
                    'gold' => (int) $row->gold,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @param  class-string  $modelClass
     * @return array{flow_key: string, direction: string, ledger_table: string, record_count: int, gold: int}
     */
    private function goldFlowRow(string $flowKey, string $direction, string $ledgerTable, string $modelClass, string $column): array
    {
        try {
            return [
                'flow_key' => $flowKey,
                'direction' => $direction,
                'ledger_table' => "connected_realms_{$ledgerTable}",
                'record_count' => (int) $modelClass::query()->count(),
                'gold' => (int) $modelClass::query()->sum($column),
            ];
        } catch (QueryException) {
            return [
                'flow_key' => $flowKey,
                'direction' => $direction,
                'ledger_table' => "connected_realms_{$ledgerTable}",
                'record_count' => 0,
                'gold' => 0,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return list<array<string, mixed>>
     */
    private function tierCoverageRows(array $catalogs): array
    {
        return collect($catalogs['tiers'])
            ->map(function (array $tier) use ($catalogs): array {
                $level = (int) $tier['level'];

                return [
                    'tier' => EvergatherTierCatalog::itemTierForLevel($level),
                    'level' => $level,
                    'label' => (string) $tier['mark'],
                    'gathering_actions' => $this->catalogEntriesAtLevel($catalogs['gathering_actions'], $level),
                    'skill_activities' => $this->catalogEntriesAtLevel($catalogs['skill_activities'], $level),
                    'recipes' => $this->catalogEntriesAtLevel($catalogs['crafting_recipes'], $level),
                    'jobs' => $this->catalogEntriesAtLevel($catalogs['job_contracts'], $level),
                    'expeditions' => $this->catalogEntriesAtLevel($catalogs['expeditions'], $level),
                    'tool_variants' => count($catalogs['tool_families']) * $this->catalogEntriesAtLevel($catalogs['tool_tiers'], $level, 'level'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  list<array<string, mixed>>  $itemRows
     * @return array<string, mixed>
     */
    private function pilotLoops(array $catalogs, array $itemRows): array
    {
        $itemsByKey = collect($itemRows)->keyBy('item_key');
        $loops = [
            $this->pilotLoop('mining_smithing_tool_repair', 'Mining -> smelting -> smithing/toolmaking -> tool use/repair', [
                $this->pilotRouteStage($catalogs, 'raw_faucet', 'gathering_actions', 'mine', ['iron_ore', 'coal_chunk']),
                $this->pilotRouteStage($catalogs, 'processing_recipe', 'crafting_recipes', 'iron_bar', ['iron_ore', 'iron_bar']),
                $this->pilotRouteStage($catalogs, 'component_recipe', 'crafting_recipes', 'iron_fittings', ['iron_bar', 'coal_chunk', 'iron_fittings']),
                $this->pilotToolFamilyStage($catalogs, 'durable_tool_family', 'mining', ['iron_bar']),
                $this->pilotRouteStage($catalogs, 'bounded_job_demand', 'job_contracts', 'quarry_smelter', ['iron_bar'], true),
                $this->pilotRouteStage($catalogs, 'bounded_component_demand', 'job_contracts', 'fittings_batch', ['iron_fittings'], true),
                $this->pilotItemStage($itemsByKey, 'recurring_repair_sink', 'iron_bar', 'tool_repair'),
            ]),
            $this->pilotLoop('fishing_farming_cooking_expedition', 'Fishing/farming -> cooking -> consumable use -> expedition demand', [
                $this->pilotRouteStage($catalogs, 'fishing_raw_faucet', 'gathering_actions', 'fish', ['river_minnow']),
                $this->pilotRouteStage($catalogs, 'farming_raw_faucet', 'gathering_actions', 'farm', ['sunfield_grain']),
                $this->pilotRouteStage($catalogs, 'cooked_meal_recipe', 'crafting_recipes', 'grilled_minnow', ['river_minnow', 'grilled_minnow']),
                $this->pilotRouteStage($catalogs, 'tier_two_meal_recipe', 'crafting_recipes', 'grain_flatbread', ['sunfield_grain', 'field_bean', 'grain_flatbread']),
                $this->pilotRouteStage($catalogs, 'bounded_meal_job_demand', 'job_contracts', 'pier_provisions', ['grilled_minnow'], true),
                $this->pilotRouteStage($catalogs, 'bounded_flatbread_job_demand', 'job_contracts', 'flatbread_cache', ['grain_flatbread'], true),
                $this->pilotRouteStage($catalogs, 'expedition_consumable_sink', 'expeditions', 'moonwake_supply_run', ['grilled_minnow']),
                $this->pilotRouteStage($catalogs, 'tier_two_expedition_consumable_sink', 'expeditions', 'training_ring', ['grain_flatbread']),
            ]),
        ];

        return [
            'schema_version' => 1,
            'tier_range' => [1, 2],
            'loops' => $loops,
            'incomplete_loops' => collect($loops)
                ->reject(fn (array $loop): bool => $loop['is_complete'])
                ->pluck('loop_key')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, mixed>
     */
    private function pilotLoop(string $loopKey, string $label, array $stages): array
    {
        $missingStages = collect($stages)
            ->reject(fn (array $stage): bool => $stage['is_complete'])
            ->pluck('stage_key')
            ->values()
            ->all();

        return [
            'loop_key' => $loopKey,
            'label' => $label,
            'is_complete' => $missingStages === [],
            'missing_stages' => $missingStages,
            'stages' => $stages,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  list<string>  $itemKeys
     * @return array<string, mixed>
     */
    private function pilotRouteStage(array $catalogs, string $stageKey, string $surface, string $routeKey, array $itemKeys, bool $requiresBoundedDemand = false): array
    {
        $route = $catalogs[$surface][$routeKey] ?? null;
        $presentItemKeys = $route === null ? [] : $this->routeItemKeys($route);
        $missingItemKeys = collect($itemKeys)
            ->reject(fn (string $itemKey): bool => in_array($itemKey, $presentItemKeys, true))
            ->values()
            ->all();
        $hasBoundedDemand = ! $requiresBoundedDemand || ($route !== null && $this->hasBoundedDemand($route));

        return [
            'stage_key' => $stageKey,
            'surface' => $surface,
            'route_key' => $routeKey,
            'route_label' => $route['label'] ?? null,
            'required_level' => $route['required_level'] ?? null,
            'required_items' => $itemKeys,
            'missing_items' => $missingItemKeys,
            'requires_bounded_demand' => $requiresBoundedDemand,
            'has_bounded_demand' => $hasBoundedDemand,
            'is_complete' => $route !== null && $missingItemKeys === [] && $hasBoundedDemand,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  list<string>  $baseItemKeys
     * @return array<string, mixed>
     */
    private function pilotToolFamilyStage(array $catalogs, string $stageKey, string $skill, array $baseItemKeys): array
    {
        $family = $catalogs['tool_families'][$skill] ?? null;
        $firstTier = collect($catalogs['tool_tiers'])->sortBy('level')->first();
        $tierIngredients = $family === null || $firstTier === null
            ? []
            : $this->tools->tierIngredients($family, $firstTier, $firstTier['extra'] ?? null);
        $presentItemKeys = collect($tierIngredients)
            ->map(fn (array $item): string => $this->itemKey($item))
            ->values()
            ->all();
        $missingItemKeys = collect($baseItemKeys)
            ->reject(fn (string $itemKey): bool => in_array($itemKey, $presentItemKeys, true))
            ->values()
            ->all();

        return [
            'stage_key' => $stageKey,
            'surface' => 'tool_families',
            'route_key' => $skill,
            'route_label' => $family['label'] ?? null,
            'craft_skill' => $family['craft'] ?? null,
            'required_level' => $firstTier['level'] ?? null,
            'required_items' => $baseItemKeys,
            'missing_items' => $missingItemKeys,
            'requires_bounded_demand' => false,
            'has_bounded_demand' => true,
            'is_complete' => $family !== null && $firstTier !== null && $missingItemKeys === [],
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $itemsByKey
     * @return array<string, mixed>
     */
    private function pilotItemStage(Collection $itemsByKey, string $stageKey, string $itemKey, string $requiredSinkSystem): array
    {
        $item = $itemsByKey->get($itemKey);
        $sinkSystems = collect($item['primary_uses'] ?? [])
            ->pluck('system')
            ->values()
            ->all();

        return [
            'stage_key' => $stageKey,
            'surface' => 'items',
            'route_key' => $itemKey,
            'route_label' => $item['item_name'] ?? null,
            'required_level' => null,
            'required_items' => [$itemKey],
            'missing_items' => $item === null ? [$itemKey] : [],
            'requires_bounded_demand' => false,
            'has_bounded_demand' => true,
            'required_sink_system' => $requiredSinkSystem,
            'has_required_sink_system' => in_array($requiredSinkSystem, $sinkSystems, true),
            'is_complete' => $item !== null && in_array($requiredSinkSystem, $sinkSystems, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function routeItemKeys(array $route): array
    {
        return collect([
            ...($route['loot'] ?? []),
            ...($route['ingredients'] ?? []),
            ...($route['outputs'] ?? []),
            ...($route['requirements'] ?? []),
            ...($route['supplies'] ?? []),
            ...($route['rewards'] ?? []),
            $route,
        ])
            ->map(fn (array $item): string => $this->itemKey($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function hasBoundedDemand(array $route): bool
    {
        return collect(['completion_cap', 'quota', 'cooldown_seconds', 'rotation'])
            ->contains(fn (string $key): bool => array_key_exists($key, $route));
    }

    /**
     * @return array<string, mixed>
     */
    private function playerStateFixtures(): array
    {
        try {
            $players = ConnectedRealmsPlayer::query()
                ->with(['skills', 'inventoryStacks', 'tools', 'equipmentSlots'])
                ->orderBy('id')
                ->get()
                ->map(fn (ConnectedRealmsPlayer $player): array => $this->playerFixtureCandidate($player));
        } catch (QueryException) {
            $players = collect();
        }

        return [
            'schema_version' => 1,
            'generated_from' => 'connected_realms_players',
            'selection_rules' => [
                'new_player' => 'Lowest-id player with no inventory, no non-starter tools, and no skill above level 1.',
                'midgame_player' => 'Player whose highest skill is between levels 10 and 64, preferring the highest level then lowest id.',
                'max_tier_player' => 'Player with any skill or tool tier at level 100 or above, preferring the highest level then lowest id.',
                'inventory_heavy_player' => 'Player with the most total inventory quantity, then most distinct item stacks, then lowest id.',
                'unique_tool_player' => 'Player with the most non-starter unique tools, then most total tools, then lowest id.',
            ],
            'fixtures' => [
                'new_player' => $this->selectedFixture($players, fn (array $player): bool => $player['max_skill_level'] <= 1
                    && $player['inventory_stack_count'] === 0
                    && $player['unique_tool_count'] === 0),
                'midgame_player' => $this->selectedFixture(
                    $players
                        ->filter(fn (array $player): bool => $player['max_skill_level'] >= 10 && $player['max_skill_level'] < 65)
                        ->sortBy([['max_skill_level', 'desc'], ['player_id', 'asc']]),
                ),
                'max_tier_player' => $this->selectedFixture(
                    $players
                        ->filter(fn (array $player): bool => $player['max_skill_level'] >= 100 || $player['max_tool_tier_level'] >= 100)
                        ->sortBy([['max_skill_level', 'desc'], ['max_tool_tier_level', 'desc'], ['player_id', 'asc']]),
                ),
                'inventory_heavy_player' => $this->selectedFixture(
                    $players
                        ->filter(fn (array $player): bool => $player['inventory_total_quantity'] > 0)
                        ->sortBy([['inventory_total_quantity', 'desc'], ['inventory_stack_count', 'desc'], ['player_id', 'asc']]),
                ),
                'unique_tool_player' => $this->selectedFixture(
                    $players
                        ->filter(fn (array $player): bool => $player['unique_tool_count'] > 0)
                        ->sortBy([['unique_tool_count', 'desc'], ['tool_count', 'desc'], ['player_id', 'asc']]),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function playerFixtureCandidate(ConnectedRealmsPlayer $player): array
    {
        $skills = $player->skills
            ->sortBy('skill')
            ->map(fn ($skill): array => [
                'skill' => $skill->skill,
                'level' => (int) $skill->level,
                'experience' => (int) $skill->experience,
            ])
            ->values();
        $inventory = $player->inventoryStacks
            ->sortBy('item_key')
            ->map(fn ($stack): array => [
                'item_key' => $stack->item_key,
                'item_name' => $stack->item_name,
                'rarity' => $stack->rarity,
                'quantity' => (int) $stack->quantity,
            ])
            ->values();
        $tools = $player->tools
            ->sortBy('id')
            ->map(fn (ConnectedRealmsTool $tool): array => [
                'tool_id' => (int) $tool->id,
                'item_key' => $tool->item_key,
                'item_name' => $tool->item_name,
                'skill' => $tool->skill,
                'slot' => $tool->slot,
                'rarity' => $tool->rarity,
                'status' => $tool->status,
                'origin' => $tool->origin,
                'durability' => (int) $tool->durability,
                'tier_level' => (int) $tool->tier_level,
                'upgrade_count' => (int) $tool->upgrade_count,
                'tier_upgrade_count' => (int) $tool->tier_upgrade_count,
                'rarity_upgrade_attempts' => (int) $tool->rarity_upgrade_attempts,
                'maker_name' => $tool->maker_name,
                'bonuses' => $tool->bonuses ?? [],
            ])
            ->values();

        return [
            'player_id' => (int) $player->id,
            'display_name' => $player->display_name,
            'gold' => (int) $player->gold,
            'max_skill_level' => (int) ($skills->max('level') ?? 1),
            'total_skill_experience' => (int) $skills->sum('experience'),
            'skill_count' => $skills->count(),
            'inventory_stack_count' => $inventory->count(),
            'inventory_total_quantity' => (int) $inventory->sum('quantity'),
            'tool_count' => $tools->count(),
            'unique_tool_count' => $tools->where('origin', '!=', 'starter')->count(),
            'equipped_tool_count' => $tools->where('status', ConnectedRealmsTool::STATUS_EQUIPPED)->count(),
            'listed_tool_count' => $tools->where('status', ConnectedRealmsTool::STATUS_LISTED)->count(),
            'max_tool_tier_level' => (int) ($tools->max('tier_level') ?? 0),
            'skills' => $skills->all(),
            'inventory_stacks' => $inventory->all(),
            'tools' => $tools->all(),
            'equipment_slots' => $player->equipmentSlots
                ->sortBy('slot')
                ->map(fn ($slot): array => [
                    'slot' => $slot->slot,
                    'tool_id' => $slot->tool_id === null ? null : (int) $slot->tool_id,
                    'item_key' => $slot->item_key,
                    'item_name' => $slot->item_name,
                    'rarity' => $slot->rarity,
                    'durability' => (int) $slot->durability,
                    'bonuses' => $slot->bonuses ?? [],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $players
     * @param  (callable(array<string, mixed>): bool)|null  $predicate
     * @return array<string, mixed>
     */
    private function selectedFixture(Collection $players, ?callable $predicate = null): array
    {
        $selected = $predicate === null ? $players->first() : $players->first($predicate);

        return [
            'matched' => $selected !== null,
            'player' => $selected,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function catalogEntriesAtLevel(array $entries, int $level, string $field = 'required_level'): int
    {
        return collect($entries)
            ->filter(fn (array $entry): bool => (int) ($entry[$field] ?? $entry['level'] ?? 1) === $level)
            ->count();
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $extra
     */
    private function recordEdge(array &$items, array &$edges, string $edgeType, string $system, string $routeKey, array $route, array $item, array $extra = []): void
    {
        $itemKey = $this->itemKey($item);

        if ($itemKey === '') {
            return;
        }

        $itemName = $this->itemName($item);
        $items[$itemKey] ??= [
            'item' => [
                'item_key' => $itemKey,
                'item_name' => $itemName,
                'rarity' => (string) ($item['rarity'] ?? 'common'),
                ...array_filter([
                    'item_tier' => $item['item_tier'] ?? null,
                    'item_class' => $item['item_class'] ?? null,
                    'material_family' => $item['material_family'] ?? null,
                    'vendor_value' => $item['vendor_value'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'tags' => $item['tags'] ?? null,
                    'stack_limit' => $item['stack_limit'] ?? null,
                    'tradeable' => $item['tradeable'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ],
            'sources' => [],
            'primary_sinks' => [],
            'maintenance_sinks' => [],
            'fallback_sinks' => [],
            'transfer_routes' => [],
        ];

        $classification = (string) ($extra['classification'] ?? ($edgeType === 'source' ? 'source' : 'primary'));
        $recurrence = (string) ($extra['recurrence'] ?? 'recurring');
        $edge = [
            'edge_type' => $edgeType,
            'classification' => $classification,
            'recurrence' => $recurrence,
            'system' => $system,
            'route_key' => $routeKey,
            'route_label' => (string) ($route['label'] ?? $route['name'] ?? str($routeKey)->headline()->toString()),
            'skill' => $route['skill'] ?? null,
            'required_level' => (int) ($route['required_level'] ?? 1),
            'item_tier' => EvergatherTierCatalog::itemTierForLevel((int) ($route['required_level'] ?? 1)),
            'item_key' => $itemKey,
            'item_name' => $itemName,
            'quantity_min' => (int) ($extra['quantity_min'] ?? $item['min'] ?? $item['quantity'] ?? 1),
            'quantity_max' => (int) ($extra['quantity_max'] ?? $item['max'] ?? $item['quantity'] ?? 1),
        ];

        $edges[] = $edge;

        if ($edgeType === 'source') {
            $items[$itemKey]['sources'][] = $edge;

            return;
        }

        match ($classification) {
            'maintenance' => $items[$itemKey]['maintenance_sinks'][] = $edge,
            'fallback' => $items[$itemKey]['fallback_sinks'][] = $edge,
            'transfer' => $items[$itemKey]['transfer_routes'][] = $edge,
            default => $items[$itemKey]['primary_sinks'][] = $edge,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $item
     */
    private function recordItemRequisitionEdge(array &$items, array &$edges, array $item): void
    {
        $requisition = $this->purposes->requisitionFor($item);

        $this->recordEdge($items, $edges, 'sink', 'item_requisition', (string) $requisition['key'], $requisition, $item, [
            'classification' => 'primary',
            'recurrence' => 'recurring',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isLifecycleToolItem(array $item): bool
    {
        if (isset($item['equipment_skill'])) {
            return true;
        }

        return $this->items->enrich($item)['item_class'] === 'tool';
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function itemRows(array $items): array
    {
        return collect($items)
            ->map(function (array $record, string $itemKey): array {
                $payload = $this->items->enrich($record['item']);
                $primarySinkCount = count($record['primary_sinks']) + count($record['maintenance_sinks']);
                $status = match (true) {
                    count($record['sources']) === 0 => 'missing_source',
                    $primarySinkCount > 0 => 'healthy',
                    count($record['fallback_sinks']) > 0 => 'fallback_only',
                    count($record['transfer_routes']) > 0 => 'transfer_only',
                    default => 'missing_use',
                };

                return [
                    'item_key' => $itemKey,
                    'item_name' => $payload['item_name'],
                    'tier' => $payload['item_tier'],
                    'rarity' => $payload['rarity'],
                    'item_class' => $payload['item_class'],
                    'material_family' => $payload['material_family'],
                    'source_count' => count($record['sources']),
                    'primary_use_count' => $primarySinkCount,
                    'recurring_sink_count' => collect([...$record['primary_sinks'], ...$record['maintenance_sinks']])
                        ->where('recurrence', 'recurring')
                        ->count(),
                    'fallback_sink_count' => count($record['fallback_sinks']),
                    'transfer_route_count' => count($record['transfer_routes']),
                    'status' => $status,
                    'sources' => $this->edgeRefs($record['sources']),
                    'primary_uses' => $this->edgeRefs([...$record['primary_sinks'], ...$record['maintenance_sinks']]),
                    'fallback_sinks' => $this->edgeRefs($record['fallback_sinks']),
                    'transfer_routes' => $this->edgeRefs($record['transfer_routes']),
                ];
            })
            ->sortBy('item_key')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function edgeRefs(array $edges): array
    {
        return collect($edges)
            ->map(fn (array $edge): array => [
                'system' => $edge['system'],
                'route_key' => $edge['route_key'],
                'route_label' => $edge['route_label'],
                'skill' => $edge['skill'],
                'required_level' => $edge['required_level'],
                'item_tier' => $edge['item_tier'],
                'classification' => $edge['classification'],
                'recurrence' => $edge['recurrence'],
                'quantity_min' => $edge['quantity_min'],
                'quantity_max' => $edge['quantity_max'],
            ])
            ->unique(fn (array $edge): string => implode('|', $edge))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $itemRows
     * @return list<array<string, mixed>>
     */
    private function canonicalRegistry(array $itemRows): array
    {
        $migrationRules = $this->itemMigrationRules();

        return collect($itemRows)
            ->map(function (array $item) use ($migrationRules): array {
                $migrationRule = $migrationRules[$item['item_key']] ?? null;
                $disposition = $this->registryDisposition($item, $migrationRule);

                return [
                    'item_key' => $item['item_key'],
                    'display_name' => $item['item_name'],
                    'tier' => $item['tier'],
                    'rarity' => $item['rarity'],
                    'item_class' => $item['item_class'],
                    'material_family' => $item['material_family'],
                    'economic_role' => $this->economicRole($item),
                    'producing_skill' => $this->firstEdgeSkill($item['sources']),
                    'consuming_skill' => $this->firstEdgeSkill($item['primary_uses']),
                    'sources' => $item['sources'],
                    'primary_uses' => $item['primary_uses'],
                    'recurring_sinks' => collect($item['primary_uses'])
                        ->where('recurrence', 'recurring')
                        ->values()
                        ->all(),
                    'fallback_sinks' => $item['fallback_sinks'],
                    'tradeable' => (int) $item['transfer_route_count'] > 0,
                    'stackable' => ! in_array($item['item_class'], ['equipment', 'tool'], true),
                    'status' => $this->registryStatus($item, $disposition, $migrationRule),
                    'disposition' => $disposition,
                    'replacement_key' => $migrationRule['replacement_key'] ?? null,
                    'conversion_ratio' => $migrationRule['conversion_ratio'] ?? null,
                    'migration_outcome' => $migrationRule['outcome'] ?? $this->defaultMigrationOutcome($disposition),
                    'migration_version' => $migrationRule === null ? null : $migrationRule['migration_version'],
                    'alias_keys' => $migrationRule['alias_keys'] ?? [],
                    'design_note' => $this->registryDesignNote($item, $disposition, $migrationRule),
                ];
            })
            ->sortBy('item_key')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $registry
     * @return array<string, mixed>
     */
    private function canonicalRegistrySummary(array $registry): array
    {
        return [
            'total_items' => count($registry),
            'by_disposition' => collect($registry)
                ->countBy('disposition')
                ->sortKeys()
                ->all(),
            'by_status' => collect($registry)
                ->countBy('status')
                ->sortKeys()
                ->all(),
            'unexplained_fallback_only_items' => collect($registry)
                ->filter(fn (array $item): bool => $item['disposition'] === 'keep' && $item['status'] === 'needs_primary_use')
                ->pluck('item_key')
                ->values()
                ->all(),
            'items_requiring_replacement' => collect($registry)
                ->filter(fn (array $item): bool => in_array($item['disposition'], ['merge', 'rename', 'retire'], true)
                    && $item['replacement_key'] === null
                    && ! in_array($item['migration_outcome'], ['preserve', 'remove_with_reason'], true))
                ->pluck('item_key')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function registryDisposition(array $item, ?array $migrationRule = null): string
    {
        if ($migrationRule !== null) {
            return $migrationRule['disposition'];
        }

        if ($item['item_class'] === 'currency') {
            return 'currency';
        }

        if (in_array($item['item_class'], ['collectible', 'trinket'], true)) {
            return 'collectible';
        }

        if ($item['status'] === 'healthy') {
            return 'keep';
        }

        return 'repurpose';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function registryStatus(array $item, string $disposition, ?array $migrationRule = null): string
    {
        if ($migrationRule !== null && in_array($disposition, ['merge', 'rename', 'retire'], true)) {
            return 'deprecated';
        }

        if ($disposition === 'currency') {
            return 'currency';
        }

        if ($disposition === 'collectible') {
            return 'collectible';
        }

        return match ($item['status']) {
            'healthy' => 'active',
            'missing_source' => 'needs_source',
            'fallback_only', 'missing_use', 'transfer_only' => 'needs_primary_use',
            default => 'needs_review',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function economicRole(array $item): string
    {
        if ($item['item_class'] === 'currency') {
            return 'currency';
        }

        if (in_array($item['item_class'], ['collectible', 'trinket'], true)) {
            return 'collectible';
        }

        if (in_array($item['item_class'], ['equipment', 'tool'], true)) {
            return 'finished_good';
        }

        if ((int) $item['recurring_sink_count'] > 0) {
            return 'maintenance_good';
        }

        if ((int) $item['source_count'] > 0 && (int) $item['primary_use_count'] > 0) {
            return 'intermediate';
        }

        if ((int) $item['source_count'] > 0) {
            return 'unassigned_reward';
        }

        return 'input';
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function firstEdgeSkill(array $edges): ?string
    {
        return collect($edges)
            ->pluck('skill')
            ->filter()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function registryDesignNote(array $item, string $disposition, ?array $migrationRule = null): string
    {
        if (is_string($migrationRule['reason'] ?? null) && $migrationRule['reason'] !== '') {
            return $migrationRule['reason'];
        }

        if ($disposition === 'repurpose') {
            return match ($item['status']) {
                'missing_source' => 'No runtime source is recorded; assign a source or migration target before activation.',
                'fallback_only' => 'Only fallback disposal is recorded; assign a designed consumer before treating this item as active.',
                'transfer_only' => 'Only transfer routes are recorded; add a real sink because markets do not destroy supply.',
                default => 'No primary use is recorded; assign a designed consumer or consolidate into another item.',
            };
        }

        if ($disposition === 'collectible') {
            return 'Excluded from normal sink requirements as a collectible-style item.';
        }

        if ($disposition === 'currency') {
            return 'Currency item; verify earn and spend loop during balance pass.';
        }

        return 'Resolved runtime graph records at least one designed use.';
    }

    private function defaultMigrationOutcome(string $disposition): string
    {
        return match ($disposition) {
            'currency', 'collectible', 'keep', 'repurpose' => 'preserve',
            default => 'requires_mapping',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $canonicalRegistry
     * @return array<string, mixed>
     */
    private function migrationPlan(array $canonicalRegistry): array
    {
        $migrationRules = $this->itemMigrationRules();
        $registryByKey = collect($canonicalRegistry)->keyBy('item_key');
        $ruleRows = collect($migrationRules)
            ->map(function (array $rule, string $itemKey) use ($registryByKey): array {
                $replacementKey = $rule['replacement_key'];

                return [
                    'item_key' => $itemKey,
                    'disposition' => $rule['disposition'],
                    'replacement_key' => $replacementKey,
                    'replacement_item_name' => $rule['replacement_item_name'] ?? null,
                    'conversion_ratio' => $rule['conversion_ratio'],
                    'rounding' => $rule['rounding'],
                    'outcome' => $rule['outcome'],
                    'reason' => $rule['reason'],
                    'alias_keys' => $rule['alias_keys'],
                    'source' => $rule['source'],
                    'is_known_registry_item' => $registryByKey->has($itemKey),
                    'replacement_is_known_registry_item' => $replacementKey === null || $registryByKey->has($replacementKey),
                    'requires_review' => $this->migrationRuleRequiresReview($rule, $registryByKey->has($replacementKey ?? '')),
                ];
            })
            ->sortBy('item_key')
            ->values()
            ->all();

        return [
            'schema_version' => 1,
            'migration_version' => self::MIGRATION_VERSION,
            'rules' => $ruleRows,
            'rules_requiring_review' => collect($ruleRows)
                ->where('requires_review', true)
                ->pluck('item_key')
                ->values()
                ->all(),
            'inventory_dry_run' => $this->inventoryMigrationDryRun($migrationRules),
            'rollback_policy' => [
                'requires_database_backup_before_force' => true,
                'automatic_rollback_supported' => false,
                'authoritative_restore_path' => 'Restore the pre-migration database backup before re-running application traffic.',
                'audit_record' => 'connected_realms_inventory_migrations records old and new quantities, values, action, reason, player, and migration version for review.',
                'reason' => 'Applied replacement stacks may merge with existing inventory, so exact reversal is not safe without the pre-apply backup.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function migrationRuleRequiresReview(array $rule, bool $replacementIsKnown): bool
    {
        if (in_array($rule['disposition'], ['merge', 'rename'], true)) {
            return $rule['replacement_key'] === null || ! $replacementIsKnown;
        }

        if ($rule['disposition'] === 'retire') {
            return $rule['replacement_key'] === null && $rule['outcome'] !== 'remove_with_reason';
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemMigrationRules(): array
    {
        $overrideKeys = $this->itemRuleOverrideKeys();
        $generatedRules = SkillActivityService::legacyActivityRewardMigrationRules();
        $contentRules = collect($this->content->apply('item_rules', $this->items->baseKeyRules()))
            ->mapWithKeys(function (array $rule, string $itemKey) use ($overrideKeys): array {
                $migration = $this->normalizedItemMigrationRule($itemKey, $rule, in_array($itemKey, $overrideKeys, true));

                return $migration === null ? [] : [$itemKey => $migration];
            })
            ->all();

        return collect([...$generatedRules, ...$contentRules])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>|null
     */
    private function normalizedItemMigrationRule(string $itemKey, array $rule, bool $isDatabaseOverride): ?array
    {
        $migration = is_array($rule['migration'] ?? null) ? $rule['migration'] : [];

        foreach (['disposition', 'replacement_key', 'replacement_item_name', 'conversion_ratio', 'rounding', 'outcome', 'reason', 'alias_keys', 'gold_compensation_per_unit'] as $field) {
            if (array_key_exists($field, $rule) && ! array_key_exists($field, $migration)) {
                $migration[$field] = $rule[$field];
            }
        }

        if ($migration === []) {
            return null;
        }

        $disposition = (string) ($migration['disposition'] ?? 'repurpose');

        if (! in_array($disposition, ['keep', 'merge', 'rename', 'repurpose', 'currency', 'collectible', 'retire'], true)) {
            $disposition = 'repurpose';
        }

        $replacementKey = $this->nullableString($migration['replacement_key'] ?? $migration['target_key'] ?? null);
        $conversionRatio = $this->positiveFloat($migration['conversion_ratio'] ?? ($replacementKey === null ? null : 1));
        $rounding = (string) ($migration['rounding'] ?? 'floor');

        if (! in_array($rounding, ['floor', 'ceil', 'round'], true)) {
            $rounding = 'floor';
        }

        $reason = $this->nullableString($migration['reason'] ?? null) ?? "Migration rule declared for {$itemKey}.";

        return [
            'item_key' => $itemKey,
            'disposition' => $disposition,
            'replacement_key' => $replacementKey,
            'replacement_item_name' => $this->nullableString($migration['replacement_item_name'] ?? null),
            'conversion_ratio' => $conversionRatio,
            'rounding' => $rounding,
            'outcome' => (string) ($migration['outcome'] ?? $this->ruleOutcome($disposition, $replacementKey, $reason)),
            'reason' => $reason,
            'alias_keys' => $this->stringList($migration['alias_keys'] ?? $migration['aliases'] ?? []),
            'gold_compensation_per_unit' => (int) max(0, $migration['gold_compensation_per_unit'] ?? 0),
            'migration_version' => (string) ($migration['migration_version'] ?? self::MIGRATION_VERSION),
            'source' => $isDatabaseOverride ? 'database' : 'catalog',
        ];
    }

    private function ruleOutcome(string $disposition, ?string $replacementKey, string $reason): string
    {
        if ($replacementKey !== null) {
            return 'convert';
        }

        if ($disposition === 'retire' && $reason !== '') {
            return 'remove_with_reason';
        }

        return 'preserve';
    }

    /**
     * @return list<string>
     */
    private function itemRuleOverrideKeys(): array
    {
        try {
            return ConnectedRealmsContentEntry::query()
                ->where('surface', 'item_rules')
                ->where('enabled', true)
                ->orderBy('entry_key')
                ->pluck('entry_key')
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $migrationRules
     * @return array<string, mixed>
     */
    private function inventoryMigrationDryRun(array $migrationRules): array
    {
        $rows = $this->inventoryStackGroupsForMigrationDryRun()
            ->map(fn (array $stack): array => $this->inventoryMigrationDryRunRow($stack, $migrationRules[$stack['item_key']] ?? null))
            ->filter(fn (array $row): bool => $row['action'] !== 'preserve' || $row['has_migration_rule'])
            ->values();

        return [
            'mode' => 'dry_run',
            'mutates_database' => false,
            'rounding_policy' => 'per-stack-group deterministic rounding; default floor unless a rule overrides it',
            'summary' => [
                'affected_item_count' => $rows->count(),
                'affected_stack_count' => (int) $rows->sum('stack_count'),
                'affected_player_count' => $this->affectedInventoryPlayerCount($rows->pluck('item_key')->all()),
                'quantity_before' => (int) $rows->sum('old_quantity'),
                'quantity_after' => (int) $rows->sum('new_quantity'),
                'value_before' => (int) $rows->sum('value_before'),
                'value_after' => (int) $rows->sum('value_after'),
                'value_delta' => (int) $rows->sum('value_delta'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function inventoryStackGroupsForMigrationDryRun(): Collection
    {
        try {
            return ConnectedRealmsInventoryStack::query()
                ->selectRaw('item_key, min(item_name) as item_name, rarity, sum(quantity) as quantity, count(*) as stack_count, count(distinct player_id) as affected_player_count')
                ->groupBy('item_key', 'rarity')
                ->orderBy('item_key')
                ->orderBy('rarity')
                ->get()
                ->map(fn (ConnectedRealmsInventoryStack $stack): array => [
                    'item_key' => (string) $stack->item_key,
                    'item_name' => (string) $stack->item_name,
                    'rarity' => (string) $stack->rarity,
                    'quantity' => (int) $stack->quantity,
                    'stack_count' => (int) $stack->stack_count,
                    'affected_player_count' => (int) $stack->affected_player_count,
                ]);
        } catch (QueryException) {
            return collect();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryStackRow(ConnectedRealmsInventoryStack $stack): array
    {
        return [
            'item_key' => (string) $stack->item_key,
            'item_name' => (string) $stack->item_name,
            'rarity' => (string) $stack->rarity,
            'quantity' => (int) $stack->quantity,
            'stack_count' => 1,
            'affected_player_count' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $stack
     * @param  array<string, mixed>|null  $migrationRule
     * @return array<string, mixed>
     */
    private function inventoryMigrationDryRunRow(array $stack, ?array $migrationRule): array
    {
        $oldQuantity = (int) $stack['quantity'];
        $oldItem = $this->items->enrich([
            'item_key' => $stack['item_key'],
            'item_name' => $stack['item_name'],
            'rarity' => $stack['rarity'],
            'quantity' => $oldQuantity,
        ]);

        if ($migrationRule === null) {
            $newQuantity = $oldQuantity;
            $replacementKey = $stack['item_key'];
            $replacementName = $stack['item_name'];
            $action = 'preserve';
            $quantityRemainder = 0.0;
        } elseif ($migrationRule['replacement_key'] !== null) {
            $replacementKey = $migrationRule['replacement_key'];
            $replacementName = $this->nullableString($migrationRule['replacement_item_name'] ?? null)
                ?? str($replacementKey)->headline()->toString();
            $newQuantity = $this->convertedQuantity($oldQuantity, (float) $migrationRule['conversion_ratio'], (string) $migrationRule['rounding']);
            $action = 'convert';
            $quantityRemainder = round(($oldQuantity * (float) $migrationRule['conversion_ratio']) - $newQuantity, 4);
        } elseif ($migrationRule['disposition'] === 'retire') {
            $newQuantity = 0;
            $replacementKey = null;
            $replacementName = null;
            $action = 'remove';
            $quantityRemainder = 0.0;
        } else {
            $newQuantity = $oldQuantity;
            $replacementKey = $stack['item_key'];
            $replacementName = $stack['item_name'];
            $action = 'preserve';
            $quantityRemainder = 0.0;
        }

        $valueBefore = (int) $oldItem['total_vendor_value'];
        $valueAfter = $replacementKey === null
            ? 0
            : (int) $this->items->enrich([
                'item_key' => $replacementKey,
                'item_name' => $replacementName,
                'rarity' => $stack['rarity'],
                'quantity' => $newQuantity,
            ])['total_vendor_value'];
        $goldCompensation = (int) (($migrationRule['gold_compensation_per_unit'] ?? 0) * $oldQuantity);

        return [
            'row_key' => hash('sha256', self::MIGRATION_VERSION.'|'.$stack['item_key'].'|'.$stack['rarity'].'|'.$oldQuantity.'|'.($replacementKey ?? 'remove')),
            'item_key' => $stack['item_key'],
            'item_name' => $stack['item_name'],
            'rarity' => $stack['rarity'],
            'stack_count' => $stack['stack_count'],
            'affected_player_count' => $stack['affected_player_count'],
            'old_quantity' => $oldQuantity,
            'new_item_key' => $replacementKey,
            'new_item_name' => $replacementName,
            'new_quantity' => $newQuantity,
            'conversion_ratio' => $migrationRule['conversion_ratio'] ?? 1,
            'rounding' => $migrationRule['rounding'] ?? 'none',
            'quantity_remainder' => $quantityRemainder,
            'gold_compensation' => $goldCompensation,
            'value_before' => $valueBefore,
            'value_after' => $valueAfter + $goldCompensation,
            'value_delta' => ($valueAfter + $goldCompensation) - $valueBefore,
            'action' => $action,
            'has_migration_rule' => $migrationRule !== null,
            'migration_version' => $migrationRule['migration_version'] ?? null,
            'reason' => $migrationRule['reason'] ?? null,
        ];
    }

    private function convertedQuantity(int $quantity, float $conversionRatio, string $rounding): int
    {
        $converted = $quantity * $conversionRatio;

        return max(0, (int) match ($rounding) {
            'ceil' => ceil($converted),
            'round' => round($converted),
            default => floor($converted),
        });
    }

    /**
     * @param  list<string>  $itemKeys
     */
    private function affectedInventoryPlayerCount(array $itemKeys): int
    {
        if ($itemKeys === []) {
            return 0;
        }

        try {
            return ConnectedRealmsInventoryStack::query()
                ->whereIn('item_key', $itemKeys)
                ->distinct('player_id')
                ->count('player_id');
        } catch (QueryException) {
            return 0;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $itemRows
     * @param  array<string, mixed>  $catalogs
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    private function violations(array $itemRows, array $catalogs, array $edges): array
    {
        $sourceLevelsByItem = $this->sourceLevelsByItem($edges);
        $itemsByKey = collect($itemRows)->keyBy('item_key');

        return [
            'missing_sources' => collect($itemRows)
                ->where('source_count', 0)
                ->pluck('item_key')
                ->values()
                ->all(),
            'missing_primary_uses' => collect($itemRows)
                ->where('source_count', '>', 0)
                ->where('primary_use_count', 0)
                ->pluck('item_key')
                ->values()
                ->all(),
            'fallback_only_items' => collect($itemRows)
                ->where('status', 'fallback_only')
                ->pluck('item_key')
                ->values()
                ->all(),
            'repeatable_faucets_without_recurring_sinks' => collect($itemRows)
                ->where('source_count', '>', 0)
                ->where('recurring_sink_count', 0)
                ->pluck('item_key')
                ->values()
                ->all(),
            'recipe_inputs_unavailable_at_tier' => $this->recipeInputAvailabilityViolations($catalogs, $sourceLevelsByItem),
            'recipe_outputs_without_primary_use' => $this->recipeOutputUseViolations($catalogs, $itemsByKey),
            'zero_input_crafting_paths' => $this->zeroInputCraftingPaths($catalogs),
            'job_inputs_unavailable_at_tier' => $this->jobInputAvailabilityViolations($catalogs, $sourceLevelsByItem),
            'unbounded_job_demand' => $this->unboundedJobDemandViolations($catalogs),
            'missing_tier_content' => $this->missingTierContentViolations($catalogs),
            'tool_effects_without_runtime_consumer' => $this->toolEffectConsumerViolations(),
            'shop_liquidation_paths' => $this->shopLiquidationPaths($catalogs),
            'shop_job_paths' => $this->shopJobPaths($catalogs),
            'tool_craft_salvage_paths' => $this->toolCraftSalvagePaths($catalogs),
            'tool_purchase_salvage_paths' => $this->toolPurchaseSalvagePaths($catalogs),
            'tool_repair_salvage_paths' => $this->toolRepairSalvagePaths($catalogs),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, list<int>>
     */
    private function sourceLevelsByItem(array $edges): array
    {
        return collect($edges)
            ->where('edge_type', 'source')
            ->groupBy('item_key')
            ->map(fn ($itemEdges): array => $itemEdges
                ->pluck('required_level')
                ->map(fn ($level): int => (int) $level)
                ->sort()
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  array<string, list<int>>  $sourceLevelsByItem
     * @return array<string, list<array<string, mixed>>>
     */
    private function recipeInputAvailabilityViolations(array $catalogs, array $sourceLevelsByItem): array
    {
        return collect($catalogs['crafting_recipes'])
            ->mapWithKeys(function (array $recipe, string $recipeKey) use ($sourceLevelsByItem): array {
                $requiredLevel = (int) ($recipe['required_level'] ?? 1);
                $violations = collect($recipe['ingredients'] ?? [])
                    ->map(fn (array $ingredient): ?array => $this->availabilityViolation($ingredient, $requiredLevel, $sourceLevelsByItem))
                    ->filter()
                    ->values()
                    ->all();

                return $violations === [] ? [] : [$recipeKey => $violations];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  Collection<string, array<string, mixed>>  $itemsByKey
     * @return array<string, list<string>>
     */
    private function recipeOutputUseViolations(array $catalogs, $itemsByKey): array
    {
        return collect($catalogs['crafting_recipes'])
            ->mapWithKeys(function (array $recipe, string $recipeKey) use ($itemsByKey): array {
                $violations = collect($recipe['outputs'] ?? [])
                    ->map(fn (array $output): string => $this->itemKey($output))
                    ->filter(function (string $itemKey) use ($itemsByKey): bool {
                        $item = $itemsByKey->get($itemKey);

                        if ($item === null || in_array($item['item_class'], ['collectible', 'trinket'], true)) {
                            return false;
                        }

                        return (int) $item['primary_use_count'] === 0;
                    })
                    ->values()
                    ->all();

                return $violations === [] ? [] : [$recipeKey => $violations];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, mixed>>
     */
    private function zeroInputCraftingPaths(array $catalogs): array
    {
        return collect($catalogs['crafting_recipes'])
            ->mapWithKeys(function (array $recipe, string $recipeKey): array {
                $ingredientQuantity = collect($recipe['ingredients'] ?? [])
                    ->sum(fn (array $ingredient): int => max(0, (int) ($ingredient['quantity'] ?? 0)));
                $goldCost = max(0, (int) ($recipe['gold_cost'] ?? 0));
                $createsValue = (int) ($recipe['experience'] ?? 0) > 0 || count($recipe['outputs'] ?? []) > 0;

                if (! $createsValue || $ingredientQuantity > 0 || $goldCost > 0) {
                    return [];
                }

                return [$recipeKey => [
                    'ingredient_quantity' => $ingredientQuantity,
                    'gold_cost' => $goldCost,
                    'experience' => (int) ($recipe['experience'] ?? 0),
                    'output_count' => count($recipe['outputs'] ?? []),
                ]];
            })
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  array<string, list<int>>  $sourceLevelsByItem
     * @return array<string, list<array<string, mixed>>>
     */
    private function jobInputAvailabilityViolations(array $catalogs, array $sourceLevelsByItem): array
    {
        return collect($catalogs['job_contracts'])
            ->mapWithKeys(function (array $job, string $jobKey) use ($sourceLevelsByItem): array {
                $requiredLevel = (int) ($job['required_level'] ?? 1);
                $violations = collect($job['requirements'] ?? [])
                    ->map(fn (array $requirement): ?array => $this->availabilityViolation($requirement, $requiredLevel, $sourceLevelsByItem))
                    ->filter()
                    ->values()
                    ->all();

                return $violations === [] ? [] : [$jobKey => $violations];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return list<string>
     */
    private function unboundedJobDemandViolations(array $catalogs): array
    {
        return collect($catalogs['job_contracts'])
            ->filter(fn (array $job): bool => ! collect(['completion_cap', 'quota', 'cooldown_seconds', 'rotation'])
                ->contains(fn (string $key): bool => array_key_exists($key, $job)))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return list<array<string, mixed>>
     */
    private function missingTierContentViolations(array $catalogs): array
    {
        return collect($this->tierCoverageRows($catalogs))
            ->filter(fn (array $tier): bool => collect($tier)
                ->only(['gathering_actions', 'skill_activities', 'recipes', 'jobs', 'expeditions', 'tool_variants'])
                ->sum() === 0)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function toolEffectConsumerViolations(): array
    {
        $runtimeConsumers = [
            'critical_chance',
            'cooldown_reduction',
            'experience',
            'gold',
            'market_premium',
            'material_preservation',
            'yield',
        ];

        return collect(['critical_chance', 'cooldown_reduction', 'experience', 'gold', 'market_premium', 'material_preservation', 'yield'])
            ->diff($runtimeConsumers)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, list<int>>  $sourceLevelsByItem
     */
    private function availabilityViolation(array $item, int $requiredLevel, array $sourceLevelsByItem): ?array
    {
        $itemKey = $this->itemKey($item);
        $sourceLevels = $sourceLevelsByItem[$itemKey] ?? [];

        if ($sourceLevels === []) {
            return [
                'item_key' => $itemKey,
                'item_name' => $this->itemName($item),
                'required_level' => $requiredLevel,
                'earliest_source_level' => null,
                'reason' => 'missing_source',
            ];
        }

        $earliestSourceLevel = min($sourceLevels);

        if ($earliestSourceLevel > $requiredLevel) {
            return [
                'item_key' => $itemKey,
                'item_name' => $this->itemName($item),
                'required_level' => $requiredLevel,
                'earliest_source_level' => $earliestSourceLevel,
                'reason' => 'source_unlocks_late',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, int>>
     */
    private function shopLiquidationPaths(array $catalogs): array
    {
        $paths = [];

        foreach ($catalogs['shop_offers'] as $offerKey => $offer) {
            if (($offer['kind'] ?? null) !== 'item') {
                continue;
            }

            $item = $this->items->enrich($offer);
            $vendorReturn = (int) $item['total_npc_buy_price'];

            if ($vendorReturn >= (int) $offer['price']) {
                $paths[$offerKey] = [
                    'price' => (int) $offer['price'],
                    'vendor_return' => $vendorReturn,
                ];
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, int>>
     */
    private function shopJobPaths(array $catalogs): array
    {
        $paths = [];

        foreach ($catalogs['shop_offers'] as $offerKey => $offer) {
            if (($offer['kind'] ?? null) !== 'item') {
                continue;
            }

            $item = $this->items->enrich($offer);

            foreach ($catalogs['job_contracts'] as $jobKey => $job) {
                $requiredQuantity = collect($job['requirements'] ?? [])
                    ->where('item_key', $offer['item_key'])
                    ->sum('quantity');

                if ($requiredQuantity === 0 || $requiredQuantity > (int) $offer['quantity'] || count($job['requirements'] ?? []) !== 1) {
                    continue;
                }

                $remainingVendorReturn = ((int) $offer['quantity'] - $requiredQuantity) * (int) $item['npc_buy_price'];
                $totalReturn = (int) $job['gold'] + $remainingVendorReturn;

                if ($totalReturn >= (int) $offer['price']) {
                    $paths["{$offerKey}->{$jobKey}"] = [
                        'price' => (int) $offer['price'],
                        'job_gold' => (int) $job['gold'],
                        'remaining_vendor_return' => $remainingVendorReturn,
                        'total_return' => $totalReturn,
                    ];
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, mixed>>
     */
    private function toolCraftSalvagePaths(array $catalogs): array
    {
        $paths = [];

        foreach ($catalogs['crafting_recipes'] as $recipeKey => $recipe) {
            $craftValue = $this->itemsValue($recipe['ingredients'] ?? []) + (int) ($recipe['gold_cost'] ?? 0);

            foreach ($recipe['outputs'] ?? [] as $output) {
                $tool = $this->toolAuditPayload($output, (int) ($recipe['required_level'] ?? 1), 'crafted');

                if ($tool === null) {
                    continue;
                }

                $salvageValue = $this->itemsValue($this->tools->salvageMaterials($tool));

                if ($salvageValue >= $craftValue) {
                    $paths["{$recipeKey}:{$tool['item_key']}"] = [
                        'recipe_key' => $recipeKey,
                        'tool_key' => $tool['item_key'],
                        'tool_name' => $tool['item_name'],
                        'tier_level' => (int) $tool['tier_level'],
                        'craft_value' => $craftValue,
                        'salvage_value' => $salvageValue,
                    ];
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, mixed>>
     */
    private function toolPurchaseSalvagePaths(array $catalogs): array
    {
        $paths = [];

        foreach ($catalogs['shop_offers'] as $offerKey => $offer) {
            if (($offer['kind'] ?? null) !== 'tool') {
                continue;
            }

            $tool = $this->toolAuditPayload($offer, (int) ($offer['required_level'] ?? 1), 'bought');

            if ($tool === null) {
                continue;
            }

            $price = (int) ($offer['price'] ?? 0);
            $salvageValue = $this->itemsValue($this->tools->salvageMaterials($tool));

            if ($salvageValue >= $price) {
                $paths[$offerKey] = [
                    'price' => $price,
                    'tool_key' => $tool['item_key'],
                    'tool_name' => $tool['item_name'],
                    'tier_level' => (int) $tool['tier_level'],
                    'salvage_value' => $salvageValue,
                ];
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @return array<string, array<string, mixed>>
     */
    private function toolRepairSalvagePaths(array $catalogs): array
    {
        $paths = [];

        foreach ($catalogs['crafting_recipes'] as $recipeKey => $recipe) {
            foreach ($recipe['outputs'] ?? [] as $output) {
                $tool = $this->toolAuditPayload($output, (int) ($recipe['required_level'] ?? 1), 'crafted');

                if ($tool === null) {
                    continue;
                }

                $damagedTool = [
                    ...$tool,
                    'durability' => 50,
                ];
                $repairedTool = [
                    ...$tool,
                    'durability' => 100,
                ];
                $repair = $this->tools->repairCost($damagedTool);

                if (! $repair['can_repair']) {
                    continue;
                }

                $repairCostValue = (int) $repair['gold_cost'] + $this->itemsValue($repair['materials']);
                $salvageValueBeforeRepair = $this->itemsValue($this->tools->salvageMaterials($damagedTool));
                $salvageValueAfterRepair = $this->itemsValue($this->tools->salvageMaterials($repairedTool));
                $salvageValueGain = $salvageValueAfterRepair - $salvageValueBeforeRepair;

                if ($salvageValueGain >= $repairCostValue) {
                    $paths["{$recipeKey}:{$tool['item_key']}"] = [
                        'recipe_key' => $recipeKey,
                        'tool_key' => $tool['item_key'],
                        'tool_name' => $tool['item_name'],
                        'tier_level' => (int) $tool['tier_level'],
                        'repair_cost_value' => $repairCostValue,
                        'salvage_value_before_repair' => $salvageValueBeforeRepair,
                        'salvage_value_after_repair' => $salvageValueAfterRepair,
                        'salvage_value_gain' => $salvageValueGain,
                    ];
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function toolAuditPayload(array $item, int $tierLevel, string $origin): ?array
    {
        $skill = $this->nullableString($item['equipment_skill'] ?? $item['skill'] ?? $item['bonuses']['skill'] ?? null);

        if ($skill === null) {
            return null;
        }

        return [
            'item_key' => $this->itemKey($item),
            'item_name' => $this->itemName($item),
            'rarity' => (string) ($item['rarity'] ?? 'common'),
            'skill' => $skill,
            'tier_level' => max(1, $tierLevel),
            'durability' => (int) ($item['durability'] ?? 100),
            'origin' => $origin,
            'bonuses' => is_array($item['bonuses'] ?? null) ? $item['bonuses'] : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemsValue(array $items): int
    {
        return collect($items)
            ->sum(fn (array $item): int => (int) $this->items->enrich($item)['total_vendor_value']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function itemRewards(array $entry): array
    {
        return collect($entry['rewards'] ?? [])
            ->filter(fn (array $reward): bool => isset($reward['item_key']) || isset($reward['key']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function storedEntriesBySurface(): array
    {
        try {
            return ConnectedRealmsContentEntry::query()
                ->where('enabled', true)
                ->get(['surface', 'entry_key'])
                ->groupBy('surface')
                ->map(fn ($entries): array => $entries
                    ->mapWithKeys(fn (ConnectedRealmsContentEntry $entry): array => [$entry->entry_key => true])
                    ->all())
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $tier
     */
    private function toolTierKey(array $tier): string
    {
        return str((string) ($tier['name_mark'] ?? $tier['mark'] ?? 'tier'))->slug('_')->toString();
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
