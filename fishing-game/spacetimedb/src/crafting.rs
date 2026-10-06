use crate::{accounts::current_player, tables::*};
use serde::Deserialize;
use spacetimedb::{ReducerContext, Table, TimeDuration, ViewContext, rand::Rng};

#[derive(Deserialize)]
struct Catalog {
    version: u32,
    qualities: Vec<Quality>,
    baits: Vec<Bait>,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Quality {
    quality_level: u8,
    name: String,
    power_bonus: u32,
    luck_bp: u32,
    xp_bonus_bp: u32,
    tin_cost: u64,
    scrap_cost: u64,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Bait {
    bait_id: u32,
    name: String,
    luck_bp: u32,
    resource_item: String,
    uses_per_purchase: u64,
    sprite_asset: String,
}

#[spacetimedb::view(accessor=adapter_items,public)]
pub fn adapter_items(ctx: &ViewContext) -> Vec<ItemStack> {
    crate::shop::selected(ctx)
        .map(|id| ctx.db.item_stack().player_id().filter(id).collect())
        .unwrap_or_default()
}

pub fn seed(ctx: &ReducerContext) {
    let catalog: Catalog = serde_json::from_str(include_str!("../../content/crafting.json"))
        .expect("validated crafting catalog");
    for q in catalog.qualities {
        let row = QualityDefinition {
            quality_level: q.quality_level,
            version: catalog.version,
            name: q.name,
            power_bonus: q.power_bonus,
            luck_bp: q.luck_bp,
            xp_bonus_bp: q.xp_bonus_bp,
            tin_cost: q.tin_cost,
            scrap_cost: q.scrap_cost,
        };
        if ctx
            .db
            .quality_definition()
            .quality_level()
            .find(row.quality_level)
            .is_some()
        {
            ctx.db.quality_definition().quality_level().update(row);
        } else {
            ctx.db.quality_definition().insert(row);
        }
    }
    for b in catalog.baits {
        let row = BaitDefinition {
            bait_id: b.bait_id,
            name: b.name,
            luck_bp: b.luck_bp,
            resource_item: b.resource_item,
            uses_per_purchase: b.uses_per_purchase,
            sprite_asset: b.sprite_asset,
        };
        if ctx
            .db
            .bait_definition()
            .bait_id()
            .find(row.bait_id)
            .is_some()
        {
            ctx.db.bait_definition().bait_id().update(row);
        } else {
            ctx.db.bait_definition().insert(row);
        }
    }
}

/// Metadata-only, idempotent activation. Never grants or spends player resources.
#[spacetimedb::reducer]
pub fn activate_crafting(ctx: &ReducerContext) -> Result<(), String> {
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
    crate::gear::seed(ctx);
    crate::shop::seed(ctx);
    for mut biome in ctx.db.biome_definition().iter() {
        biome.required_power = 0;
        ctx.db.biome_definition().biome_id().update(biome);
    }
    if ctx.db.game_config().version().find(6).is_none() {
        let mut rules = crate::accounts::config(ctx);
        rules.version = 6;
        ctx.db.game_config().insert(rules);
    }
    Ok(())
}

pub fn grant_bait(ctx: &ReducerContext, player_id: u64, bait_id: u32) -> Result<(), String> {
    let bait = ctx
        .db
        .bait_definition()
        .bait_id()
        .find(bait_id)
        .ok_or("BAIT_UNAVAILABLE")?;
    let key = u128::from(player_id) << 32 | u128::from(bait_id);
    if let Some(mut row) = ctx.db.bait_stack().key().find(key) {
        row.uses_left = row
            .uses_left
            .checked_add(bait.uses_per_purchase)
            .ok_or("ITEM_OVERFLOW")?;
        ctx.db.bait_stack().key().update(row);
    } else {
        ctx.db.bait_stack().insert(BaitStack {
            key,
            player_id,
            bait_id,
            uses_left: bait.uses_per_purchase,
        });
    }
    Ok(())
}

fn equip(ctx: &ReducerContext, player_id: u64, bait_id: u32) -> Result<(), String> {
    if bait_id != 0 {
        ctx.db
            .bait_definition()
            .bait_id()
            .find(bait_id)
            .ok_or("BAIT_UNAVAILABLE")?;
        let key = u128::from(player_id) << 32 | u128::from(bait_id);
        if ctx
            .db
            .bait_stack()
            .key()
            .find(key)
            .is_none_or(|row| row.uses_left == 0)
        {
            return Err("BAIT_NOT_OWNED".into());
        }
    }
    let row = BaitLoadout { player_id, bait_id };
    if ctx.db.bait_loadout().player_id().find(player_id).is_some() {
        ctx.db.bait_loadout().player_id().update(row);
    } else {
        ctx.db.bait_loadout().insert(row);
    }
    Ok(())
}
#[spacetimedb::reducer]
pub fn equip_bait(ctx: &ReducerContext, bait_id: u32) -> Result<(), String> {
    equip(ctx, crate::shop::browser_player(ctx)?, bait_id)
}
#[spacetimedb::reducer]
pub fn equip_bait_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    bait_id: u32,
) -> Result<(), String> {
    equip(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        bait_id,
    )
}

pub struct UsedBait {
    pub bait_id: u32,
    pub luck_bp: u32,
    pub resource_item: String,
    pub uses_left: u64,
}
pub fn consume_bait(ctx: &ReducerContext, player_id: u64) -> Result<UsedBait, String> {
    let bait_id = ctx
        .db
        .bait_loadout()
        .player_id()
        .find(player_id)
        .map_or(0, |row| row.bait_id);
    if bait_id == 0 {
        return Ok(UsedBait {
            bait_id: 0,
            luck_bp: 0,
            resource_item: String::new(),
            uses_left: 0,
        });
    }
    let bait = ctx
        .db
        .bait_definition()
        .bait_id()
        .find(bait_id)
        .ok_or("BAIT_UNAVAILABLE")?;
    let key = u128::from(player_id) << 32 | u128::from(bait_id);
    let mut stack = ctx
        .db
        .bait_stack()
        .key()
        .find(key)
        .ok_or("BAIT_NOT_OWNED")?;
    stack.uses_left = stack.uses_left.checked_sub(1).ok_or("BAIT_NOT_OWNED")?;
    let uses_left = stack.uses_left;
    ctx.db.bait_stack().key().update(stack);
    if uses_left == 0 {
        equip(ctx, player_id, 0)?;
    }
    crate::inventory::ledger(
        ctx,
        player_id,
        format!("bait:{}", ctx.timestamp.to_micros_since_unix_epoch()),
        &format!("bait_{bait_id}_uses"),
        -1,
        "cast_bait",
    );
    Ok(UsedBait {
        bait_id,
        luck_bp: bait.luck_bp,
        resource_item: bait.resource_item,
        uses_left,
    })
}

fn material(ctx: &ReducerContext, player_id: u64, slot: u32) -> u64 {
    ctx.db
        .item_stack()
        .key()
        .find(u128::from(player_id) << 32 | u128::from(slot))
        .map_or(0, |row| row.quantity)
}
fn affordable(ctx: &ReducerContext, player_id: u64, q: &QualityDefinition) -> Result<(), String> {
    if material(ctx, player_id, 2) < q.tin_cost || material(ctx, player_id, 1) < q.scrap_cost {
        return Err("INSUFFICIENT_MATERIALS".into());
    }
    Ok(())
}
fn quote_key(ctx: &ReducerContext, player_id: u64) -> String {
    format!("{}:{player_id}", ctx.sender())
}
fn prepare(ctx: &ReducerContext, player_id: u64, rod_id: u32) -> Result<(), String> {
    let owned = ctx
        .db
        .owned_rod()
        .key()
        .find(u128::from(player_id) << 32 | u128::from(rod_id))
        .ok_or("ROD_NOT_OWNED")?;
    let next = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(owned.upgrade_level.checked_add(1).ok_or("MAX_QUALITY")?)
        .ok_or("MAX_QUALITY")?;
    affordable(ctx, player_id, &next)?;
    let key = quote_key(ctx, player_id);
    if let Some(old) = ctx.db.upgrade_quote().key().find(&key) {
        if ctx.timestamp < old.created_at + TimeDuration::from_micros(1_000_000) {
            if !old.consumed
                && old.rod_id == rod_id
                && old.from_quality == owned.upgrade_level
                && old.catalog_version == next.version
                && old.tin_cost == next.tin_cost
                && old.scrap_cost == next.scrap_cost
            {
                return Ok(());
            }
            return Err("ACTION_RATE_LIMITED".into());
        }
    }
    let row = UpgradeQuote {
        key: key.clone(),
        identity: ctx.sender(),
        player_id,
        nonce: ctx.rng().r#gen(),
        rod_id,
        from_quality: owned.upgrade_level,
        catalog_version: next.version,
        tin_cost: next.tin_cost,
        scrap_cost: next.scrap_cost,
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(120_000_000),
        consumed: false,
    };
    if ctx.db.upgrade_quote().key().find(key).is_some() {
        ctx.db.upgrade_quote().key().update(row);
    } else {
        ctx.db.upgrade_quote().insert(row);
    }
    Ok(())
}
fn spend(
    ctx: &ReducerContext,
    player_id: u64,
    slot: u32,
    amount: u64,
    operation: &str,
) -> Result<(), String> {
    let key = u128::from(player_id) << 32 | u128::from(slot);
    let mut row = ctx
        .db
        .item_stack()
        .key()
        .find(key)
        .ok_or("INSUFFICIENT_MATERIALS")?;
    row.quantity = row
        .quantity
        .checked_sub(amount)
        .ok_or("INSUFFICIENT_MATERIALS")?;
    crate::inventory::ledger(
        ctx,
        player_id,
        operation.into(),
        &row.item,
        -i64::try_from(amount).map_err(|_| "ITEM_OVERFLOW")?,
        "rod_upgrade",
    );
    ctx.db.item_stack().key().update(row);
    Ok(())
}
fn commit(ctx: &ReducerContext, player_id: u64, nonce: u128) -> Result<(), String> {
    let mut quote = ctx
        .db
        .upgrade_quote()
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
    let mut rod = ctx
        .db
        .owned_rod()
        .key()
        .find(u128::from(player_id) << 32 | u128::from(quote.rod_id))
        .ok_or("ROD_NOT_OWNED")?;
    if rod.upgrade_level != quote.from_quality {
        return Err("QUOTE_CHANGED".into());
    }
    let next = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(rod.upgrade_level + 1)
        .ok_or("MAX_QUALITY")?;
    if next.version != quote.catalog_version
        || next.tin_cost != quote.tin_cost
        || next.scrap_cost != quote.scrap_cost
    {
        return Err("QUOTE_CHANGED".into());
    }
    affordable(ctx, player_id, &next)?;
    let operation = format!("upgrade:{nonce}");
    spend(ctx, player_id, 2, next.tin_cost, &operation)?;
    spend(ctx, player_id, 1, next.scrap_cost, &operation)?;
    rod.upgrade_level = next.quality_level;
    ctx.db.owned_rod().key().update(rod);
    crate::inventory::ledger(
        ctx,
        player_id,
        operation,
        &format!("rod_{}_quality", quote.rod_id),
        1,
        "rod_upgrade",
    );
    quote.consumed = true;
    ctx.db.upgrade_quote().key().update(quote);
    crate::achievements::refresh_player(ctx, player_id)
}
#[spacetimedb::reducer]
pub fn prepare_rod_upgrade(ctx: &ReducerContext, rod_id: u32) -> Result<(), String> {
    prepare(ctx, crate::shop::browser_player(ctx)?, rod_id)
}
#[spacetimedb::reducer]
pub fn commit_rod_upgrade(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    commit(ctx, crate::shop::browser_player(ctx)?, nonce)
}
#[spacetimedb::reducer]
pub fn prepare_upgrade_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    rod_id: u32,
) -> Result<(), String> {
    prepare(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        rod_id,
    )
}
#[spacetimedb::reducer]
pub fn commit_upgrade_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    nonce: u128,
) -> Result<(), String> {
    commit(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        nonce,
    )
}

#[spacetimedb::view(accessor=my_baits,public)]
pub fn my_baits(ctx: &ViewContext) -> Vec<BaitStack> {
    current_player(ctx)
        .map(|id| ctx.db.bait_stack().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor=my_bait_loadout,public)]
pub fn my_bait_loadout(ctx: &ViewContext) -> Option<BaitLoadout> {
    ctx.db.bait_loadout().player_id().find(current_player(ctx)?)
}
#[spacetimedb::view(accessor=my_upgrade_quote,public)]
pub fn my_upgrade_quote(ctx: &ViewContext) -> Option<UpgradeQuote> {
    let id = current_player(ctx)?;
    ctx.db
        .upgrade_quote()
        .key()
        .find(format!("{}:{id}", ctx.sender()))
}
#[spacetimedb::view(accessor=adapter_baits,public)]
pub fn adapter_baits(ctx: &ViewContext) -> Vec<BaitStack> {
    crate::shop::selected(ctx)
        .map(|id| ctx.db.bait_stack().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor=adapter_bait_loadout,public)]
pub fn adapter_bait_loadout(ctx: &ViewContext) -> Option<BaitLoadout> {
    ctx.db
        .bait_loadout()
        .player_id()
        .find(crate::shop::selected(ctx)?)
}
#[spacetimedb::view(accessor=adapter_upgrade_quote,public)]
pub fn adapter_upgrade_quote(ctx: &ViewContext) -> Option<UpgradeQuote> {
    let id = crate::shop::selected(ctx)?;
    ctx.db
        .upgrade_quote()
        .key()
        .find(format!("{}:{id}", ctx.sender()))
}
#[spacetimedb::view(accessor=adapter_cast_pulls,public)]
pub fn adapter_cast_pulls(ctx: &ViewContext) -> Vec<CastPull> {
    let Some(id) = crate::shop::selected(ctx) else {
        return vec![];
    };
    let Some(selection) = ctx.db.service_selection().identity().find(ctx.sender()) else {
        return vec![];
    };
    ctx.db
        .cast_pull()
        .interaction_id()
        .filter(selection.interaction_id)
        .filter(|row| row.player_id == id)
        .collect()
}
#[spacetimedb::view(accessor=adapter_cast_equipment,public)]
pub fn adapter_cast_equipment(ctx: &ViewContext) -> Option<CastEquipmentReceipt> {
    let id = crate::shop::selected(ctx)?;
    let selection = ctx.db.service_selection().identity().find(ctx.sender())?;
    ctx.db
        .cast_equipment_receipt()
        .interaction_id()
        .find(selection.interaction_id)
        .filter(|row| row.player_id == id)
}
