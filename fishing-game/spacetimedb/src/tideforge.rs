use crate::{
    accounts::current_player,
    gear_instances::{self, GearFamilyProgress, GearInstance, gear_family_progress, gear_instance},
    tables::*,
};
use game_rules::tideforge::{self as rules, AffixRoll, ComposeInput, Effects};
use spacetimedb::{ReducerContext, Table, Timestamp, ViewContext};

const CATALOG_VERSION: u32 = 1;
const EFFECTS_VERSION: u32 = 1;
const CURVE_VERSION: u32 = 1;

#[spacetimedb::table(accessor = tideforge_state, public)]
pub struct TideforgeState {
    #[primary_key]
    pub singleton: u8,
    pub version: u32,
    pub catalog_version: u32,
    pub curve_version: u32,
    pub activated_at: Timestamp,
}

#[spacetimedb::table(accessor = tideforge_gear)]
pub struct TideforgeGear {
    #[primary_key]
    pub instance_id: u128,
    #[index(btree)]
    pub player_id: u64,
    pub affixes_json: String,
    pub native_signature: Option<String>,
}

#[spacetimedb::table(accessor = tideforge_library)]
pub struct TideforgeLibrary {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub signature_id: String,
}

#[spacetimedb::table(accessor = tideforge_loadout)]
pub struct TideforgeLoadout {
    #[primary_key]
    pub player_id: u64,
    pub rod_signature: Option<String>,
    pub haul_signature: Option<String>,
    pub waters_signature: Option<String>,
    pub goal: String,
}

#[spacetimedb::table(accessor = tideforge_activity_snapshot)]
#[derive(Clone)]
pub struct TideforgeActivitySnapshot {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub activity_kind: String,
    pub activity_id: u128,
    pub gear_instance_id: u128,
    pub gear_revision: u64,
    pub family_id: u32,
    pub gear_kind: String,
    pub biome_id: u32,
    pub effects_json: String,
    pub catalog_version: u32,
    pub mastery_curve_version: u32,
    pub mastery_xp_at_booking: u64,
    pub mastery_level: u8,
    pub base_mastery_xp: u64,
    pub booked_at: Timestamp,
}

#[spacetimedb::table(accessor = tideforge_fractional_credit)]
pub struct TideforgeFractionalCredit {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub resource: String,
    pub remainder_bp: u16,
}

#[spacetimedb::table(accessor = tideforge_daily_use)]
pub struct TideforgeDailyUse {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub biome_id: u32,
    #[index(btree)]
    pub utc_day: i64,
    pub signature_id: String,
    pub activity_key: String,
}

#[spacetimedb::table(accessor = tideforge_activity_receipt)]
pub struct TideforgeActivityReceipt {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub activity_kind: String,
    pub activity_id: u128,
    pub gear_instance_id: u128,
    pub gear_kind: String,
    pub family_id: u32,
    pub mastery_xp_granted: u64,
    pub settled_at: Timestamp,
}

#[spacetimedb::table(accessor = tideforge_specimen_modifiers)]
pub struct TideforgeSpecimenModifiers {
    #[primary_key]
    pub catch_id: u64,
    #[index(btree)]
    pub player_id: u64,
    pub sale_bp: u32,
    pub fillet_bp: u32,
    pub scale_bp: u32,
    pub effects_version: u32,
    pub activity_key: String,
}

pub(crate) fn enabled(ctx: &ReducerContext) -> Result<bool, String> {
    match ctx.db.tideforge_state().singleton().find(1) {
        None => Ok(false),
        Some(state)
            if state.version == EFFECTS_VERSION
                && state.catalog_version == CATALOG_VERSION
                && state.curve_version == CURVE_VERSION =>
        {
            Ok(true)
        }
        Some(_) => Err("TIDEFORGE_VERSION_UNSUPPORTED".into()),
    }
}

fn view_enabled(ctx: &ViewContext) -> bool {
    ctx.db
        .tideforge_state()
        .singleton()
        .find(1)
        .is_some_and(|state| {
            state.version == EFFECTS_VERSION
                && state.catalog_version == CATALOG_VERSION
                && state.curve_version == CURVE_VERSION
        })
}

fn require_enabled(ctx: &ReducerContext) -> Result<(), String> {
    if !enabled(ctx)? {
        return Err("TIDEFORGE_UNAVAILABLE".into());
    }
    Ok(())
}

fn valid_goal(goal: &str) -> bool {
    matches!(goal, "ranks" | "maxima" | "perfects")
}

fn valid_kind(kind: &str) -> bool {
    matches!(kind, "rod" | "boat" | "net")
}

fn require_player(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    ctx.db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    Ok(())
}

fn family_progress(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    family_id: u32,
) -> Result<GearFamilyProgress, String> {
    require_player(ctx, player_id)?;
    if !valid_kind(kind)
        || family_id == 0
        || ctx.db.rod_definition().rod_id().find(family_id).is_none()
        || ctx.db.biome_definition().biome_id().find(family_id).is_none()
    {
        return Err("TIDEFORGE_FAMILY_INVALID".into());
    }
    let key = gear_instances::family_key(player_id, kind, family_id)?;
    let row = ctx
        .db
        .gear_family_progress()
        .key()
        .find(key)
        .ok_or("GEAR_FAMILY_UNAVAILABLE")?;
    if row.key != key
        || row.player_id != player_id
        || row.kind != kind
        || row.family_id != family_id
    {
        return Err("TIDEFORGE_FAMILY_INVALID".into());
    }
    if row.curve_version != 0 && row.curve_version != CURVE_VERSION {
        return Err("TIDEFORGE_CURVE_UNSUPPORTED".into());
    }
    Ok(row)
}

fn validate_instance(
    ctx: &ReducerContext,
    instance: &GearInstance,
    player_id: u64,
) -> Result<(), String> {
    require_player(ctx, player_id)?;
    if instance.player_id != player_id
        || instance.instance_id >> 64 != u128::from(player_id)
        || !valid_kind(&instance.kind)
        || instance.revision == 0
    {
        return Err("TIDEFORGE_GEAR_INVALID".into());
    }
    let original = gear_instances::original_id(player_id, &instance.kind, instance.definition_id)?;
    if (instance.protected_original && instance.instance_id != original)
        || (!instance.protected_original && instance.instance_id & (1u128 << 63) == 0)
    {
        return Err("TIDEFORGE_GEAR_INVALID".into());
    }
    let family_id = if instance.kind == "rod" {
        ctx.db
            .rod_definition()
            .rod_id()
            .find(instance.definition_id)
            .ok_or("ROD_UNAVAILABLE")?;
        instance.definition_id
    } else {
        let definition = ctx
            .db
            .haul_equipment_definition()
            .equipment_id()
            .find(instance.definition_id)
            .ok_or("EQUIPMENT_UNAVAILABLE")?;
        if definition.kind != instance.kind {
            return Err("TIDEFORGE_GEAR_INVALID".into());
        }
        definition.rod_id
    };
    if family_id != instance.family_id
        || ctx.db.rod_definition().rod_id().find(family_id).is_none()
        || ctx.db.biome_definition().biome_id().find(family_id).is_none()
        || ctx.db.biome_definition().biome_id().find(instance.primary_biome_id).is_none()
        || ctx.db.quality_definition().quality_level().find(instance.quality_level).is_none()
        || (instance.kind != "rod" && instance.reinforcement_level != 0)
        || instance.reinforcement_level > game_rules::raids::MAX_REINFORCEMENT_LEVEL
    {
        return Err("TIDEFORGE_GEAR_INVALID".into());
    }
    family_progress(ctx, player_id, &instance.kind, family_id)?;
    Ok(())
}

fn parse_affixes(ctx: &ReducerContext, json: &str) -> Result<Vec<AffixRoll>, String> {
    let affixes: Vec<AffixRoll> =
        serde_json::from_str(json).map_err(|_| "TIDEFORGE_AFFIX_JSON_INVALID")?;
    for affix in &affixes {
        if let Some(biome_id) = affix.secondary_biome_id {
            if ctx.db.biome_definition().biome_id().find(biome_id).is_none() {
                return Err("TIDEFORGE_BIOME_UNAVAILABLE".into());
            }
        }
    }
    Ok(affixes)
}

fn validate_native_signature(kind: &str, signature: Option<&str>) -> Result<(), String> {
    if let Some(id) = signature {
        let signature_kind = rules::signature_kind(id).map_err(str::to_owned)?;
        if signature_kind != kind && signature_kind != "waters" {
            return Err("TIDEFORGE_SIGNATURE_KIND_INVALID".into());
        }
    }
    Ok(())
}

fn validate_library_row(ctx: &ReducerContext, row: &TideforgeLibrary) -> Result<(), String> {
    require_player(ctx, row.player_id)?;
    if row.key != format!("{}:{}", row.player_id, row.signature_id) {
        return Err("TIDEFORGE_LIBRARY_INVALID".into());
    }
    rules::signature_kind(&row.signature_id).map_err(str::to_owned)?;
    Ok(())
}

fn validate_library_selection(
    ctx: &ReducerContext,
    player_id: u64,
    signature: Option<&str>,
    slot: &str,
) -> Result<(), String> {
    let Some(id) = signature else {
        return Ok(());
    };
    let kind = rules::signature_kind(id).map_err(str::to_owned)?;
    if !match slot {
        "rod" => kind == "rod",
        "haul" => matches!(kind, "boat" | "net"),
        "waters" => kind == "waters",
        _ => false,
    } {
        return Err("TIDEFORGE_SIGNATURE_KIND_INVALID".into());
    }
    let key = format!("{player_id}:{id}");
    let row = ctx
        .db
        .tideforge_library()
        .key()
        .find(&key)
        .ok_or("TIDEFORGE_SIGNATURE_NOT_OWNED")?;
    if row.player_id != player_id || row.signature_id != id || row.key != key {
        return Err("TIDEFORGE_LIBRARY_INVALID".into());
    }
    Ok(())
}

fn default_loadout(player_id: u64) -> TideforgeLoadout {
    TideforgeLoadout {
        player_id,
        rod_signature: None,
        haul_signature: None,
        waters_signature: None,
        goal: "ranks".into(),
    }
}

fn validate_loadout(ctx: &ReducerContext, row: &TideforgeLoadout) -> Result<(), String> {
    require_player(ctx, row.player_id)?;
    if !valid_goal(&row.goal) {
        return Err("TIDEFORGE_GOAL_INVALID".into());
    }
    validate_library_selection(ctx, row.player_id, row.rod_signature.as_deref(), "rod")?;
    validate_library_selection(ctx, row.player_id, row.haul_signature.as_deref(), "haul")?;
    validate_library_selection(ctx, row.player_id, row.waters_signature.as_deref(), "waters")?;
    Ok(())
}

#[spacetimedb::reducer]
pub fn activate_tideforge_effects(ctx: &ReducerContext) -> Result<(), String> {
    if ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_REQUIRED")?
        .identity
        != ctx.sender()
    {
        return Err("OWNER_REQUIRED".into());
    }
    if enabled(ctx)? {
        return Ok(());
    }
    if !gear_instances::enabled(ctx)? {
        return Err("GEAR_FOUNDATION_UNAVAILABLE".into());
    }
    if rules::EFFECTS_VERSION != EFFECTS_VERSION || rules::MASTERY_CURVE_VERSION != CURVE_VERSION {
        return Err("TIDEFORGE_VERSION_UNSUPPORTED".into());
    }
    rules::validate_catalog().map_err(str::to_owned)?;
    for instance in ctx.db.gear_instance().iter() {
        validate_instance(ctx, &instance, instance.player_id)?;
    }
    for row in ctx.db.tideforge_library().iter() {
        validate_library_row(ctx, &row)?;
    }
    for row in ctx.db.tideforge_loadout().iter() {
        validate_loadout(ctx, &row)?;
    }
    for row in ctx.db.tideforge_gear().iter() {
        let instance = ctx
            .db
            .gear_instance()
            .instance_id()
            .find(row.instance_id)
            .ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
        validate_instance(ctx, &instance, row.player_id)?;
        let progress = family_progress(ctx, row.player_id, &instance.kind, instance.family_id)?;
        let affixes = parse_affixes(ctx, &row.affixes_json)?;
        validate_native_signature(&instance.kind, row.native_signature.as_deref())?;
        rules::compose(ComposeInput {
            kind: &instance.kind,
            biome_id: instance.primary_biome_id,
            primary_biome_id: instance.primary_biome_id,
            mastery_xp: progress.mastery_xp,
            affixes: &affixes,
            native_signature: row.native_signature.as_deref(),
            kind_signature: None,
            waters_signature: None,
            goal: "ranks",
        })
        .map_err(str::to_owned)?;
    }
    for mut row in ctx.db.gear_family_progress().iter() {
        let checked = family_progress(ctx, row.player_id, &row.kind, row.family_id)?;
        if checked.key != row.key {
            return Err("TIDEFORGE_FAMILY_INVALID".into());
        }
        if row.curve_version == 0 {
            row.curve_version = CURVE_VERSION;
            ctx.db.gear_family_progress().key().update(row);
        }
    }
    ctx.db.tideforge_state().insert(TideforgeState {
        singleton: 1,
        version: EFFECTS_VERSION,
        catalog_version: CATALOG_VERSION,
        curve_version: CURVE_VERSION,
        activated_at: ctx.timestamp,
    });
    Ok(())
}

fn set_goal(ctx: &ReducerContext, player_id: u64, goal: String) -> Result<(), String> {
    require_enabled(ctx)?;
    require_player(ctx, player_id)?;
    if !valid_goal(&goal) {
        return Err("TIDEFORGE_GOAL_INVALID".into());
    }
    match ctx.db.tideforge_loadout().player_id().find(player_id) {
        Some(mut row) => {
            validate_loadout(ctx, &row)?;
            if row.goal != goal {
                row.goal = goal;
                ctx.db.tideforge_loadout().player_id().update(row);
            }
        }
        None => {
            let mut row = default_loadout(player_id);
            row.goal = goal;
            ctx.db.tideforge_loadout().insert(row);
        }
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn set_tideforge_goal(ctx: &ReducerContext, goal: String) -> Result<(), String> {
    set_goal(ctx, crate::shop::browser_player(ctx)?, goal)
}

#[spacetimedb::reducer]
pub fn set_tideforge_goal_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    goal: String,
) -> Result<(), String> {
    set_goal(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        goal,
    )
}

fn select_signatures(
    ctx: &ReducerContext,
    player_id: u64,
    rod_signature: Option<String>,
    haul_signature: Option<String>,
    waters_signature: Option<String>,
) -> Result<(), String> {
    require_enabled(ctx)?;
    require_player(ctx, player_id)?;
    validate_library_selection(ctx, player_id, rod_signature.as_deref(), "rod")?;
    validate_library_selection(ctx, player_id, haul_signature.as_deref(), "haul")?;
    validate_library_selection(ctx, player_id, waters_signature.as_deref(), "waters")?;
    let existing = ctx.db.tideforge_loadout().player_id().find(player_id);
    let exists = existing.is_some();
    let mut row = existing.unwrap_or_else(|| default_loadout(player_id));
    validate_loadout(ctx, &row)?;
    let changed = row.rod_signature != rod_signature
        || row.haul_signature != haul_signature
        || row.waters_signature != waters_signature;
    if changed && crate::raids::boat_locked_by_raid(ctx, player_id) {
        return Err("RAID_EQUIPMENT_LOCKED".into());
    }
    row.rod_signature = rod_signature;
    row.haul_signature = haul_signature;
    row.waters_signature = waters_signature;
    if !exists {
        ctx.db.tideforge_loadout().insert(row);
    } else if changed {
        ctx.db.tideforge_loadout().player_id().update(row);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn select_tideforge_signatures(
    ctx: &ReducerContext,
    rod_signature: Option<String>,
    haul_signature: Option<String>,
    waters_signature: Option<String>,
) -> Result<(), String> {
    select_signatures(
        ctx,
        crate::shop::browser_player(ctx)?,
        rod_signature,
        haul_signature,
        waters_signature,
    )
}

#[spacetimedb::reducer]
pub fn select_tideforge_signatures_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    rod_signature: Option<String>,
    haul_signature: Option<String>,
    waters_signature: Option<String>,
) -> Result<(), String> {
    select_signatures(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        rod_signature,
        haul_signature,
        waters_signature,
    )
}

#[spacetimedb::view(accessor = my_tideforge_gear, public)]
pub fn my_tideforge_gear(ctx: &ViewContext) -> Vec<TideforgeGear> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_gear().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_gear, public)]
pub fn adapter_tideforge_gear(ctx: &ViewContext) -> Vec<TideforgeGear> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_gear().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_library, public)]
pub fn my_tideforge_library(ctx: &ViewContext) -> Vec<TideforgeLibrary> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_library().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_library, public)]
pub fn adapter_tideforge_library(ctx: &ViewContext) -> Vec<TideforgeLibrary> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_library().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_loadout, public)]
pub fn my_tideforge_loadout(ctx: &ViewContext) -> Option<TideforgeLoadout> {
    if !view_enabled(ctx) {
        return None;
    }
    ctx.db.tideforge_loadout().player_id().find(current_player(ctx)?)
}

#[spacetimedb::view(accessor = adapter_tideforge_loadout, public)]
pub fn adapter_tideforge_loadout(ctx: &ViewContext) -> Option<TideforgeLoadout> {
    if !view_enabled(ctx) {
        return None;
    }
    ctx.db
        .tideforge_loadout()
        .player_id()
        .find(crate::shop::selected(ctx)?)
}

#[spacetimedb::view(accessor = my_tideforge_activity_snapshots, public)]
pub fn my_tideforge_activity_snapshots(ctx: &ViewContext) -> Vec<TideforgeActivitySnapshot> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_activity_snapshot().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_activity_snapshots, public)]
pub fn adapter_tideforge_activity_snapshots(ctx: &ViewContext) -> Vec<TideforgeActivitySnapshot> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_activity_snapshot().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_fractional_credits, public)]
pub fn my_tideforge_fractional_credits(ctx: &ViewContext) -> Vec<TideforgeFractionalCredit> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_fractional_credit().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_fractional_credits, public)]
pub fn adapter_tideforge_fractional_credits(ctx: &ViewContext) -> Vec<TideforgeFractionalCredit> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_fractional_credit().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_daily_uses, public)]
pub fn my_tideforge_daily_uses(ctx: &ViewContext) -> Vec<TideforgeDailyUse> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_daily_use().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_daily_uses, public)]
pub fn adapter_tideforge_daily_uses(ctx: &ViewContext) -> Vec<TideforgeDailyUse> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_daily_use().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_activity_receipts, public)]
pub fn my_tideforge_activity_receipts(ctx: &ViewContext) -> Vec<TideforgeActivityReceipt> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_activity_receipt().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_activity_receipts, public)]
pub fn adapter_tideforge_activity_receipts(ctx: &ViewContext) -> Vec<TideforgeActivityReceipt> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_activity_receipt().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_specimen_modifiers, public)]
pub fn my_tideforge_specimen_modifiers(ctx: &ViewContext) -> Vec<TideforgeSpecimenModifiers> {
    if !view_enabled(ctx) {
        return vec![];
    }
    current_player(ctx)
        .map(|id| ctx.db.tideforge_specimen_modifiers().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_specimen_modifiers, public)]
pub fn adapter_tideforge_specimen_modifiers(ctx: &ViewContext) -> Vec<TideforgeSpecimenModifiers> {
    if !view_enabled(ctx) {
        return vec![];
    }
    crate::shop::selected(ctx)
        .map(|id| ctx.db.tideforge_specimen_modifiers().player_id().filter(id).collect())
        .unwrap_or_default()
}

fn activity_key(activity_kind: &str, activity_id: u128) -> String {
    format!("{activity_kind}:{activity_id}")
}

pub(crate) fn lookup(
    ctx: &ReducerContext,
    player_id: u64,
    activity_kind: &str,
    activity_id: u128,
) -> Result<Option<TideforgeActivitySnapshot>, String> {
    let key = activity_key(activity_kind, activity_id);
    let Some(row) = ctx.db.tideforge_activity_snapshot().key().find(&key) else {
        return Ok(None);
    };
    if row.key != key
        || row.player_id != player_id
        || row.activity_kind != activity_kind
        || row.activity_id != activity_id
    {
        return Err("TIDEFORGE_ACTIVITY_CONFLICT".into());
    }
    Ok(Some(row))
}

pub(crate) fn book(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    activity_kind: &str,
    activity_id: u128,
    biome_id: u32,
) -> Result<Option<TideforgeActivitySnapshot>, String> {
    if !enabled(ctx)? {
        return Ok(None);
    }
    if let Some(existing) = lookup(ctx, player_id, activity_kind, activity_id)? {
        return Ok(Some(existing));
    }
    if !valid_kind(kind) {
        return Err("TIDEFORGE_GEAR_INVALID".into());
    }
    if ctx.db.biome_definition().biome_id().find(biome_id).is_none() {
        return Err("TIDEFORGE_BIOME_UNAVAILABLE".into());
    }
    let instance = gear_instances::selected_instance(ctx, player_id, kind)?
        .ok_or("GEAR_FOUNDATION_UNAVAILABLE")?;
    let mut progress = family_progress(ctx, player_id, kind, instance.family_id)?;
    let loadout = ctx
        .db
        .tideforge_loadout()
        .player_id()
        .find(player_id)
        .unwrap_or_else(|| default_loadout(player_id));
    validate_loadout(ctx, &loadout)?;
    let (affixes, native_signature) = match ctx
        .db
        .tideforge_gear()
        .instance_id()
        .find(instance.instance_id)
    {
        Some(row) => {
            if row.player_id != player_id || row.instance_id != instance.instance_id {
                return Err("TIDEFORGE_GEAR_INVALID".into());
            }
            validate_native_signature(kind, row.native_signature.as_deref())?;
            (parse_affixes(ctx, &row.affixes_json)?, row.native_signature)
        }
        None => (Vec::new(), None),
    };
    let kind_signature = if kind == "rod" {
        loadout.rod_signature.as_deref()
    } else {
        match loadout.haul_signature.as_deref() {
            Some(signature) if rules::signature_kind(signature).map_err(str::to_owned)? == kind => {
                Some(signature)
            }
            _ => None,
        }
    };
    let effects = rules::compose(ComposeInput {
        kind,
        biome_id,
        primary_biome_id: instance.primary_biome_id,
        mastery_xp: progress.mastery_xp,
        affixes: &affixes,
        native_signature: native_signature.as_deref(),
        kind_signature,
        waters_signature: loadout.waters_signature.as_deref(),
        goal: &loadout.goal,
    })
    .map_err(str::to_owned)?;
    if effects.version != EFFECTS_VERSION
        || effects.kind != kind
        || effects.goal != loadout.goal
    {
        return Err("TIDEFORGE_EFFECTS_INVALID".into());
    }
    let effects_json = serde_json::to_string(&effects).map_err(|_| "TIDEFORGE_EFFECTS_INVALID")?;
    let snapshot = TideforgeActivitySnapshot {
        key: activity_key(activity_kind, activity_id),
        player_id,
        activity_kind: activity_kind.into(),
        activity_id,
        gear_instance_id: instance.instance_id,
        gear_revision: instance.revision,
        family_id: instance.family_id,
        gear_kind: kind.into(),
        biome_id,
        effects_json,
        catalog_version: CATALOG_VERSION,
        mastery_curve_version: CURVE_VERSION,
        mastery_xp_at_booking: progress.mastery_xp,
        mastery_level: rules::mastery_level(progress.mastery_xp),
        base_mastery_xp: rules::BASE_ACTIVITY_XP,
        booked_at: ctx.timestamp,
    };
    if progress.curve_version == 0 {
        progress.curve_version = CURVE_VERSION;
        ctx.db.gear_family_progress().key().update(progress);
    }
    ctx.db.tideforge_activity_snapshot().insert(snapshot.clone());
    Ok(Some(snapshot))
}

pub(crate) fn decode(snapshot: &TideforgeActivitySnapshot) -> Result<Effects, String> {
    let effects: Effects =
        serde_json::from_str(&snapshot.effects_json).map_err(|_| "TIDEFORGE_EFFECTS_INVALID")?;
    if effects.version != EFFECTS_VERSION
        || !valid_kind(&snapshot.gear_kind)
        || effects.kind != snapshot.gear_kind
        || !valid_goal(&effects.goal)
    {
        return Err("TIDEFORGE_EFFECTS_INVALID".into());
    }
    Ok(effects)
}

fn stored_snapshot(
    ctx: &ReducerContext,
    snapshot: &TideforgeActivitySnapshot,
) -> Result<TideforgeActivitySnapshot, String> {
    let row = lookup(ctx, snapshot.player_id, &snapshot.activity_kind, snapshot.activity_id)?
        .ok_or("TIDEFORGE_SNAPSHOT_UNAVAILABLE")?;
    if row.key != snapshot.key
        || row.gear_instance_id != snapshot.gear_instance_id
        || row.gear_revision != snapshot.gear_revision
        || row.family_id != snapshot.family_id
        || row.gear_kind != snapshot.gear_kind
        || row.biome_id != snapshot.biome_id
        || row.effects_json != snapshot.effects_json
        || row.catalog_version != snapshot.catalog_version
        || row.mastery_curve_version != snapshot.mastery_curve_version
        || row.mastery_xp_at_booking != snapshot.mastery_xp_at_booking
        || row.mastery_level != snapshot.mastery_level
        || row.base_mastery_xp != snapshot.base_mastery_xp
        || row.booked_at != snapshot.booked_at
    {
        return Err("TIDEFORGE_SNAPSHOT_CONFLICT".into());
    }
    Ok(row)
}

pub(crate) fn take_daily(
    ctx: &ReducerContext,
    snapshot: &TideforgeActivitySnapshot,
    signature: &str,
) -> Result<bool, String> {
    if !matches!(
        signature,
        "tidal_memory" | "atlas_of_seven_waters" | "oceans_memory" | "rift_current"
    ) {
        return Err("TIDEFORGE_DAILY_SIGNATURE_INVALID".into());
    }
    let snapshot = stored_snapshot(ctx, snapshot)?;
    if signature == "tidal_memory" && snapshot.gear_kind != "rod" {
        return Err("TIDEFORGE_DAILY_SIGNATURE_INVALID".into());
    }
    if !decode(&snapshot)?.has(signature) {
        return Err("TIDEFORGE_SIGNATURE_INACTIVE".into());
    }
    let utc_day = ctx.timestamp.to_micros_since_unix_epoch().div_euclid(86_400_000_000);
    let key = format!("{}:{}:{utc_day}:{signature}", snapshot.player_id, snapshot.biome_id);
    if let Some(row) = ctx.db.tideforge_daily_use().key().find(&key) {
        if row.player_id != snapshot.player_id
            || row.biome_id != snapshot.biome_id
            || row.utc_day != utc_day
            || row.signature_id != signature
            || row.key != key
        {
            return Err("TIDEFORGE_DAILY_USE_INVALID".into());
        }
        return Ok(false);
    }
    ctx.db.tideforge_daily_use().insert(TideforgeDailyUse {
        key,
        player_id: snapshot.player_id,
        biome_id: snapshot.biome_id,
        utc_day,
        signature_id: signature.into(),
        activity_key: snapshot.key,
    });
    Ok(true)
}

fn valid_resource(resource: &str) -> bool {
    if matches!(
        resource,
        "rusted_tin" | "scrap" | "normal_fillets" | "normal_scales" | "player_xp"
    ) {
        return true;
    }
    let Some(rest) = resource.strip_prefix("mastery:") else {
        return false;
    };
    let Some((kind, family)) = rest.split_once(':') else {
        return false;
    };
    if !valid_kind(kind) {
        return false;
    }
    family
        .parse::<u32>()
        .ok()
        .is_some_and(|id| id > 0 && id.to_string() == family)
}

pub(crate) fn credit(
    ctx: &ReducerContext,
    player_id: u64,
    resource: &str,
    amount: u64,
    rate_bp: u32,
) -> Result<u64, String> {
    require_player(ctx, player_id)?;
    if !valid_resource(resource) {
        return Err("TIDEFORGE_RESOURCE_INVALID".into());
    }
    let key = format!("{player_id}:{resource}");
    let existing = ctx.db.tideforge_fractional_credit().key().find(&key);
    let remainder = if let Some(row) = &existing {
        if row.key != key
            || row.player_id != player_id
            || row.resource != resource
            || row.remainder_bp >= 10_000
        {
            return Err("TIDEFORGE_CREDIT_INVALID".into());
        }
        row.remainder_bp
    } else {
        0
    };
    let (value, next_remainder) =
        rules::scale_credit(amount, rate_bp, remainder).map_err(str::to_owned)?;
    match existing {
        Some(mut row) if row.remainder_bp != next_remainder => {
            row.remainder_bp = next_remainder;
            ctx.db.tideforge_fractional_credit().key().update(row);
        }
        None if next_remainder != 0 => {
            ctx.db.tideforge_fractional_credit().insert(TideforgeFractionalCredit {
                key,
                player_id,
                resource: resource.into(),
                remainder_bp: next_remainder,
            });
        }
        _ => {}
    }
    Ok(value)
}

pub(crate) fn award_mastery(
    ctx: &ReducerContext,
    snapshot: &TideforgeActivitySnapshot,
    ordinary_fish: bool,
) -> Result<u64, String> {
    let key = activity_key(&snapshot.activity_kind, snapshot.activity_id);
    if snapshot.key != key {
        return Err("TIDEFORGE_ACTIVITY_CONFLICT".into());
    }
    if let Some(receipt) = ctx.db.tideforge_activity_receipt().key().find(&key) {
        if receipt.key != key
            || receipt.player_id != snapshot.player_id
            || receipt.activity_kind != snapshot.activity_kind
            || receipt.activity_id != snapshot.activity_id
            || receipt.gear_instance_id != snapshot.gear_instance_id
            || receipt.gear_kind != snapshot.gear_kind
            || receipt.family_id != snapshot.family_id
        {
            return Err("TIDEFORGE_ACTIVITY_CONFLICT".into());
        }
        return Ok(0);
    }
    let snapshot = stored_snapshot(ctx, snapshot)?;
    let effects = decode(&snapshot)?;
    let mut progress =
        family_progress(ctx, snapshot.player_id, &snapshot.gear_kind, snapshot.family_id)?;
    let mut modifier = effects.stat("masteryXpBp");
    if ordinary_fish
        && effects.has("atlas_of_seven_waters")
        && take_daily(ctx, &snapshot, "atlas_of_seven_waters")?
    {
        modifier = modifier
            .checked_add(effects.parameter("atlas_of_seven_waters", "dailyBaseMasteryBp"))
            .ok_or("TIDEFORGE_MASTERY_OVERFLOW")?;
    }
    if ordinary_fish
        && snapshot.gear_kind == "rod"
        && effects.has("tidal_memory")
        && take_daily(ctx, &snapshot, "tidal_memory")?
    {
        modifier = modifier
            .checked_add(effects.parameter("tidal_memory", "dailyBaseMasteryBp"))
            .ok_or("TIDEFORGE_MASTERY_OVERFLOW")?;
    }
    let rate = 10_000u32
        .checked_add(modifier)
        .ok_or("TIDEFORGE_MASTERY_OVERFLOW")?;
    let resource = format!("mastery:{}:{}", snapshot.gear_kind, snapshot.family_id);
    let granted = credit(ctx, snapshot.player_id, &resource, snapshot.base_mastery_xp, rate)?;
    progress.mastery_xp = progress
        .mastery_xp
        .checked_add(granted)
        .ok_or("TIDEFORGE_MASTERY_OVERFLOW")?;
    progress.curve_version = CURVE_VERSION;
    ctx.db.gear_family_progress().key().update(progress);
    ctx.db.tideforge_activity_receipt().insert(TideforgeActivityReceipt {
        key,
        player_id: snapshot.player_id,
        activity_kind: snapshot.activity_kind,
        activity_id: snapshot.activity_id,
        gear_instance_id: snapshot.gear_instance_id,
        gear_kind: snapshot.gear_kind,
        family_id: snapshot.family_id,
        mastery_xp_granted: granted,
        settled_at: ctx.timestamp,
    });
    Ok(granted)
}

pub(crate) fn delete_activity(
    ctx: &ReducerContext,
    activity_kind: &str,
    activity_id: u128,
) {
    let key = activity_key(activity_kind, activity_id);
    ctx.db.tideforge_activity_snapshot().key().delete(&key);
    ctx.db.tideforge_activity_receipt().key().delete(&key);
}
