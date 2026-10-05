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
    let mut player = ensure_player(ctx, discord_user_id, "Angler")?;
    let player_id = player.player_id;
    let today = utc_day(ctx.timestamp.to_micros_since_unix_epoch());
    let previous = ctx.db.daily_delivery().player_id().find(player_id);
    let last_day = previous.as_ref().map(|row| row.last_claim_day);
    let mut claims = previous.as_ref().map_or(0, |row| row.total_claims);
    let reward = award(claims, last_day, today)?;
    let coins = if let Some((count, coins)) = reward {
        claims = count;
        player.coins = player.coins.checked_add(coins).ok_or("COIN_OVERFLOW")?;
        ctx.db.player().player_id().update(player);
        let state = DailyDelivery {
            player_id,
            last_claim_day: today,
            total_claims: claims,
        };
        if previous.is_some() {
            ctx.db.daily_delivery().player_id().update(state);
        } else {
            ctx.db.daily_delivery().insert(state);
        }
        ledger(
            ctx,
            player_id,
            format!("daily:{interaction_id}"),
            "coins",
            coins as i64,
            "dockside_delivery",
        );
        coins
    } else {
        0
    };
    ctx.db.daily_receipt().insert(DailyReceipt {
        interaction_id,
        player_id,
        discord_user_id,
        guild_id,
        channel_id,
        claimed: reward.is_some(),
        coins_granted: coins,
        total_claims: claims,
        stamps: stamps(claims),
        next_delivery_at: Timestamp::from_micros_since_unix_epoch(next_reset(
            last_day.unwrap_or(today).max(today),
        )?),
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
