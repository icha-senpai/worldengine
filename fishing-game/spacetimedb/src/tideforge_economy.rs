use crate::{
    accounts::current_player,
    gear_instances::{self, gear_activity_snapshot, gear_instance, gear_instance_name, gear_loadout, GearInstance},
    hauls::haul_trip,
    raids::{self, raid_attempt},
    tables::*,
    tideforge::{self, tideforge_fractional_credit, tideforge_gear, tideforge_library,
        tideforge_specimen_modifiers, TideforgeGear, TideforgeLibrary},
};
use game_rules::{tideforge as effects, tideforge_economy as rules};
use serde::{Deserialize, Serialize};
use spacetimedb::{Identity, ReducerContext, Table, TimeDuration, Timestamp, ViewContext, rand::Rng};
use std::collections::{BTreeMap, BTreeSet};

#[spacetimedb::table(accessor = tideforge_economy_state, public)]
pub struct TideforgeEconomyState {
    #[primary_key]
    pub singleton: u8,
    pub version: u32,
    pub catalog_version: u32,
    pub activated_at: Timestamp,
}

#[spacetimedb::table(accessor = tideforge_economy_wallet)]
pub struct TideforgeEconomyWallet {
    #[primary_key]
    pub player_id: u64,
    pub marks: u64,
    pub next_sequence: u64,
    pub revision: u64,
}

#[spacetimedb::table(accessor = tideforge_affix_shard)]
pub struct TideforgeAffixShard {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub affix_id: String,
    pub count: u64,
}

#[spacetimedb::table(accessor = tideforge_codex)]
pub struct TideforgeCodex {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub affix_id: String,
    pub highest_tier: u8,
    pub best_finish_bp: u16,
    pub natural_count: u64,
}

#[spacetimedb::table(accessor = tideforge_economy_quote)]
pub struct TideforgeEconomyQuote {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    #[index(btree)]
    pub player_id: u64,
    pub nonce: u128,
    pub intent_json: String,
    pub preview_json: String,
    pub catalog_version: u32,
    pub created_at: Timestamp,
    pub expires_at: Timestamp,
    pub consumed: bool,
}

#[spacetimedb::table(accessor = tideforge_economy_receipt)]
pub struct TideforgeEconomyReceipt {
    #[primary_key]
    pub key: String,
    pub identity: Identity,
    #[index(btree)]
    pub player_id: u64,
    pub nonce: u128,
    pub action: String,
    pub summary_json: String,
    pub created_at: Timestamp,
    #[index(btree)]
    pub prune_at: Timestamp,
}

#[spacetimedb::table(accessor = tideforge_mark_award)]
pub struct TideforgeMarkAward {
    #[primary_key]
    pub key: String,
    #[index(btree)]
    pub player_id: u64,
    pub source: String,
    pub source_id: String,
    pub amount: u64,
    pub created_at: Timestamp,
    pub prune_at: Option<Timestamp>,
}

#[spacetimedb::table(accessor = tideforge_raid_mark_booking)]
pub struct TideforgeRaidMarkBooking {
    #[primary_key]
    pub attempt_id: u128,
    #[index(btree)]
    pub player_id: u64,
    pub grade: u8,
    pub marks: u64,
    pub catalog_version: u32,
    pub booked_at: Timestamp,
    pub settled: bool,
}

pub(crate) fn enabled(ctx: &ReducerContext) -> Result<bool, String> {
    match ctx.db.tideforge_economy_state().singleton().find(1) {
        None => Ok(false),
        Some(row) if row.version == rules::VERSION && row.catalog_version == rules::VERSION => Ok(true),
        Some(_) => Err("TIDEFORGE_ECONOMY_VERSION_UNSUPPORTED".into()),
    }
}

fn require_enabled(ctx: &ReducerContext) -> Result<(), String> {
    if !enabled(ctx)? {
        return Err("TIDEFORGE_ECONOMY_UNAVAILABLE".into());
    }
    if !gear_instances::enabled(ctx)? || !tideforge::enabled(ctx)? {
        return Err("TIDEFORGE_ECONOMY_DEPENDENCIES_UNAVAILABLE".into());
    }
    rules::catalog().map_err(str::to_owned)?;
    Ok(())
}

#[spacetimedb::reducer]
pub fn activate_tideforge_economy(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    if enabled(ctx)? {
        return Ok(());
    }
    if !gear_instances::enabled(ctx)? || !tideforge::enabled(ctx)? {
        return Err("TIDEFORGE_ECONOMY_DEPENDENCIES_UNAVAILABLE".into());
    }
    let catalog = rules::catalog().map_err(str::to_owned)?;
    ctx.db.tideforge_economy_state().insert(TideforgeEconomyState {
        singleton: 1, version: rules::VERSION, catalog_version: catalog.version, activated_at: ctx.timestamp,
    });
    Ok(())
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
enum Intent {
    CampCopy { kind: String, definition_id: u32, secondary_biome_id: u32 },
    WanderingGamble { kind: String, definition_id: u32, secondary_biome_id: u32 },
    AddAffix { instance_id: String, affix_id: String, secondary_biome_id: Option<u32> },
    UpgradeAffix { instance_id: String, slot: u8, target_tier: u8 },
    RefineAffix { instance_id: String, slot: u8 },
    ChangeAffix { instance_id: String, slot: u8, affix_id: String, secondary_biome_id: Option<u32> },
    Dismantle { instance_id: String },
    ExtractSignature { instance_id: String },
    Fillet { catch_ids: Vec<String>, allow_trophies: bool },
    ConvertNormal { material_id: u8, batches: u64 },
    RefineMaterial { material_id: u8, from_grade: u8, to_grade: u8, batches: u64 },
}

fn canonical_u128(value: &str) -> Result<u128, String> {
    let id: u128 = value.parse().map_err(|_| "TIDEFORGE_ID_INVALID")?;
    if id == 0 || id.to_string() != value {
        return Err("TIDEFORGE_ID_INVALID".into());
    }
    Ok(id)
}

fn parse_intent(value: &str) -> Result<Intent, String> {
    if value.len() > 4096 {
        return Err("TIDEFORGE_INTENT_INVALID".into());
    }
    serde_json::from_str(value).map_err(|_| "TIDEFORGE_INTENT_INVALID".into())
}

fn quote_key(identity: Identity, player_id: u64) -> String {
    format!("{identity}:{player_id}")
}

fn receipt_key(identity: Identity, player_id: u64, nonce: u128) -> String {
    format!("{identity}:{player_id}:{nonce}")
}

#[spacetimedb::view(accessor = my_tideforge_economy_wallet, public)]
pub fn my_tideforge_economy_wallet(ctx: &ViewContext) -> Option<TideforgeEconomyWallet> {
    ctx.db.tideforge_economy_wallet().player_id().find(current_player(ctx)?)
}

#[spacetimedb::view(accessor = adapter_tideforge_economy_wallet, public)]
pub fn adapter_tideforge_economy_wallet(ctx: &ViewContext) -> Option<TideforgeEconomyWallet> {
    ctx.db.tideforge_economy_wallet().player_id().find(crate::shop::selected(ctx)?)
}

#[spacetimedb::view(accessor = my_tideforge_vault, public)]
pub fn my_tideforge_vault(ctx: &ViewContext) -> Vec<TideforgeAffixShard> {
    current_player(ctx).map(|id| ctx.db.tideforge_affix_shard().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_vault, public)]
pub fn adapter_tideforge_vault(ctx: &ViewContext) -> Vec<TideforgeAffixShard> {
    crate::shop::selected(ctx).map(|id| ctx.db.tideforge_affix_shard().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_codex, public)]
pub fn my_tideforge_codex(ctx: &ViewContext) -> Vec<TideforgeCodex> {
    current_player(ctx).map(|id| ctx.db.tideforge_codex().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_codex, public)]
pub fn adapter_tideforge_codex(ctx: &ViewContext) -> Vec<TideforgeCodex> {
    crate::shop::selected(ctx).map(|id| ctx.db.tideforge_codex().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_economy_quote, public)]
pub fn my_tideforge_economy_quote(ctx: &ViewContext) -> Option<TideforgeEconomyQuote> {
    ctx.db.tideforge_economy_quote().key().find(quote_key(ctx.sender(), current_player(ctx)?))
}

#[spacetimedb::view(accessor = adapter_tideforge_economy_quote, public)]
pub fn adapter_tideforge_economy_quote(ctx: &ViewContext) -> Option<TideforgeEconomyQuote> {
    ctx.db.tideforge_economy_quote().key().find(quote_key(ctx.sender(), crate::shop::selected(ctx)?))
}

#[spacetimedb::view(accessor = my_tideforge_economy_receipts, public)]
pub fn my_tideforge_economy_receipts(ctx: &ViewContext) -> Vec<TideforgeEconomyReceipt> {
    current_player(ctx).map(|id| ctx.db.tideforge_economy_receipt().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_economy_receipts, public)]
pub fn adapter_tideforge_economy_receipts(ctx: &ViewContext) -> Vec<TideforgeEconomyReceipt> {
    crate::shop::selected(ctx).map(|id| ctx.db.tideforge_economy_receipt().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_mark_awards, public)]
pub fn my_tideforge_mark_awards(ctx: &ViewContext) -> Vec<TideforgeMarkAward> {
    current_player(ctx).map(|id| ctx.db.tideforge_mark_award().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_mark_awards, public)]
pub fn adapter_tideforge_mark_awards(ctx: &ViewContext) -> Vec<TideforgeMarkAward> {
    crate::shop::selected(ctx).map(|id| ctx.db.tideforge_mark_award().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = my_tideforge_raid_mark_bookings, public)]
pub fn my_tideforge_raid_mark_bookings(ctx: &ViewContext) -> Vec<TideforgeRaidMarkBooking> {
    current_player(ctx).map(|id| ctx.db.tideforge_raid_mark_booking().player_id().filter(id).collect()).unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_tideforge_raid_mark_bookings, public)]
pub fn adapter_tideforge_raid_mark_bookings(ctx: &ViewContext) -> Vec<TideforgeRaidMarkBooking> {
    crate::shop::selected(ctx).map(|id| ctx.db.tideforge_raid_mark_booking().player_id().filter(id).collect()).unwrap_or_default()
}

fn wallet(ctx: &ReducerContext, player_id: u64) -> Result<TideforgeEconomyWallet, String> {
    if ctx.db.player().player_id().find(player_id).is_none() {
        return Err("PLAYER_UNAVAILABLE".into());
    }
    let mut maximum = 0u64;
    for instance in ctx.db.gear_instance().player_id().filter(player_id) {
        if instance.instance_id >> 64 != u128::from(player_id) {
            return Err("TIDEFORGE_COPY_ID_INVALID".into());
        }
        let low = instance.instance_id as u64;
        if !instance.protected_original {
            if low & (1u64 << 63) == 0 || low & !(1u64 << 63) == 0 {
                return Err("TIDEFORGE_COPY_ID_INVALID".into());
            }
            maximum = maximum.max(low & !(1u64 << 63));
        }
    }
    let next = maximum.checked_add(1).ok_or("TIDEFORGE_COPY_ID_EXHAUSTED")?;
    let row = ctx.db.tideforge_economy_wallet().player_id().find(player_id)
        .unwrap_or(TideforgeEconomyWallet { player_id, marks: 0, next_sequence: next, revision: 0 });
    if row.player_id != player_id || row.next_sequence < next
        || row.next_sequence == 0 || row.next_sequence > 1u64 << 63
    {
        return Err("TIDEFORGE_COPY_ID_INVALID".into());
    }
    Ok(row)
}

fn save_wallet(ctx: &ReducerContext, row: TideforgeEconomyWallet) {
    if ctx.db.tideforge_economy_wallet().player_id().find(row.player_id).is_some() {
        ctx.db.tideforge_economy_wallet().player_id().update(row);
    } else {
        ctx.db.tideforge_economy_wallet().insert(row);
    }
}

fn checked_gear(ctx: &ReducerContext, player_id: u64, id: &str) -> Result<GearInstance, String> {
    let instance = ctx.db.gear_instance().instance_id().find(canonical_u128(id)?)
        .ok_or("GEAR_INSTANCE_UNAVAILABLE")?;
    tideforge::validate_instance(ctx, &instance, player_id)?;
    Ok(instance)
}

fn gear_affixes(ctx: &ReducerContext, instance: &GearInstance) -> Result<(Vec<effects::AffixRoll>, Option<String>), String> {
    let Some(row) = ctx.db.tideforge_gear().instance_id().find(instance.instance_id) else {
        return Ok((Vec::new(), None));
    };
    if row.player_id != instance.player_id {
        return Err("TIDEFORGE_GEAR_INVALID".into());
    }
    let affixes = tideforge::parse_affixes(ctx, &row.affixes_json)?;
    rules::validate_affix_set(&instance.kind, &affixes).map_err(str::to_owned)?;
    let waters: Vec<u32> = ctx.db.biome_definition().iter().map(|row| row.biome_id).collect();
    for roll in &affixes {
        if let Some(id) = roll.secondary_biome_id {
            if !waters.contains(&id) {
                return Err("TIDEFORGE_SECONDARY_BIOME_INVALID".into());
            }
        }
    }
    if let Some(id) = row.native_signature.as_deref() {
        let kind = effects::signature_kind(id).map_err(str::to_owned)?;
        if kind != instance.kind && kind != "waters" {
            return Err("TIDEFORGE_SIGNATURE_KIND_INVALID".into());
        }
    }
    Ok((affixes, row.native_signature))
}

fn ensure_editable(ctx: &ReducerContext, instance: &GearInstance, consume: bool) -> Result<(), String> {
    let loadout = ctx.db.gear_loadout().player_id().find(instance.player_id)
        .ok_or("GEAR_LOADOUT_UNAVAILABLE")?;
    let selected = [loadout.rod_instance_id, loadout.boat_instance_id, loadout.net_instance_id]
        .contains(&instance.instance_id);
    if consume && (instance.protected_original || instance.favorite || selected) {
        return Err("TIDEFORGE_GEAR_PROTECTED".into());
    }
    if selected && raids::boat_locked_by_raid(ctx, instance.player_id) {
        return Err("RAID_EQUIPMENT_LOCKED".into());
    }
    for snapshot in ctx.db.gear_activity_snapshot().player_id().filter(instance.player_id) {
        if snapshot.snapshot.instance_id != instance.instance_id {
            continue;
        }
        let active = match snapshot.activity_kind.as_str() {
            "haul" => u64::try_from(snapshot.activity_id).ok()
                .and_then(|id| ctx.db.haul_trip().interaction_id().find(id))
                .is_some_and(|row| row.status == "running"),
            "raid" => ctx.db.raid_attempt().id().find(snapshot.activity_id)
                .is_some_and(|row| matches!(row.status.as_str(), "prepared" | "deployed" | "running")),
            _ => false,
        };
        if active {
            return Err("TIDEFORGE_GEAR_IN_USE".into());
        }
    }
    Ok(())
}

fn stack_count(ctx: &ReducerContext, player_id: u64, slot: u32, name: &str) -> Result<u64, String> {
    let key = (u128::from(player_id) << 32) | u128::from(slot);
    match ctx.db.item_stack().key().find(key) {
        None => Ok(0),
        Some(row) if row.player_id == player_id && row.key == key && row.item == name => Ok(row.quantity),
        Some(_) => Err("ITEM_SLOT_MISMATCH".into()),
    }
}

fn shard_count(ctx: &ReducerContext, player_id: u64, affix_id: &str) -> Result<u64, String> {
    let key = format!("{player_id}:{affix_id}");
    match ctx.db.tideforge_affix_shard().key().find(&key) {
        None => Ok(0),
        Some(row) if row.player_id == player_id && row.key == key && row.affix_id == affix_id => Ok(row.count),
        Some(_) => Err("TIDEFORGE_SHARD_INVALID".into()),
    }
}

fn require_discovery(ctx: &ReducerContext, player_id: u64, affix_id: &str) -> Result<(), String> {
    let key = format!("{player_id}:{affix_id}");
    let row = ctx.db.tideforge_codex().key().find(&key).ok_or("TIDEFORGE_AFFIX_NOT_DISCOVERED")?;
    if row.player_id != player_id || row.affix_id != affix_id || row.key != key
        || !(1..=10).contains(&row.highest_tier) || row.best_finish_bp > 10_000 || row.natural_count == 0
    {
        return Err("TIDEFORGE_CODEX_INVALID".into());
    }
    Ok(())
}

fn adjust_shard(ctx: &ReducerContext, player_id: u64, affix_id: &str, delta: i64) -> Result<(), String> {
    let key = format!("{player_id}:{affix_id}");
    let count = shard_count(ctx, player_id, affix_id)?;
    let count = if delta < 0 {
        count.checked_sub(delta.unsigned_abs()).ok_or("TIDEFORGE_SHARDS_REQUIRED")?
    } else {
        count.checked_add(delta as u64).ok_or("TIDEFORGE_SHARD_OVERFLOW")?
    };
    let row = TideforgeAffixShard { key: key.clone(), player_id, affix_id: affix_id.into(), count };
    if ctx.db.tideforge_affix_shard().key().find(&key).is_some() {
        ctx.db.tideforge_affix_shard().key().update(row);
    } else {
        ctx.db.tideforge_affix_shard().insert(row);
    }
    Ok(())
}

fn save_gear_affixes(
    ctx: &ReducerContext,
    mut instance: GearInstance,
    affixes: &[effects::AffixRoll],
    signature: Option<String>,
) -> Result<(), String> {
    rules::validate_affix_set(&instance.kind, affixes).map_err(str::to_owned)?;
    instance.revision = instance.revision.checked_add(1).ok_or("GEAR_REVISION_OVERFLOW")?;
    let row = TideforgeGear {
        instance_id: instance.instance_id,
        player_id: instance.player_id,
        affixes_json: serde_json::to_string(affixes).map_err(|_| "TIDEFORGE_AFFIX_JSON_INVALID")?,
        native_signature: signature,
    };
    if ctx.db.tideforge_gear().instance_id().find(instance.instance_id).is_some() {
        ctx.db.tideforge_gear().instance_id().update(row);
    } else {
        ctx.db.tideforge_gear().insert(row);
    }
    ctx.db.gear_instance().instance_id().update(instance);
    Ok(())
}

fn record_natural(ctx: &ReducerContext, player_id: u64, affixes: &[effects::AffixRoll]) -> Result<(), String> {
    for affix in affixes {
        let key = format!("{player_id}:{}", affix.id);
        if let Some(mut row) = ctx.db.tideforge_codex().key().find(&key) {
            if row.player_id != player_id || row.affix_id != affix.id || row.key != key
                || !(1..=10).contains(&row.highest_tier) || row.best_finish_bp > 10_000 || row.natural_count == 0
            {
                return Err("TIDEFORGE_CODEX_INVALID".into());
            }
            if affix.tier > row.highest_tier {
                row.highest_tier = affix.tier;
                row.best_finish_bp = affix.finish_bp;
            } else if affix.tier == row.highest_tier {
                row.best_finish_bp = row.best_finish_bp.max(affix.finish_bp);
            }
            row.natural_count = row.natural_count.checked_add(1).ok_or("TIDEFORGE_CODEX_OVERFLOW")?;
            ctx.db.tideforge_codex().key().update(row);
        } else {
            ctx.db.tideforge_codex().insert(TideforgeCodex {
                key, player_id, affix_id: affix.id.clone(), highest_tier: affix.tier,
                best_finish_bp: affix.finish_bp, natural_count: 1,
            });
        }
    }
    Ok(())
}

pub(crate) fn award_marks(
    ctx: &ReducerContext,
    player_id: u64,
    source: &str,
    source_id: &str,
    amount: u64,
    prune_at: Option<Timestamp>,
) -> Result<bool, String> {
    if !enabled(ctx)? || amount == 0 {
        return Ok(false);
    }
    if !matches!(source, "raid" | "achievement") || source_id.is_empty() {
        return Err("TIDEFORGE_AWARD_INVALID".into());
    }
    let key = format!("{player_id}:{source}:{source_id}");
    if let Some(row) = ctx.db.tideforge_mark_award().key().find(&key) {
        if row.key != key || row.player_id != player_id || row.source != source || row.source_id != source_id
            || row.amount != amount || (source == "achievement" && row.prune_at.is_some())
        {
            return Err("TIDEFORGE_AWARD_CONFLICT".into());
        }
        return Ok(false);
    }
    let delta = i64::try_from(amount).map_err(|_| "TIDEFORGE_MARK_OVERFLOW")?;
    let mut row = wallet(ctx, player_id)?;
    row.marks = row.marks.checked_add(amount).ok_or("TIDEFORGE_MARK_OVERFLOW")?;
    row.revision = row.revision.checked_add(1).ok_or("TIDEFORGE_WALLET_OVERFLOW")?;
    save_wallet(ctx, row);
    ctx.db.tideforge_mark_award().insert(TideforgeMarkAward {
        key: key.clone(), player_id, source: source.into(), source_id: source_id.into(),
        amount, created_at: ctx.timestamp, prune_at,
    });
    crate::inventory::ledger(ctx, player_id, key, "tideforge_marks", delta, "tideforge_award");
    Ok(true)
}

pub(crate) fn award_achievement(ctx: &ReducerContext, player_id: u64, achievement_id: u32) -> Result<(), String> {
    if enabled(ctx)? {
        let amount = rules::catalog().map_err(str::to_owned)?.marks.new_achievement;
        award_marks(ctx, player_id, "achievement", &achievement_id.to_string(), amount, None)?;
    }
    Ok(())
}

pub(crate) fn book_raid(
    ctx: &ReducerContext,
    attempt_id: u128,
    player_id: u64,
    grade: u8,
) -> Result<(), String> {
    if !enabled(ctx)? {
        return Ok(());
    }
    let catalog = rules::catalog().map_err(str::to_owned)?;
    let marks = *catalog.marks.ordinary_raid_by_grade.get(usize::from(grade)).ok_or("INVALID_RAID_GRADE")?;
    if let Some(row) = ctx.db.tideforge_raid_mark_booking().attempt_id().find(attempt_id) {
        if row.player_id != player_id || row.grade != grade || row.marks != marks
            || row.catalog_version != catalog.version
        {
            return Err("TIDEFORGE_RAID_BOOKING_CONFLICT".into());
        }
        return Ok(());
    }
    ctx.db.tideforge_raid_mark_booking().insert(TideforgeRaidMarkBooking {
        attempt_id, player_id, grade, marks, catalog_version: catalog.version,
        booked_at: ctx.timestamp, settled: false,
    });
    Ok(())
}

pub(crate) fn settle_raid(ctx: &ReducerContext, attempt_id: u128, player_id: u64) -> Result<(), String> {
    let Some(mut booking) = ctx.db.tideforge_raid_mark_booking().attempt_id().find(attempt_id) else {
        return Ok(());
    };
    require_enabled(ctx)?;
    let catalog = rules::catalog().map_err(str::to_owned)?;
    let state = ctx.db.tideforge_economy_state().singleton().find(1).ok_or("TIDEFORGE_ECONOMY_UNAVAILABLE")?;
    let attempt = ctx.db.raid_attempt().id().find(attempt_id).ok_or("RAID_ATTEMPT_UNAVAILABLE")?;
    if booking.attempt_id != attempt_id || booking.player_id != player_id || booking.catalog_version != catalog.version
        || booking.marks == 0 || i64::try_from(booking.marks).is_err()
        || booking.booked_at < state.activated_at || attempt.player_id != player_id
        || attempt.kind != "personal" || attempt.grade != Some(booking.grade) || attempt.prepared_at != booking.booked_at
    {
        return Err("TIDEFORGE_RAID_BOOKING_CONFLICT".into());
    }
    if !booking.settled {
        let days = catalog.receipt_retention_days;
        let micros = i64::try_from(days).ok().and_then(|v| v.checked_mul(86_400_000_000))
            .ok_or("TIDEFORGE_TIME_INVALID")?;
        award_marks(ctx, player_id, "raid", &attempt_id.to_string(), booking.marks,
            Some(ctx.timestamp + TimeDuration::from_micros(micros)))?;
        booking.settled = true;
        ctx.db.tideforge_raid_mark_booking().attempt_id().update(booking);
    }
    Ok(())
}

pub(crate) fn forget_raid(ctx: &ReducerContext, attempt_id: u128, player_id: u64) {
    if ctx.db.raid_attempt().id().find(attempt_id).is_some() {
        return;
    }
    if let Some(booking) = ctx.db.tideforge_raid_mark_booking().attempt_id().find(attempt_id) {
        if booking.player_id != player_id {
            return;
        }
        let key = format!("{player_id}:raid:{attempt_id}");
        if ctx.db.tideforge_mark_award().key().find(&key)
            .is_some_and(|row| row.prune_at.is_some_and(|at| at <= ctx.timestamp))
        {
            ctx.db.tideforge_mark_award().key().delete(key);
        }
        ctx.db.tideforge_raid_mark_booking().attempt_id().delete(attempt_id);
    }
}

pub(crate) fn prune_history(ctx: &ReducerContext) -> Result<(), String> {
    if !enabled(ctx)? {
        return Ok(());
    }
    let limit = rules::catalog().map_err(str::to_owned)?.maximum_prune_rows;
    for receipt in ctx.db.tideforge_economy_receipt().prune_at().filter(..ctx.timestamp).take(limit) {
        ctx.db.tideforge_economy_receipt().key().delete(receipt.key);
    }
    Ok(())
}

#[derive(Clone, Serialize)]
struct MaterialChange {
    slot: u32,
    item: String,
    before: u64,
    consumed: u64,
    produced: u64,
    after: u64,
}

#[derive(Clone, Serialize)]
struct ShardChange {
    affix_id: String,
    before: u64,
    consumed: u64,
    produced: u64,
    after: u64,
}

enum Mutation {
    Purchase { original: GearInstance, secondary_biome_id: u32, wandering: bool },
    Gear {
        instance: GearInstance,
        affixes: Vec<effects::AffixRoll>,
        signature: Option<String>,
        consume: bool,
        extract: bool,
    },
    Fillet {
        catch_ids: Vec<u64>,
        credits: Vec<(u64, u32, u32)>,
    },
    Materials,
}

struct Plan {
    action: &'static str,
    preview: serde_json::Value,
    coins: u64,
    marks: u64,
    materials: Vec<MaterialChange>,
    shards: Vec<ShardChange>,
    mutation: Mutation,
}

fn material_change(
    ctx: &ReducerContext,
    player_id: u64,
    slot: u32,
    item: String,
    consumed: u64,
    produced: u64,
) -> Result<MaterialChange, String> {
    let before = stack_count(ctx, player_id, slot, &item)?;
    i64::try_from(consumed).map_err(|_| "ITEM_OVERFLOW")?;
    i64::try_from(produced).map_err(|_| "ITEM_OVERFLOW")?;
    let after = before.checked_sub(consumed).ok_or("RAID_MATERIALS_REQUIRED")?
        .checked_add(produced).ok_or("ITEM_OVERFLOW")?;
    Ok(MaterialChange { slot, item, before, consumed, produced, after })
}

fn shard_change(
    ctx: &ReducerContext,
    player_id: u64,
    affix_id: &str,
    consumed: u64,
    produced: u64,
) -> Result<ShardChange, String> {
    let before = shard_count(ctx, player_id, affix_id)?;
    let after = before.checked_sub(consumed).ok_or("TIDEFORGE_SHARDS_REQUIRED")?
        .checked_add(produced).ok_or("TIDEFORGE_SHARD_OVERFLOW")?;
    i64::try_from(consumed).map_err(|_| "TIDEFORGE_SHARD_OVERFLOW")?;
    i64::try_from(produced).map_err(|_| "TIDEFORGE_SHARD_OVERFLOW")?;
    Ok(ShardChange { affix_id: affix_id.into(), before, consumed, produced, after })
}

fn require_coins(ctx: &ReducerContext, player_id: u64, amount: u64) -> Result<(u64, u64), String> {
    i64::try_from(amount).map_err(|_| "COIN_OVERFLOW")?;
    let before = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?.coins;
    Ok((before, before.checked_sub(amount).ok_or("INSUFFICIENT_COINS")?))
}

fn gear_json(instance: &GearInstance, affixes: &[effects::AffixRoll], signature: &Option<String>) -> serde_json::Value {
    serde_json::json!({
        "instance_id": instance.instance_id.to_string(), "revision": instance.revision,
        "kind": instance.kind, "definition_id": instance.definition_id,
        "family_id": instance.family_id, "primary_biome_id": instance.primary_biome_id,
        "quality_level": instance.quality_level, "reinforcement_level": instance.reinforcement_level,
        "protected_original": instance.protected_original, "favorite": instance.favorite,
        "affixes": affixes, "native_signature": signature,
    })
}

fn grade_material_costs(
    ctx: &ReducerContext,
    player_id: u64,
    costs: BTreeMap<u8, (u64, u64)>,
) -> Result<Vec<MaterialChange>, String> {
    let mut result = Vec::new();
    for (grade, (fillets, scales)) in costs {
        raids::require_grade_clear(ctx, player_id, grade)?;
        for (material_id, quantity) in [(0, fillets), (1, scales)] {
            let (slot, item) = game_rules::raids::material(grade, material_id).map_err(str::to_owned)?;
            result.push(material_change(ctx, player_id, slot, item, quantity, 0)?);
        }
    }
    Ok(result)
}

fn update_roll(
    affixes: &mut Vec<effects::AffixRoll>,
    slot: u8,
    id: String,
    secondary: Option<u32>,
) -> Result<(), String> {
    let row = affixes.iter_mut().find(|row| row.slot == slot).ok_or("TIDEFORGE_AFFIX_SLOT_EMPTY")?;
    if row.id == id && row.secondary_biome_id == secondary {
        return Err("TIDEFORGE_AFFIX_NO_CHANGE".into());
    }
    if affixes.iter().any(|row| row.slot != slot && row.id == id) {
        return Err("TIDEFORGE_AFFIX_DUPLICATE".into());
    }
    let row = affixes.iter_mut().find(|row| row.slot == slot).ok_or("TIDEFORGE_AFFIX_SLOT_EMPTY")?;
    row.id = id;
    row.secondary_biome_id = secondary;
    Ok(())
}

fn plan_gear(ctx: &ReducerContext, player_id: u64, intent: &Intent) -> Result<Plan, String> {
    let id = match intent {
        Intent::AddAffix { instance_id, .. } | Intent::UpgradeAffix { instance_id, .. }
        | Intent::RefineAffix { instance_id, .. } | Intent::ChangeAffix { instance_id, .. }
        | Intent::Dismantle { instance_id } | Intent::ExtractSignature { instance_id } => instance_id,
        _ => return Err("TIDEFORGE_INTENT_INVALID".into()),
    };
    let instance = checked_gear(ctx, player_id, id)?;
    let consume = matches!(intent, Intent::Dismantle { .. } | Intent::ExtractSignature { .. });
    ensure_editable(ctx, &instance, consume)?;
    let (before, signature) = gear_affixes(ctx, &instance)?;
    let mut after = before.clone();
    let progress = tideforge::family_progress(ctx, player_id, &instance.kind, instance.family_id)?;
    let unlocked = effects::unlocked_slots(progress.mastery_xp);
    let waters: Vec<u32> = ctx.db.biome_definition().iter().map(|row| row.biome_id).collect();
    let catalog = rules::catalog().map_err(str::to_owned)?;
    let mut materials = Vec::new();
    let mut shards = Vec::new();
    let mut coins = 0;
    let mut extract = false;
    let action = match intent {
        Intent::AddAffix { affix_id, secondary_biome_id, .. } => {
            require_discovery(ctx, player_id, affix_id)?;
            rules::validate_secondary(affix_id, *secondary_biome_id, instance.primary_biome_id, &waters)
                .map_err(str::to_owned)?;
            let slot = (0..unlocked).find(|slot| !after.iter().any(|roll| roll.slot == *slot))
                .ok_or("TIDEFORGE_AFFIX_NO_SLOT")?;
            after.push(effects::AffixRoll {
                slot, id: affix_id.clone(), tier: 1,
                finish_bp: catalog.forge.new_affix_finish_bp, secondary_biome_id: *secondary_biome_id,
            });
            rules::validate_affix_set(&instance.kind, &after).map_err(str::to_owned)?;
            materials = grade_material_costs(ctx, player_id, rules::upgrade_cost(0, 1).map_err(str::to_owned)?)?;
            shards.push(shard_change(ctx, player_id, affix_id, catalog.forge.shards_per_addition, 0)?);
            "add_affix"
        }
        Intent::UpgradeAffix { slot, target_tier, .. } => {
            if *slot >= unlocked {
                return Err("TIDEFORGE_AFFIX_SLOT_LOCKED".into());
            }
            let row = after.iter_mut().find(|row| row.slot == *slot).ok_or("TIDEFORGE_AFFIX_SLOT_EMPTY")?;
            materials = grade_material_costs(ctx, player_id,
                rules::upgrade_cost(row.tier, *target_tier).map_err(str::to_owned)?)?;
            row.tier = *target_tier;
            "upgrade_affix"
        }
        Intent::RefineAffix { slot, .. } => {
            if *slot >= unlocked {
                return Err("TIDEFORGE_AFFIX_SLOT_LOCKED".into());
            }
            let row = after.iter_mut().find(|row| row.slot == *slot).ok_or("TIDEFORGE_AFFIX_SLOT_EMPTY")?;
            let (finish, price) = rules::refine_affix(&row.id, row.tier, row.finish_bp).map_err(str::to_owned)?;
            row.finish_bp = finish;
            coins = price;
            "refine_affix"
        }
        Intent::ChangeAffix { slot, affix_id, secondary_biome_id, .. } => {
            if *slot >= unlocked {
                return Err("TIDEFORGE_AFFIX_SLOT_LOCKED".into());
            }
            require_discovery(ctx, player_id, affix_id)?;
            rules::validate_secondary(affix_id, *secondary_biome_id, instance.primary_biome_id, &waters)
                .map_err(str::to_owned)?;
            update_roll(&mut after, *slot, affix_id.clone(), *secondary_biome_id)?;
            rules::validate_affix_set(&instance.kind, &after).map_err(str::to_owned)?;
            shards.push(shard_change(ctx, player_id, affix_id, catalog.forge.shards_per_change, 0)?);
            "change_affix"
        }
        Intent::Dismantle { .. } => {
            for roll in &before {
                shards.push(shard_change(ctx, player_id, &roll.id, 0, rules::dismantle_shards(roll.tier).map_err(str::to_owned)?)?);
            }
            "dismantle"
        }
        Intent::ExtractSignature { .. } => {
            let id = signature.as_deref().ok_or("TIDEFORGE_SIGNATURE_UNAVAILABLE")?;
            if ctx.db.tideforge_library().key().find(format!("{player_id}:{id}")).is_some() {
                return Err("TIDEFORGE_SIGNATURE_ALREADY_KNOWN".into());
            }
            extract = true;
            "extract_signature"
        }
        _ => return Err("TIDEFORGE_INTENT_INVALID".into()),
    };
    let (coins_before, coins_after) = require_coins(ctx, player_id, coins)?;
    let after_json = if consume {
        serde_json::Value::Null
    } else {
        let mut next = instance.clone();
        next.revision = next.revision.checked_add(1).ok_or("GEAR_REVISION_OVERFLOW")?;
        gear_json(&next, &after, &signature)
    };
    let preview = serde_json::json!({
        "action": action, "gear_before": gear_json(&instance, &before, &signature),
        "gear_after": after_json, "family_mastery_xp": progress.mastery_xp,
        "unlocked_slots": unlocked, "coins_before": coins_before, "coins_cost": coins,
        "coins_after": coins_after, "materials": materials, "shards": shards,
        "learned_signature": if extract { signature.clone() } else { None },
    });
    Ok(Plan {
        action, preview, coins, marks: 0, materials, shards,
        mutation: Mutation::Gear { instance, affixes: after, signature, consume, extract },
    })
}

fn plan_purchase(
    ctx: &ReducerContext,
    player_id: u64,
    kind: &str,
    definition_id: u32,
    secondary_biome_id: u32,
    wandering: bool,
) -> Result<Plan, String> {
    let original_id = gear_instances::original_id(player_id, kind, definition_id)?;
    let original = ctx.db.gear_instance().instance_id().find(original_id).ok_or("GEAR_ORIGINAL_REQUIRED")?;
    tideforge::validate_instance(ctx, &original, player_id)?;
    if !original.protected_original || original.kind != kind || original.definition_id != definition_id {
        return Err("GEAR_ORIGINAL_REQUIRED".into());
    }
    let waters: Vec<u32> = ctx.db.biome_definition().iter().map(|row| row.biome_id).collect();
    rules::validate_secondary("secondary_affinity", Some(secondary_biome_id), original.family_id, &waters)
        .map_err(str::to_owned)?;
    let price = rules::family_price(original.family_id).map_err(str::to_owned)?;
    let player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
    let listing = ctx.db.shop_listing().iter()
        .find(|row| row.kind == kind && row.target_id == definition_id && row.biome_id == original.family_id)
        .ok_or("SHOP_ITEM_UNAVAILABLE")?;
    let config = crate::accounts::config(ctx);
    let family = ctx.db.rod_definition().rod_id().find(original.family_id).ok_or("GEAR_FAMILY_UNAVAILABLE")?;
    let level = game_rules::progression::level_for_xp_for_version(player.total_xp, config.level_cap, config.version);
    if level < listing.minimum_level || level < family.minimum_level
    {
        return Err("SHOP_LEVEL_REQUIRED".into());
    }
    if !crate::shop::owns_licence(ctx, player_id, listing.previous_biome_id) {
        return Err("PREVIOUS_LICENCE_REQUIRED".into());
    }
    if wandering && rules::wandering_close(ctx.timestamp.to_micros_since_unix_epoch())
        .map_err(str::to_owned)?.is_none()
    {
        return Err("TIDEFORGE_WANDERING_CLOSED".into());
    }
    let wallet = wallet(ctx, player_id)?;
    let coins = if wandering { 0 } else { price.coins };
    let marks = if wandering { price.marks } else { 0 };
    let (coins_before, coins_after) = require_coins(ctx, player_id, coins)?;
    let marks_after = wallet.marks.checked_sub(marks).ok_or("TIDEFORGE_MARKS_REQUIRED")?;
    i64::try_from(marks).map_err(|_| "TIDEFORGE_MARK_OVERFLOW")?;
    wallet.revision.checked_add(1).ok_or("TIDEFORGE_WALLET_OVERFLOW")?;
    if wallet.next_sequence >= 1u64 << 63 {
        return Err("TIDEFORGE_COPY_ID_EXHAUSTED".into());
    }
    let c = rules::catalog().map_err(str::to_owned)?;
    let offer = if wandering { &c.acquisition.wandering } else { &c.acquisition.camp };
    let possible_tiers: Vec<_> = offer.tiers.iter().map(|row| serde_json::json!({
        "tier": row.tier, "weight": row.weight
    })).collect();
    let action = if wandering { "wandering_gamble" } else { "camp_copy" };
    let preview = serde_json::json!({
        "action": action, "kind": kind, "definition_id": definition_id,
        "original_id": original.instance_id.to_string(), "original_revision": original.revision,
        "family_id": original.family_id, "primary_biome_id": original.family_id,
        "secondary_biome_id": secondary_biome_id,
        "listing_catalog_version": listing.catalog_version, "minimum_level": listing.minimum_level,
        "family_minimum_level": family.minimum_level,
        "previous_biome_id": listing.previous_biome_id,
        "coins_before": coins_before, "coins_cost": coins, "coins_after": coins_after,
        "marks_before": wallet.marks, "marks_cost": marks, "marks_after": marks_after,
        "wallet_revision": wallet.revision, "next_sequence": wallet.next_sequence,
        "affix_count": offer.affix_count, "tier_weights": possible_tiers,
        "finish_minimum_bp": c.acquisition.finish_minimum_bp,
        "finish_maximum_bp": c.acquisition.finish_maximum_bp,
        "signature_chance_bp": offer.signature_chance_bp,
        "possible_affix_ids": effects::affix_ids(kind), "possible_signature_ids": effects::signature_ids(kind),
    });
    Ok(Plan { action, preview, coins, marks, materials: Vec::new(), shards: Vec::new(),
        mutation: Mutation::Purchase { original, secondary_biome_id, wandering } })
}

fn normal_material(material_id: u8) -> Result<(u32, &'static str), String> {
    match material_id {
        0 => Ok((effects::NORMAL_FILLETS_SLOT, "normal_fillets")),
        1 => Ok((effects::NORMAL_SCALES_SLOT, "normal_scales")),
        _ => Err("INVALID_RAID_MATERIAL".into()),
    }
}

fn plan_materials(ctx: &ReducerContext, player_id: u64, intent: &Intent) -> Result<Plan, String> {
    let c = rules::catalog().map_err(str::to_owned)?;
    let mut materials = Vec::new();
    let coins;
    let action = match intent {
        Intent::ConvertNormal { material_id, batches } => {
            if *batches == 0 || *batches > c.maximum_conversion_batches {
                return Err("TIDEFORGE_REFINING_INVALID".into());
            }
            raids::require_grade_clear(ctx, player_id, 0)?;
            let (slot, item) = normal_material(*material_id)?;
            let input = batches.checked_mul(c.processing.normal_to_f_input).ok_or("ITEM_OVERFLOW")?;
            let output = batches.checked_mul(c.processing.normal_to_f_output).ok_or("ITEM_OVERFLOW")?;
            materials.push(material_change(ctx, player_id, slot, item.into(), input, 0)?);
            let (slot, item) = game_rules::raids::material(0, *material_id).map_err(str::to_owned)?;
            materials.push(material_change(ctx, player_id, slot, item, 0, output)?);
            coins = batches.checked_mul(c.processing.normal_to_f_coin_cost).ok_or("COIN_OVERFLOW")?;
            "convert_normal"
        }
        Intent::RefineMaterial { material_id, from_grade, to_grade, batches } => {
            normal_material(*material_id)?;
            let plan = rules::refine_material(*from_grade, *to_grade, *batches).map_err(str::to_owned)?;
            for grade in (*from_grade + 1)..=*to_grade {
                raids::require_grade_clear(ctx, player_id, grade)?;
            }
            let (slot, item) = game_rules::raids::material(*from_grade, *material_id).map_err(str::to_owned)?;
            materials.push(material_change(ctx, player_id, slot, item, plan.input, 0)?);
            for (grade, output) in plan.outputs {
                let (slot, item) = game_rules::raids::material(grade, *material_id).map_err(str::to_owned)?;
                materials.push(material_change(ctx, player_id, slot, item, 0, output)?);
            }
            coins = plan.coins;
            "refine_material"
        }
        _ => return Err("TIDEFORGE_INTENT_INVALID".into()),
    };
    let (coins_before, coins_after) = require_coins(ctx, player_id, coins)?;
    let preview = serde_json::json!({
        "action": action, "materials": materials,
        "coins_before": coins_before, "coins_cost": coins, "coins_after": coins_after,
    });
    Ok(Plan { action, preview, coins, marks: 0, materials, shards: Vec::new(), mutation: Mutation::Materials })
}

pub(crate) fn legacy_refine_amounts(
    ctx: &ReducerContext, player_id: u64, material_id: u8, from_grade: u8, batches: u32,
) -> Result<(u64, u64, u64), String> {
    let to_grade = from_grade.checked_add(1).ok_or("INVALID_RAID_GRADE")?;
    let intent = Intent::RefineMaterial { material_id, from_grade, to_grade, batches: u64::from(batches) };
    let plan = plan_materials(ctx, player_id, &intent)?;
    if plan.materials.len() != 2 {
        return Err("TIDEFORGE_REFINING_INVALID".into());
    }
    Ok((plan.materials[0].consumed, plan.materials[1].produced, plan.coins))
}

fn credit_remainder(ctx: &ReducerContext, player_id: u64, resource: &str) -> Result<u16, String> {
    let key = format!("{player_id}:{resource}");
    match ctx.db.tideforge_fractional_credit().key().find(&key) {
        None => Ok(0),
        Some(row) if row.key == key && row.player_id == player_id && row.resource == resource
            && row.remainder_bp < 10_000 => Ok(row.remainder_bp),
        Some(_) => Err("TIDEFORGE_CREDIT_INVALID".into()),
    }
}

fn plan_fillet(
    ctx: &ReducerContext,
    player_id: u64,
    ids: &[String],
    allow_trophies: bool,
) -> Result<Plan, String> {
    let catalog = rules::catalog().map_err(str::to_owned)?;
    if ids.is_empty() || ids.len() > catalog.maximum_fish_batch {
        return Err("BATCH_INVALID".into());
    }
    let mut seen = BTreeSet::new();
    let mut catch_ids = Vec::with_capacity(ids.len());
    let mut credits = Vec::with_capacity(ids.len());
    let mut fish_preview = Vec::with_capacity(ids.len());
    let mut fillet_remainder = credit_remainder(ctx, player_id, "normal_fillets")?;
    let mut scale_remainder = credit_remainder(ctx, player_id, "normal_scales")?;
    let initial_fillets = fillet_remainder;
    let initial_scales = scale_remainder;
    let mut fillets = 0u64;
    let mut scales = 0u64;
    let mut sale_forgone = 0u64;
    let player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
    let count = u32::try_from(ids.len()).map_err(|_| "COUNT_UNDERFLOW")?;
    let kept_after = player.kept_count.checked_sub(count).ok_or("COUNT_UNDERFLOW")?;
    for value in ids {
        let id = u64::try_from(canonical_u128(value)?).map_err(|_| "TIDEFORGE_ID_INVALID")?;
        if !seen.insert(id) {
            return Err("DUPLICATE_CATCH".into());
        }
        let fish = ctx.db.owned_specimen().catch_id().find(id).ok_or("CATCH_UNAVAILABLE")?;
        if fish.player_id != player_id {
            return Err("CATCH_NOT_OWNED".into());
        }
        if fish.favorite {
            return Err("FAVORITE_PROTECTED".into());
        }
        let species = ctx.db.species_definition().species_id().find(fish.species_id).ok_or("CONTENT_UNAVAILABLE")?;
        if !species.counts_for_ordinary_collection_completion {
            return Err("TIDEFORGE_EXCEPTION_PROTECTED".into());
        }
        let ordinal = game_rules::RARITY_TIERS.iter().position(|rank| *rank == fish.rarity)
            .ok_or("CONTENT_UNAVAILABLE")?;
        if !species.allowed_rarities.iter().any(|rank| rank == &fish.rarity) {
            return Err("CONTENT_UNAVAILABLE".into());
        }
        let model = game_rules::measurements::SpeciesMeasurements {
            typical_length_mm: species.typical_length_mm, min_length_mm: species.min_length_mm,
            max_length_mm: species.max_length_mm, typical_weight_g: species.typical_weight_g,
            min_weight_g: species.min_weight_g, max_weight_g: species.max_weight_g,
        };
        let rank_maximum = game_rules::measurements::is_rank_maximum(
            model, ordinal as u8, fish.length_mm, fish.weight_g,
        )?;
        let perfect = crate::perfection::qualifies(&species, fish.length_mm, fish.weight_g)?;
        if !allow_trophies && (rank_maximum || perfect) {
            return Err("TIDEFORGE_TROPHY_PROTECTED".into());
        }
        let modifiers = ctx.db.tideforge_specimen_modifiers().catch_id().find(id);
        let (fillet_bonus, scale_bonus) = if let Some(row) = modifiers {
            if row.catch_id != id || row.player_id != player_id || row.effects_version != effects::EFFECTS_VERSION {
                return Err("TIDEFORGE_SPECIMEN_INVALID".into());
            }
            (row.fillet_bp, row.scale_bp)
        } else {
            (0, 0)
        };
        let fillet_rate = 10_000u32.checked_add(fillet_bonus).ok_or("TIDEFORGE_CREDIT_OVERFLOW")?;
        let scale_rate = 10_000u32.checked_add(scale_bonus).ok_or("TIDEFORGE_CREDIT_OVERFLOW")?;
        let (amount, next) = effects::scale_credit(catalog.processing.fillets_by_rank[ordinal], fillet_rate, fillet_remainder)
            .map_err(str::to_owned)?;
        fillets = fillets.checked_add(amount).ok_or("ITEM_OVERFLOW")?;
        fillet_remainder = next;
        let (amount, next) = effects::scale_credit(catalog.processing.scales_by_rank[ordinal], scale_rate, scale_remainder)
            .map_err(str::to_owned)?;
        scales = scales.checked_add(amount).ok_or("ITEM_OVERFLOW")?;
        scale_remainder = next;
        sale_forgone = sale_forgone.checked_add(fish.sale_value_coins).ok_or("COIN_OVERFLOW")?;
        fish_preview.push(serde_json::json!({
            "catch_id": id.to_string(), "species_id": fish.species_id, "rarity": fish.rarity,
            "length_mm": fish.length_mm, "weight_g": fish.weight_g, "rules_version": fish.rules_version,
            "content_version": fish.content_version, "sale_value_coins": fish.sale_value_coins,
            "fillet_bonus_bp": fillet_bonus, "scale_bonus_bp": scale_bonus,
            "rank_maximum": rank_maximum, "perfect": perfect,
        }));
        catch_ids.push(id);
        credits.push((id, fillet_rate, scale_rate));
    }
    let materials = vec![
        material_change(ctx, player_id, effects::NORMAL_FILLETS_SLOT, "normal_fillets".into(), 0, fillets)?,
        material_change(ctx, player_id, effects::NORMAL_SCALES_SLOT, "normal_scales".into(), 0, scales)?,
    ];
    let preview = serde_json::json!({
        "action": "fillet", "fish": fish_preview, "allow_trophies": allow_trophies,
        "saved_sale_coins_forgone": sale_forgone, "kept_before": player.kept_count, "kept_after": kept_after,
        "fillet_carry_before": initial_fillets, "fillet_carry_after": fillet_remainder,
        "scale_carry_before": initial_scales, "scale_carry_after": scale_remainder, "materials": materials,
    });
    Ok(Plan { action: "fillet", preview, coins: 0, marks: 0, materials, shards: Vec::new(),
        mutation: Mutation::Fillet { catch_ids, credits } })
}

fn plan(ctx: &ReducerContext, player_id: u64, intent: &Intent) -> Result<Plan, String> {
    match intent {
        Intent::CampCopy { kind, definition_id, secondary_biome_id } =>
            plan_purchase(ctx, player_id, kind, *definition_id, *secondary_biome_id, false),
        Intent::WanderingGamble { kind, definition_id, secondary_biome_id } =>
            plan_purchase(ctx, player_id, kind, *definition_id, *secondary_biome_id, true),
        Intent::Fillet { catch_ids, allow_trophies } => plan_fillet(ctx, player_id, catch_ids, *allow_trophies),
        Intent::ConvertNormal { .. } | Intent::RefineMaterial { .. } => plan_materials(ctx, player_id, intent),
        _ => plan_gear(ctx, player_id, intent),
    }
}

fn checked_time(ctx: &ReducerContext, delta_seconds: u64) -> Result<Timestamp, String> {
    let now = ctx.timestamp.to_micros_since_unix_epoch();
    if now < 0 {
        return Err("TIDEFORGE_TIME_INVALID".into());
    }
    let offset = i64::try_from(delta_seconds).ok().and_then(|v| v.checked_mul(1_000_000))
        .ok_or("TIDEFORGE_TIME_INVALID")?;
    Ok(Timestamp::from_micros_since_unix_epoch(now.checked_add(offset).ok_or("TIDEFORGE_TIME_INVALID")?))
}

fn prepare(ctx: &ReducerContext, player_id: u64, intent_json: String) -> Result<(), String> {
    require_enabled(ctx)?;
    let intent = parse_intent(&intent_json)?;
    let key = quote_key(ctx.sender(), player_id);
    let catalog = rules::catalog().map_err(str::to_owned)?;
    if let Some(old) = ctx.db.tideforge_economy_quote().key().find(&key) {
        let cooldown = i64::try_from(catalog.quote_cooldown_seconds).ok()
            .and_then(|v| v.checked_mul(1_000_000)).ok_or("TIDEFORGE_TIME_INVALID")?;
        if ctx.timestamp.to_micros_since_unix_epoch() < old.created_at.to_micros_since_unix_epoch()
            .checked_add(cooldown).ok_or("TIDEFORGE_TIME_INVALID")?
        {
            return Err("ACTION_RATE_LIMITED".into());
        }
    }
    let mut quote_wallet = wallet(ctx, player_id)?;
    quote_wallet.revision = quote_wallet.revision.checked_add(1).ok_or("TIDEFORGE_WALLET_OVERFLOW")?;
    let nonce_sequence = quote_wallet.revision;
    save_wallet(ctx, quote_wallet);
    let plan = plan(ctx, player_id, &intent)?;
    let mut expires_at = checked_time(ctx, catalog.quote_lifetime_seconds)?;
    if matches!(intent, Intent::WanderingGamble { .. }) {
        let close = rules::wandering_close(ctx.timestamp.to_micros_since_unix_epoch())
            .map_err(str::to_owned)?.ok_or("TIDEFORGE_WANDERING_CLOSED")?;
        expires_at = expires_at.min(Timestamp::from_micros_since_unix_epoch(close));
    }
    let nonce = (u128::from(ctx.rng().r#gen::<u64>()) << 64) | u128::from(nonce_sequence);
    if ctx.db.tideforge_economy_receipt().key().find(receipt_key(ctx.sender(), player_id, nonce)).is_some() {
        return Err("TIDEFORGE_NONCE_CONFLICT".into());
    }
    let row = TideforgeEconomyQuote {
        key: key.clone(), identity: ctx.sender(), player_id, nonce,
        intent_json: serde_json::to_string(&intent).map_err(|_| "TIDEFORGE_INTENT_INVALID")?,
        preview_json: serde_json::to_string(&plan.preview).map_err(|_| "TIDEFORGE_PREVIEW_INVALID")?,
        catalog_version: catalog.version, created_at: ctx.timestamp, expires_at, consumed: false,
    };
    if ctx.db.tideforge_economy_quote().key().find(&key).is_some() {
        ctx.db.tideforge_economy_quote().key().update(row);
    } else {
        ctx.db.tideforge_economy_quote().insert(row);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn prepare_tideforge_action(ctx: &ReducerContext, intent_json: String) -> Result<(), String> {
    prepare(ctx, crate::shop::browser_player(ctx)?, intent_json)
}

#[spacetimedb::reducer]
pub fn prepare_tideforge_action_from_discord(
    ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, intent_json: String,
) -> Result<(), String> {
    prepare(ctx, crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?, intent_json)
}

fn apply_materials(
    ctx: &ReducerContext,
    player_id: u64,
    changes: &[MaterialChange],
    operation: &str,
) -> Result<(), String> {
    for change in changes {
        raids::consume_item(ctx, player_id, change.slot, &change.item, change.consumed, operation, "tideforge_economy")?;
    }
    for change in changes {
        if change.produced != 0 {
            crate::inventory::grant_item_with_reason(ctx, player_id, change.slot, &change.item,
                change.produced, operation.into(), "tideforge_economy")?;
        }
    }
    Ok(())
}

fn check_natural_codex(ctx: &ReducerContext, player_id: u64, kind: &str) -> Result<(), String> {
    for id in effects::affix_ids(kind) {
        let key = format!("{player_id}:{id}");
        if let Some(row) = ctx.db.tideforge_codex().key().find(&key) {
            if row.key != key || row.player_id != player_id || row.affix_id != id
                || !(1..=10).contains(&row.highest_tier) || row.best_finish_bp > 10_000
                || row.natural_count == 0
            {
                return Err("TIDEFORGE_CODEX_INVALID".into());
            }
            row.natural_count.checked_add(1).ok_or("TIDEFORGE_CODEX_OVERFLOW")?;
        }
    }
    Ok(())
}

fn apply_plan(ctx: &ReducerContext, player_id: u64, nonce: u128, plan: Plan) -> Result<serde_json::Value, String> {
    let operation = format!("tideforge:{player_id}:{nonce}");
    let mut summary = plan.preview.clone();
    match plan.mutation {
        Mutation::Purchase { original, secondary_biome_id, wandering } => {
            check_natural_codex(ctx, player_id, &original.kind)?;
            let mut wallet = wallet(ctx, player_id)?;
            if wallet.next_sequence >= 1u64 << 63 {
                return Err("TIDEFORGE_COPY_ID_EXHAUSTED".into());
            }
            let next = wallet.next_sequence.checked_add(1).ok_or("TIDEFORGE_COPY_ID_EXHAUSTED")?;
            let instance_id = (u128::from(player_id) << 64) | (1u128 << 63) | u128::from(wallet.next_sequence);
            if ctx.db.gear_instance().instance_id().find(instance_id).is_some() {
                return Err("TIDEFORGE_COPY_ID_INVALID".into());
            }
            wallet.marks = wallet.marks.checked_sub(plan.marks).ok_or("TIDEFORGE_MARKS_REQUIRED")?;
            wallet.next_sequence = next;
            wallet.revision = wallet.revision.checked_add(1).ok_or("TIDEFORGE_WALLET_OVERFLOW")?;
            let waters: Vec<u32> = ctx.db.biome_definition().iter().map(|row| row.biome_id).collect();
            let mut rng = ctx.rng();
            let rolled = rules::roll_acquisition(&original.kind, original.family_id, secondary_biome_id, &waters, wandering, &mut rng)
                .map_err(str::to_owned)?;
            let instance = GearInstance {
                instance_id, player_id, kind: original.kind, definition_id: original.definition_id,
                family_id: original.family_id, primary_biome_id: original.family_id,
                quality_level: 0, reinforcement_level: 0, protected_original: false, favorite: false,
                revision: 1, source: plan.action.into(), created_at: ctx.timestamp,
            };
            summary["gear"] = gear_json(&instance, &rolled.affixes, &rolled.signature);
            record_natural(ctx, player_id, &rolled.affixes)?;
            raids::spend_coins(ctx, player_id, plan.coins, &operation, "tideforge_economy")?;
            if plan.marks != 0 {
                crate::inventory::ledger(ctx, player_id, operation.clone(), "tideforge_marks",
                    -i64::try_from(plan.marks).map_err(|_| "TIDEFORGE_MARK_OVERFLOW")?, "tideforge_economy");
            }
            save_wallet(ctx, wallet);
            ctx.db.gear_instance().insert(instance);
            ctx.db.tideforge_gear().insert(TideforgeGear {
                instance_id, player_id, affixes_json: serde_json::to_string(&rolled.affixes)
                    .map_err(|_| "TIDEFORGE_AFFIX_JSON_INVALID")?, native_signature: rolled.signature,
            });
        }
        Mutation::Gear { instance, affixes, signature, consume, extract } => {
            raids::spend_coins(ctx, player_id, plan.coins, &operation, "tideforge_economy")?;
            apply_materials(ctx, player_id, &plan.materials, &operation)?;
            for shard in &plan.shards {
                if shard.consumed != 0 {
                    adjust_shard(ctx, player_id, &shard.affix_id,
                        -i64::try_from(shard.consumed).map_err(|_| "TIDEFORGE_SHARD_OVERFLOW")?)?;
                }
                if shard.produced != 0 {
                    adjust_shard(ctx, player_id, &shard.affix_id,
                        i64::try_from(shard.produced).map_err(|_| "TIDEFORGE_SHARD_OVERFLOW")?)?;
                }
            }
            if consume {
                if extract {
                    let id = signature.ok_or("TIDEFORGE_SIGNATURE_UNAVAILABLE")?;
                    ctx.db.tideforge_library().insert(TideforgeLibrary {
                        key: format!("{player_id}:{id}"), player_id, signature_id: id,
                    });
                }
                ctx.db.tideforge_gear().instance_id().delete(instance.instance_id);
                ctx.db.gear_instance_name().instance_id().delete(instance.instance_id);
                ctx.db.gear_instance().instance_id().delete(instance.instance_id);
            } else {
                save_gear_affixes(ctx, instance, &affixes, signature)?;
            }
        }
        Mutation::Fillet { catch_ids, credits } => {
            let catalog = rules::catalog().map_err(str::to_owned)?;
            let mut total_fillets = 0u64;
            let mut total_scales = 0u64;
            for (id, fillet_rate, scale_rate) in credits {
                let fish = ctx.db.owned_specimen().catch_id().find(id).ok_or("CATCH_UNAVAILABLE")?;
                let rank = game_rules::RARITY_TIERS.iter().position(|v| *v == fish.rarity)
                    .ok_or("CONTENT_UNAVAILABLE")?;
                total_fillets = total_fillets.checked_add(tideforge::credit(ctx, player_id, "normal_fillets",
                    catalog.processing.fillets_by_rank[rank], fillet_rate)?).ok_or("ITEM_OVERFLOW")?;
                total_scales = total_scales.checked_add(tideforge::credit(ctx, player_id, "normal_scales",
                    catalog.processing.scales_by_rank[rank], scale_rate)?).ok_or("ITEM_OVERFLOW")?;
            }
            if plan.materials[0].produced != total_fillets || plan.materials[1].produced != total_scales {
                return Err("QUOTE_CHANGED".into());
            }
            let mut player = ctx.db.player().player_id().find(player_id).ok_or("PLAYER_UNAVAILABLE")?;
            player.kept_count = player.kept_count.checked_sub(catch_ids.len() as u32).ok_or("COUNT_UNDERFLOW")?;
            for id in catch_ids {
                ctx.db.tideforge_specimen_modifiers().catch_id().delete(id);
                ctx.db.owned_specimen().catch_id().delete(id);
            }
            ctx.db.player().player_id().update(player);
            apply_materials(ctx, player_id, &plan.materials, &operation)?;
        }
        Mutation::Materials => {
            raids::spend_coins(ctx, player_id, plan.coins, &operation, "tideforge_economy")?;
            apply_materials(ctx, player_id, &plan.materials, &operation)?;
        }
    }
    Ok(summary)
}

fn commit(ctx: &ReducerContext, player_id: u64, nonce: u128) -> Result<(), String> {
    require_enabled(ctx)?;
    let key = receipt_key(ctx.sender(), player_id, nonce);
    if let Some(receipt) = ctx.db.tideforge_economy_receipt().key().find(&key) {
        if receipt.key != key || receipt.identity != ctx.sender() || receipt.player_id != player_id || receipt.nonce != nonce {
            return Err("ACTION_CONFLICT".into());
        }
        return Ok(());
    }
    let quote_key = quote_key(ctx.sender(), player_id);
    let mut quote = ctx.db.tideforge_economy_quote().key().find(&quote_key).ok_or("ACTION_NOT_FOUND")?;
    if quote.identity != ctx.sender() || quote.player_id != player_id || quote.nonce != nonce || quote.key != quote_key {
        return Err("ACTION_CONFLICT".into());
    }
    if quote.consumed {
        return Ok(());
    }
    if ctx.timestamp >= quote.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    let catalog = rules::catalog().map_err(str::to_owned)?;
    if quote.catalog_version != catalog.version {
        return Err("QUOTE_CHANGED".into());
    }
    let intent = parse_intent(&quote.intent_json)?;
    let plan = plan(ctx, player_id, &intent)?;
    let actual = serde_json::to_string(&plan.preview).map_err(|_| "TIDEFORGE_PREVIEW_INVALID")?;
    if actual != quote.preview_json {
        return Err("QUOTE_CHANGED".into());
    }
    let action = plan.action;
    let retention_seconds = catalog.receipt_retention_days.checked_mul(86_400).ok_or("TIDEFORGE_TIME_INVALID")?;
    let prune_at = checked_time(ctx, retention_seconds)?;
    let summary = apply_plan(ctx, player_id, nonce, plan)?;
    quote.consumed = true;
    ctx.db.tideforge_economy_quote().key().update(quote);
    ctx.db.tideforge_economy_receipt().insert(TideforgeEconomyReceipt {
        key, identity: ctx.sender(), player_id, nonce, action: action.into(),
        summary_json: serde_json::to_string(&summary).map_err(|_| "TIDEFORGE_PREVIEW_INVALID")?,
        created_at: ctx.timestamp, prune_at,
    });
    Ok(())
}

#[spacetimedb::reducer]
pub fn commit_tideforge_action(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    commit(ctx, crate::shop::browser_player(ctx)?, nonce)
}

#[spacetimedb::reducer]
pub fn commit_tideforge_action_from_discord(
    ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, nonce: u128,
) -> Result<(), String> {
    commit(ctx, crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?, nonce)
}
