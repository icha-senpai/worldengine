//! Permanent boat/net ownership and independent, caller-scoped equipment slots.
use crate::{accounts::current_player, tables::*};
use serde::Deserialize;
use spacetimedb::{ReducerContext, Table, TimeDuration, ViewContext, rand::Rng};

#[derive(Deserialize)]
struct Catalog {
    equipment: Vec<Definition>,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Definition {
    equipment_id: u32,
    rod_id: u32,
    kind: String,
    name: String,
    sprite_asset: String,
    luck_bp: u32,
    xp_bonus_bp: u32,
    quality_bonus_multiplier: u32,
}

pub fn seed(ctx: &ReducerContext) {
    let catalog: Catalog = serde_json::from_str(include_str!("../../content/haul-equipment.json"))
        .expect("validated boat and net catalog");
    for row in catalog.equipment {
        let definition = HaulEquipmentDefinition {
            equipment_id: row.equipment_id,
            rod_id: row.rod_id,
            kind: row.kind,
            name: row.name,
            sprite_asset: row.sprite_asset,
            luck_bp: row.luck_bp,
            xp_bonus_bp: row.xp_bonus_bp,
            quality_bonus_multiplier: row.quality_bonus_multiplier,
        };
        if ctx
            .db
            .haul_equipment_definition()
            .equipment_id()
            .find(definition.equipment_id)
            .is_some()
        {
            ctx.db
                .haul_equipment_definition()
                .equipment_id()
                .update(definition);
        } else {
            ctx.db.haul_equipment_definition().insert(definition);
        }
    }
}

pub fn grant_starters(ctx: &ReducerContext, player_id: u64) {
    for equipment_id in [101, 201] {
        let key = (u128::from(player_id) << 32) | u128::from(equipment_id);
        if ctx.db.owned_haul_equipment().key().find(key).is_none() {
            ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
                key,
                player_id,
                equipment_id,
                upgrade_level: 0,
            });
        }
    }
    if ctx.db.haul_loadout().player_id().find(player_id).is_none() {
        ctx.db.haul_loadout().insert(HaulLoadout {
            player_id,
            boat_id: 101,
            net_id: 201,
        });
    }
}

#[spacetimedb::reducer]
pub fn activate_haul_equipment(ctx: &ReducerContext) -> Result<(), String> {
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
    seed(ctx);
    crate::shop::seed(ctx);
    for player in ctx.db.player().iter() {
        grant_starters(ctx, player.player_id);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn equip_haul_equipment(ctx: &ReducerContext, equipment_id: u32) -> Result<(), String> {
    let player_id = crate::shop::browser_player(ctx)?;
    let definition = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(equipment_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    let key = (u128::from(player_id) << 32) | u128::from(equipment_id);
    if ctx.db.owned_haul_equipment().key().find(key).is_none() {
        return Err("EQUIPMENT_NOT_OWNED".into());
    }
    let mut loadout = ctx
        .db
        .haul_loadout()
        .player_id()
        .find(player_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    match definition.kind.as_str() {
        "boat" => loadout.boat_id = equipment_id,
        "net" => loadout.net_id = equipment_id,
        _ => return Err("EQUIPMENT_UNAVAILABLE".into()),
    }
    ctx.db.haul_loadout().player_id().update(loadout);
    Ok(())
}

fn quote_key(ctx: &ReducerContext, player_id: u64) -> String {
    format!("{}:{player_id}", ctx.sender())
}

#[spacetimedb::reducer]
pub fn prepare_haul_upgrade(ctx: &ReducerContext, equipment_id: u32) -> Result<(), String> {
    let player_id = crate::shop::browser_player(ctx)?;
    let owned = ctx
        .db
        .owned_haul_equipment()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(equipment_id))
        .ok_or("EQUIPMENT_NOT_OWNED")?;
    let definition = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(equipment_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    let next = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(owned.upgrade_level.checked_add(1).ok_or("MAX_QUALITY")?)
        .ok_or("MAX_QUALITY")?;
    let cost = crate::crafting::recipe(ctx, definition.rod_id, next.quality_level)?;
    crate::crafting::affordable(ctx, player_id, &cost)?;
    let key = quote_key(ctx, player_id);
    if let Some(old) = ctx.db.haul_upgrade_quote().key().find(&key) {
        if ctx.timestamp < old.created_at + TimeDuration::from_micros(1_000_000) {
            if !old.consumed
                && old.equipment_id == equipment_id
                && old.rod_id == definition.rod_id
                && old.from_quality == owned.upgrade_level
                && old.recipe_version == cost.version
                && old.quality_version == next.version
                && old.tin_cost == cost.tin_cost
                && old.scrap_cost == cost.scrap_cost
            {
                return Ok(());
            }
            return Err("ACTION_RATE_LIMITED".into());
        }
    }
    let quote = HaulUpgradeQuote {
        key: key.clone(),
        identity: ctx.sender(),
        player_id,
        nonce: ctx.rng().r#gen(),
        equipment_id,
        rod_id: definition.rod_id,
        from_quality: owned.upgrade_level,
        recipe_version: cost.version,
        quality_version: next.version,
        tin_cost: cost.tin_cost,
        scrap_cost: cost.scrap_cost,
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(120_000_000),
        consumed: false,
    };
    if ctx.db.haul_upgrade_quote().key().find(&key).is_some() {
        ctx.db.haul_upgrade_quote().key().update(quote);
    } else {
        ctx.db.haul_upgrade_quote().insert(quote);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn commit_haul_upgrade(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    let player_id = crate::shop::browser_player(ctx)?;
    let mut quote = ctx
        .db
        .haul_upgrade_quote()
        .key()
        .find(quote_key(ctx, player_id))
        .ok_or("ACTION_NOT_FOUND")?;
    if quote.identity != ctx.sender() || quote.player_id != player_id || quote.nonce != nonce {
        return Err("ACTION_CONFLICT".into());
    }
    if quote.consumed {
        return Ok(());
    }
    if ctx.timestamp >= quote.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    let mut owned = ctx
        .db
        .owned_haul_equipment()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(quote.equipment_id))
        .ok_or("EQUIPMENT_NOT_OWNED")?;
    if owned.upgrade_level != quote.from_quality {
        return Err("QUOTE_CHANGED".into());
    }
    let definition = ctx
        .db
        .haul_equipment_definition()
        .equipment_id()
        .find(quote.equipment_id)
        .ok_or("EQUIPMENT_UNAVAILABLE")?;
    if definition.rod_id != quote.rod_id {
        return Err("QUOTE_CHANGED".into());
    }
    if !matches!(definition.kind.as_str(), "boat" | "net") {
        return Err("EQUIPMENT_UNAVAILABLE".into());
    }
    let next = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(owned.upgrade_level.checked_add(1).ok_or("MAX_QUALITY")?)
        .ok_or("MAX_QUALITY")?;
    let cost = crate::crafting::recipe(ctx, definition.rod_id, next.quality_level)?;
    if cost.version != quote.recipe_version
        || next.version != quote.quality_version
        || cost.tin_cost != quote.tin_cost
        || cost.scrap_cost != quote.scrap_cost
    {
        return Err("QUOTE_CHANGED".into());
    }
    crate::crafting::affordable(ctx, player_id, &cost)?;
    let operation = format!("haul-upgrade:{nonce}");
    let reason = format!("{}_upgrade", definition.kind);
    crate::crafting::spend(ctx, player_id, 2, cost.tin_cost, &operation, &reason)?;
    crate::crafting::spend(ctx, player_id, 1, cost.scrap_cost, &operation, &reason)?;
    owned.upgrade_level = next.quality_level;
    ctx.db.owned_haul_equipment().key().update(owned);
    crate::inventory::ledger(
        ctx,
        player_id,
        operation,
        &format!("{}_{}_quality", definition.kind, quote.equipment_id),
        1,
        &reason,
    );
    quote.consumed = true;
    ctx.db.haul_upgrade_quote().key().update(quote);
    Ok(())
}

#[spacetimedb::view(accessor = my_haul_upgrade_quote, public)]
pub fn my_haul_upgrade_quote(ctx: &ViewContext) -> Option<HaulUpgradeQuote> {
    let id = current_player(ctx)?;
    ctx.db
        .haul_upgrade_quote()
        .key()
        .find(format!("{}:{id}", ctx.sender()))
}

#[spacetimedb::view(accessor = my_haul_equipment, public)]
pub fn my_haul_equipment(ctx: &ViewContext) -> Vec<OwnedHaulEquipment> {
    current_player(ctx)
        .map(|id| {
            ctx.db
                .owned_haul_equipment()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_haul_loadout, public)]
pub fn my_haul_loadout(ctx: &ViewContext) -> Option<HaulLoadout> {
    ctx.db.haul_loadout().player_id().find(current_player(ctx)?)
}
