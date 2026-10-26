use crate::hauls::haul_request;
use crate::{
    accounts::{ensure_player, require_service, service_can_read},
    inventory::ledger,
    tables::*,
};
use game_rules::{
    daily::{award, next_reset, stamps, utc_day},
    progression::check_interaction_freshness,
};
use spacetimedb::{ReducerContext, Table, Timestamp, ViewContext};

#[spacetimedb::reducer]
pub fn daily_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    guild_id: Option<u64>,
    channel_id: u64,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if ctx
        .db
        .haul_request()
        .interaction_id()
        .find(interaction_id)
        .is_some()
    {
        return Err("REQUEST_CONFLICT".into());
    }
    if channel_id == 0 {
        return Err("INVALID_CHANNEL".into());
    }
    if let Some(receipt) = ctx.db.daily_receipt().interaction_id().find(interaction_id) {
        return if receipt.discord_user_id == discord_user_id
            && receipt.guild_id == guild_id
            && receipt.channel_id == channel_id
        {
            Ok(())
        } else {
            Err("REQUEST_CONFLICT".into())
        };
    }
    if ctx
        .db
        .command_receipt()
        .interaction_id()
        .find(interaction_id)
        .is_some()
    {
        return Err("REQUEST_CONFLICT".into());
    }
    check_interaction_freshness(interaction_id, ctx.timestamp.to_micros_since_unix_epoch())?;
    let player = ensure_player(ctx, discord_user_id, "Angler")?;
    let player_id = player.player_id;
    let (claimed, coins, claims, next_at) = claim_delivery(ctx, player_id)?;
    ctx.db.daily_receipt().insert(DailyReceipt {
        interaction_id,
        player_id,
        discord_user_id,
        guild_id,
        channel_id,
        claimed,
        coins_granted: coins,
        total_claims: claims,
        stamps: stamps(claims),
        next_delivery_at: next_at,
        created_at: ctx.timestamp,
    });
    Ok(())
}

#[spacetimedb::view(accessor = adapter_daily_receipt, public)]
pub fn adapter_daily_receipt(ctx: &ViewContext) -> Option<DailyReceipt> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return None;
    }
    let selection = ctx.db.service_selection().identity().find(ctx.sender())?;
    ctx.db
        .daily_receipt()
        .interaction_id()
        .find(selection.interaction_id)
        .filter(|row| row.player_id == selection.player_id)
}

// Shared by web and Discord: one stamp card and one reward per UTC day.
fn claim_delivery(
    ctx: &ReducerContext,
    player_id: u64,
) -> Result<(bool, u64, u64, Timestamp), String> {
    crate::quests::refresh_calendar(ctx);
    let today = utc_day(ctx.timestamp.to_micros_since_unix_epoch());
    let previous = ctx.db.daily_delivery().player_id().find(player_id);
    let total = previous.as_ref().map_or(0, |r| r.total_claims);
    let reward = award(total, previous.as_ref().map(|r| r.last_claim_day), today)?;
    let Some((claims, coins)) = reward else {
        return Ok((
            false,
            0,
            total,
            Timestamp::from_micros_since_unix_epoch(next_reset(today)?),
        ));
    };
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    player.coins = player.coins.checked_add(coins).ok_or("COIN_OVERFLOW")?;
    ctx.db.player().player_id().update(player);
    let row = DailyDelivery {
        player_id,
        last_claim_day: today,
        total_claims: claims,
    };
    if previous.is_some() {
        ctx.db.daily_delivery().player_id().update(row);
    } else {
        ctx.db.daily_delivery().insert(row);
    }
    ledger(
        ctx,
        player_id,
        format!("daily:{player_id}:{today}"),
        "coins",
        coins as i64,
        "dockside_delivery",
    );
    Ok((
        true,
        coins,
        claims,
        Timestamp::from_micros_since_unix_epoch(next_reset(today)?),
    ))
}

#[spacetimedb::reducer]
pub fn claim_dockside_delivery(ctx: &ReducerContext) -> Result<(), String> {
    claim_delivery(ctx, crate::shop::browser_player(ctx)?)?;
    Ok(())
}

#[spacetimedb::view(accessor = my_dockside, public)]
pub fn my_dockside(ctx: &ViewContext) -> Option<DocksideStatus> {
    let id = crate::accounts::current_player(ctx)?;
    let calendar = ctx.db.quest_calendar().singleton().find(1)?;
    let previous = ctx.db.daily_delivery().player_id().find(id);
    let total = previous.as_ref().map_or(0, |r| r.total_claims);
    let next_reward = game_rules::daily::BASE_COINS
        + if (total + 1).is_multiple_of(game_rules::daily::CARD_SIZE) {
            game_rules::daily::BONUS_COINS
        } else {
            0
        };
    Some(DocksideStatus {
        available: previous.is_none_or(|r| r.last_claim_day < calendar.day),
        total_claims: total,
        stamps: stamps(total),
        next_reward,
        next_reset_at: Timestamp::from_micros_since_unix_epoch(next_reset(calendar.day).ok()?),
    })
}
