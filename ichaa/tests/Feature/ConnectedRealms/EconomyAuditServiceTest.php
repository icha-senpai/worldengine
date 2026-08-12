<?php

namespace Tests\Feature\ConnectedRealms;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsContentEntry;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsExpeditionRun;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsGoldFlow;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryMigration;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketListing;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketTransaction;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayerSkill;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsVendorSale;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsContentService;
use App\Domain\ConnectedRealms\Services\EconomyAuditService;
use App\Domain\ConnectedRealms\Services\EvergatherTierCatalog;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Domain\ConnectedRealms\Services\SkillCatalogService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EconomyAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(ConnectedRealmsContentService::surfaces()) as $surface) {
            ConnectedRealmsContentService::forgetSurface($surface);
        }

        EvergatherTierCatalog::forgetCache();
        SkillCatalogService::forgetCache();
    }

    public function test_export_reconciles_runtime_catalog_counts_and_guardrails(): void
    {
        $audit = app(EconomyAuditService::class);

        $first = $audit->export();
        $second = $audit->export();

        $this->assertSame($first, $second);
        $this->assertSame([
            'skills' => 38,
            'tool_families' => 38,
            'tool_tiers' => 10,
            'craftable_tool_variants' => 380,
            'gathering_actions' => 91,
            'skill_activities' => 310,
            'recipes' => 570,
            'jobs' => 380,
            'expeditions' => 140,
            'shop_offers' => 389,
        ], $first['catalog_counts']);
        $this->assertGreaterThan(1000, count($first['items']));
        $this->assertGreaterThan(1000, count($first['edges']));
        $this->assertGreaterThan(300, count($first['currency_sinks']));
        $this->assertContains('player_market_transaction_tax', collect($first['currency_sinks'])->pluck('sink_key')->all());
        $this->assertSame(0.05, collect($first['currency_sinks'])->firstWhere('sink_key', 'player_market_transaction_tax')['rate'] ?? null);
        $this->assertArrayHasKey('observed_gold_flows', $first);
        $this->assertSame(0, $first['observed_gold_flows']['totals']['gold_created']);
        $this->assertArrayHasKey('pilot_loops', $first);
        $this->assertSame([], $first['pilot_loops']['incomplete_loops']);
        $this->assertArrayHasKey('migration_plan', $first);
        $this->assertSame(1, $first['migration_plan']['schema_version']);
        $this->assertSame('evergather-economy-rebuild-v1', $first['migration_plan']['migration_version']);
        $this->assertGreaterThan(0, count($first['migration_plan']['rules']));
        $this->assertFalse($first['migration_plan']['inventory_dry_run']['mutates_database']);
        $this->assertTrue($first['migration_plan']['rollback_policy']['requires_database_backup_before_force']);
        $this->assertFalse($first['migration_plan']['rollback_policy']['automatic_rollback_supported']);
        $this->assertCount(10, $first['tier_coverage']);
        $this->assertSame([], $first['violations']['missing_tier_content']);
        $this->assertSame([], $first['violations']['tool_effects_without_runtime_consumer']);
        $this->assertSame([], $first['violations']['recipe_inputs_unavailable_at_tier']);
        $this->assertArrayHasKey('recipe_outputs_without_primary_use', $first['violations']);
        $this->assertSame([], $first['violations']['zero_input_crafting_paths']);
        $this->assertArrayHasKey('job_inputs_unavailable_at_tier', $first['violations']);
        $this->assertSame([], $first['violations']['unbounded_job_demand']);
        $this->assertCount(count($first['items']), $first['canonical_registry']);
        $this->assertSame(
            collect($first['items'])->pluck('item_key')->sort()->values()->all(),
            collect($first['canonical_registry'])->pluck('item_key')->sort()->values()->all(),
        );
        $this->assertSame(count($first['items']), $first['canonical_registry_summary']['total_items']);
        $this->assertSame(0, $first['canonical_registry_summary']['by_disposition']['repurpose'] ?? 0);
        $this->assertSame([], $first['canonical_registry_summary']['unexplained_fallback_only_items']);
        $this->assertSame([], $first['canonical_registry_summary']['items_requiring_replacement']);
        $this->assertArrayHasKey('player_state_fixtures', $first);
        $this->assertArrayHasKey('fixtures', $first['player_state_fixtures']);
        $this->assertSame([], $first['violations']['shop_liquidation_paths']);
        $this->assertSame([], $first['violations']['shop_job_paths']);
        $this->assertSame([], $first['violations']['tool_craft_salvage_paths']);
        $this->assertSame([], $first['violations']['tool_purchase_salvage_paths']);
        $this->assertSame([], $first['violations']['tool_repair_salvage_paths']);
        $this->assertSame([], $first['violations']['missing_primary_uses']);
        $this->assertSame([], $first['violations']['fallback_only_items']);
        $this->assertSame([], $first['violations']['repeatable_faucets_without_recurring_sinks']);

        $starterIngot = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'smelting_candlemark_ingot');
        $craftedTool = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'candlemark_boughsplitter_hatchet');
        $craftedKitTool = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'candlemark_ashcamp_camp_kit');
        $trapDiagram = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'activity_tooling_document_uncommon_tier_1');
        $activityReward = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'activity_material_weapon_component_common_tier_1');
        $expeditionReward = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'expedition_exploration_tier_1_explorer_compass');
        $craftOutput = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'smelting_wayside_ingot');
        $gatheringReward = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'brine_shrimp');

        $this->assertSame('keep', $starterIngot['disposition'] ?? null);
        $this->assertSame('active', $starterIngot['status'] ?? null);
        $this->assertNotNull($starterIngot['producing_skill'] ?? null);
        $this->assertNotNull($starterIngot['consuming_skill'] ?? null);
        $this->assertNotSame([], $starterIngot['recurring_sinks'] ?? []);
        $this->assertSame('keep', $craftedTool['disposition'] ?? null);
        $this->assertSame('active', $craftedTool['status'] ?? null);
        $this->assertContains('tool_lifecycle', collect($craftedTool['recurring_sinks'] ?? [])->pluck('system')->all());
        $this->assertSame('keep', $craftedKitTool['disposition'] ?? null);
        $this->assertSame('active', $craftedKitTool['status'] ?? null);
        $this->assertContains('tool_lifecycle', collect($craftedKitTool['recurring_sinks'] ?? [])->pluck('system')->all());
        $this->assertSame('tooling', $trapDiagram['item_class'] ?? null);
        $this->assertSame('Document', $trapDiagram['material_family'] ?? null);
        $this->assertSame('keep', $activityReward['disposition'] ?? null);
        $this->assertSame('active', $activityReward['status'] ?? null);
        $this->assertContains('item_requisition', collect($activityReward['primary_uses'] ?? [])->pluck('system')->all());
        $this->assertSame('keep', $expeditionReward['disposition'] ?? null);
        $this->assertSame('active', $expeditionReward['status'] ?? null);
        $this->assertContains('item_requisition', collect($expeditionReward['primary_uses'] ?? [])->pluck('system')->all());
        $this->assertSame('keep', $craftOutput['disposition'] ?? null);
        $this->assertSame('active', $craftOutput['status'] ?? null);
        $this->assertContains('item_requisition', collect($craftOutput['primary_uses'] ?? [])->pluck('system')->all());
        $this->assertSame('keep', $gatheringReward['disposition'] ?? null);
        $this->assertSame('active', $gatheringReward['status'] ?? null);
        $this->assertContains('item_requisition', collect($gatheringReward['primary_uses'] ?? [])->pluck('system')->all());
    }

    public function test_export_tracks_tier_one_and_two_pilot_loop_completion(): void
    {
        $pilotLoops = app(EconomyAuditService::class)->export()['pilot_loops'];
        $loops = collect($pilotLoops['loops'])->keyBy('loop_key');
        $miningStages = collect($loops['mining_smithing_tool_repair']['stages'])->keyBy('stage_key');
        $provisioningStages = collect($loops['fishing_farming_cooking_expedition']['stages'])->keyBy('stage_key');

        $this->assertSame([1, 2], $pilotLoops['tier_range']);
        $this->assertTrue($loops['mining_smithing_tool_repair']['is_complete']);
        $this->assertTrue($loops['fishing_farming_cooking_expedition']['is_complete']);
        $this->assertSame('mine', $miningStages['raw_faucet']['route_key']);
        $this->assertSame(['iron_ore', 'coal_chunk'], $miningStages['raw_faucet']['required_items']);
        $this->assertSame('smelting_candlemark_ingot', $miningStages['processing_recipe']['route_key']);
        $this->assertSame('mining', $miningStages['durable_tool_family']['route_key']);
        $this->assertSame('smithing', $miningStages['durable_tool_family']['craft_skill']);
        $this->assertTrue($miningStages['bounded_job_demand']['has_bounded_demand']);
        $this->assertTrue($miningStages['recurring_repair_sink']['has_required_sink_system']);
        $this->assertSame('fish', $provisioningStages['fishing_raw_faucet']['route_key']);
        $this->assertSame('farm', $provisioningStages['farming_raw_faucet']['route_key']);
        $this->assertSame('cooking_candlemark_meal', $provisioningStages['cooked_meal_recipe']['route_key']);
        $this->assertSame('cooking_wayside_meal', $provisioningStages['tier_two_meal_recipe']['route_key']);
        $this->assertTrue($provisioningStages['bounded_meal_job_demand']['has_bounded_demand']);
        $this->assertTrue($provisioningStages['bounded_flatbread_job_demand']['has_bounded_demand']);
        $this->assertSame('survival_starter_expedition', $provisioningStages['expedition_consumable_sink']['route_key']);
        $this->assertSame(['cooking_candlemark_meal'], $provisioningStages['expedition_consumable_sink']['required_items']);
        $this->assertSame('survival_local_expedition', $provisioningStages['tier_two_expedition_consumable_sink']['route_key']);
        $this->assertSame(['cooking_wayside_meal'], $provisioningStages['tier_two_expedition_consumable_sink']['required_items']);
        $this->assertSame([], $pilotLoops['incomplete_loops']);
    }

    public function test_export_flags_database_overrides_that_break_pilot_loop_required_items(): void
    {
        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'job_contracts',
            'entry_key' => 'cooking_starter_contract',
            'label' => 'Unbounded Cooking Starter Contract',
            'category' => 'Crafting',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'skill' => 'cooking',
                'experience' => 35,
                'gold' => 35,
                'requirements' => [
                    ['item_key' => 'audit_wrong_meal', 'item_name' => 'Audit Wrong Meal', 'quantity' => 1],
                ],
                'rewards' => [
                    ['type' => 'gold', 'label' => 'Gold', 'quantity' => 35],
                    ['type' => 'experience', 'label' => 'Cooking XP', 'quantity' => 35],
                ],
            ],
        ]);

        $pilotLoops = app(EconomyAuditService::class)->export()['pilot_loops'];
        $provisioningLoop = collect($pilotLoops['loops'])->firstWhere('loop_key', 'fishing_farming_cooking_expedition');
        $mealDemand = collect($provisioningLoop['stages'])->firstWhere('stage_key', 'bounded_meal_job_demand');

        $this->assertFalse($provisioningLoop['is_complete']);
        $this->assertContains('fishing_farming_cooking_expedition', $pilotLoops['incomplete_loops']);
        $this->assertSame(['cooking_candlemark_meal'], $mealDemand['missing_items']);
        $this->assertTrue($mealDemand['has_bounded_demand']);
        $this->assertFalse($mealDemand['is_complete']);
    }

    public function test_export_records_representative_player_state_fixtures_for_migration_planning(): void
    {
        $newPlayer = $this->createConnectedRealmsPlayer('Audit Newcomer', 15);
        $midgamePlayer = $this->createConnectedRealmsPlayer('Audit Midgame', 250);
        $maxTierPlayer = $this->createConnectedRealmsPlayer('Audit Crownmark', 5000);
        $inventoryHeavyPlayer = $this->createConnectedRealmsPlayer('Audit Hoarder', 1200);
        $uniqueToolPlayer = $this->createConnectedRealmsPlayer('Audit Toolkeeper', 900);

        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $midgamePlayer->id,
            'skill' => 'smithing',
            'level' => 42,
            'experience' => 8400,
        ]);
        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $maxTierPlayer->id,
            'skill' => 'exploration',
            'level' => 100,
            'experience' => 200000,
        ]);

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $inventoryHeavyPlayer->id,
            'item_key' => 'iron_ore',
            'item_name' => 'Iron Ore',
            'rarity' => 'common',
            'quantity' => 300,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $inventoryHeavyPlayer->id,
            'item_key' => 'tin_ore',
            'item_name' => 'Tin Ore',
            'rarity' => 'common',
            'quantity' => 125,
        ]);

        ConnectedRealmsTool::query()->create([
            'player_id' => $uniqueToolPlayer->id,
            'slot' => 'mining',
            'skill' => 'mining',
            'item_key' => 'candlemark_pickaxe',
            'item_name' => 'Candlemark Pickaxe',
            'rarity' => 'rare',
            'durability' => 71,
            'bonuses' => ['yield' => 0.08],
            'origin' => 'crafted',
            'status' => ConnectedRealmsTool::STATUS_INVENTORY,
            'maker_name' => 'Audit Smith',
            'tier_level' => 1,
            'upgrade_count' => 2,
            'tier_upgrade_count' => 0,
            'rarity_upgrade_attempts' => 1,
        ]);

        $fixtures = app(EconomyAuditService::class)->export()['player_state_fixtures']['fixtures'];

        $this->assertSame($newPlayer->id, $fixtures['new_player']['player']['player_id']);
        $this->assertSame($midgamePlayer->id, $fixtures['midgame_player']['player']['player_id']);
        $this->assertSame($maxTierPlayer->id, $fixtures['max_tier_player']['player']['player_id']);
        $this->assertSame($inventoryHeavyPlayer->id, $fixtures['inventory_heavy_player']['player']['player_id']);
        $this->assertSame($uniqueToolPlayer->id, $fixtures['unique_tool_player']['player']['player_id']);
        $this->assertSame(425, $fixtures['inventory_heavy_player']['player']['inventory_total_quantity']);
        $this->assertSame('candlemark_pickaxe', $fixtures['unique_tool_player']['player']['tools'][0]['item_key']);
        $this->assertSame(['yield' => 0.08], $fixtures['unique_tool_player']['player']['tools'][0]['bonuses']);
    }

    public function test_export_builds_idempotent_inventory_migration_dry_run_from_item_rules(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Migrator', 100);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'item_rules',
            'entry_key' => 'audit_legacy_scrap',
            'label' => 'Audit Legacy Scrap',
            'category' => 'Migration',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'item_class' => 'material',
                'material_family' => 'Audit Scrap',
                'weight' => 0.1,
                'base_value' => 10,
                'tags' => ['audit', 'legacy'],
                'migration' => [
                    'disposition' => 'merge',
                    'replacement_key' => 'smelting_candlemark_ingot',
                    'conversion_ratio' => 0.5,
                    'rounding' => 'floor',
                    'reason' => 'Consolidated into the Tier 1 smithing pilot material.',
                    'aliases' => ['old_audit_scrap'],
                ],
            ],
        ]);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'shop_offers',
            'entry_key' => 'audit_legacy_scrap_bundle',
            'label' => 'Audit Legacy Scrap Bundle',
            'category' => 'Migration',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'kind' => 'item',
                'skill' => null,
                'skill_label' => null,
                'price' => 50,
                'item_key' => 'audit_legacy_scrap',
                'item_name' => 'Audit Legacy Scrap',
                'quantity' => 1,
                'durability' => null,
                'bonuses' => null,
            ],
        ]);

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'audit_legacy_scrap',
            'item_name' => 'Audit Legacy Scrap',
            'rarity' => 'common',
            'quantity' => 5,
        ]);

        $first = app(EconomyAuditService::class)->export();
        $second = app(EconomyAuditService::class)->export();

        $this->assertSame($first['migration_plan'], $second['migration_plan']);

        $registryRow = collect($first['canonical_registry'])
            ->firstWhere('item_key', 'audit_legacy_scrap');
        $rule = collect($first['migration_plan']['rules'])
            ->firstWhere('item_key', 'audit_legacy_scrap');
        $dryRunRow = collect($first['migration_plan']['inventory_dry_run']['rows'])
            ->firstWhere('item_key', 'audit_legacy_scrap');

        $this->assertSame('merge', $registryRow['disposition'] ?? null);
        $this->assertSame('deprecated', $registryRow['status'] ?? null);
        $this->assertSame('smelting_candlemark_ingot', $registryRow['replacement_key'] ?? null);
        $this->assertSame(0.5, $registryRow['conversion_ratio'] ?? null);
        $this->assertSame(['old_audit_scrap'], $registryRow['alias_keys'] ?? null);
        $this->assertSame('database', $rule['source'] ?? null);
        $this->assertFalse($rule['requires_review'] ?? true);
        $this->assertSame('convert', $dryRunRow['action'] ?? null);
        $this->assertSame(5, $dryRunRow['old_quantity'] ?? null);
        $this->assertSame('smelting_candlemark_ingot', $dryRunRow['new_item_key'] ?? null);
        $this->assertSame(2, $dryRunRow['new_quantity'] ?? null);
        $this->assertSame(0.5, $dryRunRow['quantity_remainder'] ?? null);
        $this->assertSame(50, $dryRunRow['value_before'] ?? null);
        $this->assertSame(48, $dryRunRow['value_after'] ?? null);
        $this->assertSame(-2, $dryRunRow['value_delta'] ?? null);
        $this->assertSame(1, $first['migration_plan']['inventory_dry_run']['summary']['affected_item_count']);
        $this->assertSame(1, $first['migration_plan']['inventory_dry_run']['summary']['affected_player_count']);

        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'audit_legacy_scrap',
            'quantity' => 5,
        ]);
        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'smelting_candlemark_ingot',
            'quantity' => 2,
        ]);
    }

    public function test_export_maps_legacy_skill_activity_rewards_to_canonical_keys(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Legacy Activity', 100);

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'smithing_candlemark_weapon_blank_1',
            'item_name' => 'Candlemark Rivet Set Tempered Blank',
            'rarity' => 'common',
            'quantity' => 4,
        ]);

        $export = app(EconomyAuditService::class)->export();
        $registryKeys = collect($export['canonical_registry'])->pluck('item_key');
        $rule = collect($export['migration_plan']['rules'])
            ->firstWhere('item_key', 'smithing_candlemark_weapon_blank_1');
        $dryRunRow = collect($export['migration_plan']['inventory_dry_run']['rows'])
            ->firstWhere('item_key', 'smithing_candlemark_weapon_blank_1');

        $this->assertFalse($registryKeys->contains('smithing_candlemark_weapon_blank_1'));
        $this->assertTrue($registryKeys->contains('activity_material_weapon_component_common_tier_1'));
        $this->assertSame('merge', $rule['disposition'] ?? null);
        $this->assertSame('activity_material_weapon_component_common_tier_1', $rule['replacement_key'] ?? null);
        $this->assertSame(1.0, $rule['conversion_ratio'] ?? null);
        $this->assertSame('catalog', $rule['source'] ?? null);
        $this->assertFalse($rule['is_known_registry_item'] ?? true);
        $this->assertTrue($rule['replacement_is_known_registry_item'] ?? false);
        $this->assertSame('convert', $dryRunRow['action'] ?? null);
        $this->assertSame(4, $dryRunRow['old_quantity'] ?? null);
        $this->assertSame('activity_material_weapon_component_common_tier_1', $dryRunRow['new_item_key'] ?? null);
        $this->assertSame(4, $dryRunRow['new_quantity'] ?? null);

        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'smithing_candlemark_weapon_blank_1',
            'quantity' => 4,
        ]);
        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'activity_material_weapon_component_common_tier_1',
            'quantity' => 4,
        ]);
    }

    public function test_inventory_migration_command_applies_legacy_activity_rules_idempotently(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Apply Migration', 100);

        $legacyStack = ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'smithing_candlemark_weapon_blank_1',
            'item_name' => 'Candlemark Rivet Set Tempered Blank',
            'rarity' => 'common',
            'quantity' => 4,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'activity_material_weapon_component_common_tier_1',
            'item_name' => 'Tier 1 Common Weapon Component Material',
            'rarity' => 'common',
            'quantity' => 2,
        ]);

        $this->artisan('evergather:migrate-inventory', ['--force' => true])
            ->assertExitCode(0);
        $this->artisan('evergather:migrate-inventory', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'smithing_candlemark_weapon_blank_1',
        ]);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'activity_material_weapon_component_common_tier_1',
            'item_name' => 'Tier 1 Common Weapon Component Material',
            'quantity' => 6,
        ]);
        $this->assertDatabaseHas('connected_realms_inventory_migrations', [
            'player_id' => $player->id,
            'old_stack_id' => $legacyStack->id,
            'item_key' => 'smithing_candlemark_weapon_blank_1',
            'new_item_key' => 'activity_material_weapon_component_common_tier_1',
            'old_quantity' => 4,
            'new_quantity' => 4,
            'action' => 'convert',
            'migration_version' => 'evergather-economy-rebuild-v1',
        ]);
        $this->assertSame(1, ConnectedRealmsInventoryMigration::query()->count());
    }

    public function test_activity_reward_requisition_items_do_not_create_runtime_jobs(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Requisitioner', 100);
        $itemKey = 'activity_material_weapon_component_common_tier_1';
        $jobKey = 'item_requisition_'.$itemKey;

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => $itemKey,
            'item_name' => 'Tier 1 Common Weapon Component Material',
            'rarity' => 'common',
            'quantity' => 3,
        ]);

        $jobs = app(JobContractService::class)->availableJobsFor($player->load(['inventoryStacks', 'skills']));
        $job = collect($jobs)->firstWhere('key', $jobKey);

        $this->assertNull($job);

        $service = app(JobContractService::class);
        $user = $player->user()->firstOrFail();

        $this->expectException(ValidationException::class);

        try {
            $service->complete($user, $jobKey);
        } finally {
            $this->assertDatabaseHas('connected_realms_inventory_stacks', [
                'player_id' => $player->id,
                'item_key' => $itemKey,
                'quantity' => 3,
            ]);
            $this->assertDatabaseMissing('connected_realms_job_completions', [
                'player_id' => $player->id,
                'job_key' => $jobKey,
            ]);
        }
    }

    public function test_expedition_reward_requisitions_are_not_runtime_jobs(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Researcher', 100);

        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $player->id,
            'skill' => 'mining',
            'level' => 100,
            'experience' => 200000,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'expedition_exploration_tier_1_explorer_compass',
            'item_name' => 'Candlemark Sketch Route Explorer Compass',
            'rarity' => 'common',
            'quantity' => 2,
        ]);

        $job = collect(app(JobContractService::class)->availableJobsFor($player->load(['inventoryStacks', 'skills'])))
            ->firstWhere('key', 'item_requisition_expedition_exploration_tier_1_explorer_compass');

        $this->assertNull($job);
    }

    public function test_crafted_output_requisitions_are_not_runtime_jobs(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Commissioner', 100);

        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $player->id,
            'skill' => 'mining',
            'level' => 100,
            'experience' => 200000,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'smelting_wayside_ingot',
            'item_name' => 'Wayside Ingot',
            'rarity' => 'common',
            'quantity' => 2,
        ]);

        $job = collect(app(JobContractService::class)->availableJobsFor($player->load(['inventoryStacks', 'skills'])))
            ->firstWhere('key', 'item_requisition_smelting_wayside_ingot');

        $this->assertNull($job);
    }

    public function test_gathered_reward_requisitions_are_not_runtime_jobs(): void
    {
        $player = $this->createConnectedRealmsPlayer('Audit Gatherer', 100);

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'brine_shrimp',
            'item_name' => 'Brine Shrimp',
            'rarity' => 'common',
            'quantity' => 3,
        ]);

        $job = collect(app(JobContractService::class)->availableJobsFor($player->load(['inventoryStacks', 'skills'])))
            ->firstWhere('key', 'item_requisition_brine_shrimp');

        $this->assertNull($job);
    }

    public function test_export_summarizes_observed_gold_faucets_sinks_and_market_transfers(): void
    {
        $seller = $this->createConnectedRealmsPlayer('Audit Seller', 100);
        $buyer = $this->createConnectedRealmsPlayer('Audit Buyer', 1000);

        ConnectedRealmsActionLog::query()->create([
            'player_id' => $seller->id,
            'action' => 'gather',
            'skill' => 'mining',
            'platform' => 'website',
            'result_label' => 'Audit Vein',
            'items_awarded' => [],
            'experience_awarded' => 12,
            'gold_awarded' => 7,
            'available_at' => now(),
        ]);
        ConnectedRealmsJobCompletion::query()->create([
            'player_id' => $seller->id,
            'job_key' => 'audit_job',
            'job_name' => 'Audit Job',
            'category' => 'Audit',
            'items_delivered' => [],
            'rewards' => [],
            'experience_awarded' => 20,
            'gold_awarded' => 35,
        ]);
        ConnectedRealmsExpeditionRun::query()->create([
            'player_id' => $seller->id,
            'expedition_key' => 'audit_expedition',
            'expedition_name' => 'Audit Expedition',
            'status' => 'resolved',
            'gold_awarded' => 60,
            'resolved_at' => now(),
        ]);
        ConnectedRealmsVendorSale::query()->create([
            'player_id' => $seller->id,
            'item_key' => 'audit_scrap',
            'item_name' => 'Audit Scrap',
            'rarity' => 'common',
            'quantity' => 4,
            'unit_price' => 3,
            'total_price' => 12,
        ]);
        ConnectedRealmsCraftingLog::query()->create([
            'player_id' => $seller->id,
            'recipe_key' => 'audit_recipe',
            'recipe_name' => 'Audit Recipe',
            'skill' => 'smithing',
            'items_consumed' => [],
            'items_created' => [],
            'experience_awarded' => 10,
            'gold_cost' => 9,
        ]);
        $listing = ConnectedRealmsMarketListing::query()->create([
            'seller_player_id' => $seller->id,
            'item_key' => 'audit_scrap',
            'item_name' => 'Audit Scrap',
            'rarity' => 'common',
            'quantity' => 1,
            'unit_price' => 100,
            'status' => 'sold',
            'sold_at' => now(),
        ]);
        ConnectedRealmsMarketTransaction::query()->create([
            'listing_id' => $listing->id,
            'seller_player_id' => $seller->id,
            'buyer_player_id' => $buyer->id,
            'listing_type' => 'item',
            'item_key' => 'audit_scrap',
            'item_name' => 'Audit Scrap',
            'rarity' => 'common',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'market_fee' => 5,
            'seller_payout' => 95,
        ]);
        ConnectedRealmsGoldFlow::query()->create([
            'player_id' => $buyer->id,
            'flow_key' => 'shop_purchase',
            'direction' => ConnectedRealmsGoldFlow::DIRECTION_DESTROYED,
            'source_system' => 'shop',
            'gold' => 30,
            'context' => ['offer_key' => 'audit_bundle'],
            'occurred_at' => now(),
        ]);
        ConnectedRealmsGoldFlow::query()->create([
            'player_id' => $seller->id,
            'flow_key' => 'tool_repair',
            'direction' => ConnectedRealmsGoldFlow::DIRECTION_DESTROYED,
            'source_system' => 'tool_lifecycle',
            'gold' => 320,
            'context' => ['tool_id' => 99],
            'occurred_at' => now(),
        ]);

        $flows = app(EconomyAuditService::class)->export()['observed_gold_flows'];
        $rows = collect($flows['rows'])->keyBy('flow_key');

        $this->assertSame(114, $flows['totals']['gold_created']);
        $this->assertSame(364, $flows['totals']['gold_destroyed']);
        $this->assertSame(-250, $flows['totals']['net_gold_created']);
        $this->assertSame(95, $flows['totals']['gold_transferred']);
        $this->assertSame(7, $rows['gathering_and_activity_rewards']['gold']);
        $this->assertSame(35, $rows['job_rewards']['gold']);
        $this->assertSame(60, $rows['expedition_rewards']['gold']);
        $this->assertSame(12, $rows['npc_vendor_sales']['gold']);
        $this->assertSame(9, $rows['crafting_costs']['gold']);
        $this->assertSame(5, $rows['player_market_transaction_tax']['gold']);
        $this->assertSame(95, $rows['player_market_seller_payouts']['gold']);
        $this->assertSame(30, $rows['shop_purchase']['gold']);
        $this->assertSame(320, $rows['tool_repair']['gold']);
        $this->assertSame([], $flows['known_unmeasured_sinks']);
    }

    public function test_export_tracks_database_overrides_as_runtime_catalog_sources(): void
    {
        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'shop_offers',
            'entry_key' => 'audit_test_bundle',
            'label' => 'Audit Test Bundle',
            'category' => 'Materials',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'kind' => 'item',
                'skill' => null,
                'skill_label' => null,
                'price' => 999,
                'item_key' => 'audit_test_root',
                'item_name' => 'Audit Test Root',
                'quantity' => 1,
                'durability' => null,
                'bonuses' => null,
            ],
        ]);

        $export = app(EconomyAuditService::class)->export();
        $entry = collect($export['catalog_entries'])
            ->first(fn (array $row): bool => $row['surface'] === 'shop_offers' && $row['entry_key'] === 'audit_test_bundle');

        $this->assertSame(390, $export['catalog_counts']['shop_offers']);
        $this->assertSame('database', $entry['source'] ?? null);
        $this->assertContains('audit_test_root', collect($export['items'])->pluck('item_key')->all());
        $this->assertSame('audit_test_bundle', $export['content_overrides'][0]['entry_key']);
    }

    public function test_export_detects_database_override_guardrail_violations(): void
    {
        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'crafting_recipes',
            'entry_key' => 'audit_broken_recipe',
            'label' => 'Audit Broken Recipe',
            'category' => 'Audit',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'skill' => 'smithing',
                'experience' => 1,
                'gold_cost' => 0,
                'ingredients' => [[
                    'item_key' => 'audit_void_shard',
                    'item_name' => 'Audit Void Shard',
                    'quantity' => 1,
                ]],
                'outputs' => [[
                    'item_key' => 'audit_orphan_output',
                    'item_name' => 'Audit Orphan Output',
                    'rarity' => 'common',
                    'quantity' => 1,
                ]],
            ],
        ]);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'job_contracts',
            'entry_key' => 'audit_broken_job',
            'label' => 'Audit Broken Job',
            'category' => 'Audit',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'skill' => 'trading',
                'experience' => 1,
                'gold' => 1,
                'requirements' => [[
                    'item_key' => 'audit_missing_contract_item',
                    'item_name' => 'Audit Missing Contract Item',
                    'quantity' => 1,
                ]],
                'rewards' => [
                    ['type' => 'gold', 'label' => 'Gold', 'quantity' => 1],
                    ['type' => 'experience', 'label' => 'Trading XP', 'quantity' => 1],
                ],
            ],
        ]);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'crafting_recipes',
            'entry_key' => 'audit_profitable_tool',
            'label' => 'Audit Profitable Tool',
            'category' => 'Audit',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'skill' => 'smithing',
                'experience' => 1,
                'gold_cost' => 0,
                'ingredients' => [[
                    'item_key' => 'iron_ore',
                    'item_name' => 'Iron Ore',
                    'quantity' => 1,
                ]],
                'outputs' => [[
                    'item_key' => 'audit_scrap_pickaxe',
                    'item_name' => 'Audit Scrap Pickaxe',
                    'rarity' => 'common',
                    'quantity' => 1,
                    'equipment_skill' => 'mining',
                    'durability' => 100,
                    'bonuses' => [
                        'experience' => 1,
                        'yield' => 1,
                    ],
                ]],
            ],
        ]);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'shop_offers',
            'entry_key' => 'audit_underpriced_pickaxe',
            'label' => 'Audit Underpriced Pickaxe',
            'category' => 'Audit Tools',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'kind' => 'tool',
                'skill' => 'mining',
                'skill_label' => 'Mining',
                'price' => 1,
                'item_key' => 'audit_underpriced_pickaxe',
                'item_name' => 'Audit Underpriced Pickaxe',
                'quantity' => 1,
                'durability' => 100,
                'bonuses' => [
                    'experience' => 1,
                    'yield' => 1,
                ],
            ],
        ]);

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'crafting_recipes',
            'entry_key' => 'audit_free_output_recipe',
            'label' => 'Audit Free Output Recipe',
            'category' => 'Audit',
            'required_level' => 1,
            'rarity' => 'common',
            'enabled' => true,
            'payload' => [
                'skill' => 'smithing',
                'experience' => 1,
                'gold_cost' => 0,
                'ingredients' => [],
                'outputs' => [[
                    'item_key' => 'audit_free_widget',
                    'item_name' => 'Audit Free Widget',
                    'rarity' => 'common',
                    'quantity' => 1,
                ]],
            ],
        ]);

        $violations = app(EconomyAuditService::class)->export()['violations'];

        $this->assertSame('audit_void_shard', $violations['recipe_inputs_unavailable_at_tier']['audit_broken_recipe'][0]['item_key'] ?? null);
        $this->assertSame('missing_source', $violations['recipe_inputs_unavailable_at_tier']['audit_broken_recipe'][0]['reason'] ?? null);
        $this->assertContains('audit_orphan_output', $violations['recipe_outputs_without_primary_use']['audit_broken_recipe'] ?? []);
        $this->assertSame('audit_missing_contract_item', $violations['job_inputs_unavailable_at_tier']['audit_broken_job'][0]['item_key'] ?? null);
        $this->assertContains('audit_broken_job', $violations['unbounded_job_demand']);
        $this->assertSame(5, $violations['tool_craft_salvage_paths']['audit_profitable_tool:audit_scrap_pickaxe']['craft_value'] ?? null);
        $this->assertSame(24, $violations['tool_craft_salvage_paths']['audit_profitable_tool:audit_scrap_pickaxe']['salvage_value'] ?? null);
        $this->assertSame(1, $violations['tool_purchase_salvage_paths']['audit_underpriced_pickaxe']['price'] ?? null);
        $this->assertSame(24, $violations['tool_purchase_salvage_paths']['audit_underpriced_pickaxe']['salvage_value'] ?? null);
        $this->assertSame(0, $violations['zero_input_crafting_paths']['audit_free_output_recipe']['ingredient_quantity'] ?? null);
        $this->assertSame(1, $violations['zero_input_crafting_paths']['audit_free_output_recipe']['output_count'] ?? null);
    }

    public function test_economy_export_command_writes_machine_readable_json(): void
    {
        $path = storage_path('framework/testing/evergather-economy-export.json');

        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('evergather:economy-export', ['--out' => $path])
            ->assertExitCode(0);

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);
        $this->assertSame(389, $payload['catalog_counts']['shop_offers']);
        $this->assertArrayHasKey('canonical_registry', $payload);
        $this->assertArrayHasKey('canonical_registry_summary', $payload);
        $this->assertArrayHasKey('tier_coverage', $payload);
        $this->assertArrayHasKey('pilot_loops', $payload);
        $this->assertArrayHasKey('currency_sinks', $payload);
        $this->assertArrayHasKey('observed_gold_flows', $payload);
        $this->assertArrayHasKey('player_state_fixtures', $payload);
        $this->assertArrayHasKey('edges', $payload);
    }

    private function createConnectedRealmsPlayer(string $displayName, int $gold): ConnectedRealmsPlayer
    {
        $user = User::factory()->create();

        return ConnectedRealmsPlayer::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'species' => 'human',
            'gold' => $gold,
        ]);
    }
}
