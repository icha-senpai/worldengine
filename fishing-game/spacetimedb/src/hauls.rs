//! Durable timed fishing. Rewards commit atomically; Discord delivery is separate.
use crate::{
    accounts::{config, ensure_player, require_service, service_can_read},
    tables::*,
};
use spacetimedb::{Identity, ReducerContext, Table, TimeDuration, Timestamp, ViewContext};

#[spacetimedb::table(accessor = haul_trip)]
pub struct HaulTrip {
    #[primary_key]
    pub interaction_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub discord_user_id: u64,
    pub guild_id: Option<u64>,
    pub channel_id: u64,
    pub kind: String,
    pub equipment_id: u32,
    pub equipment_name: String,
    pub quality_level: u8,
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub biome: BiomeDefinition,
    pub rules: GameConfig,
    pub collection_focus_enabled: bool,
    pub started_at: Timestamp,
    pub due_at: Timestamp,
    pub completed_at: Option<Timestamp>,
    #[index(btree)]
    pub status: String,
    pub results: Vec<CommandReceipt>,
    pub total_xp: u64,
    pub total_coins: u64,
    #[index(btree)]
    pub available_at: Timestamp,
    pub lease_owner: Identity,
    pub lease_nonce: u128,
    pub attempts: u32,
}

#[spacetimedb::table(accessor = haul_job, scheduled(finish_scheduled_haul))]
pub struct HaulJob {
    #[primary_key]
    pub scheduled_id: u64,
    pub scheduled_at: spacetimedb::ScheduleAt,
}

/// Both starts and status checks retain their original trip on interaction replay.
#[spacetimedb::table(accessor = haul_request)]
pub struct HaulRequest {
    #[primary_key]
    pub interaction_id: u64,
    pub trip_id: u64,
    pub discord_user_id: u64,
    pub guild_id: Option<u64>,
    pub channel_id: u64,
    pub kind: String,
    #[index(btree)]
    pub created_at: Timestamp,
}

#[spacetimedb::reducer]
pub fn start_haul_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    guild_id: Option<u64>,
    channel_id: u64,
    kind: String,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    let (seconds, _) = game_rules::hauls::settings(&kind)?;
    if channel_id == 0 {
        return Err("INVALID_CHANNEL".into());
    }
    if let Some(old) = ctx.db.haul_request().interaction_id().find(interaction_id) {
        return if old.discord_user_id == discord_user_id
            && old.guild_id == guild_id
            && old.channel_id == channel_id
            && old.kind == kind
        {
            Ok(())
        } else {
            Err("REQUEST_CONFLICT".into())
        };
    }
    let mut request = HaulRequest {
        interaction_id,
        trip_id: interaction_id,
        discord_user_id,
        guild_id,
        channel_id,
        kind: kind.clone(),
        created_at: ctx.timestamp,
    };
    if ctx
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
    {
        return Err("REQUEST_CONFLICT".into());
    }
    game_rules::progression::check_interaction_freshness(
        interaction_id,
        ctx.timestamp.to_micros_since_unix_epoch(),
    )?;
    let player = ensure_player(ctx, discord_user_id, "Angler")?;
    if kind == "boat" && crate::raids::boat_locked_by_raid(ctx, player.player_id) {
        return Err("BOAT_IN_RAID".into());
    }
    // Repeating a command while underway reports the existing trip, without restarting.
    if let Some(active) = ctx
        .db
        .haul_trip()
        .player_id()
        .filter(player.player_id)
        .find(|t| t.kind == kind && t.status == "running")
    {
        request.trip_id = active.interaction_id;
        ctx.db.haul_request().insert(request);
        return Ok(());
    }
    crate::haul_equipment::grant_starters(ctx, player.player_id);
    let loadout = ctx
        .db
        .haul_loadout()
        .player_id()
        .find(player.player_id)
        .ok_or("HAUL_LOADOUT_UNAVAILABLE")?;
    let equipment_id = if kind == "boat" {
        loadout.boat_id
    } else {
        loadout.net_id
    };
    let owned = ctx
        .db
        .owned_haul_equipment()
        .key()
        .find((u128::from(player.player_id) << 32) | u128::from(equipment_id))
        .ok_or("EQUIPMENT_NOT_OWNED")?;
    let gear = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(equipment_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    if gear.kind != kind {
        return Err("INVALID_HAUL_KIND".into());
    }
    let quality = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(owned.upgrade_level)
        .ok_or("QUALITY_UNAVAILABLE")?;
    let rules = config(ctx);
    let level = game_rules::progression::level_for_xp_for_version(
        player.total_xp,
        rules.level_cap,
        rules.version,
    );
    let biome = ctx
        .db
        .biome_definition()
        .biome_id()
        .find(player.selected_biome_id)
        .ok_or("BIOME_UNAVAILABLE")?;
    if !crate::shop::owns_licence(ctx, player.player_id, biome.biome_id) {
        return Err("BIOME_LICENCE_REQUIRED".into());
    }
    if level < biome.minimum_level {
        return Err("BIOME_LOCKED".into());
    }
    let family = ctx
        .db
        .rod_definition()
        .rod_id()
        .find(gear.rod_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    if level < family.minimum_level {
        return Err("ROD_LOCKED".into());
    }
    let species: Vec<_> = ctx
        .db
        .species_definition()
        .biome_id()
        .filter(biome.biome_id)
        .collect();
    if species.is_empty() {
        return Err("CONTENT_UNAVAILABLE".into());
    }
    let luck_bp = quality
        .luck_bp
        .checked_mul(gear.quality_bonus_multiplier)
        .and_then(|q| gear.luck_bp.checked_add(q))
        .ok_or("INVALID_HAUL_LUCK")?;
    let xp_bonus_bp = quality
        .xp_bonus_bp
        .checked_mul(gear.quality_bonus_multiplier)
        .and_then(|q| gear.xp_bonus_bp.checked_add(q))
        .ok_or("INVALID_HAUL_XP")?;
    let due_at = ctx.timestamp + TimeDuration::from_micros(seconds * 1_000_000);
    let focus = crate::collection_focus::active(ctx, player.player_id, biome.biome_id, &species);
    ctx.db.haul_request().insert(request);
    ctx.db.haul_trip().insert(HaulTrip {
        interaction_id,
        player_id: player.player_id,
        discord_user_id,
        guild_id,
        channel_id,
        kind,
        equipment_id,
        equipment_name: gear.name,
        quality_level: quality.quality_level,
        luck_bp,
        xp_bonus_bp,
        biome,
        rules,
        collection_focus_enabled: focus,
        started_at: ctx.timestamp,
        due_at,
        completed_at: None,
        status: "running".into(),
        results: vec![],
        total_xp: 0,
        total_coins: 0,
        available_at: due_at,
        lease_owner: Identity::ZERO,
        lease_nonce: 0,
        attempts: 0,
    });
    ctx.db.haul_job().insert(HaulJob {
        scheduled_id: interaction_id,
        scheduled_at: due_at.into(),
    });
    Ok(())
}

fn finish(ctx: &ReducerContext, interaction_id: u64) -> Result<(), String> {
    let Some(mut trip) = ctx.db.haul_trip().interaction_id().find(interaction_id) else {
        return Ok(());
    };
    if trip.status != "running" {
        return Ok(());
    }
    if ctx.timestamp < trip.due_at {
        return Err("HAUL_NOT_READY".into());
    }
    let player = ctx
        .db
        .player()
        .player_id()
        .find(trip.player_id)
        .ok_or("PLAYER_MISSING")?;
    let (_, pull_count) = game_rules::hauls::settings(&trip.kind)?;
    crate::casting::resolve(
        ctx,
        crate::casting::CastPlan {
            player,
            biome: trip.biome.clone(),
            rules: trip.rules.clone(),
            interaction_id,
            guild_id: trip.guild_id,
            channel_id: trip.channel_id,
            command: format!("fish{}", trip.kind),
            equipment_id: trip.equipment_id,
            quality_level: trip.quality_level,
            power: 0,
            luck_bp: trip.luck_bp,
            xp_bonus_bp: trip.xp_bonus_bp,
            bait: crate::crafting::UsedBaits {
                primary: Default::default(),
                secondary: Default::default(),
                luck_bp: 0,
                xp_bonus_bp: 0,
                size_rolls: 1,
            },
            pull_count,
            collection_focus_enabled: trip.collection_focus_enabled,
            reinforcement_level: 0,
            special_raid_species_id: None,
            special_raid_species_definition: None,
            special_raid_rank_definition: None,
            special_raid_band: None,
            special_raid_maximum_roll_one_in: 0,
            content_version: crate::content::CONTENT_VERSION,
        },
    )?;
    let receipt = ctx
        .db
        .command_receipt()
        .interaction_id()
        .find(interaction_id)
        .ok_or("RECEIPT_MISSING")?;
    trip.total_xp = receipt.xp_granted;
    trip.total_coins = receipt.coins_granted;
    trip.results = ctx
        .db
        .cast_pull()
        .interaction_id()
        .filter(interaction_id)
        .map(|p| p.receipt)
        .collect();
    trip.results.sort_by_key(|r| r.catch_id.unwrap_or(0));
    trip.status = "ready".into();
    trip.completed_at = Some(ctx.timestamp);
    trip.available_at = ctx.timestamp;
    ctx.db.haul_trip().interaction_id().update(trip);
    ctx.db.haul_job().scheduled_id().delete(interaction_id);
    Ok(())
}

#[spacetimedb::reducer]
pub fn finish_scheduled_haul(ctx: &ReducerContext, job: HaulJob) -> Result<(), String> {
    if ctx.sender() != ctx.database_identity() {
        return Err("SCHEDULER_REQUIRED".into());
    }
    finish(ctx, job.scheduled_id)
}

/// Recovery if a scheduled attempt failed or the server was offline at the deadline.
#[spacetimedb::reducer]
pub fn finish_haul_from_adapter(ctx: &ReducerContext, interaction_id: u64) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    finish(ctx, interaction_id)
}

#[spacetimedb::reducer]
pub fn claim_haul_delivery(
    ctx: &ReducerContext,
    interaction_id: u64,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if lease_nonce == 0 {
        return Err("INVALID_LEASE".into());
    }
    let Some(mut trip) = ctx.db.haul_trip().interaction_id().find(interaction_id) else {
        return Ok(());
    };
    if trip.status != "ready" || trip.available_at > ctx.timestamp {
        return Ok(());
    }
    trip.lease_owner = ctx.sender();
    trip.lease_nonce = lease_nonce;
    trip.attempts = trip.attempts.saturating_add(1);
    trip.available_at = ctx.timestamp + TimeDuration::from_micros(120_000_000);
    ctx.db.haul_trip().interaction_id().update(trip);
    Ok(())
}

#[spacetimedb::reducer]
pub fn acknowledge_haul_delivery(
    ctx: &ReducerContext,
    interaction_id: u64,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    let Some(mut trip) = ctx.db.haul_trip().interaction_id().find(interaction_id) else {
        return Ok(());
    };
    if trip.status == "delivered" {
        return Ok(());
    }
    if trip.status != "ready"
        || trip.lease_owner != ctx.sender()
        || trip.lease_nonce != lease_nonce
        || lease_nonce == 0
    {
        return Err("DELIVERY_LEASE_CHANGED".into());
    }
    trip.status = "delivered".into();
    ctx.db.haul_trip().interaction_id().update(trip);
    Ok(())
}

#[spacetimedb::view(accessor = adapter_haul_work, public)]
pub fn adapter_haul_work(ctx: &ViewContext) -> Vec<HaulTrip> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return vec![];
    }
    ctx.db
        .haul_trip()
        .status()
        .filter("running")
        .take(100)
        .chain(ctx.db.haul_trip().status().filter("ready").take(100))
        .collect()
}

fn player_trips(ctx: &ViewContext, id: u64) -> Vec<HaulTrip> {
    let mut trips: Vec<_> = ctx.db.haul_trip().player_id().filter(id).collect();
    trips.sort_by_key(|t| std::cmp::Reverse(t.started_at));
    trips.truncate(20);
    trips
}

#[spacetimedb::view(accessor = adapter_haul_status, public)]
pub fn adapter_haul_status(ctx: &ViewContext) -> Vec<HaulTrip> {
    let Some(id) = crate::shop::selected(ctx) else {
        return vec![];
    };
    let Some(selection) = ctx.db.service_selection().identity().find(ctx.sender()) else {
        return vec![];
    };
    let Some(request) = ctx
        .db
        .haul_request()
        .interaction_id()
        .find(selection.interaction_id)
    else {
        return vec![];
    };
    ctx.db
        .haul_trip()
        .interaction_id()
        .find(request.trip_id)
        .filter(|trip| trip.player_id == id)
        .into_iter()
        .collect()
}

#[spacetimedb::view(accessor = my_haul_trips, public)]
pub fn my_haul_trips(ctx: &ViewContext) -> Vec<HaulTrip> {
    crate::accounts::current_player(ctx)
        .map(|id| player_trips(ctx, id))
        .unwrap_or_default()
}

pub fn prune_delivered(ctx: &ReducerContext) {
    let week = ctx.timestamp - TimeDuration::from_micros(7 * 86_400_000_000);
    for request in ctx.db.haul_request().created_at().filter(..week).take(1000) {
        ctx.db
            .haul_request()
            .interaction_id()
            .delete(request.interaction_id);
    }
    let month = ctx.timestamp - TimeDuration::from_micros(30 * 86_400_000_000);
    for trip in ctx
        .db
        .haul_trip()
        .available_at()
        .filter(..month)
        .filter(|t| t.status == "delivered")
        .take(100)
    {
        ctx.db
            .haul_trip()
            .interaction_id()
            .delete(trip.interaction_id);
    }
}
