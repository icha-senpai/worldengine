use spacetimedb::{Identity, SpacetimeType, Timestamp};

/// Public quest text and rewards; progress and claims are caller-scoped.
#[derive(serde::Deserialize)]
#[spacetimedb::table(accessor = quest_definition, public)]
pub struct QuestDefinition {
    #[primary_key]
    pub quest_id: u32,
    pub chain: String,
    pub name: String,
    pub giver: String,
    pub dialogue: String,
    pub conclusion: String,
    pub objective: String,
    pub goal: String,
    pub target: u64,
    pub biome_id: u32,
    pub previous_quest_id: u32,
    #[index(btree)]
    pub weekly: bool,
    pub coins: u64,
    pub tin: u64,
    pub scrap: u64,
    pub bait_choices: Vec<u32>,
    pub bait_packs: u32,
    pub achievement_id: u32,
}

#[spacetimedb::table(accessor = quest_claim)]
pub struct QuestClaim {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub quest_id: u32,
    pub bait_id: u32,
    pub delivered_catch_id: Option<u64>,
    pub claimed_at: Timestamp,
}

/// One current UTC-week row per player, replaced in place on the next week's cast.
#[spacetimedb::table(accessor = weekly_quest_progress)]
pub struct WeeklyQuestProgress {
    #[primary_key]
    pub player_id: u64,
    pub week: i64,
    pub fish_count: u64,
    pub junk_count: u64,
    pub species_ids: Vec<u32>,
    pub claimed_quest_id: u32,
    pub bait_id: u32,
}

/// One bounded active run per player/job; claims advance the cycle in place.
#[spacetimedb::table(accessor = repeat_quest_progress)]
pub struct RepeatQuestProgress {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub quest_id: u32,
    pub cycle: u64,
    pub current: u64,
    pub species_ids: Vec<u32>,
}

#[derive(SpacetimeType)]
pub struct QuestStatus {
    pub quest_id: u32,
    pub cycle: u64,
    pub current: u64,
    pub unlocked: bool,
    pub claimed: bool,
    pub delivery_species_ids: Vec<u32>,
    pub week: i64,
}

#[derive(SpacetimeType)]
pub struct DocksideStatus {
    pub available: bool,
    pub total_claims: u64,
    pub stamps: u8,
    pub next_reward: u64,
    pub next_reset_at: Timestamp,
}

#[spacetimedb::table(accessor = quest_calendar, public)]
pub struct QuestCalendar {
    #[primary_key]
    pub singleton: u8,
    pub day: i64,
    pub week: i64,
    pub next_week_at: Timestamp,
}

#[spacetimedb::table(accessor = achievement_definition, public)]
pub struct AchievementDefinition {
    #[primary_key]
    pub achievement_id: u32,
    pub name: String,
    pub description: String,
    pub title: String,
    pub target: u64,
    pub bonus: bool,
}

/// Collection criteria are public; each player's unfinished progress stays private.
#[spacetimedb::table(accessor = achievement_collection, public)]
pub struct AchievementCollection {
    #[primary_key]
    pub achievement_id: u32,
    /// Zero covers every biome.
    pub biome_id: u32,
    /// Empty for any-rank discoveries or every-rank completion.
    pub rarity: String,
    pub all_ranks: bool,
}

#[spacetimedb::table(accessor = earned_achievement, public)]
pub struct EarnedAchievement {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub achievement_id: u32,
    pub completed_at: Timestamp,
}

#[spacetimedb::table(accessor = achievement_progress)]
pub struct AchievementProgress {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub achievement_id: u32,
    pub current: u64,
}

#[spacetimedb::table(accessor = angler_title, public)]
pub struct AnglerTitle {
    #[primary_key]
    pub player_id: u64,
    /// Zero clears the selected title.
    pub achievement_id: u32,
}

#[spacetimedb::table(accessor = sale_quote)]
pub struct SaleQuote {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    pub player_id: u64,
    #[unique]
    pub nonce: u128,
    pub catch_ids: Vec<u64>,
    pub quoted_coins: u64,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = deployment_owner)]
pub struct DeploymentOwner {
    #[primary_key]
    pub singleton: u8,
    pub identity: Identity,
}

#[derive(SpacetimeType, Clone, Copy, PartialEq, Eq, Debug)]
pub enum ServiceRole {
    DiscordAdapter,
    AccountLinker,
}

#[spacetimedb::table(accessor = service_principal)]
pub struct ServicePrincipal {
    #[primary_key]
    pub identity: Identity,
    pub role: ServiceRole,
    pub active: bool,
}

#[spacetimedb::table(accessor = service_selection)]
pub struct ServiceSelection {
    #[primary_key]
    pub identity: Identity,
    pub player_id: u64,
    pub interaction_id: u64,
}

#[spacetimedb::table(accessor = rarity_definition, public)]
pub struct RarityDefinition {
    #[primary_key]
    pub ordinal: u8,
    pub tier: String,
    pub reference_weight: u32,
    pub card_asset: String,
    pub minimum_length_millionths: u64,
    pub minimum_weight_millionths: u64,
}

#[spacetimedb::table(accessor = game_config, public)]
#[derive(Clone)]
pub struct GameConfig {
    #[primary_key]
    pub version: u32,
    pub cast_cooldown_seconds: u32,
    /// Legacy schema field, retained for existing databases. Zero means unlimited.
    /// Casting does not enforce this field.
    pub inventory_capacity: u32,
    pub recent_limit: u32,
    pub level_cap: u32,
}

#[spacetimedb::table(accessor = player)]
pub struct Player {
    #[primary_key]
    #[auto_inc]
    pub player_id: u64,
    #[unique]
    pub discord_user_id: u64,
    pub display_name: String,
    pub created_at: Timestamp,
    pub total_xp: u64,
    pub coins: u64,
    pub completed_casts: u64,
    pub fish_count: u64,
    pub junk_count: u64,
    pub treasure_count: u64,
    pub kept_count: u32,
    pub next_cast_at: Timestamp,
    pub selected_biome_id: u32,
    pub equipped_rod_id: u32,
}

#[spacetimedb::table(accessor = biome_definition, public)]
#[derive(Clone)]
pub struct BiomeDefinition {
    #[primary_key]
    pub biome_id: u32,
    pub name: String,
    pub description: String,
    pub minimum_level: u32,
    pub required_power: u32,
    pub fish_weight: u64,
    pub junk_weight: u64,
    pub treasure_weight: u64,
}

#[spacetimedb::table(accessor = rod_definition, public)]
pub struct RodDefinition {
    #[primary_key]
    pub rod_id: u32,
    pub name: String,
    pub power: u32,
    pub minimum_level: u32,
}

#[spacetimedb::table(accessor = rod_bonuses, public)]
pub struct RodBonuses {
    #[primary_key]
    pub rod_id: u32,
    pub version: u32,
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub sprite_asset: String,
}

#[spacetimedb::table(accessor = owned_rod)]
pub struct OwnedRod {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub rod_id: u32,
    pub upgrade_level: u8,
}

// Boats and nets use separate ownership/loadout tables so existing rod, bait,
// player and catch schemas remain unchanged.
#[spacetimedb::table(accessor = haul_equipment_definition, public)]
pub struct HaulEquipmentDefinition {
    #[primary_key]
    pub equipment_id: u32,
    pub kind: String,
    pub name: String,
    pub sprite_asset: String,
    #[default(0)]
    pub luck_bp: u32,
    #[default(0)]
    pub xp_bonus_bp: u32,
    #[default(0)]
    pub rod_id: u32,
    #[default(1)]
    pub quality_bonus_multiplier: u32,
}

#[spacetimedb::table(accessor = owned_haul_equipment)]
pub struct OwnedHaulEquipment {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub equipment_id: u32,
    #[default(0)]
    pub upgrade_level: u8,
}

#[spacetimedb::table(accessor = haul_upgrade_quote)]
pub struct HaulUpgradeQuote {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    pub player_id: u64,
    #[unique]
    pub nonce: u128,
    pub equipment_id: u32,
    pub rod_id: u32,
    pub from_quality: u8,
    pub recipe_version: u32,
    pub quality_version: u32,
    pub tin_cost: u64,
    pub scrap_cost: u64,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = haul_loadout)]
pub struct HaulLoadout {
    #[primary_key]
    pub player_id: u64,
    pub boat_id: u32,
    pub net_id: u32,
}

#[spacetimedb::table(accessor = player_identity)]
pub struct PlayerIdentity {
    #[primary_key]
    pub identity: Identity,
    #[unique]
    pub player_id: u64,
    pub linked_at: Timestamp,
}

#[spacetimedb::table(accessor = link_challenge)]
pub struct LinkChallenge {
    #[primary_key]
    #[auto_inc]
    pub challenge_id: u64,
    #[unique]
    pub browser_identity: Identity,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
    pub proof: u128,
}

#[spacetimedb::table(accessor = action_nonce)]
pub struct ActionNonce {
    #[primary_key]
    pub identity: Identity,
    pub player_id: u64,
    pub nonce: u128,
    pub kind: String,
    pub catch_ids: Vec<u64>,
    pub favorite: bool,
    pub quoted_coins: u64,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = item_stack)]
pub struct ItemStack {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub item: String,
    pub quantity: u64,
}

#[spacetimedb::table(accessor = economy_ledger)]
pub struct EconomyLedger {
    #[primary_key]
    #[auto_inc]
    pub ledger_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub operation_id: String,
    pub item: String,
    pub delta: i64,
    pub reason: String,
    #[index(btree)]
    pub created_at: Timestamp,
}

#[spacetimedb::table(accessor = admin_audit)]
pub struct AdminAudit {
    #[primary_key]
    #[auto_inc]
    pub audit_id: u64,
    pub actor: Identity,
    pub target: Identity,
    pub action: String,
    #[index(btree)]
    pub created_at: Timestamp,
}

#[spacetimedb::table(accessor = maintenance_job, scheduled(crate::maintenance::prune_history))]
pub struct MaintenanceJob {
    #[primary_key]
    pub scheduled_id: u64,
    pub scheduled_at: spacetimedb::ScheduleAt,
}

#[spacetimedb::table(accessor = species_record, public)]
pub struct SpeciesRecord {
    #[primary_key]
    pub key: u64,
    pub species_id: u32,
    pub rarity: String,
    pub metric: String,
    pub measurement: u64,
    pub player_id: u64,
    pub display_name: String,
    pub catch_id: u64,
    pub caught_at: Timestamp,
    pub rules_version: u32,
    pub content_version: u32,
}

#[spacetimedb::table(accessor = owned_specimen)]
pub struct OwnedSpecimen {
    #[primary_key]
    #[auto_inc]
    pub catch_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub species_id: u32,
    pub rarity: String,
    pub length_mm: u32,
    pub weight_g: u64,
    pub size_grade: u8,
    pub caught_at: Timestamp,
    pub sale_value_coins: u64,
    pub favorite: bool,
    pub rules_version: u32,
    pub content_version: u32,
    pub biome_id: u32,
    pub source_guild_id: Option<u64>,
}

#[spacetimedb::table(accessor = command_receipt)]
pub struct CommandReceipt {
    #[primary_key]
    pub interaction_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub discord_user_id: u64,
    pub guild_id: Option<u64>,
    pub channel_id: u64,
    pub command: String,
    #[index(btree)]
    pub caught_at: Timestamp,
    pub outcome: String,
    pub catch_id: Option<u64>,
    pub species_id: Option<u32>,
    pub rarity: String,
    pub length_mm: u32,
    pub weight_g: u64,
    pub size_grade: u8,
    pub xp_granted: u64,
    pub coins_granted: u64,
    pub sale_value_coins: u64,
    pub rules_version: u32,
    pub content_version: u32,
    pub item_granted: String,
    pub item_quantity: u64,
    pub biome_id: u32,
    pub rod_id: u32,
}

#[spacetimedb::table(accessor = perfect_species, public)]
pub struct PerfectSpecies {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub species_id: u32,
    pub first_catch_id: u64,
    pub perfected_at: Timestamp,
    pub length_mm: u32,
    pub weight_g: u64,
}

// Lifetime progress survives sale, journal pruning, and receipt retention.
#[spacetimedb::table(accessor = rank_maximum, public)]
pub struct RankMaximum {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub species_id: u32,
    pub rarity: String,
    pub first_catch_id: u64,
    pub achieved_at: Timestamp,
    pub length_mm: u32,
    pub weight_g: u64,
}

#[spacetimedb::table(accessor = bait_definition, public)]
pub struct BaitDefinition {
    #[primary_key]
    pub bait_id: u32,
    pub name: String,
    pub luck_bp: u32,
    pub resource_item: String,
    pub uses_per_purchase: u64,
    pub sprite_asset: String,
    #[default(0)]
    pub xp_bonus_bp: u32,
    #[default(0)]
    pub resource_quantity: u64,
    #[default(1)]
    pub size_rolls: u8,
}

#[spacetimedb::table(accessor = quality_definition, public)]
pub struct QualityDefinition {
    #[primary_key]
    pub quality_level: u8,
    pub version: u32,
    pub name: String,
    pub power_bonus: u32,
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub tin_cost: u64,
    pub scrap_cost: u64,
}

#[spacetimedb::table(accessor = rod_upgrade_recipe, public)]
pub struct RodUpgradeRecipe {
    #[primary_key]
    pub key: u64,
    pub rod_id: u32,
    pub quality_level: u8,
    pub version: u32,
    pub tin_cost: u64,
    pub scrap_cost: u64,
}

#[spacetimedb::table(accessor = bait_stack)]
pub struct BaitStack {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub bait_id: u32,
    pub uses_left: u64,
}

#[spacetimedb::table(accessor = bait_loadout)]
pub struct BaitLoadout {
    #[primary_key]
    pub player_id: u64,
    pub bait_id: u32,
    #[default(0)]
    pub secondary_bait_id: u32,
}

#[spacetimedb::table(accessor = upgrade_quote)]
pub struct UpgradeQuote {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    pub player_id: u64,
    #[unique]
    pub nonce: u128,
    pub rod_id: u32,
    pub from_quality: u8,
    pub catalog_version: u32,
    pub tin_cost: u64,
    pub scrap_cost: u64,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = cast_pull)]
pub struct CastPull {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub interaction_id: u64,
    #[index(btree)]
    pub caught_at: Timestamp,
    pub player_id: u64,
    pub base_xp: u64,
    /// Per-pull rewards before applying the XP bonus to the full cast once.
    pub receipt: CommandReceipt,
}

#[spacetimedb::table(accessor = collection_focus_preference)]
pub struct CollectionFocusPreference {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub biome_id: u32,
    pub enabled: bool,
}

#[spacetimedb::table(accessor = cast_equipment_receipt)]
pub struct CastEquipmentReceipt {
    #[primary_key]
    pub interaction_id: u64,
    #[index(btree)]
    pub caught_at: Timestamp,
    pub player_id: u64,
    pub quality_level: u8,
    pub power: u32,
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub bait_id: u32,
    pub bait_uses_left: u64,
    pub bait_item: String,
    #[default(0)]
    pub bait_quantity: u64,
    #[default(1)]
    pub bait_size_rolls: u8,
    #[default(false)]
    pub collection_focus_enabled: bool,
    #[default(0)]
    pub collection_focus_rerolls: u8,
    #[default(0)]
    pub secondary_bait_id: u32,
    #[default(0)]
    pub secondary_bait_uses_left: u64,
    #[default("")]
    pub secondary_bait_item: String,
    #[default(0)]
    pub secondary_bait_quantity: u64,
}

#[spacetimedb::table(accessor = recent_catch)]
pub struct RecentCatch {
    #[primary_key]
    #[auto_inc]
    pub recent_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub caught_at: Timestamp,
    pub outcome: String,
    pub species_id: Option<u32>,
    pub rarity: String,
    pub length_mm: u32,
    pub weight_g: u64,
    pub size_grade: u8,
    pub xp_granted: u64,
}

/// Permanent per-pull history. Kept separate from inventory and retry receipts.
#[spacetimedb::table(accessor = journal_entry,
    index(accessor = by_player_rarity, btree(columns = [player_id, rarity])),
    index(accessor = by_player_outcome, btree(columns = [player_id, outcome]))
)]
pub struct JournalEntry {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub catch_id: Option<u64>,
    pub caught_at: Timestamp,
    pub outcome: String,
    pub species_id: Option<u32>,
    pub name: String,
    pub rarity: String,
    pub length_mm: u32,
    pub weight_g: u64,
    /// Legacy inventory snapshots did not save XP. Never guess those rewards.
    pub xp_granted: Option<u64>,
    pub biome_id: Option<u32>,
}

#[spacetimedb::table(accessor = journal_selection)]
pub struct JournalSelection {
    #[primary_key]
    pub identity: Identity,
    pub player_id: u64,
    pub page: u32,
    pub page_size: u32,
    pub sort: String,
    pub descending: bool,
    pub search: String,
    pub biome_id: u32,
    pub rarity: String,
    pub outcome: String,
}

#[spacetimedb::table(accessor = journal_count)]
pub struct JournalCount {
    #[primary_key]
    pub player_id: u64,
    pub total: u64,
}

#[spacetimedb::table(accessor = journal_import)]
pub struct JournalImport {
    #[primary_key]
    pub player_id: u64,
    pub completed_at: Timestamp,
}

#[derive(SpacetimeType)]
pub struct JournalPage {
    pub entries: Vec<JournalEntry>,
    pub total_entries: u64,
    pub matching_entries: u64,
    pub page: u32,
    pub page_size: u32,
}

#[spacetimedb::table(accessor = player_species_progress)]
pub struct PlayerSpeciesProgress {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub species_id: u32,
    pub count: u64,
    pub rank_counts: Vec<u64>,
    pub first_caught_at: Timestamp,
    pub best_length_mm: u32,
    pub best_weight_g: u64,
}

#[spacetimedb::table(accessor = public_profile, public)]
pub struct PublicProfile {
    #[primary_key]
    pub player_id: u64,
    pub display_name: String,
    pub level: u32,
    pub fish_count: u64,
    pub discoveries: u32,
}

/// Public lifetime totals only; no Discord IDs, balances, or inventory.
#[spacetimedb::table(accessor = angler_standing, public)]
pub struct AnglerStanding {
    #[primary_key]
    pub player_id: u64,
    pub display_name: String,
    pub discoveries: u32,
    pub fish_count: u64,
    pub uur_count: u64,
    pub records_held: u32,
}

/// Discord CDN portraits only. Account IDs and OAuth credentials remain private.
#[spacetimedb::table(accessor = angler_portrait, public)]
pub struct AnglerPortrait {
    #[primary_key]
    pub player_id: u64,
    pub avatar_url: String,
    pub still_url: String,
    pub updated_at: Timestamp,
}

/// Public game statistics only: no wallet, Discord IDs, identities or raw history.
#[spacetimedb::table(accessor = angler_statistics, public)]
pub struct AnglerStatistics {
    #[primary_key]
    pub player_id: u64,
    pub display_name: String,
    pub total_casts: u64,
    pub bonus_pulls: u64,
    pub fish_count: u64,
    pub junk_count: u64,
    pub treasure_count: u64,
    pub bonus_fish: u64,
    pub bonus_junk: u64,
    pub bonus_treasure: u64,
    pub bonus_history_complete: bool,
    pub rank_counts: Vec<u64>,
    pub collection_pairs: u32,
    pub most_caught_species_id: Option<u32>,
    pub most_caught_species_count: u64,
    pub total_weight_g: u64,
    pub measured_fish_count: u64,
    pub recorded_casts: u64,
    pub history_complete: bool,
    pub days_fished: u32,
    pub favorite_biome_id: Option<u32>,
    pub favorite_biome_casts: u64,
    pub biome_history_complete: bool,
    pub best_relative_species_id: Option<u32>,
    pub best_relative_catch_id: Option<u64>,
    pub best_relative_length_millionths: u64,
    pub best_relative_length_mm: u32,
    pub best_relative_weight_g: u64,
    pub sale_earnings: u64,
    pub biggest_single_sale: u64,
    pub biggest_sale_batch: u64,
    pub sale_history_complete: bool,
    pub sales_tracked_since: Timestamp,
    pub records_set: u64,
    pub records_tracked_since: Timestamp,
}

/// Private lifetime haul accounting: scheduled haul catches beyond each
/// completed trip's first catch. Never rod bonuses, never rebuilt once written.
#[spacetimedb::table(accessor = haul_statistics)]
pub struct HaulStatistics {
    #[primary_key]
    pub player_id: u64,
    pub extra_pulls: u64,
}

#[spacetimedb::table(accessor = fishing_day)]
pub struct FishingDay {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub utc_day: u64,
}

#[spacetimedb::table(accessor = fishing_biome_activity)]
pub struct FishingBiomeActivity {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub biome_id: u32,
    pub casts: u64,
}

/// Each angler's first legendary discovery, retained after selling a catch.
#[spacetimedb::table(accessor = legendary_find, public)]
pub struct LegendaryFind {
    #[primary_key]
    pub key: u128,
    pub player_id: u64,
    pub display_name: String,
    pub species_id: u32,
    pub first_caught_at: Timestamp,
    pub count: u64,
}

#[spacetimedb::table(accessor = species_definition, public)]
pub struct SpeciesDefinition {
    #[primary_key]
    pub species_id: u32,
    pub key: String,
    pub name: String,
    pub sprite_asset: String,
    pub allowed_rarities: Vec<String>,
    pub typical_length_mm: u32,
    pub min_length_mm: u32,
    pub max_length_mm: u32,
    pub typical_weight_g: u64,
    pub min_weight_g: u64,
    pub max_weight_g: u64,
    pub base_value: u64,
    pub encounter_weight: u64,
    #[index(btree)]
    pub biome_id: u32,
    pub discovery_xp: u64,
    pub counts_for_ordinary_collection_completion: bool,
}

#[spacetimedb::table(accessor = species_rank_definition, public)]
pub struct SpeciesRankDefinition {
    #[primary_key]
    pub key: u64,
    pub species_id: u32,
    #[index(btree)]
    pub biome_id: u32,
    pub ordinal: u8,
    pub rarity: String,
    pub encounter_weight: u64,
    pub base_xp: u64,
    pub base_value: u64,
}
/// Durable eligibility and lifetime stamps; history pruning never resets this row.
#[spacetimedb::table(accessor = daily_delivery)]
pub struct DailyDelivery {
    #[primary_key]
    pub player_id: u64,
    pub last_claim_day: i64,
    pub total_claims: u64,
}

#[spacetimedb::table(accessor = daily_receipt)]
pub struct DailyReceipt {
    #[primary_key]
    pub interaction_id: u64,
    pub player_id: u64,
    pub discord_user_id: u64,
    pub guild_id: Option<u64>,
    pub channel_id: u64,
    pub claimed: bool,
    pub coins_granted: u64,
    pub total_claims: u64,
    pub stamps: u8,
    pub next_delivery_at: Timestamp,
    #[index(btree)]
    pub created_at: Timestamp,
}

#[spacetimedb::table(accessor = shop_listing, public)]
pub struct ShopListing {
    #[primary_key]
    pub listing_id: u32,
    pub kind: String,
    pub target_id: u32,
    pub name: String,
    pub price_coins: u64,
    pub minimum_level: u32,
    pub previous_biome_id: u32,
    pub biome_id: u32,
    pub catalog_version: u32,
}

#[spacetimedb::table(accessor = owned_biome_licence)]
pub struct OwnedBiomeLicence {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub biome_id: u32,
}

#[spacetimedb::table(accessor = shop_quote)]
pub struct ShopQuote {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    pub player_id: u64,
    #[unique]
    pub nonce: u128,
    pub listing_id: u32,
    pub quoted_coins: u64,
    pub catalog_version: u32,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = trader_migration)]
pub struct TraderMigration {
    #[primary_key]
    pub singleton: u8,
    pub grandfathered: bool,
}
