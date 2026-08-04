<?php

namespace Tests\Feature\ConnectedRealms;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsAchievementClaim;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsActionLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsCraftingLog;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsEquipmentSlot;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsExpeditionRun;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsInventoryStack;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsJobCompletion;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketListing;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsMarketTransaction;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayer;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsPlayerSkill;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsTool;
use App\Domain\ConnectedRealms\Models\ConnectedRealmsVendorSale;
use App\Domain\ConnectedRealms\Services\CraftingService;
use App\Domain\ConnectedRealms\Services\ExpeditionService;
use App\Domain\ConnectedRealms\Services\GatheringActionService;
use App\Domain\ConnectedRealms\Services\ItemPurposeService;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Domain\ConnectedRealms\Services\ShopService;
use App\Domain\ConnectedRealms\Services\SkillActivityService;
use App\Domain\ConnectedRealms\Services\SkillCatalogService;
use App\Domain\ConnectedRealms\Services\ToolCatalogService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConnectedRealmsGatheringTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_realms_dashboard_creates_player_profile_for_authorized_user(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ConnectedRealms/Index')
                ->where('player.display_name', $user->name)
                ->where('player.species', 'human')
                ->where('player.home_region', 'moonwake_coast')
                ->where('player.appearance.palette', 'moonlit')
                ->where('player.reward_loadout.has_equipped', false)
                ->where('player.gold', 0)
                ->where('player.can_act_now', true)
                ->has('character_options.species')
                ->has('summary')
                ->has('actions', 119)
                ->where('actions.0.equipped_tool.item_key', 'reed_rod')
                ->where('actions.0.equipped_tool.signature_trait', 'Tidehook Memory')
                ->where('actions.0.cooldown_seconds', 1)
                ->where('actions.0.equipped_tool.item_class', 'tool')
                ->where('actions.0.loot_preview.0.quality', 'standard')
                ->where('actions.0.loot_preview.0.weight', 0.2)
                ->where('actions.0.loot_preview.0.item_class', 'resource')
                ->where('actions.7.required_level', 3)
                ->where('actions.28.required_level', 15)
                ->has('skill_activities', 310)
                ->where('skill_activities.0.key', 'smelting_starter_activity_1')
                ->where('skill_activities.0.band', '1-30')
                ->where('skill_activities.0.cooldown_seconds', 1)
                ->where('skill_activities.0.equipped_tool.signature_trait', 'Coalbed Heat Sense')
                ->where('skill_activities.4.required_level', 30)
                ->where('skill_activities.8.band', '80-100')
                ->has('skills', 38)
                ->where('skills.0.skill', 'fishing')
                ->where('skills.0.level', 1)
                ->where('skills.0.next_level_experience', 200)
                ->where('skills.0.target_hours_range.0', 50)
                ->where('skills.0.target_hours_range.1', 90)
                ->has('skills.0.activities', 28)
                ->where('skills.0.unlocks.1.level', 3)
                ->has('skill_catalog.groups')
                ->where('item_catalog.rarities.common.quality', 'standard')
                ->where('item_catalog.rarities.legendary.quality', 'masterwork')
                ->where('skill_catalog.pacing.level_100_experience', 170000)
                ->where('skill_catalog.pacing.target_hours_range.0', 25)
                ->where('skill_catalog.pacing.target_hours_range.1', 200)
                ->where('skill_catalog.pacing.category_targets.Gathering.target_hours_range.0', 50)
                ->where('skill_catalog.pacing.category_targets.Gathering.target_hours_range.1', 90)
                ->where('skill_catalog.pacing.major_action_goal_range.0', 3000)
                ->where('skill_catalog.pacing.major_action_goal_range.1', 5000)
                ->has('equipment', 38)
                ->has('tool_inventory', 38)
                ->where('tool_inventory.0.status', 'equipped')
                ->has('tool_rarity_upgrades.options', 38)
                ->where('tool_rarity_upgrades.options.0.current_rarity', 'common')
                ->where('tool_rarity_upgrades.options.0.target_rarity', 'uncommon')
                ->where('tool_rarity_upgrades.options.0.success_chance', 35)
                ->where('tool_rarity_upgrades.options.0.gold_cost', 45)
                ->where('tool_rarity_upgrades.options.0.materials.0.item_key', 'amber_sap')
                ->has('tool_tier_upgrades.options', 38)
                ->where('tool_tier_upgrades.options.0.next_item_name', 'Apprentice Alembic')
                ->where('tool_tier_upgrades.options.0.gold_cost', 35)
                ->has('crafting_recipes', 689)
                ->where('crafting_recipes.0.key', 'grilled_minnow')
                ->has('jobs', 443)
                ->where('jobs.0.key', 'pier_provisions')
                ->has('expeditions', 164)
                ->where('expeditions.0.key', 'moonwake_supply_run')
                ->has('shop.offers', 351)
                ->has('inventory', 0)
                ->has('recent_actions', 0)
                ->has('recent_crafts', 0)
                ->has('recent_jobs', 0)
                ->has('recent_expeditions', 0)
                ->where('progression.account_level', 1)
                ->where('progression.skill_count', 38)
                ->where('progression.active_skill_count', 0)
                ->where('progression.pacing.estimated_hours_to_level_100', 106.3)
                ->has('progression.achievements', 295)
                ->where('progression.achievements.0.key', 'first_steps')
                ->where('progression.achievements.0.category', 'Gathering')
                ->where('progression.achievements.0.category_key', 'gathering')
                ->where('progression.achievements.0.unlocked', false)
                ->where('progression.achievements.8.key', 'ready_toolbelt')
                ->where('progression.achievements.8.category', 'Equipment')
                ->where('progression.achievements.8.unlocked', true)
                ->where('progression.achievements.66.key', 'account_level_100')
                ->where('progression.achievements.66.level', 100)
                ->where('progression.achievements.66.unlocked', false)
                ->where('progression.achievements.72.key', 'skill_milestone_fishing_100')
                ->where('progression.achievements.72.category', 'Gathering Milestones')
                ->where('progression.achievements.72.skill', 'fishing')
                ->where('progression.achievements.72.level', 100)
                ->where('progression.achievements.72.unlocked', false)
                ->where('progression.stats.total_activity', 0)
                ->where('progression.stats.trade_activity', 0)
                ->where('summary.craft_count', 0)
                ->where('summary.job_count', 0)
                ->where('summary.expedition_count', 0)
                ->where('summary.account_level', 1)
                ->where('summary.inventory_weight', 0)
                ->has('marketplace.sellable_inventory', 0)
                ->has('marketplace.active_listings', 0)
                ->has('marketplace.my_listings', 0)
                ->has('marketplace.recent_transactions', 0)
                ->has('marketplace.market_board.rows', 0)
                ->missing('item_guide')
                ->missing('world_events')
                ->missing('leaderboards')
                ->loadDeferredProps(['evergather-inventory', 'evergather-events', 'evergather-ranks'], fn (Assert $page) => $page
                    ->where('item_guide.summary.tracked_items', fn (int $count): bool => $count > 1000)
                    ->where('item_guide.summary.items_with_sources', fn (int $count): bool => $count > 1000)
                    ->where('item_guide.summary.items_without_sinks', 0)
                    ->has('item_guide.categories')
                    ->has('world_events.active', 3)
                    ->where('world_events.active.0.key', 'meteorfall')
                    ->where('world_events.active.1.key', 'wardens_muster')
                    ->has('world_events.upcoming', 5)
                    ->has('world_events.categories', 8)
                    ->has('leaderboards.wealth')
                    ->has('leaderboards.skills', 0)
                    ->has('leaderboards.activity')
                )
            );

        $this->assertDatabaseHas('connected_realms_players', [
            'user_id' => $user->id,
            'display_name' => $user->name,
        ]);

        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => ConnectedRealmsPlayer::query()->where('user_id', $user->id)->value('id'),
            'slot' => 'tool_fishing',
            'item_key' => 'reed_rod',
        ]);
    }

    public function test_evergather_partial_reloads_skip_heavy_catalog_props(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ConnectedRealms/Index')
                ->has('actions', 119)
                ->has('crafting_recipes', 689)
                ->has('shop.offers', 351)
                ->missing('item_guide')
                ->missing('world_events')
                ->missing('leaderboards')
                ->loadDeferredProps(['evergather-inventory', 'evergather-events', 'evergather-ranks'], fn (Assert $deferred) => $deferred
                    ->has('item_guide.summary')
                    ->has('world_events.active', 3)
                    ->has('leaderboards.wealth')
                )
                ->reloadOnly(['player', 'summary'], fn (Assert $reload) => $reload
                    ->where('player.display_name', $user->name)
                    ->has('summary')
                    ->missing('actions')
                    ->missing('skill_activities')
                    ->missing('crafting_recipes')
                    ->missing('jobs')
                    ->missing('expeditions')
                    ->missing('shop')
                    ->missing('leaderboards')
                )
            );
    }

    public function test_inventory_payload_includes_weight_quality_value_and_searchable_item_metadata(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.inventory_weight', 5)
                ->where('inventory.0.item_key', 'iron_bar')
                ->where('inventory.0.quality', 'standard')
                ->where('inventory.0.quality_score', 40)
                ->where('inventory.0.item_class', 'material')
                ->where('inventory.0.material_family', 'Metal Bar')
                ->where('inventory.0.weight', 1)
                ->where('inventory.0.total_weight', 5)
                ->where('inventory.0.vendor_value', 12)
                ->where('inventory.0.total_vendor_value', 60)
                ->where('inventory.0.npc_buy_price', 4)
                ->where('inventory.0.total_npc_buy_price', 20)
                ->where('inventory.0.market_floor_price', 4)
                ->where('inventory.0.market_ceiling_price', 96)
                ->where('inventory.0.market_price_band', '4-96g')
                ->where('inventory.0.tags.0', 'metal')
                ->where('marketplace.npc_vendor.name', 'Ledger Steward')
                ->where('marketplace.sellable_inventory.0.quality', 'standard')
                ->where('marketplace.sellable_inventory.0.market_price_band', '4-96g')
                ->where('marketplace.sellable_inventory.0.total_weight', 5)
            );
    }

    public function test_authorized_user_can_customize_their_character_profile(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)
            ->put(route('evergather.character.update'), [
                'display_name' => 'Icha Moonhook',
                'title' => 'Pier Runner',
                'species' => 'tideborn',
                'pronouns' => 'they/them',
                'home_region' => 'moonwake_coast',
                'appearance' => [
                    'body_style' => 'compact',
                    'palette' => 'tideglass',
                    'hair_style' => 'braided',
                    'outfit' => 'gatherer',
                ],
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Character updated.');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Icha Moonhook', $player->display_name);
        $this->assertSame('Pier Runner', $player->title);
        $this->assertSame('tideborn', $player->species);
        $this->assertSame('they/them', $player->pronouns);
        $this->assertSame('moonwake_coast', $player->home_region);
        $this->assertSame('tideglass', $player->appearance['palette']);
        $this->assertSame('gatherer', $player->appearance['outfit']);
    }

    public function test_character_customization_validates_supported_options(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->put(route('evergather.character.update'), [
                'display_name' => 'I',
                'species' => 'dragon',
                'home_region' => 'elsewhere',
                'appearance' => [
                    'body_style' => 'mist',
                    'palette' => 'void',
                    'hair_style' => 'storm',
                    'outfit' => 'crown',
                ],
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors([
                'display_name',
                'species',
                'home_region',
                'appearance.body_style',
                'appearance.palette',
                'appearance.hair_style',
                'appearance.outfit',
            ]);
    }

    public function test_gathering_action_awards_items_experience_gold_and_sets_cooldown(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();
        $now = Carbon::parse('2026-08-03 12:00:00');

        Carbon::setTestNow($now);

        try {
            $this->actingAs($user)
                ->post(route('evergather.actions.store'), ['action' => 'fish'])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHas('success', 'Fishing action completed.')
                ->assertSessionHas('connected_realms_result');

            $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

            $this->assertGreaterThanOrEqual(2, $player->gold);
            $this->assertNotNull($player->last_action_at);
            $this->assertTrue($player->next_action_at->isFuture());
            $this->assertTrue($player->next_action_at->equalTo($now->copy()->addSecond()));

            $skill = ConnectedRealmsPlayerSkill::query()
                ->where('player_id', $player->id)
                ->where('skill', 'fishing')
                ->firstOrFail();

            $this->assertGreaterThanOrEqual(22, $skill->experience);
            $this->assertSame(1, $skill->level);

            $this->assertDatabaseHas('connected_realms_equipment_slots', [
                'player_id' => $player->id,
                'slot' => 'tool_fishing',
                'item_key' => 'reed_rod',
            ]);

            $this->assertDatabaseHas('connected_realms_inventory_stacks', [
                'player_id' => $player->id,
                'item_key' => 'river_minnow',
            ]);

            $log = ConnectedRealmsActionLog::query()
                ->where('player_id', $player->id)
                ->firstOrFail();

            $this->assertSame('reed_rod', $log->tool_item_key);
            $this->assertSame('Reed Rod', $log->tool_item_name);
            $this->assertSame(1, ConnectedRealmsActionLog::query()->where('player_id', $player->id)->count());
            $this->assertGreaterThanOrEqual(1, ConnectedRealmsInventoryStack::query()->where('player_id', $player->id)->count());
            $this->assertSame(38, ConnectedRealmsEquipmentSlot::query()->where('player_id', $player->id)->count());

            $this->actingAs($user)
                ->get(route('evergather.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('progression.achievements.0.key', 'first_steps')
                    ->where('progression.achievements.0.unlocked', true)
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unlocked_achievement_rewards_can_be_claimed_once(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();
        $now = Carbon::parse('2026-08-03 12:00:00');

        Carbon::setTestNow($now);

        try {
            $this->actingAs($user)
                ->post(route('evergather.actions.store'), ['action' => 'fish'])
                ->assertRedirect(route('evergather.index'));

            $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
            $goldBeforeClaim = $player->gold;

            $this->actingAs($user)
                ->post(route('evergather.achievements.claims.store'), ['achievement' => 'first_steps'])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHas('success', 'First Steps reward claimed.')
                ->assertSessionHas('connected_realms_result.type', 'achievement_claim')
                ->assertSessionHas('connected_realms_result.gold_awarded', 15);

            $player->refresh();

            $this->assertSame($goldBeforeClaim + 15, $player->gold);
            $this->assertSame('Trailmarked', $player->title);
            $this->assertSame('first_steps', $player->reward_loadout['title_claim_key']);
            $this->assertArrayNotHasKey('badge_claim_key', $player->reward_loadout);
            $this->assertArrayNotHasKey('frame_claim_key', $player->reward_loadout);
            $this->assertDatabaseHas('connected_realms_achievement_claims', [
                'player_id' => $player->id,
                'achievement_key' => 'first_steps',
                'achievement_label' => 'First Steps',
            ]);

            $claim = ConnectedRealmsAchievementClaim::query()
                ->where('player_id', $player->id)
                ->where('achievement_key', 'first_steps')
                ->firstOrFail();

            $this->assertSame('Trailmarked', $claim->reward['title']);
            $this->assertSame(15, $claim->reward['gold']);
            $this->assertArrayNotHasKey('profile_badge', $claim->reward);
            $this->assertArrayNotHasKey('profile_frame', $claim->reward);
            $this->assertArrayNotHasKey('unlock', $claim->reward);

            $this->actingAs($user)
                ->from(route('evergather.index'))
                ->post(route('evergather.achievements.claims.store'), ['achievement' => 'first_steps'])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHasErrors('achievement');

            $this->assertSame(1, ConnectedRealmsAchievementClaim::query()->where('player_id', $player->id)->count());

            $this->actingAs($user)
                ->get(route('evergather.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('player.reward_loadout.title_label', 'Trailmarked')
                    ->missing('player.reward_loadout.badge_label')
                    ->missing('player.reward_loadout.badge_mark')
                    ->missing('player.reward_loadout.badge_tone')
                    ->missing('player.reward_loadout.badge_icon_path')
                    ->missing('player.reward_loadout.frame_label')
                    ->missing('player.reward_loadout.frame_style')
                    ->missing('player.reward_loadout.frame_image_path')
                    ->where('progression.achievements.0.key', 'first_steps')
                    ->where('progression.achievements.0.claimed', true)
                    ->where('progression.achievements.0.can_claim', false)
                    ->where('progression.claimed_rewards.0.achievement_key', 'first_steps')
                    ->where('progression.reward_options.titles.0.key', 'first_steps')
                    ->where('progression.reward_options.titles.0.label', 'Trailmarked')
                    ->missing('progression.reward_options.badges')
                    ->missing('progression.reward_options.frames')
                    ->where('progression.reward_loadout.title_claim_key', 'first_steps')
                    ->missing('progression.reward_loadout.badge_claim_key')
                    ->missing('progression.reward_loadout.frame_claim_key')
                );

            $this->actingAs($user)
                ->put(route('evergather.rewards.loadout.update'), [
                    'title_claim_key' => null,
                ])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHas('success', 'Reward loadout updated.')
                ->assertSessionHas('connected_realms_result.type', 'reward_loadout');

            $player->refresh();

            $this->assertNull($player->title);
            $this->assertNull($player->reward_loadout['title_claim_key']);
            $this->assertArrayNotHasKey('badge_claim_key', $player->reward_loadout);
            $this->assertArrayNotHasKey('frame_claim_key', $player->reward_loadout);

            $this->actingAs($user)
                ->from(route('evergather.index'))
                ->put(route('evergather.rewards.loadout.update'), [
                    'title_claim_key' => 'account_level_100',
                ])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHasErrors('title_claim_key');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_skill_activity_awards_items_experience_gold_and_sets_cooldown(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();
        $now = Carbon::parse('2026-08-03 12:00:00');

        Carbon::setTestNow($now);

        try {
            $this->actingAs($user)
                ->post(route('evergather.activities.store'), ['activity' => 'combat_starter_activity_1'])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHas('success', 'Moonwake Sparring Candlemark Primer completed.')
                ->assertSessionHas('connected_realms_result.type', 'skill_activity')
                ->assertSessionHas('connected_realms_result.band', '1-30');

            $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

            $this->assertGreaterThanOrEqual(3, $player->gold);
            $this->assertTrue($player->next_action_at->equalTo($now->copy()->addSecond()));

            $skill = ConnectedRealmsPlayerSkill::query()
                ->where('player_id', $player->id)
                ->where('skill', 'combat')
                ->firstOrFail();

            $this->assertGreaterThanOrEqual(22, $skill->experience);

            $this->assertDatabaseHas('connected_realms_action_logs', [
                'player_id' => $player->id,
                'action' => 'combat_starter_activity_1',
                'skill' => 'combat',
                'result_label' => 'Moonwake Training Ring - Candleline Station',
            ]);

            $this->assertDatabaseHas('connected_realms_inventory_stacks', [
                'player_id' => $player->id,
                'item_key' => 'combat_starter_combat_badge_1',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_skill_catalog_has_level_100_progression_targets_for_all_leveling_tracks(): void
    {
        $catalog = app(SkillCatalogService::class);
        $pacing = $catalog->pacing();

        $this->assertCount(38, SkillCatalogService::keys());
        $this->assertSame(1, $catalog->levelForExperience(0));
        $this->assertSame(1, $catalog->levelForExperience(199));
        $this->assertSame(2, $catalog->levelForExperience(200));
        $this->assertSame(2500, $catalog->experienceForLevel(20));
        $this->assertSame(9000, $catalog->experienceForLevel(40));
        $this->assertSame(26000, $catalog->experienceForLevel(60));
        $this->assertSame(52000, $catalog->experienceForLevel(75));
        $this->assertSame(95000, $catalog->experienceForLevel(90));
        $this->assertSame(150000, $catalog->experienceForLevel(99));
        $this->assertSame(99, $catalog->levelForExperience(169999));
        $this->assertSame(100, $catalog->levelForExperience(170000));
        $this->assertNull($catalog->nextLevelExperience(100));
        $this->assertSame(170000, $pacing['level_100_experience']);
        $this->assertSame(106.3, $pacing['estimated_hours_to_level_100']);
        $this->assertSame([25, 200], $pacing['target_hours_range']);
        $this->assertSame([50, 90], $catalog->definition('fishing')['target_hours_range']);
        $this->assertSame([50, 100], $catalog->definition('smithing')['target_hours_range']);
        $this->assertSame([75, 150], $catalog->definition('slayer')['target_hours_range']);
        $this->assertSame([100, 200], $catalog->definition('exploration')['target_hours_range']);
        $this->assertSame([100, 200], $catalog->definition('leadership')['target_hours_range']);
        $this->assertSame([3000, 5000], $pacing['major_action_goal_range']);
        $this->assertSame(20000, $pacing['brutal_repetition_threshold']);
        $this->assertSame([
            ['from_level' => 1, 'to_level' => 20, 'target_hours_range' => [1, 2]],
            ['from_level' => 20, 'to_level' => 40, 'target_hours_range' => [3, 5]],
            ['from_level' => 40, 'to_level' => 60, 'target_hours_range' => [8, 12]],
            ['from_level' => 60, 'to_level' => 75, 'target_hours_range' => [12, 18]],
            ['from_level' => 75, 'to_level' => 90, 'target_hours_range' => [20, 30]],
            ['from_level' => 90, 'to_level' => 99, 'target_hours_range' => [20, 35]],
            ['from_level' => 99, 'to_level' => 100, 'target_hours_range' => [5, 15]],
        ], $pacing['level_band_targets']);
    }

    public function test_skill_milestone_achievements_unlock_through_level_100(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsPlayerSkill::query()->updateOrCreate([
            'player_id' => $player->id,
            'skill' => 'fishing',
        ], [
            'level' => 100,
            'experience' => SkillCatalogService::LEVEL_100_EXPERIENCE,
        ]);

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('progression.mastered_skill_count', 1)
                ->where('progression.achievements.67.key', 'skill_milestone_fishing_5')
                ->where('progression.achievements.67.unlocked', true)
                ->where('progression.achievements.72.key', 'skill_milestone_fishing_100')
                ->where('progression.achievements.72.unlocked', true)
            );
    }

    public function test_every_skill_has_at_least_one_leveling_activity(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->load(['skills', 'equipmentSlots', 'inventoryStacks']);

        $coveredSkills = collect([
            ...collect(app(GatheringActionService::class)->availableActionsFor($player))->pluck('skill')->all(),
            ...collect(app(SkillActivityService::class)->availableActivitiesFor($player))->pluck('skill')->all(),
            ...collect(app(CraftingService::class)->availableRecipesFor($player))->pluck('skill')->all(),
            ...collect(app(JobContractService::class)->availableJobsFor($player))->pluck('skill')->all(),
            ...collect(app(ExpeditionService::class)->availableExpeditionsFor($player))->pluck('skill')->all(),
        ])->unique()->sort()->values();

        $missingSkills = collect(SkillCatalogService::keys())
            ->diff($coveredSkills)
            ->values()
            ->all();

        $this->assertSame([], $missingSkills);
    }

    public function test_evergather_catalogs_are_level_100_ready(): void
    {
        $gatheringSkills = ['fishing', 'mining', 'woodcutting', 'foraging', 'hunting', 'farming', 'excavation'];
        $allSkills = SkillCatalogService::keys();
        $recipeSkills = ['smelting', 'milling', 'tanning', 'cutting', 'weaving', 'smithing', 'carpentry', 'cooking', 'alchemy', 'tailoring', 'leatherworking', 'engineering', 'enchanting', 'jewelcrafting', 'boatbuilding', 'furniture', 'construction', 'cartography', 'trading'];
        $activitySkills = collect(SkillCatalogService::keys())
            ->diff($gatheringSkills)
            ->values()
            ->all();
        $expeditionSkills = collect(app(SkillCatalogService::class)->all())
            ->filter(fn (array $skill): bool => in_array($skill['category'], ['Combat', 'World', 'Social'], true))
            ->pluck('key')
            ->all();

        $actions = $this->privateStaticCatalog(GatheringActionService::class, 'actionDefinitions');
        $activities = $this->privateStaticCatalog(SkillActivityService::class, 'activities');
        $recipes = $this->privateStaticCatalog(CraftingService::class, 'recipes');
        $jobs = $this->privateStaticCatalog(JobContractService::class, 'jobs');
        $expeditions = $this->privateStaticCatalog(ExpeditionService::class, 'expeditions');
        $shopOffers = $this->privateStaticCatalog(ShopService::class, 'offers');

        $this->assertCatalogSkillsReachLevel($actions, $gatheringSkills);
        $this->assertCatalogSkillsReachLevel($activities, $activitySkills);
        $this->assertCatalogSkillsReachLevel($recipes, $recipeSkills);
        $this->assertCatalogSkillsReachLevel($jobs, SkillCatalogService::keys());
        $this->assertCatalogSkillsReachLevel($expeditions, $expeditionSkills);
        $this->assertCatalogSkillsReachLevel(
            collect($shopOffers)->where('kind', 'tool')->all(),
            $allSkills,
        );

        $craftedToolsBySkill = collect($recipes)
            ->flatMap(fn (array $recipe): array => collect($recipe['outputs'])
                ->filter(fn (array $output): bool => isset($output['equipment_skill']))
                ->map(fn (array $output): array => [
                    'skill' => $output['equipment_skill'],
                    'required_level' => $recipe['required_level'] ?? 1,
                ])
                ->all())
            ->all();

        $this->assertCatalogSkillsReachLevel($craftedToolsBySkill, $allSkills);

        $this->assertCatalogSkillsIncludeLevels($actions, $gatheringSkills, [15, 20, 25, 30, 35, 40, 45]);
        $this->assertCatalogSkillsIncludeLevels($activities, $activitySkills, [1, 5, 10, 20, 30, 40, 50, 65, 80, 100]);
        $this->assertCatalogSkillsIncludeLevels($recipes, $recipeSkills, [20, 25, 30, 35, 40, 45]);
        $this->assertCatalogSkillsIncludeLevels($jobs, SkillCatalogService::keys(), [15, 20, 25, 30, 35, 40, 45]);
        $this->assertCatalogSkillsIncludeLevels($expeditions, $expeditionSkills, [15, 20, 25, 30, 35, 40, 45]);
        $this->assertCatalogSkillsIncludeLevels($craftedToolsBySkill, $allSkills, [20, 25, 30, 35, 40, 45]);
        $this->assertCatalogSkillsIncludeLevels(
            collect($shopOffers)->where('kind', 'tool')->all(),
            $allSkills,
            [15, 25, 35, 40, 45],
        );
    }

    public function test_evergather_generated_job_names_are_board_ready(): void
    {
        $jobs = collect($this->privateStaticCatalog(JobContractService::class, 'jobs'))
            ->filter(fn (array $job, string $key): bool => str_contains($key, '_contract_'));

        $placeholderLabels = $jobs
            ->filter(fn (array $job): bool => preg_match('/\b(?:Contract|Commission)\s+\d+\b/', $job['label']) === 1
                || preg_match('/\s\d+$/', $job['label']) === 1)
            ->pluck('label')
            ->values()
            ->all();

        $this->assertSame([], $placeholderLabels);
        $this->assertSame('River Market Icebox', $jobs->get('fishing_midgame_contract_15')['label']);
        $this->assertSame('Evergather Tide Tribute', $jobs->get('fishing_mastery_contract_100')['label']);
        $this->assertSame('Market Sovereign Mandate', $jobs->get('trading_mastery_contract_100')['label']);
        $this->assertSame('Realm Mandates', $jobs->get('fishing_mastery_contract_100')['category']);
    }

    public function test_evergather_generated_activity_names_are_board_ready(): void
    {
        $activities = collect($this->privateStaticCatalog(SkillActivityService::class, 'activities'));
        $oldTierPrefixes = ['Starter ', 'Local ', 'Apprentice ', 'Guild ', 'Runed ', 'Storm ', 'Elite ', 'Elder ', 'Mythic ', 'Evergather '];
        $skillPattern = 'Smelting|Milling|Tanning|Cutting|Weaving|Smithing|Carpentry|Cooking|Alchemy|Tailoring|Leatherworking|Engineering|Enchanting|Jewelcrafting|Boatbuilding|Furniture|Construction|Combat|Slayer|Defense|Healing|Magic|Ranged|Exploration|Dungeoneering|Sailing|Survival|Cartography|Reputation|Leadership|Trading';

        $placeholderLabels = $activities
            ->pluck('label')
            ->filter(fn (string $label): bool => preg_match("/^(?:Starter|Local|Apprentice|Guild|Runed|Storm|Elite|Elder|Mythic|Evergather) (?:{$skillPattern})\\b/", $label) === 1)
            ->values()
            ->all();
        $placeholderLoot = $activities
            ->flatMap(fn (array $activity): array => collect($activity['loot'])->pluck('item_name')->all())
            ->filter(fn (string $name): bool => str($name)->startsWith($oldTierPrefixes))
            ->values()
            ->all();

        $this->assertSame([], $placeholderLabels);
        $this->assertSame([], $placeholderLoot);
        $this->assertSame('Moonwake Sparring Candlemark Primer', $activities->get('combat_starter_activity_1')['label']);
        $this->assertSame('Candlemark Sparring Notch', $activities->get('combat_starter_activity_1')['loot'][0]['item_name']);
        $this->assertSame('Crownmark Sparring Notch', $activities->get('combat_evergather_activity_100')['loot'][0]['item_name']);
    }

    public function test_evergather_item_economy_has_coherent_sources_sinks_and_names(): void
    {
        $catalogs = $this->evergatherCatalogs();
        $tools = app(ToolCatalogService::class);
        $producedItems = [];
        $consumedItems = [];
        $itemNames = [];

        foreach ($catalogs['actions'] as $key => $action) {
            foreach ($action['loot'] as $item) {
                $this->recordProducedItem($producedItems, $itemNames, $item, "gather:{$key}");
            }
        }

        foreach ($catalogs['activities'] as $key => $activity) {
            foreach ($activity['loot'] as $item) {
                $this->recordProducedItem($producedItems, $itemNames, $item, "activity:{$key}");
            }
        }

        foreach ($catalogs['recipes'] as $key => $recipe) {
            foreach ($recipe['outputs'] as $item) {
                $this->recordProducedItem($producedItems, $itemNames, $item, "recipe_output:{$key}");
            }

            foreach ($recipe['ingredients'] as $item) {
                $this->recordConsumedItem($consumedItems, $itemNames, $item, "recipe_ingredient:{$key}");
            }
        }

        foreach ($catalogs['jobs'] as $key => $job) {
            foreach ($job['requirements'] as $item) {
                $this->recordConsumedItem($consumedItems, $itemNames, $item, "job:{$key}");
            }
        }

        foreach ($catalogs['expeditions'] as $key => $expedition) {
            foreach ($expedition['rewards'] as $item) {
                $this->recordProducedItem($producedItems, $itemNames, $item, "expedition_reward:{$key}");
            }

            foreach ($expedition['supplies'] as $item) {
                $this->recordConsumedItem($consumedItems, $itemNames, $item, "expedition_supply:{$key}");
            }
        }

        foreach ($catalogs['shop_offers'] as $key => $offer) {
            if ($offer['kind'] === 'item') {
                $this->recordProducedItem($producedItems, $itemNames, $offer, "shop:{$key}");
            }
        }

        foreach (['common', 'uncommon', 'rare', 'epic'] as $rarity) {
            foreach ($tools->rarityMaterials($rarity) as $item) {
                $this->recordConsumedItem($consumedItems, $itemNames, $item, "tool_rarity:{$rarity}");
            }
        }

        foreach ($tools->families() as $skill => $family) {
            foreach ($tools->tierPath() as $tier) {
                foreach ($tools->tierIngredients($family, $tier, $tier['extra']) as $item) {
                    $this->recordConsumedItem($consumedItems, $itemNames, $item, "tool_tier:{$skill}:{$tier['level']}");
                }
            }
        }

        $purposes = app(ItemPurposeService::class);

        foreach ($producedItems as $itemKey => $sources) {
            $itemName = array_key_first($itemNames[$itemKey]);
            $purpose = $purposes->requisitionFor([
                'item_key' => $itemKey,
                'item_name' => $itemName,
            ]);

            foreach ($purpose['requirements'] as $item) {
                $this->recordConsumedItem($consumedItems, $itemNames, $item, "requisition:{$purpose['key']}");
            }
        }

        $missingSources = collect($consumedItems)
            ->keys()
            ->diff(collect($producedItems)->keys())
            ->values()
            ->all();
        $missingUses = collect($producedItems)
            ->keys()
            ->diff(collect($consumedItems)->keys())
            ->values()
            ->all();

        $conflictingNames = collect($itemNames)
            ->filter(fn (array $names): bool => count($names) > 1)
            ->map(fn (array $names): array => array_keys($names))
            ->all();

        $placeholderNames = collect($itemNames)
            ->flatMap(fn (array $names): array => array_keys($names))
            ->unique()
            ->filter(fn (string $name): bool => $this->isPlaceholderItemName($name))
            ->values()
            ->all();

        $this->assertSame([], $missingSources);
        $this->assertSame([], $missingUses);
        $this->assertSame([], $conflictingNames);
        $this->assertSame([], $placeholderNames);
    }

    public function test_owned_orphan_items_unlock_meaningful_requisition_jobs(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'brine_shrimp',
            'item_name' => 'Brine Shrimp',
            'rarity' => 'common',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs', 444)
                ->where('jobs.443.key', 'item_requisition_brine_shrimp')
                ->where('jobs.443.label', 'Brine Shrimp Field Sample')
                ->where('jobs.443.category', 'Field Requisitions')
                ->where('jobs.443.can_complete', true)
                ->missing('item_guide')
                ->loadDeferredProps('evergather-inventory', fn (Assert $deferred) => $deferred
                    ->where('item_guide.summary.items_without_sinks', 0)
                )
            );

        $this->actingAs($user)
            ->post(route('evergather.jobs.store'), ['job' => 'item_requisition_brine_shrimp'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Brine Shrimp Field Sample completed.');

        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'brine_shrimp',
        ]);
        $this->assertDatabaseHas('connected_realms_job_completions', [
            'player_id' => $player->id,
            'job_key' => 'item_requisition_brine_shrimp',
            'job_name' => 'Brine Shrimp Field Sample',
            'category' => 'Field Requisitions',
        ]);
    }

    public function test_locked_gathering_action_requires_skill_level(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.actions.store'), ['action' => 'reef_net'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('action');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(0, ConnectedRealmsActionLog::query()->where('player_id', $player->id)->count());
    }

    public function test_active_world_event_bonus_applies_to_matching_gathering_action(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)
            ->post(route('evergather.actions.store'), ['action' => 'mine'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('connected_realms_result.event.key', 'meteorfall');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $skill = ConnectedRealmsPlayerSkill::query()
            ->where('player_id', $player->id)
            ->where('skill', 'mining')
            ->firstOrFail();
        $log = ConnectedRealmsActionLog::query()
            ->where('player_id', $player->id)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(26, $skill->experience);
        $this->assertSame('meteorfall', $log->event_key);
        $this->assertSame('Meteorfall', $log->event_label);
    }

    public function test_leaderboards_rank_players_by_gold_skill_experience_and_activity(): void
    {
        $viewer = $this->verifiedUserWithConnectedRealmsAccess();
        $wealthUser = $this->verifiedUserWithConnectedRealmsAccess();
        $activeUser = $this->verifiedUserWithConnectedRealmsAccess();

        $wealthPlayer = ConnectedRealmsPlayer::query()->create([
            'user_id' => $wealthUser->id,
            'display_name' => 'High Gold',
            'species' => 'human',
            'gold' => 80,
        ]);
        $activePlayer = ConnectedRealmsPlayer::query()->create([
            'user_id' => $activeUser->id,
            'display_name' => 'Busy Crafter',
            'species' => 'sylvan',
            'gold' => 20,
        ]);

        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $activePlayer->id,
            'skill' => 'mining',
            'level' => 2,
            'experience' => 120,
        ]);
        ConnectedRealmsPlayerSkill::query()->create([
            'player_id' => $wealthPlayer->id,
            'skill' => 'fishing',
            'level' => 1,
            'experience' => 50,
        ]);

        foreach (range(1, 2) as $index) {
            ConnectedRealmsActionLog::query()->create([
                'player_id' => $activePlayer->id,
                'action' => 'mine',
                'skill' => 'mining',
                'platform' => 'website',
                'result_label' => 'Emberdeep Quarry',
                'items_awarded' => [],
                'experience_awarded' => 20,
                'gold_awarded' => $index,
                'available_at' => now(),
            ]);
        }

        ConnectedRealmsCraftingLog::query()->create([
            'player_id' => $activePlayer->id,
            'recipe_key' => 'iron_bar',
            'recipe_name' => 'Iron Bar',
            'skill' => 'smithing',
            'items_consumed' => [],
            'items_created' => [],
            'experience_awarded' => 44,
            'gold_cost' => 0,
        ]);
        ConnectedRealmsJobCompletion::query()->create([
            'player_id' => $activePlayer->id,
            'job_key' => 'quarry_smelter',
            'job_name' => 'Quarry Smelter',
            'category' => 'smithing',
            'items_delivered' => [],
            'rewards' => [],
            'experience_awarded' => 35,
            'gold_awarded' => 35,
        ]);
        ConnectedRealmsExpeditionRun::query()->create([
            'player_id' => $activePlayer->id,
            'expedition_key' => 'moonwake_supply_run',
            'expedition_name' => 'Moonwake Supply Run',
            'status' => 'completed',
            'supplies_consumed' => [],
            'items_awarded' => [],
            'experience_awarded' => 45,
            'gold_awarded' => 30,
            'resolved_at' => now(),
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $activePlayer->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 7,
        ]);

        $listing = ConnectedRealmsMarketListing::query()->create([
            'seller_player_id' => $activePlayer->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 2,
            'unit_price' => 9,
            'status' => ConnectedRealmsMarketListing::STATUS_SOLD,
            'sold_at' => now(),
        ]);
        ConnectedRealmsMarketTransaction::query()->create([
            'listing_id' => $listing->id,
            'seller_player_id' => $activePlayer->id,
            'buyer_player_id' => $wealthPlayer->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 2,
            'unit_price' => 9,
            'total_price' => 18,
        ]);

        $this->actingAs($viewer)
            ->get(route('evergather.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('leaderboards')
                ->loadDeferredProps('evergather-ranks', fn (Assert $deferred) => $deferred
                    ->where('leaderboards.groups.0.key', 'summary')
                    ->where('leaderboards.wealth.0.display_name', 'High Gold')
                    ->where('leaderboards.overall.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.realm_score.0.display_name', 'High Gold')
                    ->where('leaderboards.skills.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.skill_champions.0.display_name', 'High Gold')
                    ->where('leaderboards.skill_champions.1.display_name', 'Busy Crafter')
                    ->where('leaderboards.activity.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.gathering.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.crafting.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.jobs.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.expeditions.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.inventory.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.market_sellers.0.display_name', 'Busy Crafter')
                    ->where('leaderboards.market_buyers.0.display_name', 'High Gold')
                )
            );

        $this->assertDatabaseHas('connected_realms_leaderboard_seasons', [
            'key' => 'current',
            'name' => 'Current Season',
            'active' => true,
        ]);
        $this->assertDatabaseHas('connected_realms_leaderboard_boards', [
            'key' => 'market_buyers',
            'group_key' => 'trade',
            'label' => 'Market Buyers',
        ]);
        $this->assertDatabaseHas('connected_realms_leaderboard_entries', [
            'player_id' => $wealthPlayer->id,
            'display_name' => 'High Gold',
            'score' => 18,
            'score_label' => '18 gold',
        ]);
    }

    public function test_authorized_user_can_craft_basic_recipe_from_inventory(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'river_minnow',
            'item_name' => 'River Minnow',
            'rarity' => 'common',
            'quantity' => 3,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.crafting.store'), ['recipe' => 'grilled_minnow'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Grilled Minnow crafted.')
            ->assertSessionHas('connected_realms_result');

        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'river_minnow',
        ]);

        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'grilled_minnow',
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('connected_realms_player_skills', [
            'player_id' => $player->id,
            'skill' => 'cooking',
            'experience' => 20,
        ]);

        $this->assertSame(1, ConnectedRealmsCraftingLog::query()->where('player_id', $player->id)->count());
    }

    public function test_authorized_user_can_craft_tool_upgrade_that_equips_to_slot(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 2,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'amber_sap',
            'item_name' => 'Amber Sap',
            'rarity' => 'uncommon',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.crafting.store'), ['recipe' => 'apprentice_pickaxe_craft'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Apprentice Pickaxe crafted.');

        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'item_key' => 'apprentice_pickaxe',
            'rarity' => 'uncommon',
        ]);
        $this->assertDatabaseHas('connected_realms_player_skills', [
            'player_id' => $player->id,
            'skill' => 'smithing',
            'experience' => 44,
        ]);
    }

    public function test_authorized_user_can_buy_shop_tool_for_gold(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $player->forceFill(['gold' => 100])->save();

        $this->actingAs($user)
            ->post(route('evergather.shop.purchases.store'), ['offer' => 'apprentice_pickaxe'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Apprentice Pickaxe purchased.');

        $player->refresh();

        $this->assertSame(20, $player->gold);
        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'item_key' => 'apprentice_pickaxe',
        ]);

        $shopOffer = collect(app(ShopService::class)->snapshotFor($player->refresh())['offers'])
            ->firstWhere('key', 'apprentice_pickaxe');

        $this->assertTrue($shopOffer['is_equipped']);
        $this->assertFalse($shopOffer['can_buy']);
        $this->assertSame('Already equipped', $shopOffer['ownership_status']);
        $this->assertSame('Apprentice Pickaxe', $shopOffer['current_tool']['item_name']);

        $player->forceFill(['gold' => 100])->save();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.shop.purchases.store'), ['offer' => 'apprentice_pickaxe'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('offer');
    }

    public function test_shop_purchase_requires_enough_gold(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.shop.purchases.store'), ['offer' => 'apprentice_pickaxe'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('offer');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertDatabaseHas('connected_realms_equipment_slots', [
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'item_key' => 'worn_pickaxe',
        ]);
    }

    public function test_authorized_user_can_complete_job_contract_from_inventory(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'grilled_minnow',
            'item_name' => 'Grilled Minnow',
            'rarity' => 'common',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.jobs.store'), ['job' => 'pier_provisions'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Pier Provisions completed.')
            ->assertSessionHas('connected_realms_result');

        $player->refresh();

        $this->assertSame(35, $player->gold);
        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'grilled_minnow',
        ]);
        $this->assertDatabaseHas('connected_realms_player_skills', [
            'player_id' => $player->id,
            'skill' => 'cooking',
            'experience' => 35,
        ]);
        $this->assertSame(1, ConnectedRealmsJobCompletion::query()->where('player_id', $player->id)->count());
    }

    public function test_job_completion_requires_required_materials(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.jobs.store'), ['job' => 'quarry_smelter'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('job');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(0, ConnectedRealmsJobCompletion::query()->where('player_id', $player->id)->count());
    }

    public function test_authorized_user_can_run_supplied_expedition(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'grilled_minnow',
            'item_name' => 'Grilled Minnow',
            'rarity' => 'common',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.expeditions.store'), ['expedition' => 'moonwake_supply_run'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Moonwake Supply Run resolved.');

        $player->refresh();

        $this->assertSame(30, $player->gold);
        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'grilled_minnow',
        ]);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'shell_charm',
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('connected_realms_player_skills', [
            'player_id' => $player->id,
            'skill' => 'exploration',
            'experience' => 45,
        ]);
        $this->assertSame(1, ConnectedRealmsExpeditionRun::query()->where('player_id', $player->id)->count());
    }

    public function test_expedition_requires_supplies(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.expeditions.store'), ['expedition' => 'emberdeep_delve'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('expedition');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(0, ConnectedRealmsExpeditionRun::query()->where('player_id', $player->id)->count());
    }

    public function test_authorized_user_can_list_inventory_on_marketplace(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.marketplace.listings.store'), [
                'item_key' => 'iron_bar',
                'quantity' => 2,
                'unit_price' => 9,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Iron Bar listed.');

        $this->assertDatabaseHas('connected_realms_market_listings', [
            'seller_player_id' => $player->id,
            'item_key' => 'iron_bar',
            'quantity' => 2,
            'unit_price' => 9,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'quantity' => 3,
        ]);
    }

    public function test_marketplace_listing_requires_item_price_floor_and_cap(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.marketplace.listings.store'), [
                'item_key' => 'iron_bar',
                'quantity' => 1,
                'unit_price' => 1,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('unit_price');

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.marketplace.listings.store'), [
                'item_key' => 'iron_bar',
                'quantity' => 1,
                'unit_price' => 2000,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('unit_price');

        $this->assertSame(0, ConnectedRealmsMarketListing::query()->where('seller_player_id', $player->id)->count());
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.marketplace.listings.store'), [
                'item_key' => 'iron_bar',
                'quantity' => 1,
                'unit_price' => 4,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Iron Bar listed.');

        $this->assertDatabaseHas('connected_realms_market_listings', [
            'seller_player_id' => $player->id,
            'item_key' => 'iron_bar',
            'quantity' => 1,
            'unit_price' => 4,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);
    }

    public function test_authorized_user_can_sell_inventory_to_npc_market_floor_vendor(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.marketplace.vendor-sales.store'), [
                'item_key' => 'iron_bar',
                'quantity' => 3,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Iron Bar sold to Ledger Steward.')
            ->assertSessionHas('connected_realms_result.type', 'npc_sale')
            ->assertSessionHas('connected_realms_result.gold_awarded', 12);

        $player->refresh();

        $this->assertSame(12, $player->gold);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('connected_realms_vendor_sales', [
            'player_id' => $player->id,
            'vendor_name' => 'Ledger Steward',
            'item_key' => 'iron_bar',
            'quantity' => 3,
            'unit_price' => 4,
            'total_price' => 12,
        ]);
        $this->assertSame(1, ConnectedRealmsVendorSale::query()->where('player_id', $player->id)->count());
    }

    public function test_marketplace_purchase_transfers_gold_and_items(): void
    {
        $sellerUser = $this->verifiedUserWithConnectedRealmsAccess();
        $buyerUser = $this->verifiedUserWithConnectedRealmsAccess();

        $seller = ConnectedRealmsPlayer::query()->create([
            'user_id' => $sellerUser->id,
            'display_name' => 'Seller',
            'species' => 'human',
            'gold' => 5,
        ]);
        $buyer = ConnectedRealmsPlayer::query()->create([
            'user_id' => $buyerUser->id,
            'display_name' => 'Buyer',
            'species' => 'human',
            'gold' => 40,
        ]);
        $listing = ConnectedRealmsMarketListing::query()->create([
            'seller_player_id' => $seller->id,
            'item_key' => 'ashwood_plank',
            'item_name' => 'Ashwood Plank',
            'rarity' => 'common',
            'quantity' => 2,
            'unit_price' => 10,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);

        $this->actingAs($buyerUser)
            ->post(route('evergather.marketplace.listings.buy', $listing->id))
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Ashwood Plank purchased.');

        $seller->refresh();
        $buyer->refresh();
        $listing->refresh();

        $this->assertSame(25, $seller->gold);
        $this->assertSame(20, $buyer->gold);
        $this->assertSame(ConnectedRealmsMarketListing::STATUS_SOLD, $listing->status);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $buyer->id,
            'item_key' => 'ashwood_plank',
            'quantity' => 2,
        ]);
        $this->assertSame(1, ConnectedRealmsMarketTransaction::query()->where('listing_id', $listing->id)->count());
    }

    public function test_marketplace_listing_cancellation_returns_items_to_seller(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();
        $player = ConnectedRealmsPlayer::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Seller',
            'species' => 'human',
            'gold' => 0,
        ]);
        $listing = ConnectedRealmsMarketListing::query()->create([
            'seller_player_id' => $player->id,
            'item_key' => 'field_tonic',
            'item_name' => 'Field Tonic',
            'rarity' => 'uncommon',
            'quantity' => 1,
            'unit_price' => 25,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->delete(route('evergather.marketplace.listings.destroy', $listing->id))
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Field Tonic listing cancelled.');

        $listing->refresh();

        $this->assertSame(ConnectedRealmsMarketListing::STATUS_CANCELLED, $listing->status);
        $this->assertDatabaseHas('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'field_tonic',
            'quantity' => 1,
        ]);
    }

    public function test_authorized_user_can_list_inventory_tool_on_marketplace(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $tool = ConnectedRealmsTool::query()->create([
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'skill' => 'mining',
            'item_key' => 'guild_pickaxe',
            'item_name' => 'Guild Pickaxe',
            'rarity' => 'rare',
            'durability' => 100,
            'bonuses' => ['skill' => 'mining', 'experience' => 17, 'yield' => 3],
            'origin' => 'crafted',
            'status' => ConnectedRealmsTool::STATUS_INVENTORY,
            'maker_name' => $player->display_name,
            'tier_level' => 20,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.marketplace.listings.store'), [
                'listing_type' => 'tool',
                'tool_id' => $tool->id,
                'unit_price' => 400,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Guild Pickaxe listed.');

        $tool->refresh();

        $this->assertSame(ConnectedRealmsTool::STATUS_LISTED, $tool->status);
        $this->assertDatabaseHas('connected_realms_market_listings', [
            'seller_player_id' => $player->id,
            'listing_type' => ConnectedRealmsMarketListing::TYPE_TOOL,
            'tool_id' => $tool->id,
            'item_key' => 'guild_pickaxe',
            'quantity' => 1,
            'unit_price' => 400,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);
    }

    public function test_marketplace_purchase_transfers_unique_tool_without_stacking_it(): void
    {
        $sellerUser = $this->verifiedUserWithConnectedRealmsAccess();
        $buyerUser = $this->verifiedUserWithConnectedRealmsAccess();

        $seller = ConnectedRealmsPlayer::query()->create([
            'user_id' => $sellerUser->id,
            'display_name' => 'Toolsmith',
            'species' => 'human',
            'gold' => 10,
        ]);
        $buyer = ConnectedRealmsPlayer::query()->create([
            'user_id' => $buyerUser->id,
            'display_name' => 'Buyer',
            'species' => 'human',
            'gold' => 600,
        ]);
        $tool = ConnectedRealmsTool::query()->create([
            'player_id' => $seller->id,
            'slot' => 'tool_mining',
            'skill' => 'mining',
            'item_key' => 'guild_pickaxe',
            'item_name' => 'Guild Pickaxe',
            'rarity' => 'rare',
            'durability' => 100,
            'bonuses' => ['skill' => 'mining', 'experience' => 17, 'yield' => 3],
            'origin' => 'crafted',
            'status' => ConnectedRealmsTool::STATUS_LISTED,
            'maker_name' => 'Toolsmith',
            'tier_level' => 20,
        ]);
        $listing = ConnectedRealmsMarketListing::query()->create([
            'seller_player_id' => $seller->id,
            'listing_type' => ConnectedRealmsMarketListing::TYPE_TOOL,
            'tool_id' => $tool->id,
            'item_key' => $tool->item_key,
            'item_name' => $tool->item_name,
            'rarity' => $tool->rarity,
            'quantity' => 1,
            'unit_price' => 400,
            'status' => ConnectedRealmsMarketListing::STATUS_ACTIVE,
        ]);

        $this->actingAs($buyerUser)
            ->post(route('evergather.marketplace.listings.buy', $listing->id))
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Guild Pickaxe purchased.');

        $seller->refresh();
        $buyer->refresh();
        $tool->refresh();
        $listing->refresh();

        $this->assertSame(410, $seller->gold);
        $this->assertSame(200, $buyer->gold);
        $this->assertSame($buyer->id, $tool->player_id);
        $this->assertSame(ConnectedRealmsTool::STATUS_INVENTORY, $tool->status);
        $this->assertSame(ConnectedRealmsMarketListing::STATUS_SOLD, $listing->status);
        $this->assertSame(0, ConnectedRealmsInventoryStack::query()->where('player_id', $buyer->id)->where('item_key', 'guild_pickaxe')->count());
        $this->assertDatabaseHas('connected_realms_market_transactions', [
            'listing_id' => $listing->id,
            'listing_type' => ConnectedRealmsMarketListing::TYPE_TOOL,
            'tool_id' => $tool->id,
            'total_price' => 400,
        ]);
    }

    public function test_crafting_requires_required_materials(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.crafting.store'), ['recipe' => 'iron_bar'])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('recipe');

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(0, ConnectedRealmsCraftingLog::query()->where('player_id', $player->id)->count());
    }

    public function test_tool_tier_upgrade_preserves_tool_identity_and_consumes_materials(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $player->forceFill(['gold' => 100])->save();
        $equipment = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $player->id)
            ->where('slot', 'tool_mining')
            ->firstOrFail();
        $toolId = $equipment->tool_id;

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
            'item_name' => 'Iron Bar',
            'rarity' => 'common',
            'quantity' => 2,
        ]);
        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'amber_sap',
            'item_name' => 'Amber Sap',
            'rarity' => 'common',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.tools.tier-upgrades.store'), [
                'slot' => 'tool_mining',
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('connected_realms_result.type', 'tool_tier_upgrade')
            ->assertSessionHas('connected_realms_result.previous_item_name', 'Worn Pickaxe')
            ->assertSessionHas('connected_realms_result.item_name', 'Apprentice Pickaxe');

        $equipment->refresh();
        $player->refresh();
        $tool = ConnectedRealmsTool::query()->findOrFail($toolId);

        $this->assertSame($toolId, $equipment->tool_id);
        $this->assertSame($toolId, $tool->id);
        $this->assertSame('apprentice_pickaxe', $tool->item_key);
        $this->assertSame('Apprentice Pickaxe', $tool->item_name);
        $this->assertSame('upgraded', $tool->origin);
        $this->assertSame(1, $tool->tier_level);
        $this->assertSame(1, $tool->tier_upgrade_count);
        $this->assertSame(65, $player->gold);
        $this->assertDatabaseMissing('connected_realms_inventory_stacks', [
            'player_id' => $player->id,
            'item_key' => 'iron_bar',
        ]);
        $this->assertDatabaseHas('connected_realms_player_skills', [
            'player_id' => $player->id,
            'skill' => 'smithing',
            'experience' => 44,
        ]);
    }

    public function test_authorized_user_can_equip_stored_tool_and_swap_current_tool_to_inventory(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $starterEquipment = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $player->id)
            ->where('slot', 'tool_mining')
            ->firstOrFail();
        $starterToolId = $starterEquipment->tool_id;
        $storedTool = ConnectedRealmsTool::query()->create([
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'skill' => 'mining',
            'item_key' => 'guild_pickaxe',
            'item_name' => 'Guild Pickaxe',
            'rarity' => 'rare',
            'durability' => 100,
            'bonuses' => ['skill' => 'mining', 'experience' => 17, 'yield' => 3],
            'origin' => 'crafted',
            'status' => ConnectedRealmsTool::STATUS_INVENTORY,
            'tier_level' => 20,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.tools.equipment.store'), [
                'tool_id' => $storedTool->id,
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Guild Pickaxe equipped.')
            ->assertSessionHas('connected_realms_result.type', 'tool_equip');

        $starterEquipment->refresh();
        $storedTool->refresh();
        $starterTool = ConnectedRealmsTool::query()->findOrFail($starterToolId);

        $this->assertSame($storedTool->id, $starterEquipment->tool_id);
        $this->assertSame('Guild Pickaxe', $starterEquipment->item_name);
        $this->assertSame(ConnectedRealmsTool::STATUS_EQUIPPED, $storedTool->status);
        $this->assertSame(ConnectedRealmsTool::STATUS_INVENTORY, $starterTool->status);
    }

    public function test_authorized_user_can_unequip_tool_back_to_starter_tool(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $storedTool = ConnectedRealmsTool::query()->create([
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'skill' => 'mining',
            'item_key' => 'guild_pickaxe',
            'item_name' => 'Guild Pickaxe',
            'rarity' => 'rare',
            'durability' => 100,
            'bonuses' => ['skill' => 'mining', 'experience' => 17, 'yield' => 3],
            'origin' => 'crafted',
            'status' => ConnectedRealmsTool::STATUS_INVENTORY,
            'tier_level' => 20,
        ]);

        $this->actingAs($user)->post(route('evergather.tools.equipment.store'), [
            'tool_id' => $storedTool->id,
        ]);

        $this->actingAs($user)
            ->delete(route('evergather.tools.equipment.destroy'), [
                'slot' => 'tool_mining',
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('success', 'Guild Pickaxe unequipped.')
            ->assertSessionHas('connected_realms_result.type', 'tool_unequip');

        $equipment = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $player->id)
            ->where('slot', 'tool_mining')
            ->firstOrFail();
        $storedTool->refresh();

        $this->assertSame('worn_pickaxe', $equipment->item_key);
        $this->assertSame('starter', $equipment->origin);
        $this->assertSame(ConnectedRealmsTool::STATUS_INVENTORY, $storedTool->status);
        $this->assertDatabaseHas('connected_realms_tools', [
            'player_id' => $player->id,
            'slot' => 'tool_mining',
            'origin' => 'starter',
            'status' => ConnectedRealmsTool::STATUS_EQUIPPED,
        ]);
    }

    public function test_starter_tools_cannot_be_unequipped_without_replacement(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->delete(route('evergather.tools.equipment.destroy'), [
                'slot' => 'tool_mining',
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('slot');
    }

    public function test_tool_rarity_upgrade_completes_from_banked_progress_without_replacing_tool(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $player->forceFill(['gold' => 1000])->save();

        $tool = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $player->id)
            ->where('slot', 'tool_mining')
            ->firstOrFail();

        $tool->forceFill([
            'rarity' => 'common',
            'rarity_progress' => 99,
            'upgrade_count' => 0,
            'rarity_upgrade_attempts' => 0,
            'bonuses' => ['skill' => 'mining', 'experience' => 4, 'yield' => 1],
        ])->save();

        ConnectedRealmsInventoryStack::query()->create([
            'player_id' => $player->id,
            'item_key' => 'amber_sap',
            'item_name' => 'Amber Sap',
            'rarity' => 'common',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('evergather.tools.rarity-upgrades.store'), [
                'slot' => 'tool_mining',
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHas('connected_realms_result', function (array $result) use ($tool): bool {
                return $result['type'] === 'tool_rarity_upgrade'
                    && $result['success'] === true
                    && $result['previous_rarity'] === 'common'
                    && $result['rarity'] === 'uncommon'
                    && $result['tool']['item_key'] === $tool->item_key;
            });

        $tool->refresh();
        $player->refresh();

        $this->assertSame('worn_pickaxe', $tool->item_key);
        $this->assertSame('uncommon', $tool->rarity);
        $this->assertSame(0, $tool->rarity_progress);
        $this->assertSame(1, $tool->upgrade_count);
        $this->assertSame(1, $tool->rarity_upgrade_attempts);
        $this->assertSame(7, $tool->bonuses['experience']);
        $this->assertSame(1, $tool->bonuses['yield']);
        $this->assertSame(955, $player->gold);
    }

    public function test_tool_rarity_upgrade_refuses_max_rarity_tools(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();

        $this->actingAs($user)->get(route('evergather.index'))->assertOk();

        $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();
        $player->forceFill(['gold' => 10000])->save();

        $tool = ConnectedRealmsEquipmentSlot::query()
            ->where('player_id', $player->id)
            ->where('slot', 'tool_fishing')
            ->firstOrFail();

        $tool->forceFill([
            'rarity' => 'legendary',
            'rarity_progress' => 0,
        ])->save();

        $this->actingAs($user)
            ->from(route('evergather.index'))
            ->post(route('evergather.tools.rarity-upgrades.store'), [
                'slot' => 'tool_fishing',
            ])
            ->assertRedirect(route('evergather.index'))
            ->assertSessionHasErrors('slot');

        $tool->refresh();
        $player->refresh();

        $this->assertSame('legendary', $tool->rarity);
        $this->assertSame(0, $tool->rarity_upgrade_attempts);
        $this->assertSame(10000, $player->gold);
    }

    public function test_cooldown_blocks_platform_hopping_between_actions(): void
    {
        $user = $this->verifiedUserWithConnectedRealmsAccess();
        $now = Carbon::parse('2026-08-03 12:00:00');

        Carbon::setTestNow($now);

        try {
            $this->actingAs($user)
                ->post(route('evergather.actions.store'), ['action' => 'mine'])
                ->assertRedirect(route('evergather.index'));

            $this->actingAs($user)
                ->from(route('evergather.index'))
                ->post(route('evergather.actions.store'), ['action' => 'forage'])
                ->assertRedirect(route('evergather.index'))
                ->assertSessionHasErrors('action');

            $player = ConnectedRealmsPlayer::query()->where('user_id', $user->id)->firstOrFail();

            $this->assertSame(1, ConnectedRealmsActionLog::query()->where('player_id', $player->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_connected_realms_requires_area_access(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('evergather.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }

    private function verifiedUserWithConnectedRealmsAccess(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_CONNECTED_REALMS, 'web');
        $user->assignRole(User::ROLE_CONNECTED_REALMS);

        return $user;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function privateStaticCatalog(string $class, string $method): array
    {
        $reflection = new ReflectionMethod($class, $method);

        return $reflection->invoke(null);
    }

    /**
     * @return array{
     *     actions: array<string, array<string, mixed>>,
     *     activities: array<string, array<string, mixed>>,
     *     recipes: array<string, array<string, mixed>>,
     *     jobs: array<string, array<string, mixed>>,
     *     expeditions: array<string, array<string, mixed>>,
     *     shop_offers: array<string, array<string, mixed>>
     * }
     */
    private function evergatherCatalogs(): array
    {
        return [
            'actions' => $this->privateStaticCatalog(GatheringActionService::class, 'actionDefinitions'),
            'activities' => $this->privateStaticCatalog(SkillActivityService::class, 'activities'),
            'recipes' => $this->privateStaticCatalog(CraftingService::class, 'recipes'),
            'jobs' => $this->privateStaticCatalog(JobContractService::class, 'jobs'),
            'expeditions' => $this->privateStaticCatalog(ExpeditionService::class, 'expeditions'),
            'shop_offers' => $this->privateStaticCatalog(ShopService::class, 'offers'),
        ];
    }

    /**
     * @param  array<string, list<string>>  $producedItems
     * @param  array<string, array<string, true>>  $itemNames
     * @param  array<string, mixed>  $item
     */
    private function recordProducedItem(array &$producedItems, array &$itemNames, array $item, string $source): void
    {
        $this->recordCatalogItem($producedItems, $itemNames, $item, $source);
    }

    /**
     * @param  array<string, list<string>>  $consumedItems
     * @param  array<string, array<string, true>>  $itemNames
     * @param  array<string, mixed>  $item
     */
    private function recordConsumedItem(array &$consumedItems, array &$itemNames, array $item, string $source): void
    {
        $this->recordCatalogItem($consumedItems, $itemNames, $item, $source);
    }

    /**
     * @param  array<string, list<string>>  $items
     * @param  array<string, array<string, true>>  $itemNames
     * @param  array<string, mixed>  $item
     */
    private function recordCatalogItem(array &$items, array &$itemNames, array $item, string $source): void
    {
        $itemKey = $this->catalogItemKey($item);

        if ($itemKey === '') {
            return;
        }

        $items[$itemKey][] = $source;
        $itemNames[$itemKey][$this->catalogItemName($item)] = true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function catalogItemKey(array $item): string
    {
        return (string) ($item['item_key'] ?? $item['key'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function catalogItemName(array $item): string
    {
        return (string) ($item['item_name'] ?? $item['name'] ?? $this->catalogItemKey($item));
    }

    private function isPlaceholderItemName(string $name): bool
    {
        $skillPattern = 'Fishing|Mining|Woodcutting|Foraging|Hunting|Farming|Excavation|Smelting|Milling|Tanning|Cutting|Weaving|Smithing|Carpentry|Cooking|Alchemy|Tailoring|Leatherworking|Engineering|Enchanting|Jewelcrafting|Boatbuilding|Furniture|Construction|Cartography|Trading';

        return preg_match("/\\b({$skillPattern}) (Resource|Work)\\b/", $name) === 1
            || preg_match('/\\b(Astral|Prismatic|Elder|Mythic|Runed|Evergather) \\1\\b/', $name) === 1
            || str_contains($name, 'Mythic Mythrite');
    }

    /**
     * @param  array<string, array<string, mixed>>|array<int, array<string, mixed>>  $catalog
     * @param  list<string>  $skills
     */
    private function assertCatalogSkillsReachLevel(array $catalog, array $skills): void
    {
        $maxLevels = collect($catalog)
            ->groupBy(fn (array $entry): string => $entry['skill'])
            ->map(fn ($entries): int => (int) $entries
                ->map(fn (array $entry): int => (int) ($entry['required_level'] ?? 1))
                ->max());

        $missing = collect($skills)
            ->filter(fn (string $skill): bool => (int) ($maxLevels->get($skill) ?? 0) < SkillCatalogService::MAX_LEVEL)
            ->values()
            ->all();

        $this->assertSame([], $missing);
    }

    /**
     * @param  array<string, array<string, mixed>>|array<int, array<string, mixed>>  $catalog
     * @param  list<string>  $skills
     * @param  list<int>  $levels
     */
    private function assertCatalogSkillsIncludeLevels(array $catalog, array $skills, array $levels): void
    {
        $levelsBySkill = collect($catalog)
            ->groupBy(fn (array $entry): string => $entry['skill'])
            ->map(fn ($entries) => $entries
                ->map(fn (array $entry): int => (int) ($entry['required_level'] ?? 1))
                ->unique()
                ->values());

        $missing = collect($skills)
            ->flatMap(function (string $skill) use ($levels, $levelsBySkill): array {
                $availableLevels = $levelsBySkill->get($skill, collect());

                return collect($levels)
                    ->filter(fn (int $level): bool => ! $availableLevels->contains($level))
                    ->map(fn (int $level): string => "{$skill}:{$level}")
                    ->all();
            })
            ->values()
            ->all();

        $this->assertSame([], $missing);
    }
}
