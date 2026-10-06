use spacetimedb::{Identity, SpacetimeType, Timestamp};

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

#[spacetimedb::table(accessor = bait_definition, public)]
pub struct BaitDefinition {
    #[primary_key]
    pub bait_id: u32,
    pub name: String,
    pub luck_bp: u32,
    pub resource_item: String,
    pub uses_per_purchase: u64,
    pub sprite_asset: String,
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
