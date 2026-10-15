//! Durable pending deliveries. Awards are authoritative; Discord is an adapter.
use crate::{
    accounts::{require_service, service_can_read},
    tables::*,
};
use spacetimedb::{Identity, ReducerContext, Table, TimeDuration, Timestamp, ViewContext};

#[spacetimedb::table(accessor = achievement_route)]
pub struct AchievementRoute {
    #[primary_key]
    pub discord_user_id: u64,
    pub channel_id: u64,
    pub updated_at: Timestamp,
}

#[spacetimedb::table(accessor = achievement_delivery)]
pub struct AchievementDelivery {
    #[primary_key]
    pub key: u128,
    #[index(btree)]
    pub player_id: u64,
    pub achievement_id: u32,
    pub discord_user_id: u64,
    pub created_at: Timestamp,
    #[index(btree)]
    pub available_at: Timestamp,
    pub channel_id: u64,
    pub lease_owner: Identity,
    pub lease_nonce: u128,
    pub attempts: u32,
}

pub fn enqueue(ctx: &ReducerContext, key: u128) {
    // Owner-triggered maintenance/backfills never announce historical awards.
    if ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .is_some_and(|owner| owner.identity == ctx.sender())
    {
        return;
    }
    if ctx.db.achievement_delivery().key().find(key).is_some() {
        return;
    }
    let Some(award) = ctx.db.earned_achievement().key().find(key) else {
        return;
    };
    let Some(player) = ctx.db.player().player_id().find(award.player_id) else {
        return;
    };
    ctx.db.achievement_delivery().insert(AchievementDelivery {
        key,
        player_id: award.player_id,
        achievement_id: award.achievement_id,
        discord_user_id: player.discord_user_id,
        created_at: ctx.timestamp,
        available_at: ctx.timestamp,
        channel_id: 0,
        lease_owner: Identity::ZERO,
        lease_nonce: 0,
        attempts: 0,
    });
}

#[spacetimedb::reducer]
pub fn record_achievement_route(
    ctx: &ReducerContext,
    discord_user_id: u64,
    channel_id: u64,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if discord_user_id == 0 || channel_id == 0 {
        return Err("INVALID_CHANNEL".into());
    }
    let row = AchievementRoute {
        discord_user_id,
        channel_id,
        updated_at: ctx.timestamp,
    };
    if ctx
        .db
        .achievement_route()
        .discord_user_id()
        .find(discord_user_id)
        .is_some()
    {
        ctx.db.achievement_route().discord_user_id().update(row);
    } else {
        ctx.db.achievement_route().insert(row);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn claim_achievement_delivery(
    ctx: &ReducerContext,
    key: u128,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if lease_nonce == 0 {
        return Err("INVALID_LEASE".into());
    }
    let Some(mut row) = ctx.db.achievement_delivery().key().find(key) else {
        return Ok(());
    };
    if row.available_at > ctx.timestamp {
        return Ok(());
    }
    let route = ctx
        .db
        .achievement_route()
        .discord_user_id()
        .find(row.discord_user_id);
    if let Some(route) = route {
        row.channel_id = route.channel_id;
        row.lease_owner = ctx.sender();
        row.lease_nonce = lease_nonce;
        row.attempts = row.attempts.saturating_add(1);
        row.available_at = ctx.timestamp + TimeDuration::from_micros(120_000_000);
    } else {
        row.available_at = ctx.timestamp + TimeDuration::from_micros(60_000_000);
    }
    ctx.db.achievement_delivery().key().update(row);
    Ok(())
}

#[spacetimedb::reducer]
pub fn acknowledge_achievement_delivery(
    ctx: &ReducerContext,
    key: u128,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    let Some(row) = ctx.db.achievement_delivery().key().find(key) else {
        return Ok(());
    };
    if row.lease_owner != ctx.sender() || row.lease_nonce != lease_nonce || lease_nonce == 0 {
        return Err("DELIVERY_LEASE_CHANGED".into());
    }
    // Removing the pending row is the durable acknowledgement. Existing award
    // rows are never replayed into this queue on initial load or reconnection.
    ctx.db.achievement_delivery().key().delete(key);
    Ok(())
}

#[spacetimedb::view(accessor = adapter_achievement_deliveries, public)]
pub fn adapter_achievement_deliveries(ctx: &ViewContext) -> Vec<AchievementDelivery> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return vec![];
    }
    ctx.db
        .achievement_delivery()
        .available_at()
        .filter(Timestamp::UNIX_EPOCH..)
        .take(100)
        .collect()
}
