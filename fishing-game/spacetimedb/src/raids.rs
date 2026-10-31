use crate::{
    accounts::{config, current_player},
    hauls::{haul_request, haul_trip},
    tables::*,
};
use game_rules::{progression::check_interaction_freshness, raids as rules};
use spacetimedb::{ReducerContext, Table, TimeDuration, Timestamp, ViewContext};

const TERMINAL_PRUNE_MICROS: i64 = 30 * 86_400_000_000;
const FAR_FUTURE_MICROS: i64 = i64::MAX;
const SPECIAL_FIHS: u32 = 74;
const SPECIAL_SOCK: u32 = 165;

#[spacetimedb::table(accessor = raid_grade_definition, public)]
pub struct RaidGradeDefinition {
    #[primary_key]
    pub grade: u8,
    pub catalog_version: u32,
    pub name: String,
    pub duration_seconds: u32,
    pub supply_units: u32,
    pub fillets: u64,
    pub scales: u64,
    pub reinforce_coins: u64,
    pub focus_chance_bp: u32,
    pub perfect_bonus_rolls: u8,
}

#[spacetimedb::table(accessor = community_raid_event, public)]
pub struct CommunityRaidEvent {
    #[primary_key]
    pub event_id: u64,
    pub name: String,
    #[index(btree)]
    pub signup_at: Timestamp,
    #[index(btree)]
    pub starts_at: Timestamp,
    #[index(btree)]
    pub ends_at: Timestamp,
}

#[spacetimedb::table(accessor = raid_attempt)]
#[derive(Clone)]
pub struct RaidAttempt {
    #[primary_key]
    pub id: u128,
    #[index(btree)]
    pub player_id: u64,
    pub kind: String,
    pub species_id: Option<u32>,
    pub grade: Option<u8>,
    pub event_id: Option<u64>,
    pub boat_id: u32,
    pub boat_name: String,
    pub boat_quality_level: u8,
    pub catalog_version: u32,
    pub content_version: u32,
    pub rules_version: u32,
    #[index(btree)]
    pub status: String,
    pub prepared_at: Timestamp,
    pub deployment_deadline: Timestamp,
    pub starts_at: Timestamp,
    pub ends_at: Timestamp,
    pub deployed_at: Option<Timestamp>,
    pub started_at: Option<Timestamp>,
    pub settled_at: Option<Timestamp>,
    pub duration_seconds: u32,
    pub supply_units: u32,
    pub supply_slot: u32,
    pub supply_item: String,
    pub base_fillets: u64,
    pub base_scales: u64,
    pub full_fillets: u64,
    pub full_scales: u64,
    pub maximum_fillets: u64,
    pub maximum_scales: u64,
    pub fillets_slot: u32,
    pub fillets_item: String,
    pub scales_slot: u32,
    pub scales_item: String,
    pub reward_bonus_divisor: u64,
    pub special_maximum_roll_one_in: u32,
    pub special_fish_count: u8,
    pub special_band: Option<u8>,
    pub special_species: Option<SpeciesDefinition>,
    pub special_rank: Option<SpeciesRankDefinition>,
    pub special_biome: Option<BiomeDefinition>,
    pub special_rules: Option<GameConfig>,
    pub awarded_fillets: u64,
    pub awarded_scales: u64,
    pub catch_id: Option<u64>,
    #[index(btree)]
    pub prune_at: Timestamp,
}

#[spacetimedb::table(accessor = raid_job, scheduled(run_raid_job))]
#[derive(Clone)]
pub struct RaidJob {
    #[primary_key]
    #[auto_inc]
    pub scheduled_id: u64,
    #[index(btree)]
    pub attempt_id: u128,
    pub phase: String,
    pub scheduled_at: spacetimedb::ScheduleAt,
}

#[spacetimedb::table(accessor = raid_badge, public)]
pub struct RaidBadge {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub species_id: u32,
    pub cleared_grades: u8,
    pub successful_clears: u64,
    pub special_completions: u64,
    pub first_clear_at: Option<Timestamp>,
    pub latest_clear_at: Option<Timestamp>,
}

#[spacetimedb::table(accessor = rod_reinforcement)]
pub struct RodReinforcement {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub rod_id: u32,
    pub level: u8,
    pub updated_at: Timestamp,
}

#[spacetimedb::table(accessor = raid_statistics, public)]
pub struct RaidStatistics {
    #[primary_key]
    pub player_id: u64,
    pub display_name: String,
    pub personal_completions: u64,
    pub special_completions: u64,
    pub community_completions: u64,
    pub mastered_bosses: u64,
}

#[spacetimedb::table(accessor = raid_action_receipt)]
pub struct RaidActionReceipt {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub request_id: u64,
    pub kind: String,
    pub fingerprint: String,
    pub attempt_id: Option<u128>,
    pub result: String,
    pub created_at: Timestamp,
    #[index(btree)]
    pub expires_at: Timestamp,
}

#[spacetimedb::table(accessor = raid_catch_counter)]
pub struct RaidCatchCounter {
    #[primary_key]
    pub singleton: u8,
    pub next_sequence: u64,
}

pub fn seed(ctx: &ReducerContext) -> Result<(), String> {
    let catalog = rules::catalog();
    for grade in &catalog.grades {
        let row = RaidGradeDefinition {
            grade: grade.grade,
            catalog_version: catalog.version,
            name: grade.name.clone(),
            duration_seconds: grade.duration_seconds,
            supply_units: grade.supply_units,
            fillets: grade.fillets,
            scales: grade.scales,
            reinforce_coins: grade.reinforce_coins,
            focus_chance_bp: grade.focus_chance_bp,
            perfect_bonus_rolls: grade.perfect_bonus_rolls,
        };
        if ctx.db.raid_grade_definition().grade().find(row.grade).is_some() {
            ctx.db.raid_grade_definition().grade().update(row);
        } else {
            ctx.db.raid_grade_definition().insert(row);
        }
    }
    let supply = &catalog.supply;
    let listing = ShopListing {
        listing_id: supply.listing_id,
        kind: "raid_supply".into(),
        target_id: supply.target_id,
        name: supply.name.clone(),
        price_coins: supply.price_coins,
        minimum_level: 1,
        previous_biome_id: 1,
        biome_id: 1,
        catalog_version: catalog.version,
    };
    if let Some(old) = ctx.db.shop_listing().listing_id().find(supply.listing_id) {
        if old.kind != "raid_supply" || old.target_id != supply.target_id {
            return Err("RAID_SUPPLY_LISTING_CONFLICT".into());
        }
        ctx.db.shop_listing().listing_id().update(listing);
    } else {
        ctx.db.shop_listing().insert(listing);
    }
    refresh_calendar(ctx)
}

pub fn refresh_calendar(ctx: &ReducerContext) -> Result<(), String> {
    let windows = rules::community_windows(ctx.timestamp.to_micros_since_unix_epoch())
        .map_err(|_| "RAID_CALENDAR_UNAVAILABLE")?;
    for window in windows {
        let row = CommunityRaidEvent {
            event_id: window.event_id,
            name: window.name,
            signup_at: Timestamp::from_micros_since_unix_epoch(window.signup_at),
            starts_at: Timestamp::from_micros_since_unix_epoch(window.starts_at),
            ends_at: Timestamp::from_micros_since_unix_epoch(window.ends_at),
        };
        if ctx.db.community_raid_event().event_id().find(row.event_id).is_some() {
            ctx.db.community_raid_event().event_id().update(row);
        } else {
            ctx.db.community_raid_event().insert(row);
        }
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn activate_raids(ctx: &ReducerContext) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_REQUIRED")?;
    if owner.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    seed(ctx)?;
    if ctx.db.game_config().version().find(rules::catalog().rules_version).is_none() {
        let mut config = config(ctx);
        config.version = rules::catalog().rules_version;
        ctx.db.game_config().insert(config);
    }
    Ok(())
}

fn attempt_key(player_id: u64, request_id: u64) -> u128 {
    (u128::from(player_id) << 64) | u128::from(request_id)
}

fn badge_key(player_id: u64, species_id: u32) -> u128 {
    (u128::from(player_id) << 32) | u128::from(species_id)
}

fn active_status(status: &str) -> bool {
    matches!(status, "prepared" | "deployed" | "running")
}

fn require_enabled(ctx: &ReducerContext) -> Result<(), String> {
    if config(ctx).version < rules::catalog().rules_version
        || ctx.db.raid_grade_definition().grade().find(0).is_none()
    {
        return Err("RAIDS_UNAVAILABLE".into());
    }
    Ok(())
}

fn terminal_prune_at(ctx: &ReducerContext) -> Timestamp {
    ctx.timestamp + TimeDuration::from_micros(TERMINAL_PRUNE_MICROS)
}

fn record_action(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    kind: &str,
    fingerprint: &str,
    attempt_id: Option<u128>,
    result: &str,
) {
    ctx.db.raid_action_receipt().insert(RaidActionReceipt {
        key: attempt_key(player_id, request_id),
        player_id,
        request_id,
        kind: kind.into(),
        fingerprint: fingerprint.into(),
        attempt_id,
        result: result.into(),
        created_at: ctx.timestamp,
        expires_at: terminal_prune_at(ctx),
    });
}

fn begin_action(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    kind: &str,
    fingerprint: &str,
    discord: bool,
) -> Result<bool, String> {
    let now = ctx.timestamp.to_micros_since_unix_epoch();
    if discord {
        check_interaction_freshness(request_id, now)?;
    } else {
        rules::validate_browser_request(now, request_id)?;
    }
    if let Some(old) = ctx
        .db
        .raid_action_receipt()
        .key()
        .find(attempt_key(player_id, request_id))
    {
        if old.kind != kind || old.fingerprint != fingerprint {
            return Err("RAID_REQUEST_CONFLICT".into());
        }
        return Ok(true);
    }
    Ok(false)
}

fn has_active_raid(ctx: &ReducerContext, player_id: u64, except: Option<u128>) -> bool {
    ctx.db
        .raid_attempt()
        .player_id()
        .filter(player_id)
        .any(|attempt| Some(attempt.id) != except && active_status(&attempt.status))
}

pub(crate) fn boat_locked_by_raid(ctx: &ReducerContext, player_id: u64) -> bool {
    ctx.db
        .raid_attempt()
        .player_id()
        .filter(player_id)
        .any(|attempt| matches!(attempt.status.as_str(), "deployed" | "running"))
}

fn running_boat_haul(ctx: &ReducerContext, player_id: u64) -> bool {
    ctx.db
        .haul_trip()
        .player_id()
        .filter(player_id)
        .any(|trip| trip.kind == "boat" && trip.status == "running")
}

fn remove_jobs(ctx: &ReducerContext, attempt_id: u128) {
    for job in ctx
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .collect::<Vec<_>>()
    {
        ctx.db.raid_job().scheduled_id().delete(job.scheduled_id);
    }
}

fn schedule_job(ctx: &ReducerContext, attempt_id: u128, phase: &str, at: Timestamp) {
    ctx.db.raid_job().insert(RaidJob {
        scheduled_id: 0,
        attempt_id,
        phase: phase.into(),
        scheduled_at: at.into(),
    });
}

fn player_attempts(ctx: &ViewContext, player_id: u64) -> Vec<RaidAttempt> {
    let mut rows: Vec<_> = ctx.db.raid_attempt().player_id().filter(player_id).collect();
    rows.sort_by_key(|row| std::cmp::Reverse(row.prepared_at));
    rows.truncate(100);
    rows
}

fn player_actions(ctx: &ViewContext, player_id: u64) -> Vec<RaidActionReceipt> {
    let mut rows: Vec<_> = ctx
        .db
        .raid_action_receipt()
        .player_id()
        .filter(player_id)
        .collect();
    rows.sort_by_key(|row| std::cmp::Reverse(row.created_at));
    rows.truncate(50);
    rows
}

#[spacetimedb::view(accessor = my_raid_attempts, public)]
pub fn my_raid_attempts(ctx: &ViewContext) -> Vec<RaidAttempt> {
    current_player(ctx)
        .map(|player_id| player_attempts(ctx, player_id))
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_raid_actions, public)]
pub fn my_raid_actions(ctx: &ViewContext) -> Vec<RaidActionReceipt> {
    current_player(ctx)
        .map(|player_id| player_actions(ctx, player_id))
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_rod_reinforcements, public)]
pub fn my_rod_reinforcements(ctx: &ViewContext) -> Vec<RodReinforcement> {
    current_player(ctx)
        .map(|player_id| {
            ctx.db
                .rod_reinforcement()
                .player_id()
                .filter(player_id)
                .collect()
        })
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_raid_attempts, public)]
pub fn adapter_raid_attempts(ctx: &ViewContext) -> Vec<RaidAttempt> {
    crate::shop::selected(ctx)
        .map(|player_id| player_attempts(ctx, player_id))
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_raid_actions, public)]
pub fn adapter_raid_actions(ctx: &ViewContext) -> Vec<RaidActionReceipt> {
    crate::shop::selected(ctx)
        .map(|player_id| player_actions(ctx, player_id))
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_rod_reinforcements, public)]
pub fn adapter_rod_reinforcements(ctx: &ViewContext) -> Vec<RodReinforcement> {
    crate::shop::selected(ctx)
        .map(|player_id| {
            ctx.db
                .rod_reinforcement()
                .player_id()
                .filter(player_id)
                .collect()
        })
        .unwrap_or_default()
}

fn initial_attempt(
    player_id: u64,
    id: u128,
    kind: &str,
    species_id: Option<u32>,
    grade: Option<u8>,
    event_id: Option<u64>,
    boat_id: u32,
    boat_name: String,
    boat_quality_level: u8,
    catalog_version: u32,
    content_version: u32,
    rules_version: u32,
    prepared_at: Timestamp,
    starts_at: Timestamp,
    ends_at: Timestamp,
    duration_seconds: u32,
    supply_units: u32,
    supply_slot: u32,
    supply_item: String,
) -> RaidAttempt {
    RaidAttempt {
        id,
        player_id,
        kind: kind.into(),
        species_id,
        grade,
        event_id,
        boat_id,
        boat_name,
        boat_quality_level,
        catalog_version,
        content_version,
        rules_version,
        status: "prepared".into(),
        prepared_at,
        deployment_deadline: starts_at,
        starts_at,
        ends_at,
        deployed_at: None,
        started_at: None,
        settled_at: None,
        duration_seconds,
        supply_units,
        supply_slot,
        supply_item,
        base_fillets: 0,
        base_scales: 0,
        full_fillets: 0,
        full_scales: 0,
        maximum_fillets: 0,
        maximum_scales: 0,
        fillets_slot: 0,
        fillets_item: String::new(),
        scales_slot: 0,
        scales_item: String::new(),
        reward_bonus_divisor: 0,
        special_maximum_roll_one_in: 0,
        special_fish_count: 0,
        special_band: None,
        special_species: None,
        special_rank: None,
        special_biome: None,
        special_rules: None,
        awarded_fillets: 0,
        awarded_scales: 0,
        catch_id: None,
        prune_at: Timestamp::from_micros_since_unix_epoch(FAR_FUTURE_MICROS),
    }
}

fn boat_snapshot(ctx: &ReducerContext, player_id: u64) -> Result<(u32, String, u8), String> {
    let loadout = ctx
        .db
        .haul_loadout()
        .player_id()
        .find(player_id)
        .ok_or("HAUL_LOADOUT_UNAVAILABLE")?;
    let boat_id = loadout.boat_id;
    let owned = ctx
        .db
        .owned_haul_equipment()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(boat_id))
        .ok_or("EQUIPMENT_NOT_OWNED")?;
    let definition = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(boat_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    if definition.kind != "boat" {
        return Err("INVALID_HAUL_KIND".into());
    }
    Ok((boat_id, definition.name, owned.upgrade_level))
}

fn has_discovered(ctx: &ReducerContext, player_id: u64, species_id: u32) -> bool {
    ctx.db
        .player_species_progress()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(species_id))
        .is_some_and(|row| row.count > 0)
}

fn grade_clears(ctx: &ReducerContext, player_id: u64, species_id: u32) -> u8 {
    ctx.db
        .raid_badge()
        .key()
        .find(badge_key(player_id, species_id))
        .map_or(0, |row| row.cleared_grades)
}

fn personal_attempt(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    species_id: u32,
    grade: u8,
    fingerprint: String,
    discord: bool,
) -> Result<(), String> {
    let action_kind = "prepare_raid";
    if begin_action(
        ctx,
        player_id,
        request_id,
        action_kind,
        &fingerprint,
        discord,
    )? {
        return Ok(());
    }
    require_enabled(ctx)?;
    if has_active_raid(ctx, player_id, None) {
        return Err("RAID_ALREADY_ACTIVE".into());
    }
    let catalog = rules::catalog();
    let species = ctx
        .db
        .species_definition()
        .species_id()
        .find(species_id)
        .ok_or("SPECIES_UNAVAILABLE")?;
    let is_special = catalog
        .special
        .species_keys
        .iter()
        .any(|key| key == &species.key);
    let kind = if is_special { "special" } else { "personal" };
    if !has_discovered(ctx, player_id, species_id)
        && (kind == "personal" || catalog.special.requires_species_discovery)
    {
        return Err("RAID_DISCOVERY_REQUIRED".into());
    }
    let (boat_id, boat_name, boat_quality) = boat_snapshot(ctx, player_id)?;
    let now_micros = ctx.timestamp.to_micros_since_unix_epoch();
    let starts_at_micros = rules::next_personal_start(now_micros)
        .map_err(|_| "RAID_TIME_INVALID")?;
    let starts_at = Timestamp::from_micros_since_unix_epoch(starts_at_micros);
    let (duration_seconds, supply_units) = if kind == "special" {
        if grade != 0 {
            return Err("INVALID_SPECIAL_RAID".into());
        }
        (
            catalog.special.duration_seconds,
            catalog.special.supply_units,
        )
    } else {
        if !species.counts_for_ordinary_collection_completion {
            return Err("SPECIAL_RAID_REQUIRED".into());
        }
        let current = grade_clears(ctx, player_id, species_id);
        if grade > current {
            return Err("RAID_GRADE_LOCKED".into());
        }
        let grade_config = rules::grade(grade).map_err(|_| "INVALID_RAID_GRADE")?;
        (grade_config.duration_seconds, grade_config.supply_units)
    };
    let duration_micros = i64::from(duration_seconds)
        .checked_mul(rules::MICROS_PER_SECOND)
        .ok_or("RAID_TIME_OVERFLOW")?;
    let ends_at = starts_at + TimeDuration::from_micros(duration_micros);
    let id = attempt_key(player_id, request_id);
    let mut attempt = initial_attempt(
        player_id,
        id,
        kind,
        Some(species_id),
        (kind == "personal").then_some(grade),
        None,
        boat_id,
        boat_name,
        boat_quality,
        catalog.version,
        crate::content::CONTENT_VERSION,
        config(ctx).version,
        ctx.timestamp,
        starts_at,
        ends_at,
        duration_seconds,
        supply_units,
        catalog.supply.slot,
        catalog.supply.item.clone(),
    );
    if kind == "personal" {
        let grade_config = rules::grade(grade).map_err(|_| "INVALID_RAID_GRADE")?;
        let (fillets_slot, fillets_item) =
            rules::material(grade, 0).map_err(|_| "INVALID_RAID_MATERIAL")?;
        let (scales_slot, scales_item) =
            rules::material(grade, 1).map_err(|_| "INVALID_RAID_MATERIAL")?;
        let divisor = catalog.reward_bonus_divisor;
        if divisor == 0 {
            return Err("RAID_CATALOG_INVALID".into());
        }
        attempt.base_fillets = grade_config.fillets;
        attempt.base_scales = grade_config.scales;
        attempt.maximum_fillets = grade_config
            .fillets
            .checked_add(grade_config.fillets / divisor)
            .ok_or("RAID_REWARD_OVERFLOW")?;
        attempt.maximum_scales = grade_config
            .scales
            .checked_add(grade_config.scales / divisor)
            .ok_or("RAID_REWARD_OVERFLOW")?;
        attempt.fillets_slot = fillets_slot;
        attempt.fillets_item = fillets_item;
        attempt.scales_slot = scales_slot;
        attempt.scales_item = scales_item;
        attempt.reward_bonus_divisor = divisor;
    } else {
        if catalog.special.maximum_fish != 1 {
            return Err("RAID_CATALOG_INVALID".into());
        }
        let band = if species.key == "fihs" {
            if species_id != SPECIAL_FIHS {
                return Err("SPECIAL_RAID_UNAVAILABLE".into());
            }
            9
        } else if species.key == "nidalees-lost-sock" {
            if species_id != SPECIAL_SOCK {
                return Err("SPECIAL_RAID_UNAVAILABLE".into());
            }
            0
        } else {
            return Err("SPECIAL_RAID_UNAVAILABLE".into());
        };
        let expected_rarity = game_rules::RARITY_TIERS[usize::from(band)];
        if species.allowed_rarities.len() != 1 || species.allowed_rarities[0] != expected_rarity {
            return Err("SPECIAL_RAID_UNAVAILABLE".into());
        }
        let rank_key = u64::from(species_id) * 10 + u64::from(band);
        let rank = ctx
            .db
            .species_rank_definition()
            .key()
            .find(rank_key)
            .ok_or("CONTENT_UNAVAILABLE")?;
        if rank.ordinal != band || rank.rarity != expected_rarity {
            return Err("SPECIAL_RAID_UNAVAILABLE".into());
        }
        let biome = ctx
            .db
            .biome_definition()
            .biome_id()
            .find(species.biome_id)
            .ok_or("BIOME_UNAVAILABLE")?;
        attempt.special_maximum_roll_one_in = catalog.special.maximum_roll_one_in;
        attempt.special_fish_count = catalog.special.maximum_fish;
        attempt.special_band = Some(band);
        attempt.special_species = Some(species);
        attempt.special_rank = Some(rank);
        attempt.special_biome = Some(biome);
        attempt.special_rules = Some(config(ctx));
    }
    ctx.db.raid_attempt().insert(attempt);
    schedule_job(ctx, id, "start", starts_at);
    record_action(
        ctx,
        player_id,
        request_id,
        action_kind,
        &fingerprint,
        Some(id),
        "Raid prepared; deploy the boat before its scheduled start.",
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn prepare_raid(
    ctx: &ReducerContext,
    request_id: u64,
    species_id: u32,
    grade: u8,
) -> Result<(), String> {
    personal_attempt(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        species_id,
        grade,
        format!("prepare_raid:{species_id}:{grade}"),
        false,
    )
}

#[spacetimedb::reducer]
pub fn prepare_raid_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    species_id: u32,
    grade: u8,
) -> Result<(), String> {
    personal_attempt(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        species_id,
        grade,
        format!("prepare_raid:{species_id}:{grade}"),
        true,
    )
}

fn prepare_community(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    event_id: u64,
    discord: bool,
) -> Result<(), String> {
    let fingerprint = format!("prepare_community_raid:{event_id}");
    let action_kind = "prepare_community_raid";
    if begin_action(ctx, player_id, request_id, action_kind, &fingerprint, discord)? {
        return Ok(());
    }
    require_enabled(ctx)?;
    if has_active_raid(ctx, player_id, None) {
        return Err("RAID_ALREADY_ACTIVE".into());
    }
    if ctx
        .db
        .raid_attempt()
        .player_id()
        .filter(player_id)
        .any(|attempt| {
            attempt.kind == "community"
                && attempt.event_id == Some(event_id)
                && attempt.status != "cancelled"
        })
    {
        return Err("COMMUNITY_RAID_ALREADY_JOINED".into());
    }
    let event = ctx
        .db
        .community_raid_event()
        .event_id()
        .find(event_id)
        .ok_or("COMMUNITY_RAID_UNAVAILABLE")?;
    if ctx.timestamp < event.signup_at || ctx.timestamp >= event.starts_at {
        return Err("COMMUNITY_SIGNUP_CLOSED".into());
    }
    let (boat_id, boat_name, boat_quality) = boat_snapshot(ctx, player_id)?;
    let catalog = rules::catalog();
    let id = attempt_key(player_id, request_id);
    let mut attempt = initial_attempt(
        player_id,
        id,
        "community",
        None,
        None,
        Some(event_id),
        boat_id,
        boat_name,
        boat_quality,
        catalog.version,
        crate::content::CONTENT_VERSION,
        config(ctx).version,
        ctx.timestamp,
        event.starts_at,
        event.ends_at,
        catalog.community.duration_seconds,
        catalog.community.supply_units,
        catalog.supply.slot,
        catalog.supply.item.clone(),
    );
    attempt.deployment_deadline = event.starts_at;
    ctx.db.raid_attempt().insert(attempt);
    schedule_job(ctx, id, "start", event.starts_at);
    record_action(
        ctx,
        player_id,
        request_id,
        action_kind,
        &fingerprint,
        Some(id),
        "Community raid prepared; deploy the boat before its start.",
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn prepare_community_raid(
    ctx: &ReducerContext,
    request_id: u64,
    event_id: u64,
) -> Result<(), String> {
    prepare_community(ctx, crate::shop::browser_player(ctx)?, request_id, event_id, false)
}

#[spacetimedb::reducer]
pub fn prepare_community_raid_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    event_id: u64,
) -> Result<(), String> {
    prepare_community(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        event_id,
        true,
    )
}

fn consume_item(
    ctx: &ReducerContext,
    player_id: u64,
    slot: u32,
    item: &str,
    quantity: u64,
    operation_id: &str,
    reason: &str,
) -> Result<(), String> {
    if quantity == 0 {
        return Ok(());
    }
    let key = (u128::from(player_id) << 32) | u128::from(slot);
    let mut stack = ctx
        .db
        .item_stack()
        .key()
        .find(key)
        .ok_or("RAID_MATERIALS_REQUIRED")?;
    if stack.player_id != player_id || stack.item != item {
        return Err("RAID_MATERIAL_SLOT_MISMATCH".into());
    }
    stack.quantity = stack
        .quantity
        .checked_sub(quantity)
        .ok_or("RAID_MATERIALS_REQUIRED")?;
    if stack.quantity == 0 {
        ctx.db.item_stack().key().delete(key);
    } else {
        ctx.db.item_stack().key().update(stack);
    }
    ctx.db.economy_ledger().insert(EconomyLedger {
        ledger_id: 0,
        player_id,
        operation_id: operation_id.into(),
        item: item.into(),
        delta: -i64::try_from(quantity).map_err(|_| "ITEM_OVERFLOW")?,
        reason: reason.into(),
        created_at: ctx.timestamp,
    });
    Ok(())
}

fn spend_coins(
    ctx: &ReducerContext,
    player_id: u64,
    amount: u64,
    operation_id: &str,
    reason: &str,
) -> Result<(), String> {
    if amount == 0 {
        return Ok(());
    }
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    player.coins = player
        .coins
        .checked_sub(amount)
        .ok_or("INSUFFICIENT_COINS")?;
    ctx.db.player().player_id().update(player);
    ctx.db.economy_ledger().insert(EconomyLedger {
        ledger_id: 0,
        player_id,
        operation_id: operation_id.into(),
        item: "coins".into(),
        delta: -i64::try_from(amount).map_err(|_| "COIN_OVERFLOW")?,
        reason: reason.into(),
        created_at: ctx.timestamp,
    });
    Ok(())
}

fn require_grade_clear(
    ctx: &ReducerContext,
    player_id: u64,
    grade: u8,
) -> Result<(), String> {
    if ctx
        .db
        .raid_badge()
        .player_id()
        .filter(player_id)
        .any(|badge| badge.cleared_grades > grade)
    {
        Ok(())
    } else {
        Err("RAID_GRADE_NOT_CLEARED".into())
    }
}

fn refine(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    material_id: u8,
    from_grade: u8,
    batches: u32,
    discord: bool,
) -> Result<(), String> {
    let fingerprint = format!("refine_raid_material:{material_id}:{from_grade}:{batches}");
    let kind = "refine_raid_material";
    if begin_action(ctx, player_id, request_id, kind, &fingerprint, discord)? {
        return Ok(());
    }
    require_enabled(ctx)?;
    let refining = rules::catalog().refining;
    if batches == 0 || batches > refining.maximum_batches || from_grade >= 9 {
        return Err("RAID_REFINEMENT_INVALID".into());
    }
    let to_grade = from_grade.checked_add(1).ok_or("INVALID_RAID_GRADE")?;
    require_grade_clear(ctx, player_id, to_grade)?;
    let (input_slot, input_item) =
        rules::material(from_grade, material_id).map_err(|_| "INVALID_RAID_MATERIAL")?;
    let (output_slot, output_item) =
        rules::material(to_grade, material_id).map_err(|_| "INVALID_RAID_MATERIAL")?;
    let input = refining
        .input_quantity
        .checked_mul(u64::from(batches))
        .ok_or("ITEM_OVERFLOW")?;
    let output = refining
        .output_quantity
        .checked_mul(u64::from(batches))
        .ok_or("ITEM_OVERFLOW")?;
    let coins = refining
        .coins_per_batch
        .checked_mul(u64::from(batches))
        .ok_or("COIN_OVERFLOW")?;
    let operation = format!("raid-action:{}:{}", player_id, request_id);
    consume_item(
        ctx,
        player_id,
        input_slot,
        &input_item,
        input,
        &operation,
        "raid_refining",
    )?;
    spend_coins(ctx, player_id, coins, &operation, "raid_refining")?;
    crate::inventory::grant_item_with_reason(
        ctx,
        player_id,
        output_slot,
        &output_item,
        output,
        operation.clone(),
        "raid_refining",
    )?;
    record_action(
        ctx,
        player_id,
        request_id,
        kind,
        &fingerprint,
        None,
        "Materials refined.",
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn refine_raid_material(
    ctx: &ReducerContext,
    request_id: u64,
    material_id: u8,
    from_grade: u8,
    batches: u32,
) -> Result<(), String> {
    refine(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        material_id,
        from_grade,
        batches,
        false,
    )
}

#[spacetimedb::reducer]
pub fn refine_raid_material_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    material_id: u8,
    from_grade: u8,
    batches: u32,
) -> Result<(), String> {
    refine(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        material_id,
        from_grade,
        batches,
        true,
    )
}

fn reinforce(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    rod_id: u32,
    expected_level: u8,
    discord: bool,
) -> Result<(), String> {
    let fingerprint = format!("reinforce_rod:{rod_id}:{expected_level}");
    let kind = "reinforce_rod";
    if begin_action(ctx, player_id, request_id, kind, &fingerprint, discord)? {
        return Ok(());
    }
    require_enabled(ctx)?;
    if expected_level >= game_rules::raids::MAX_REINFORCEMENT_LEVEL {
        return Err("MAX_REINFORCEMENT".into());
    }
    let key = (u128::from(player_id) << 32) | u128::from(rod_id);
    ctx.db
        .owned_rod()
        .key()
        .find(key)
        .ok_or("ROD_NOT_OWNED")?;
    let reinforcement_key = key;
    let mut current = ctx
        .db
        .rod_reinforcement()
        .key()
        .find(reinforcement_key)
        .unwrap_or(RodReinforcement {
            key: reinforcement_key,
            player_id,
            rod_id,
            level: 0,
            updated_at: ctx.timestamp,
        });
    if current.level != expected_level {
        return Err("REINFORCEMENT_LEVEL_CHANGED".into());
    }
    let grade = game_rules::raids::grade(expected_level)
        .map_err(|_| "INVALID_RAID_GRADE")?;
    require_grade_clear(ctx, player_id, expected_level)?;
    let (fillets_slot, fillets_item) =
        rules::material(expected_level, 0).map_err(|_| "INVALID_RAID_MATERIAL")?;
    let (scales_slot, scales_item) =
        rules::material(expected_level, 1).map_err(|_| "INVALID_RAID_MATERIAL")?;
    let operation = format!("raid-action:{}:{}", player_id, request_id);
    consume_item(
        ctx,
        player_id,
        fillets_slot,
        &fillets_item,
        grade.fillets,
        &operation,
        "rod_reinforcement",
    )?;
    consume_item(
        ctx,
        player_id,
        scales_slot,
        &scales_item,
        grade.scales,
        &operation,
        "rod_reinforcement",
    )?;
    spend_coins(
        ctx,
        player_id,
        grade.reinforce_coins,
        &operation,
        "rod_reinforcement",
    )?;
    current.level = expected_level + 1;
    current.updated_at = ctx.timestamp;
    if ctx.db.rod_reinforcement().key().find(reinforcement_key).is_some() {
        ctx.db.rod_reinforcement().key().update(current);
    } else {
        ctx.db.rod_reinforcement().insert(current);
    }
    record_action(
        ctx,
        player_id,
        request_id,
        kind,
        &fingerprint,
        None,
        "Rod reinforcement complete.",
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn reinforce_rod(
    ctx: &ReducerContext,
    request_id: u64,
    rod_id: u32,
    expected_level: u8,
) -> Result<(), String> {
    reinforce(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        rod_id,
        expected_level,
        false,
    )
}

#[spacetimedb::reducer]
pub fn reinforce_rod_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    rod_id: u32,
    expected_level: u8,
) -> Result<(), String> {
    reinforce(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        rod_id,
        expected_level,
        true,
    )
}

fn deploy(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    attempt_id: u128,
    discord: bool,
) -> Result<(), String> {
    let fingerprint = format!("deploy_raid:{attempt_id}");
    let kind = "deploy_raid";
    if begin_action(ctx, player_id, request_id, kind, &fingerprint, discord)? {
        return Ok(());
    }
    let mut attempt = ctx
        .db
        .raid_attempt()
        .id()
        .find(attempt_id)
        .filter(|row| row.player_id == player_id)
        .ok_or("RAID_ATTEMPT_UNAVAILABLE")?;
    if attempt.status != "prepared" {
        return Err("RAID_NOT_PREPARED".into());
    }
    if ctx.timestamp >= attempt.starts_at {
        mark_terminal(ctx, &mut attempt, "missed")?;
        record_action(
            ctx,
            player_id,
            request_id,
            kind,
            &fingerprint,
            Some(attempt_id),
            "Deployment window missed; no supplies or rewards were used.",
        );
        return Ok(());
    }
    if has_active_raid(ctx, player_id, Some(attempt_id)) {
        return Err("RAID_ALREADY_ACTIVE".into());
    }
    if running_boat_haul(ctx, player_id) {
        return Err("BOAT_HAUL_ACTIVE".into());
    }
    let boat_key = (u128::from(player_id) << 32) | u128::from(attempt.boat_id);
    let boat = ctx
        .db
        .owned_haul_equipment()
        .key()
        .find(boat_key)
        .ok_or("EQUIPMENT_NOT_OWNED")?;
    let definition = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(boat.equipment_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    if definition.kind != "boat" {
        return Err("EQUIPMENT_UNAVAILABLE".into());
    }
    if attempt.supply_units > 0 {
        consume_item(
            ctx,
            player_id,
            attempt.supply_slot,
            &attempt.supply_item,
            u64::from(attempt.supply_units),
            &format!("raid-deploy:{attempt_id}"),
            "raid_deployment",
        )?;
    }
    if attempt.kind == "personal" {
        let mut rng = ctx.rng();
        attempt.full_fillets = rules::reward_with_divisor(
            attempt.base_fillets,
            u64::from(attempt.duration_seconds),
            attempt.duration_seconds,
            attempt.reward_bonus_divisor,
            &mut rng,
        )
        .map_err(|_| "RAID_REWARD_INVALID")?
        .min(attempt.maximum_fillets);
        attempt.full_scales = rules::reward_with_divisor(
            attempt.base_scales,
            u64::from(attempt.duration_seconds),
            attempt.duration_seconds,
            attempt.reward_bonus_divisor,
            &mut rng,
        )
        .map_err(|_| "RAID_REWARD_INVALID")?
        .min(attempt.maximum_scales);
    }
    attempt.status = "deployed".into();
    attempt.deployed_at = Some(ctx.timestamp);
    ctx.db.raid_attempt().id().update(attempt);
    record_action(
        ctx,
        player_id,
        request_id,
        kind,
        &fingerprint,
        Some(attempt_id),
        "Boat deployed; the raid will start at its scheduled UTC time.",
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn deploy_raid(
    ctx: &ReducerContext,
    request_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    deploy(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        attempt_id,
        false,
    )
}

#[spacetimedb::reducer]
pub fn deploy_raid_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    deploy(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        attempt_id,
        true,
    )
}

fn proportional_reward(full: u64, maximum: u64, elapsed_micros: u64, duration: u32) -> Result<u64, String> {
    if duration == 0 {
        return Err("INVALID_RAID_DURATION".into());
    }
    let duration_micros = u128::from(duration)
        .checked_mul(1_000_000)
        .ok_or("RAID_REWARD_OVERFLOW")?;
    let elapsed = u128::from(elapsed_micros).min(duration_micros);
    let amount = u128::from(full)
        .checked_mul(elapsed)
        .ok_or("RAID_REWARD_OVERFLOW")?
        / duration_micros;
    Ok(u64::try_from(amount)
        .map_err(|_| "RAID_REWARD_OVERFLOW")?
        .min(maximum))
}

fn award_materials(
    ctx: &ReducerContext,
    attempt: &mut RaidAttempt,
    elapsed_micros: u64,
) -> Result<(), String> {
    let fillets = proportional_reward(
        attempt.full_fillets,
        attempt.maximum_fillets,
        elapsed_micros,
        attempt.duration_seconds,
    )?;
    let scales = proportional_reward(
        attempt.full_scales,
        attempt.maximum_scales,
        elapsed_micros,
        attempt.duration_seconds,
    )?;
    let operation = format!("raid-settlement:{}", attempt.id);
    if fillets > 0 {
        crate::inventory::grant_item_with_reason(
            ctx,
            attempt.player_id,
            attempt.fillets_slot,
            &attempt.fillets_item,
            fillets,
            operation.clone(),
            "raid_reward",
        )?;
    }
    if scales > 0 {
        crate::inventory::grant_item_with_reason(
            ctx,
            attempt.player_id,
            attempt.scales_slot,
            &attempt.scales_item,
            scales,
            operation,
            "raid_reward",
        )?;
    }
    attempt.awarded_fillets = fillets;
    attempt.awarded_scales = scales;
    Ok(())
}

fn update_raid_statistics(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    mastered: bool,
) -> Result<(), String> {
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut row = ctx
        .db
        .raid_statistics()
        .player_id()
        .find(player_id)
        .unwrap_or(RaidStatistics {
            player_id,
            display_name: player.display_name.clone(),
            personal_completions: 0,
            special_completions: 0,
            community_completions: 0,
            mastered_bosses: 0,
        });
    row.display_name = player.display_name;
    let counter = match kind {
        "personal" => &mut row.personal_completions,
        "special" => &mut row.special_completions,
        "community" => &mut row.community_completions,
        _ => return Err("INVALID_RAID_KIND".into()),
    };
    *counter = counter.checked_add(1).ok_or("COUNT_OVERFLOW")?;
    if mastered {
        row.mastered_bosses = row
            .mastered_bosses
            .checked_add(1)
            .ok_or("COUNT_OVERFLOW")?;
    }
    if ctx.db.raid_statistics().player_id().find(player_id).is_some() {
        ctx.db.raid_statistics().player_id().update(row);
    } else {
        ctx.db.raid_statistics().insert(row);
    }
    Ok(())
}

fn record_badge_completion(
    ctx: &ReducerContext,
    player_id: u64,
    species_id: u32,
    grade: Option<u8>,
    special: bool,
) -> Result<bool, String> {
    let key = badge_key(player_id, species_id);
    let mut badge = ctx.db.raid_badge().key().find(key).unwrap_or(RaidBadge {
        key,
        player_id,
        species_id,
        cleared_grades: 0,
        successful_clears: 0,
        special_completions: 0,
        first_clear_at: None,
        latest_clear_at: None,
    });
    let mut mastered = false;
    if special {
        badge.special_completions = badge
            .special_completions
            .checked_add(1)
            .ok_or("COUNT_OVERFLOW")?;
    } else {
        let grade = grade.ok_or("INVALID_RAID_GRADE")?;
        if grade > badge.cleared_grades {
            return Err("RAID_GRADE_LOCKED".into());
        }
        if grade == badge.cleared_grades {
            badge.cleared_grades = badge
                .cleared_grades
                .checked_add(1)
                .ok_or("COUNT_OVERFLOW")?;
            mastered = badge.cleared_grades == 10;
        }
    }
    badge.successful_clears = badge
        .successful_clears
        .checked_add(1)
        .ok_or("COUNT_OVERFLOW")?;
    if badge.first_clear_at.is_none() {
        badge.first_clear_at = Some(ctx.timestamp);
    }
    badge.latest_clear_at = Some(ctx.timestamp);
    if ctx.db.raid_badge().key().find(key).is_some() {
        ctx.db.raid_badge().key().update(badge);
    } else {
        ctx.db.raid_badge().insert(badge);
    }
    Ok(mastered)
}

fn next_special_receipt_id(ctx: &ReducerContext) -> Result<u64, String> {
    const HIGH_BIT: u64 = 1u64 << 63;
    let existing = ctx
        .db
        .raid_catch_counter()
        .singleton()
        .find(1);
    let mut sequence = existing.as_ref().map_or(1, |row| row.next_sequence.max(1));
    loop {
        if sequence >= HIGH_BIT {
            return Err("RAID_COUNTER_EXHAUSTED".into());
        }
        let interaction_id = HIGH_BIT | sequence;
        let used = ctx
            .db
            .command_receipt()
            .interaction_id()
            .find(interaction_id)
            .is_some()
            || ctx
                .db
                .daily_receipt()
                .interaction_id()
                .find(interaction_id)
                .is_some()
            || ctx
                .db
                .haul_request()
                .interaction_id()
                .find(interaction_id)
                .is_some()
            || ctx
                .db
                .cast_equipment_receipt()
                .interaction_id()
                .find(interaction_id)
                .is_some()
            || ctx
                .db
                .cast_pull()
                .interaction_id()
                .filter(interaction_id)
                .next()
                .is_some();
        sequence = sequence.checked_add(1).ok_or("RAID_COUNTER_EXHAUSTED")?;
        if !used {
            let counter = RaidCatchCounter {
                singleton: 1,
                next_sequence: sequence,
            };
            if existing.is_some() {
                ctx.db.raid_catch_counter().singleton().update(counter);
            } else {
                ctx.db.raid_catch_counter().insert(counter);
            }
            return Ok(interaction_id);
        }
    }
}

fn award_special_fish(ctx: &ReducerContext, attempt: &mut RaidAttempt) -> Result<CommandReceipt, String> {
    let player = ctx
        .db
        .player()
        .player_id()
        .find(attempt.player_id)
        .ok_or("PLAYER_MISSING")?;
    let interaction_id = next_special_receipt_id(ctx)?;
    let species = attempt
        .special_species
        .clone()
        .ok_or("SPECIAL_RAID_UNAVAILABLE")?;
    let rank = attempt
        .special_rank
        .clone()
        .ok_or("SPECIAL_RAID_UNAVAILABLE")?;
    let biome = attempt
        .special_biome
        .clone()
        .ok_or("SPECIAL_RAID_UNAVAILABLE")?;
    let game_config = attempt
        .special_rules
        .clone()
        .ok_or("SPECIAL_RAID_UNAVAILABLE")?;
    crate::casting::resolve(
        ctx,
        crate::casting::CastPlan {
            player,
            biome,
            rules: game_config,
            interaction_id,
            guild_id: None,
            channel_id: 0,
            command: "fishraid".into(),
            equipment_id: attempt.boat_id,
            quality_level: attempt.boat_quality_level,
            power: 0,
            luck_bp: 0,
            xp_bonus_bp: 0,
            bait: crate::crafting::UsedBaits {
                primary: Default::default(),
                secondary: Default::default(),
                luck_bp: 0,
                xp_bonus_bp: 0,
                size_rolls: 1,
            },
            pull_count: usize::from(attempt.special_fish_count),
            collection_focus_enabled: false,
            reinforcement_level: 0,
            special_raid_species_id: Some(species.species_id),
            special_raid_species_definition: Some(species),
            special_raid_rank_definition: Some(rank),
            special_raid_band: attempt.special_band,
            special_raid_maximum_roll_one_in: attempt.special_maximum_roll_one_in,
            content_version: attempt.content_version,
        },
    )?;
    let receipt = ctx
        .db
        .command_receipt()
        .interaction_id()
        .find(interaction_id)
        .ok_or("RECEIPT_MISSING")?;
    if receipt.outcome != "fish" || receipt.catch_id.is_none() {
        return Err("SPECIAL_RAID_REWARD_INVALID".into());
    }
    attempt.catch_id = receipt.catch_id;
    Ok(receipt)
}

fn complete_attempt(ctx: &ReducerContext, attempt: &mut RaidAttempt) -> Result<(), String> {
    if attempt.status == "completed" {
        return Ok(());
    }
    if attempt.status != "running" || ctx.timestamp < attempt.ends_at {
        return Err("RAID_NOT_READY".into());
    }
    let kind = attempt.kind.clone();
    let mut first_clear = false;
    let mut special_catch = None;
    match kind.as_str() {
        "personal" => {
            let full_elapsed = u64::from(attempt.duration_seconds)
                .checked_mul(1_000_000)
                .ok_or("RAID_TIME_OVERFLOW")?;
            award_materials(ctx, attempt, full_elapsed)?;
            let species_id = attempt.species_id.ok_or("SPECIES_UNAVAILABLE")?;
            let cleared = ctx.db.raid_badge().key()
                .find(badge_key(attempt.player_id, species_id))
                .map_or(0, |badge| badge.cleared_grades);
            first_clear = attempt.grade == Some(cleared);
            let mastered = record_badge_completion(
                ctx,
                attempt.player_id,
                species_id,
                attempt.grade,
                false,
            )?;
            update_raid_statistics(ctx, attempt.player_id, "personal", mastered)?;
        }
        "special" => {
            special_catch = Some(award_special_fish(ctx, attempt)?);
            record_badge_completion(
                ctx,
                attempt.player_id,
                attempt.species_id.ok_or("SPECIES_UNAVAILABLE")?,
                None,
                true,
            )?;
            update_raid_statistics(ctx, attempt.player_id, "special", false)?;
        }
        "community" => update_raid_statistics(ctx, attempt.player_id, "community", false)?,
        _ => return Err("INVALID_RAID_KIND".into()),
    }
    attempt.status = "completed".into();
    attempt.settled_at = Some(ctx.timestamp);
    attempt.prune_at = terminal_prune_at(ctx);
    ctx.db.raid_attempt().id().update(attempt.clone());
    remove_jobs(ctx, attempt.id);
    crate::raid_notifications::enqueue(ctx, attempt, first_clear, special_catch);
    Ok(())
}

fn mark_terminal(
    ctx: &ReducerContext,
    attempt: &mut RaidAttempt,
    status: &str,
) -> Result<(), String> {
    if !matches!(status, "missed" | "cancelled") {
        return Err("INVALID_RAID_STATUS".into());
    }
    attempt.status = status.into();
    attempt.settled_at = Some(ctx.timestamp);
    attempt.prune_at = terminal_prune_at(ctx);
    ctx.db.raid_attempt().id().update(attempt.clone());
    remove_jobs(ctx, attempt.id);
    Ok(())
}

fn start_deployed_attempt(
    ctx: &ReducerContext,
    attempt: &mut RaidAttempt,
) -> Result<(), String> {
    if attempt.status != "deployed" {
        return Ok(());
    }
    if ctx.timestamp < attempt.starts_at {
        return Err("RAID_NOT_READY".into());
    }
    attempt.status = "running".into();
    attempt.started_at = Some(attempt.starts_at);
    ctx.db.raid_attempt().id().update(attempt.clone());
    remove_jobs(ctx, attempt.id);
    if ctx.timestamp >= attempt.ends_at {
        complete_attempt(ctx, attempt)
    } else {
        schedule_job(ctx, attempt.id, "finish", attempt.ends_at);
        Ok(())
    }
}

fn elapsed_micros(attempt: &RaidAttempt, now: Timestamp) -> Result<u64, String> {
    let started_at = attempt.started_at.ok_or("RAID_NOT_STARTED")?;
    let micros = now
        .to_micros_since_unix_epoch()
        .checked_sub(started_at.to_micros_since_unix_epoch())
        .ok_or("INVALID_RAID_TIME")?
        .max(0);
    let duration = i64::from(attempt.duration_seconds)
        .checked_mul(rules::MICROS_PER_SECOND)
        .ok_or("RAID_TIME_OVERFLOW")?;
    u64::try_from(micros.min(duration)).map_err(|_| "INVALID_RAID_TIME".into())
}

fn cancel_attempt(ctx: &ReducerContext, attempt: &mut RaidAttempt) -> Result<String, String> {
    let status = attempt.status.clone();
    match status.as_str() {
        "completed" | "missed" | "cancelled" => {
            return Ok(format!("Raid is already {}.", attempt.status));
        }
        "prepared" => {
            if ctx.timestamp >= attempt.starts_at {
                mark_terminal(ctx, attempt, "missed")?;
                return Ok("The deployment window was missed; no supplies were consumed.".into());
            }
            mark_terminal(ctx, attempt, "cancelled")?;
            return Ok("Prepared raid cancelled; no supplies were consumed.".into());
        }
        "deployed" if ctx.timestamp < attempt.starts_at => {
            mark_terminal(ctx, attempt, "cancelled")?;
            return Ok("Deployed raid withdrawn before starting; no materials were awarded.".into());
        }
        "deployed" => start_deployed_attempt(ctx, attempt)?,
        "running" => {}
        _ => return Err("INVALID_RAID_STATUS".into()),
    }
    if attempt.status == "completed" {
        return Ok("Raid completed and its rewards were settled.".into());
    }
    if ctx.timestamp >= attempt.ends_at {
        complete_attempt(ctx, attempt)?;
        return Ok("Raid completed and its rewards were settled.".into());
    }
    if attempt.kind == "personal" {
        award_materials(ctx, attempt, elapsed_micros(attempt, ctx.timestamp)?)?;
    }
    mark_terminal(ctx, attempt, "cancelled")?;
    Ok("Raid withdrawn; eligible partial material rewards were settled.".into())
}

fn claim_attempt(ctx: &ReducerContext, attempt: &mut RaidAttempt) -> Result<String, String> {
    let status = attempt.status.clone();
    match status.as_str() {
        "completed" => return Ok("Raid rewards have already been settled.".into()),
        "missed" => return Ok("The deployment window was missed; no rewards were awarded.".into()),
        "cancelled" => return Ok("Raid was cancelled; no further rewards are available.".into()),
        "prepared" if ctx.timestamp >= attempt.starts_at => {
            mark_terminal(ctx, attempt, "missed")?;
            return Ok("The deployment window was missed; no rewards were awarded.".into());
        }
        "prepared" => return Err("RAID_NOT_DEPLOYED".into()),
        "deployed" => start_deployed_attempt(ctx, attempt)?,
        "running" => {}
        _ => return Err("INVALID_RAID_STATUS".into()),
    }
    if attempt.status == "completed" {
        return Ok("Raid rewards have been settled.".into());
    }
    if ctx.timestamp < attempt.ends_at {
        return Ok("Raid is still underway; rewards are not due yet.".into());
    }
    complete_attempt(ctx, attempt)?;
    Ok("Raid rewards have been settled.".into())
}

fn finish_action_attempt(
    ctx: &ReducerContext,
    player_id: u64,
    request_id: u64,
    attempt_id: u128,
    discord: bool,
    cancel: bool,
) -> Result<(), String> {
    let kind = if cancel { "cancel_raid" } else { "claim_raid" };
    let fingerprint = format!("{kind}:{attempt_id}");
    if begin_action(ctx, player_id, request_id, kind, &fingerprint, discord)? {
        return Ok(());
    }
    let mut attempt = ctx
        .db
        .raid_attempt()
        .id()
        .find(attempt_id)
        .filter(|row| row.player_id == player_id)
        .ok_or("RAID_ATTEMPT_UNAVAILABLE")?;
    let result = if cancel {
        cancel_attempt(ctx, &mut attempt)?
    } else {
        claim_attempt(ctx, &mut attempt)?
    };
    record_action(
        ctx,
        player_id,
        request_id,
        kind,
        &fingerprint,
        Some(attempt_id),
        &result,
    );
    Ok(())
}

#[spacetimedb::reducer]
pub fn cancel_raid(
    ctx: &ReducerContext,
    request_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    finish_action_attempt(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        attempt_id,
        false,
        true,
    )
}

#[spacetimedb::reducer]
pub fn cancel_raid_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    finish_action_attempt(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        attempt_id,
        true,
        true,
    )
}

#[spacetimedb::reducer]
pub fn claim_raid(
    ctx: &ReducerContext,
    request_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    finish_action_attempt(
        ctx,
        crate::shop::browser_player(ctx)?,
        request_id,
        attempt_id,
        false,
        false,
    )
}

#[spacetimedb::reducer]
pub fn claim_raid_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    attempt_id: u128,
) -> Result<(), String> {
    finish_action_attempt(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        interaction_id,
        attempt_id,
        true,
        false,
    )
}

#[spacetimedb::reducer]
pub fn run_raid_job(ctx: &ReducerContext, job: RaidJob) -> Result<(), String> {
    if ctx.sender() != ctx.database_identity() {
        return Err("SCHEDULER_REQUIRED".into());
    }
    let Some(mut attempt) = ctx.db.raid_attempt().id().find(job.attempt_id) else {
        return Ok(());
    };
    match job.phase.as_str() {
        "start" => match attempt.status.clone().as_str() {
            "prepared" => mark_terminal(ctx, &mut attempt, "missed"),
            "deployed" => start_deployed_attempt(ctx, &mut attempt),
            "running" if ctx.timestamp >= attempt.ends_at => complete_attempt(ctx, &mut attempt),
            "running" => {
                remove_jobs(ctx, attempt.id);
                schedule_job(ctx, attempt.id, "finish", attempt.ends_at);
                Ok(())
            }
            "completed" | "missed" | "cancelled" => Ok(()),
            _ => Err("INVALID_RAID_STATUS".into()),
        },
        "finish" => match attempt.status.clone().as_str() {
            "deployed" => start_deployed_attempt(ctx, &mut attempt),
            "running" if ctx.timestamp >= attempt.ends_at => complete_attempt(ctx, &mut attempt),
            "running" => {
                remove_jobs(ctx, attempt.id);
                schedule_job(ctx, attempt.id, "finish", attempt.ends_at);
                Ok(())
            }
            "completed" | "missed" | "cancelled" | "prepared" => Ok(()),
            _ => Err("INVALID_RAID_STATUS".into()),
        },
        _ => Err("INVALID_RAID_JOB_PHASE".into()),
    }
}

pub fn prune_history(ctx: &ReducerContext) {
    for receipt in ctx
        .db
        .raid_action_receipt()
        .expires_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        ctx.db.raid_action_receipt().key().delete(receipt.key);
    }
    for attempt in ctx
        .db
        .raid_attempt()
        .prune_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        remove_jobs(ctx, attempt.id);
        ctx.db.raid_attempt().id().delete(attempt.id);
    }
}
