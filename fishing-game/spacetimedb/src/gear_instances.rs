use crate::{
    accounts::{config, current_player},
    hauls::haul_trip,
    raids::{raid_attempt, rod_reinforcement},
    tables::*,
};
use game_rules::progression::level_for_xp_for_version;
use spacetimedb::{ReducerContext, SpacetimeType, Table, Timestamp, ViewContext};

#[spacetimedb::table(accessor = gear_foundation_state, public)]
pub struct GearFoundationState {
    #[primary_key]
    pub singleton: u8,
    pub version: u32,
    pub activated_at: Timestamp,
}

#[spacetimedb::table(accessor = gear_instance)]
#[derive(Clone)]
pub struct GearInstance {
    #[primary_key]
    pub instance_id: u128,
    #[index(btree)]
    pub player_id: u64,
    pub kind: String,
    pub definition_id: u32,
    pub family_id: u32,
    pub primary_biome_id: u32,
    pub quality_level: u8,
    pub reinforcement_level: u8,
    pub protected_original: bool,
    pub favorite: bool,
    pub revision: u64,
    pub source: String,
    pub created_at: Timestamp,
}

#[spacetimedb::table(accessor = gear_instance_name)]
pub struct GearInstanceName {
    #[primary_key]
    pub instance_id: u128,
    #[index(btree)]
    pub player_id: u64,
    pub name: String,
}

#[spacetimedb::table(accessor = gear_loadout)]
#[derive(Clone)]
pub struct GearLoadout {
    #[primary_key]
    pub player_id: u64,
    pub rod_instance_id: u128,
    pub boat_instance_id: u128,
    pub net_instance_id: u128,
    pub revision: u64,
}

#[spacetimedb::table(accessor = gear_family_progress)]
pub struct GearFamilyProgress {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub kind: String,
    pub family_id: u32,
    pub mastery_xp: u64,
    pub curve_version: u32,
}

#[derive(Clone, SpacetimeType)]
pub struct GearSnapshot {
    pub instance_id: u128,
    pub gear_revision: Option<u64>,
    pub kind: String,
    pub definition_id: u32,
    pub family_id: u32,
    pub primary_biome_id: Option<u32>,
    pub quality_level: u8,
    pub reinforcement_level: u8,
    pub effects_version: u32,
    pub power: Option<u32>,
    pub luck_bp: Option<u32>,
    pub xp_bonus_bp: Option<u32>,
    pub rules_version: u32,
    pub content_version: Option<u32>,
    pub catalog_version: Option<u32>,
}

#[spacetimedb::table(accessor = gear_activity_snapshot)]
pub struct GearActivitySnapshot {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub activity_kind: String,
    pub activity_id: u128,
    pub snapshot: GearSnapshot,
    pub booked_at: Timestamp,
    pub recorded_at: Timestamp,
    pub legacy_booking: bool,
}

fn kind_tag(kind: &str) -> Result<u128, String> {
    match kind {
        "rod" => Ok(1),
        "boat" => Ok(2),
        "net" => Ok(3),
        _ => Err("INVALID_GEAR_KIND".into()),
    }
}

pub(crate) fn original_id(player_id: u64, kind: &str, definition_id: u32) -> Result<u128, String> {
    Ok((u128::from(player_id) << 64) | (kind_tag(kind)? << 32) | u128::from(definition_id))
}

pub(crate) fn family_key(player_id: u64, kind: &str, family_id: u32) -> Result<u128, String> {
    original_id(player_id, kind, family_id)
}

pub(crate) fn enabled(ctx: &ReducerContext) -> Result<bool, String> {
    match ctx.db.gear_foundation_state().singleton().find(1) {
        None => Ok(false),
        Some(state) if state.version == 1 => Ok(true),
        Some(_) => Err("GEAR_FOUNDATION_VERSION_UNSUPPORTED".into()),
    }
}

fn view_enabled(ctx: &ViewContext) -> bool {
    ctx.db
        .gear_foundation_state()
        .singleton()
        .find(1)
        .is_some_and(|state| state.version == 1)
}

fn family_for(ctx: &ReducerContext, kind: &str, definition_id: u32) -> Result<u32, String> {
    let family_id = match kind {
        "rod" => {
            ctx.db
                .rod_definition()
                .rod_id()
                .find(definition_id)
                .ok_or("ROD_UNAVAILABLE")?;
            definition_id
        }
        "boat" | "net" => {
            let definition = ctx
                .db
                .haul_equipment_definition()
                .equipment_id()
                .find(definition_id)
                .ok_or("EQUIPMENT_UNAVAILABLE")?;
            if definition.kind != kind {
                return Err("INVALID_GEAR_KIND".into());
            }
            definition.rod_id
        }
        _ => return Err("INVALID_GEAR_KIND".into()),
    };
    ctx.db
        .rod_definition()
        .rod_id()
        .find(family_id)
        .ok_or("GEAR_FAMILY_UNAVAILABLE")?;
    ctx.db
        .biome_definition()
        .biome_id()
        .find(family_id)
        .ok_or("GEAR_BIOME_UNAVAILABLE")?;
    Ok(family_id)
}

fn validate_instance(
    ctx: &ReducerContext,
    instance: &GearInstance,
    player_id: u64,
    kind: &str,
) -> Result<(), String> {
    if instance.player_id != player_id
        || instance.instance_id >> 64 != u128::from(player_id)
        || instance.kind != kind
        || instance.revision == 0
    {
        return Err("GEAR_INSTANCE_UNAVAILABLE".into());
    }
    let canonical = original_id(player_id, kind, instance.definition_id)?;
    let copy_bit = 1u128 << 63;
    if (instance.protected_original && instance.instance_id != canonical)
        || (!instance.protected_original && instance.instance_id & copy_bit == 0)
    {
        return Err("GEAR_INSTANCE_INVALID".into());
    }
    if family_for(ctx, kind, instance.definition_id)? != instance.family_id
        || ctx
            .db
            .biome_definition()
            .biome_id()
            .find(instance.primary_biome_id)
            .is_none()
        || ctx
            .db
            .quality_definition()
            .quality_level()
            .find(instance.quality_level)
            .is_none()
        || (kind != "rod" && instance.reinforcement_level != 0)
        || instance.reinforcement_level > game_rules::raids::MAX_REINFORCEMENT_LEVEL
    {
        return Err("GEAR_INSTANCE_INVALID".into());
    }
    let progress = ctx
        .db
        .gear_family_progress()
        .key()
        .find(family_key(player_id, kind, instance.family_id)?)
        .ok_or("GEAR_FAMILY_UNAVAILABLE")?;
    if progress.player_id != player_id || progress.kind != kind || progress.family_id != instance.family_id {
        return Err("GEAR_FAMILY_INVALID".into());
    }
    Ok(())
}

fn insert_original(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
    quality_level: u8,
    reinforcement_level: u8,
) -> Result<(), String> {
    let family_id = family_for(ctx, kind, definition_id)?;
    if ctx.db.player().player_id().find(player_id).is_none()
        || ctx.db.quality_definition().quality_level().find(quality_level).is_none()
        || (kind != "rod" && reinforcement_level != 0)
        || reinforcement_level > game_rules::raids::MAX_REINFORCEMENT_LEVEL
    {
        return Err("GEAR_INSTANCE_INVALID".into());
    }
    let key = family_key(player_id, kind, family_id)?;
    if ctx.db.gear_family_progress().key().find(key).is_none() {
        ctx.db.gear_family_progress().insert(GearFamilyProgress {
            key,
            player_id,
            kind: kind.into(),
            family_id,
            mastery_xp: 0,
            curve_version: if crate::tideforge::enabled(ctx)? {
                game_rules::tideforge::MASTERY_CURVE_VERSION
            } else {
                0
            },
        });
    }
    let instance_id = original_id(player_id, kind, definition_id)?;
    if let Some(existing) = ctx.db.gear_instance().instance_id().find(instance_id) {
        validate_instance(ctx, &existing, player_id, kind)?;
        if !existing.protected_original || existing.definition_id != definition_id {
            return Err("GEAR_INSTANCE_INVALID".into());
        }
        return Ok(());
    }
    ctx.db.gear_instance().insert(GearInstance {
        instance_id,
        player_id,
        kind: kind.into(),
        definition_id,
        family_id,
        primary_biome_id: family_id,
        quality_level,
        reinforcement_level,
        protected_original: true,
        favorite: false,
        revision: 1,
        source: "legacy_original".into(),
        created_at: ctx.timestamp,
    });
    Ok(())
}

pub(crate) fn grant_original(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
    quality_level: u8,
    reinforcement_level: u8,
) -> Result<(), String> {
    if enabled(ctx)? {
        insert_original(ctx, player_id, kind, definition_id, quality_level, reinforcement_level)?;
    }
    Ok(())
}

fn original_reinforcement(ctx: &ReducerContext, player_id: u64, rod_id: u32) -> u8 {
    ctx.db
        .rod_reinforcement()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(rod_id))
        .map_or(0, |row| row.level)
}

pub(crate) fn verify_original(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
    quality: u8,
    reinforcement: u8,
) -> Result<(), String> {
    if !enabled(ctx)? {
        return Ok(());
    }
    let instance = ctx
        .db
        .gear_instance()
        .instance_id()
        .find(original_id(player_id, kind, definition_id)?)
        .ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, kind)?;
    if !instance.protected_original
        || instance.definition_id != definition_id
        || instance.quality_level != quality
        || instance.reinforcement_level != reinforcement
    {
        return Err("GEAR_ORIGINAL_MISMATCH".into());
    }
    Ok(())
}

pub(crate) fn verify_rod_original(
    ctx: &ReducerContext,
    player_id: u64,
    rod_id: u32,
    quality: u8,
) -> Result<(), String> {
    verify_original(ctx, player_id, "rod", rod_id, quality, original_reinforcement(ctx, player_id, rod_id))
}

pub(crate) fn mirror_original(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
    quality: u8,
    reinforcement: u8,
) -> Result<(), String> {
    if !enabled(ctx)? {
        return Ok(());
    }
    let mut instance = ctx
        .db
        .gear_instance()
        .instance_id()
        .find(original_id(player_id, kind, definition_id)?)
        .ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, kind)?;
    if !instance.protected_original || instance.definition_id != definition_id {
        return Err("GEAR_INSTANCE_INVALID".into());
    }
    if instance.quality_level != quality || instance.reinforcement_level != reinforcement {
        instance.revision = instance.revision.checked_add(1).ok_or("GEAR_REVISION_OVERFLOW")?;
        instance.quality_level = quality;
        instance.reinforcement_level = reinforcement;
        ctx.db.gear_instance().instance_id().update(instance);
    }
    Ok(())
}

pub(crate) fn mirror_rod_quality(ctx: &ReducerContext, player_id: u64, rod_id: u32, quality: u8) -> Result<(), String> {
    mirror_original(ctx, player_id, "rod", rod_id, quality, original_reinforcement(ctx, player_id, rod_id))
}

fn insert_player_loadout(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    if ctx.db.gear_loadout().player_id().find(player_id).is_some() {
        return Ok(());
    }
    let player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
    let haul = ctx.db.haul_loadout().player_id().find(player_id).ok_or("HAUL_LOADOUT_UNAVAILABLE")?;
    if ctx.db.owned_rod().key().find((u128::from(player_id) << 32) | u128::from(player.equipped_rod_id)).is_none()
        || ctx.db.owned_haul_equipment().key().find((u128::from(player_id) << 32) | u128::from(haul.boat_id)).is_none()
        || ctx.db.owned_haul_equipment().key().find((u128::from(player_id) << 32) | u128::from(haul.net_id)).is_none()
    {
        return Err("GEAR_OWNERSHIP_INVALID".into());
    }
    let row = GearLoadout {
        player_id,
        rod_instance_id: original_id(player_id, "rod", player.equipped_rod_id)?,
        boat_instance_id: original_id(player_id, "boat", haul.boat_id)?,
        net_instance_id: original_id(player_id, "net", haul.net_id)?,
        revision: 1,
    };
    for (kind, id) in [("rod", row.rod_instance_id), ("boat", row.boat_instance_id), ("net", row.net_instance_id)] {
        let instance = ctx.db.gear_instance().instance_id().find(id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
        validate_instance(ctx, &instance, player_id, kind)?;
    }
    ctx.db.gear_loadout().insert(row);
    Ok(())
}

pub(crate) fn initialize_player_loadout(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    if enabled(ctx)? {
        insert_player_loadout(ctx, player_id)?;
    }
    Ok(())
}

pub(crate) fn selected_instance(ctx: &ReducerContext, player_id: u64, kind: &str) -> Result<Option<GearInstance>, String> {
    if !enabled(ctx)? {
        return Ok(None);
    }
    let loadout = ctx.db.gear_loadout().player_id().find(player_id).ok_or("GEAR_LOADOUT_UNAVAILABLE")?;
    if loadout.revision == 0 {
        return Err("GEAR_LOADOUT_INVALID".into());
    }
    let instance_id = match kind {
        "rod" => loadout.rod_instance_id,
        "boat" => loadout.boat_instance_id,
        "net" => loadout.net_instance_id,
        _ => return Err("INVALID_GEAR_KIND".into()),
    };
    let instance = ctx.db.gear_instance().instance_id().find(instance_id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, kind)?;
    let player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
    let legacy_definition = if kind == "rod" {
        player.equipped_rod_id
    } else {
        let haul = ctx.db.haul_loadout().player_id().find(player_id).ok_or("HAUL_LOADOUT_UNAVAILABLE")?;
        if kind == "boat" { haul.boat_id } else { haul.net_id }
    };
    if instance.definition_id != legacy_definition {
        return Err("GEAR_LOADOUT_INVALID".into());
    }
    Ok(Some(instance))
}

fn insert_snapshot(
    ctx: &ReducerContext,
    player_id: u64,
    activity_kind: &str,
    activity_id: u128,
    snapshot: GearSnapshot,
    booked_at: Timestamp,
    legacy_booking: bool,
) -> Result<(), String> {
    let key = format!("{activity_kind}:{activity_id}");
    if ctx.db.gear_activity_snapshot().key().find(&key).is_some() {
        return Err("GEAR_SNAPSHOT_EXISTS".into());
    }
    ctx.db.gear_activity_snapshot().insert(GearActivitySnapshot {
        key,
        player_id,
        activity_kind: activity_kind.into(),
        activity_id,
        snapshot,
        booked_at,
        recorded_at: ctx.timestamp,
        legacy_booking,
    });
    Ok(())
}

pub(crate) fn snapshot_selected(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    activity_kind: &str,
    activity_id: u128,
    power: Option<u32>,
    luck_bp: Option<u32>,
    xp_bonus_bp: Option<u32>,
    rules_version: u32,
    content_version: Option<u32>,
    catalog_version: Option<u32>,
) -> Result<(), String> {
    let Some(instance) = selected_instance(ctx, player_id, kind)? else {
        return Ok(());
    };
    let effects_version = match crate::tideforge::lookup(ctx, player_id, activity_kind, activity_id)? {
        Some(row) => {
            if row.gear_instance_id != instance.instance_id
                || row.gear_revision != instance.revision
                || row.family_id != instance.family_id
                || row.gear_kind != instance.kind
            {
                return Err("TIDEFORGE_SNAPSHOT_CONFLICT".into());
            }
            crate::tideforge::decode(&row)?.version
        }
        None => 0,
    };
    insert_snapshot(
        ctx,
        player_id,
        activity_kind,
        activity_id,
        GearSnapshot {
            instance_id: instance.instance_id,
            gear_revision: Some(instance.revision),
            kind: instance.kind,
            definition_id: instance.definition_id,
            family_id: instance.family_id,
            primary_biome_id: Some(instance.primary_biome_id),
            quality_level: instance.quality_level,
            reinforcement_level: instance.reinforcement_level,
            effects_version,
            power,
            luck_bp,
            xp_bonus_bp,
            rules_version,
            content_version,
            catalog_version,
        },
        ctx.timestamp,
        false,
    )
}

pub(crate) fn haul_content_version(ctx: &ReducerContext, interaction_id: u64) -> u32 {
    ctx.db
        .gear_activity_snapshot()
        .key()
        .find(format!("haul:{interaction_id}"))
        .and_then(|row| row.snapshot.content_version)
        .unwrap_or(crate::content::CONTENT_VERSION)
}

pub(crate) fn delete_snapshot(ctx: &ReducerContext, activity_kind: &str, activity_id: u128) {
    crate::tideforge::delete_activity(ctx, activity_kind, activity_id);
    ctx.db
        .gear_activity_snapshot()
        .key()
        .delete(format!("{activity_kind}:{activity_id}"));
}

pub(crate) fn booked_raid_instance(ctx: &ReducerContext, player_id: u64, attempt_id: u128, boat_id: u32) -> Result<bool, String> {
    if !enabled(ctx)? {
        return Ok(false);
    }
    let row = ctx.db.gear_activity_snapshot().key().find(format!("raid:{attempt_id}")).ok_or("GEAR_SNAPSHOT_UNAVAILABLE")?;
    if row.player_id != player_id || row.activity_kind != "raid" || row.activity_id != attempt_id
        || row.snapshot.kind != "boat" || row.snapshot.definition_id != boat_id
    {
        return Err("GEAR_SNAPSHOT_INVALID".into());
    }
    let instance = ctx.db.gear_instance().instance_id().find(row.snapshot.instance_id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, "boat")?;
    if instance.definition_id != boat_id {
        return Err("GEAR_SNAPSHOT_INVALID".into());
    }
    Ok(true)
}

fn booked_original_id(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
) -> Result<u128, String> {
    let id = original_id(player_id, kind, definition_id)?;
    let instance = ctx.db.gear_instance().instance_id().find(id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, kind)?;
    if !instance.protected_original || instance.definition_id != definition_id {
        return Err("GEAR_INSTANCE_INVALID".into());
    }
    Ok(id)
}

#[spacetimedb::reducer]
pub fn activate_gear_foundation(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    if enabled(ctx)? {
        return Ok(());
    }
    for reinforcement in ctx.db.rod_reinforcement().iter() {
        let key = (u128::from(reinforcement.player_id) << 32) | u128::from(reinforcement.rod_id);
        if reinforcement.key != key
            || ctx.db.owned_rod().key().find(key).is_none_or(|rod|
                rod.player_id != reinforcement.player_id || rod.rod_id != reinforcement.rod_id)
        {
            return Err("GEAR_REINFORCEMENT_INVALID".into());
        }
    }
    for rod in ctx.db.owned_rod().iter() {
        if rod.key != (u128::from(rod.player_id) << 32) | u128::from(rod.rod_id) {
            return Err("GEAR_OWNERSHIP_INVALID".into());
        }
        insert_original(ctx, rod.player_id, "rod", rod.rod_id, rod.upgrade_level, original_reinforcement(ctx, rod.player_id, rod.rod_id))?;
    }
    for owned in ctx.db.owned_haul_equipment().iter() {
        if owned.key != (u128::from(owned.player_id) << 32) | u128::from(owned.equipment_id) {
            return Err("GEAR_OWNERSHIP_INVALID".into());
        }
        let definition = ctx.db.haul_equipment_definition().equipment_id().find(owned.equipment_id).ok_or("EQUIPMENT_UNAVAILABLE")?;
        insert_original(ctx, owned.player_id, &definition.kind, owned.equipment_id, owned.upgrade_level, 0)?;
    }
    for player in ctx.db.player().iter() {
        insert_player_loadout(ctx, player.player_id)?;
    }
    for trip in ctx.db.haul_trip().iter().filter(|trip| trip.status == "running") {
        let family_id = family_for(ctx, &trip.kind, trip.equipment_id)?;
        let instance_id = booked_original_id(ctx, trip.player_id, &trip.kind, trip.equipment_id)?;
        insert_snapshot(
            ctx,
            trip.player_id,
            "haul",
            u128::from(trip.interaction_id),
            GearSnapshot {
                instance_id,
                gear_revision: None,
                kind: trip.kind,
                definition_id: trip.equipment_id,
                family_id,
                primary_biome_id: None,
                quality_level: trip.quality_level,
                reinforcement_level: 0,
                effects_version: 0,
                power: Some(0),
                luck_bp: Some(trip.luck_bp),
                xp_bonus_bp: Some(trip.xp_bonus_bp),
                rules_version: trip.rules.version,
                content_version: None,
                catalog_version: None,
            },
            trip.started_at,
            true,
        )?;
    }
    for attempt in ctx.db.raid_attempt().iter().filter(|attempt| matches!(attempt.status.as_str(), "prepared" | "deployed" | "running")) {
        let family_id = family_for(ctx, "boat", attempt.boat_id)?;
        let instance_id = booked_original_id(ctx, attempt.player_id, "boat", attempt.boat_id)?;
        insert_snapshot(
            ctx,
            attempt.player_id,
            "raid",
            attempt.id,
            GearSnapshot {
                instance_id,
                gear_revision: None,
                kind: "boat".into(),
                definition_id: attempt.boat_id,
                family_id,
                primary_biome_id: None,
                quality_level: attempt.boat_quality_level,
                reinforcement_level: 0,
                effects_version: 0,
                power: None,
                luck_bp: None,
                xp_bonus_bp: None,
                rules_version: attempt.rules_version,
                content_version: Some(attempt.content_version),
                catalog_version: Some(attempt.catalog_version),
            },
            attempt.prepared_at,
            true,
        )?;
    }
    ctx.db.gear_foundation_state().insert(GearFoundationState {
        singleton: 1,
        version: 1,
        activated_at: ctx.timestamp,
    });
    Ok(())
}

pub(crate) fn equip_instance(ctx: &ReducerContext, player_id: u64, instance_id: u128) -> Result<(), String> {
    if !enabled(ctx)? {
        return Err("GEAR_FOUNDATION_UNAVAILABLE".into());
    }
    let instance = ctx.db.gear_instance().instance_id().find(instance_id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, &instance.kind)?;
    let mut player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
    let rules = config(ctx);
    let level = level_for_xp_for_version(player.total_xp, rules.level_cap, rules.version);
    let family = ctx.db.rod_definition().rod_id().find(instance.family_id).ok_or("GEAR_FAMILY_UNAVAILABLE")?;
    if level < family.minimum_level {
        return Err("ROD_LOCKED".into());
    }
    let biome = ctx.db.biome_definition().biome_id().find(player.selected_biome_id).ok_or("BIOME_UNAVAILABLE")?;
    if level < biome.minimum_level {
        return Err("BIOME_LEVEL_REQUIRED".into());
    }
    if !crate::shop::owns_licence(ctx, player_id, biome.biome_id) {
        return Err("BIOME_LICENCE_REQUIRED".into());
    }
    let mut loadout = ctx.db.gear_loadout().player_id().find(player_id).ok_or("GEAR_LOADOUT_UNAVAILABLE")?;
    let selected = match instance.kind.as_str() {
        "rod" => loadout.rod_instance_id,
        "boat" => loadout.boat_instance_id,
        "net" => loadout.net_instance_id,
        _ => return Err("INVALID_GEAR_KIND".into()),
    };
    if selected == instance_id {
        selected_instance(ctx, player_id, &instance.kind)?;
        return Ok(());
    }
    if crate::raids::boat_locked_by_raid(ctx, player_id) {
        return Err("GEAR_LOCKED_BY_RAID".into());
    }
    loadout.revision = loadout.revision.checked_add(1).ok_or("GEAR_REVISION_OVERFLOW")?;
    match instance.kind.as_str() {
        "rod" => {
            player.equipped_rod_id = instance.definition_id;
            ctx.db.player().player_id().update(player);
            loadout.rod_instance_id = instance_id;
        }
        "boat" | "net" => {
            let mut haul = ctx.db.haul_loadout().player_id().find(player_id).ok_or("HAUL_LOADOUT_UNAVAILABLE")?;
            if instance.kind == "boat" {
                haul.boat_id = instance.definition_id;
                loadout.boat_instance_id = instance_id;
            } else {
                haul.net_id = instance.definition_id;
                loadout.net_instance_id = instance_id;
            }
            ctx.db.haul_loadout().player_id().update(haul);
        }
        _ => return Err("INVALID_GEAR_KIND".into()),
    }
    ctx.db.gear_loadout().player_id().update(loadout);
    Ok(())
}

#[spacetimedb::reducer]
pub fn equip_gear_instance(ctx: &ReducerContext, instance_id: u128) -> Result<(), String> {
    equip_instance(ctx, crate::shop::browser_player(ctx)?, instance_id)
}

#[spacetimedb::reducer]
pub fn equip_gear_instance_from_discord(ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, instance_id: u128) -> Result<(), String> {
    equip_instance(ctx, crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?, instance_id)
}

fn set_favorite(ctx: &ReducerContext, player_id: u64, instance_id: u128, favorite: bool) -> Result<(), String> {
    if !enabled(ctx)? {
        return Err("GEAR_FOUNDATION_UNAVAILABLE".into());
    }
    let mut instance = ctx.db.gear_instance().instance_id().find(instance_id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, &instance.kind)?;
    if instance.favorite != favorite {
        instance.favorite = favorite;
        ctx.db.gear_instance().instance_id().update(instance);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn set_gear_instance_favorite(ctx: &ReducerContext, instance_id: u128, favorite: bool) -> Result<(), String> {
    set_favorite(ctx, crate::shop::browser_player(ctx)?, instance_id, favorite)
}

#[spacetimedb::reducer]
pub fn set_gear_instance_favorite_from_discord(ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, instance_id: u128, favorite: bool) -> Result<(), String> {
    set_favorite(ctx, crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?, instance_id, favorite)
}

fn set_name(ctx: &ReducerContext, player_id: u64, instance_id: u128, name: String) -> Result<(), String> {
    if !enabled(ctx)? {
        return Err("GEAR_FOUNDATION_UNAVAILABLE".into());
    }
    let instance = ctx.db.gear_instance().instance_id().find(instance_id).ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    validate_instance(ctx, &instance, player_id, &instance.kind)?;
    let existing = ctx.db.gear_instance_name().instance_id().find(instance_id);
    if existing.as_ref().is_some_and(|row| row.player_id != player_id) {
        return Err("GEAR_INSTANCE_UNAVAILABLE".into());
    }
    if name.len() > 1024 {
        return Err("GEAR_NAME_TOO_LONG".into());
    }
    let name = name.trim();
    if name.chars().count() > 32 {
        return Err("GEAR_NAME_TOO_LONG".into());
    }
    if name.chars().any(|c| c.is_control() || matches!(c, '\u{061c}' | '\u{200e}' | '\u{200f}' | '\u{202a}'..='\u{202e}' | '\u{2066}'..='\u{2069}')) {
        return Err("GEAR_NAME_INVALID".into());
    }
    match existing {
        Some(_) if name.is_empty() => { ctx.db.gear_instance_name().instance_id().delete(instance_id); }
        Some(mut row) if row.name != name => {
            row.name = name.into();
            ctx.db.gear_instance_name().instance_id().update(row);
        }
        None if !name.is_empty() => {
            ctx.db.gear_instance_name().insert(GearInstanceName { instance_id, player_id, name: name.into() });
        }
        _ => {}
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn rename_gear_instance(ctx: &ReducerContext, instance_id: u128, name: String) -> Result<(), String> {
    set_name(ctx, crate::shop::browser_player(ctx)?, instance_id, name)
}

#[spacetimedb::reducer]
pub fn rename_gear_instance_from_discord(ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, instance_id: u128, name: String) -> Result<(), String> {
    set_name(ctx, crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?, instance_id, name)
}

#[spacetimedb::view(accessor = my_gear_instances, public)]
pub fn my_gear_instances(ctx: &ViewContext) -> Vec<GearInstance> {
    if !view_enabled(ctx) { return vec![]; }
    current_player(ctx).map(|id| ctx.db.gear_instance().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_gear_instance_names, public)]
pub fn my_gear_instance_names(ctx: &ViewContext) -> Vec<GearInstanceName> {
    if !view_enabled(ctx) { return vec![]; }
    current_player(ctx).map(|id| ctx.db.gear_instance_name().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_gear_loadout, public)]
pub fn my_gear_loadout(ctx: &ViewContext) -> Option<GearLoadout> {
    if !view_enabled(ctx) { return None; }
    ctx.db.gear_loadout().player_id().find(current_player(ctx)?)
}

#[spacetimedb::view(accessor = my_gear_family_progress, public)]
pub fn my_gear_family_progress(ctx: &ViewContext) -> Vec<GearFamilyProgress> {
    if !view_enabled(ctx) { return vec![]; }
    current_player(ctx).map(|id| ctx.db.gear_family_progress().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_gear_activity_snapshots, public)]
pub fn my_gear_activity_snapshots(ctx: &ViewContext) -> Vec<GearActivitySnapshot> {
    if !view_enabled(ctx) { return vec![]; }
    current_player(ctx).map(|id| ctx.db.gear_activity_snapshot().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_gear_instances, public)]
pub fn adapter_gear_instances(ctx: &ViewContext) -> Vec<GearInstance> {
    if !view_enabled(ctx) { return vec![]; }
    crate::shop::selected(ctx).map(|id| ctx.db.gear_instance().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_gear_instance_names, public)]
pub fn adapter_gear_instance_names(ctx: &ViewContext) -> Vec<GearInstanceName> {
    if !view_enabled(ctx) { return vec![]; }
    crate::shop::selected(ctx).map(|id| ctx.db.gear_instance_name().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_gear_loadout, public)]
pub fn adapter_gear_loadout(ctx: &ViewContext) -> Option<GearLoadout> {
    if !view_enabled(ctx) { return None; }
    ctx.db.gear_loadout().player_id().find(crate::shop::selected(ctx)?)
}

#[spacetimedb::view(accessor = adapter_gear_family_progress, public)]
pub fn adapter_gear_family_progress(ctx: &ViewContext) -> Vec<GearFamilyProgress> {
    if !view_enabled(ctx) { return vec![]; }
    crate::shop::selected(ctx).map(|id| ctx.db.gear_family_progress().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_gear_activity_snapshots, public)]
pub fn adapter_gear_activity_snapshots(ctx: &ViewContext) -> Vec<GearActivitySnapshot> {
    if !view_enabled(ctx) { return vec![]; }
    crate::shop::selected(ctx).map(|id| ctx.db.gear_activity_snapshot().player_id().filter(id).collect()).unwrap_or_default()
}
