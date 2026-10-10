use crate::tables::*;
use spacetimedb::{ReducerContext, Table, TimeDuration, ViewContext, rand::Rng};

fn sale_total(ctx: &ReducerContext, player_id: u64, ids: &[u64]) -> Result<u64, String> {
    if ids.is_empty() || ids.len() > 50 {
        return Err("BATCH_INVALID".into());
    }
    let mut sorted = ids.to_vec();
    sorted.sort_unstable();
    if sorted.windows(2).any(|pair| pair[0] == pair[1]) {
        return Err("DUPLICATE_CATCH".into());
    }
    let mut total = 0u64;
    for id in ids {
        let fish = ctx
            .db
            .owned_specimen()
            .catch_id()
            .find(*id)
            .ok_or("CATCH_UNAVAILABLE")?;
        if fish.player_id != player_id {
            return Err("CATCH_NOT_OWNED".into());
        }
        if fish.favorite {
            return Err("FAVORITE_PROTECTED".into());
        }
        total = total
            .checked_add(fish.sale_value_coins)
            .ok_or("COIN_OVERFLOW")?;
    }
    Ok(total)
}

fn settle_sale(
    ctx: &ReducerContext,
    player_id: u64,
    ids: &[u64],
    quoted_coins: u64,
    operation: String,
) -> Result<(), String> {
    let coins = sale_total(ctx, player_id, ids)?;
    if coins != quoted_coins {
        return Err("QUOTE_CHANGED".into());
    }
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    player.coins = player.coins.checked_add(coins).ok_or("COIN_OVERFLOW")?;
    player.kept_count = player
        .kept_count
        .checked_sub(ids.len() as u32)
        .ok_or("COUNT_UNDERFLOW")?;
    let delta = i64::try_from(coins).map_err(|_| "COIN_OVERFLOW")?;
    for id in ids {
        ctx.db.owned_specimen().catch_id().delete(*id);
    }
    ctx.db.player().player_id().update(player);
    ledger(ctx, player_id, operation, "coins", delta, "fish_sale");
    Ok(())
}

#[spacetimedb::reducer]
pub fn prepare_sale_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    catch_ids: Vec<u64>,
) -> Result<(), String> {
    let player_id = crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?;
    let key = format!("{}:{player_id}", ctx.sender());
    if ctx
        .db
        .sale_quote()
        .key()
        .find(&key)
        .is_some_and(|old| ctx.timestamp < old.created_at + TimeDuration::from_micros(1_000_000))
    {
        return Err("ACTION_RATE_LIMITED".into());
    }
    let quoted_coins = sale_total(ctx, player_id, &catch_ids)?;
    let quote = SaleQuote {
        key,
        identity: ctx.sender(),
        player_id,
        nonce: ctx.rng().r#gen(),
        catch_ids,
        quoted_coins,
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(120_000_000),
        consumed: false,
    };
    if ctx.db.sale_quote().key().find(&quote.key).is_some() {
        ctx.db.sale_quote().key().update(quote);
    } else {
        ctx.db.sale_quote().insert(quote);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn commit_sale_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    nonce: u128,
) -> Result<(), String> {
    let player_id = crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?;
    let mut quote = ctx
        .db
        .sale_quote()
        .key()
        .find(format!("{}:{player_id}", ctx.sender()))
        .ok_or("ACTION_NOT_FOUND")?;
    if quote.nonce != nonce || quote.identity != ctx.sender() || quote.player_id != player_id {
        return Err("ACTION_CONFLICT".into());
    }
    if quote.consumed {
        return Ok(());
    }
    if ctx.timestamp >= quote.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    settle_sale(
        ctx,
        player_id,
        &quote.catch_ids,
        quote.quoted_coins,
        format!("discord-sale:{nonce}"),
    )?;
    quote.consumed = true;
    ctx.db.sale_quote().key().update(quote);
    Ok(())
}

#[spacetimedb::view(accessor = adapter_sale_quote, public)]
pub fn adapter_sale_quote(ctx: &ViewContext) -> Option<SaleQuote> {
    let player_id = crate::shop::selected(ctx)?;
    ctx.db
        .sale_quote()
        .key()
        .find(format!("{}:{player_id}", ctx.sender()))
}

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

/// Persist an explicit favorite state in one transaction. Repeated requests are
/// idempotent and cannot alter another angler's catch or spend any resources.
#[spacetimedb::reducer]
pub fn set_catch_favorite(
    ctx: &ReducerContext,
    catch_id: u64,
    favorite: bool,
) -> Result<(), String> {
    let player_id = player_id(ctx)?;
    let mut fish = ctx
        .db
        .owned_specimen()
        .catch_id()
        .find(catch_id)
        .ok_or("CATCH_UNAVAILABLE")?;
    if fish.player_id != player_id {
        return Err("CATCH_NOT_OWNED".into());
    }
    if fish.favorite != favorite {
        fish.favorite = favorite;
        ctx.db.owned_specimen().catch_id().update(fish);
    }
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
    if action.consumed {
        return Ok(());
    }
    if ctx.timestamp >= action.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    if action.kind == "sell" {
        settle_sale(
            ctx,
            player_id,
            &action.catch_ids,
            action.quoted_coins,
            format!("web:{nonce}"),
        )?;
    } else {
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
            fish.favorite = action.favorite;
            ctx.db.owned_specimen().catch_id().update(fish);
        }
    }
    action.consumed = true;
    ctx.db.action_nonce().identity().update(action);
    // Collection, recent catches, and record snapshots are intentionally retained.
    Ok(())
}
