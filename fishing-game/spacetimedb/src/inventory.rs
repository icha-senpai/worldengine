use crate::tables::*;
use spacetimedb::{ReducerContext, Table, TimeDuration, rand::Rng};

fn player_id(ctx: &ReducerContext) -> Result<u64, String> {
    ctx.db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .map(|row| row.player_id)
        .ok_or("ACCOUNT_NOT_LINKED".into())
}

/// Update legacy public configuration without altering or clearing existing tables.
#[spacetimedb::reducer]
pub fn migrate_unlimited_inventory(ctx: &ReducerContext) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_UNAVAILABLE")?;
    if ctx.sender() != owner.identity {
        return Err("OWNER_REQUIRED".into());
    }
    for mut rules in ctx.db.game_config().iter() {
        rules.inventory_capacity = 0;
        ctx.db.game_config().version().update(rules);
    }
    Ok(())
}

pub fn ledger(
    ctx: &ReducerContext,
    player_id: u64,
    operation_id: String,
    item: &str,
    delta: i64,
    reason: &str,
) {
    ctx.db.economy_ledger().insert(EconomyLedger {
        ledger_id: 0,
        player_id,
        operation_id,
        item: item.into(),
        delta,
        reason: reason.into(),
        created_at: ctx.timestamp,
    });
}

pub fn grant_item(
    ctx: &ReducerContext,
    player_id: u64,
    slot: u32,
    item: &str,
    quantity: u64,
    operation_id: String,
) -> Result<(), String> {
    let key = u128::from(player_id) << 32 | u128::from(slot);
    if let Some(mut row) = ctx.db.item_stack().key().find(key) {
        row.quantity = row.quantity.checked_add(quantity).ok_or("ITEM_OVERFLOW")?;
        ctx.db.item_stack().key().update(row);
    } else {
        ctx.db.item_stack().insert(ItemStack {
            key,
            player_id,
            item: item.into(),
            quantity,
        });
    }
    ledger(
        ctx,
        player_id,
        operation_id,
        item,
        i64::try_from(quantity).map_err(|_| "ITEM_OVERFLOW")?,
        "catch",
    );
    Ok(())
}

/// Issue one bounded, short-lived action quote per browser. No arbitrary client UUIDs.
#[spacetimedb::reducer]
pub fn prepare_inventory_action(
    ctx: &ReducerContext,
    kind: String,
    catch_ids: Vec<u64>,
    favorite: bool,
) -> Result<(), String> {
    let player = player_id(ctx)?;
    if let Some(old) = ctx.db.action_nonce().identity().find(ctx.sender())
        && ctx.timestamp < old.created_at + TimeDuration::from_micros(1_000_000)
    {
        return Err("ACTION_RATE_LIMITED".into());
    }
    if kind != "sell" && kind != "favorite" {
        return Err("ACTION_INVALID".into());
    }
    if catch_ids.is_empty() || catch_ids.len() > 50 || (kind == "favorite" && catch_ids.len() != 1)
    {
        return Err("BATCH_INVALID".into());
    }
    let mut ids = catch_ids;
    ids.sort_unstable();
    if ids.windows(2).any(|pair| pair[0] == pair[1]) {
        return Err("DUPLICATE_CATCH".into());
    }
    let mut coins = 0u64;
    for id in &ids {
        let fish = ctx
            .db
            .owned_specimen()
            .catch_id()
            .find(*id)
            .ok_or("CATCH_UNAVAILABLE")?;
        if fish.player_id != player {
            return Err("CATCH_NOT_OWNED".into());
        }
        if kind == "sell" && fish.favorite {
            return Err("FAVORITE_PROTECTED".into());
        }
        coins = coins
            .checked_add(fish.sale_value_coins)
            .ok_or("COIN_OVERFLOW")?;
    }
    let row = ActionNonce {
        identity: ctx.sender(),
        player_id: player,
        nonce: ctx.rng().r#gen(),
        kind,
        catch_ids: ids,
        favorite,
        quoted_coins: coins,
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(120_000_000),
        consumed: false,
    };
    if ctx
        .db
        .action_nonce()
        .identity()
        .find(ctx.sender())
        .is_some()
    {
        ctx.db.action_nonce().identity().update(row);
    } else {
        ctx.db.action_nonce().insert(row);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn commit_inventory_action(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    let player_id = player_id(ctx)?;
    let mut action = ctx
        .db
        .action_nonce()
        .identity()
        .find(ctx.sender())
        .ok_or("ACTION_NOT_FOUND")?;
    if action.player_id != player_id {
        return Err("ACTION_CONFLICT".into());
    }
    if nonce != action.nonce {
        return Err("ACTION_CONFLICT".into());
    }
    if ctx.timestamp >= action.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    if action.consumed {
        return Ok(());
    }
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut coins = 0u64;
    for id in &action.catch_ids {
        let mut fish = ctx
            .db
            .owned_specimen()
            .catch_id()
            .find(*id)
            .ok_or("CATCH_UNAVAILABLE")?;
        if fish.player_id != player_id {
            return Err("CATCH_NOT_OWNED".into());
        }
        if action.kind == "sell" {
            if fish.favorite {
                return Err("FAVORITE_PROTECTED".into());
            }
            coins = coins
                .checked_add(fish.sale_value_coins)
                .ok_or("COIN_OVERFLOW")?;
            ctx.db.owned_specimen().catch_id().delete(*id);
        } else {
            fish.favorite = action.favorite;
            ctx.db.owned_specimen().catch_id().update(fish);
        }
    }
    if action.kind == "sell" {
        if coins != action.quoted_coins {
            return Err("QUOTE_CHANGED".into());
        }
        player.coins = player.coins.checked_add(coins).ok_or("COIN_OVERFLOW")?;
        player.kept_count = player
            .kept_count
            .checked_sub(action.catch_ids.len() as u32)
            .ok_or("COUNT_UNDERFLOW")?;
        ledger(
            ctx,
            player_id,
            format!("web:{nonce}"),
            "coins",
            i64::try_from(coins).map_err(|_| "COIN_OVERFLOW")?,
            "fish_sale",
        );
        ctx.db.player().player_id().update(player);
    }
    action.consumed = true;
    ctx.db.action_nonce().identity().update(action);
    // Collection, recent catches, and record snapshots are intentionally retained.
    Ok(())
}
