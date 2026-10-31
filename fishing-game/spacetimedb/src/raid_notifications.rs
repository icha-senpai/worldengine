use crate::{
    accounts::{require_service, service_can_read},
    notifications::achievement_route,
    raids::{RaidAttempt, community_raid_event},
    tables::*,
};
use spacetimedb::{Identity, ReducerContext, Table, TimeDuration, Timestamp, ViewContext};

#[spacetimedb::table(accessor = raid_delivery)]
pub struct RaidDelivery {
    #[primary_key]
    pub attempt_id: u128,
    pub player_id: u64,
    pub discord_user_id: u64,
    pub display_name: String,
    pub kind: String,
    pub target_name: String,
    pub species_asset: String,
    pub grade: Option<u8>,
    pub awarded_fillets: u64,
    pub awarded_scales: u64,
    pub first_clear: bool,
    pub special_catch: Option<CommandReceipt>,
    pub completed_at: Timestamp,
    #[index(btree)]
    pub available_at: Timestamp,
    pub channel_id: u64,
    pub lease_owner: Identity,
    pub lease_nonce: u128,
    pub attempts: u32,
}

pub fn enqueue(
    ctx: &ReducerContext,
    attempt: &RaidAttempt,
    first_clear: bool,
    special_catch: Option<CommandReceipt>,
) {
    if ctx
        .db
        .raid_delivery()
        .attempt_id()
        .find(attempt.id)
        .is_some()
    {
        return;
    }
    let Some(player) = ctx.db.player().player_id().find(attempt.player_id) else {
        return;
    };
    let species = attempt.special_species.clone().or_else(|| {
        attempt
            .species_id
            .and_then(|id| ctx.db.species_definition().species_id().find(id))
    });
    let (target_name, species_asset) = if attempt.kind == "community" {
        let name = attempt
            .event_id
            .and_then(|id| ctx.db.community_raid_event().event_id().find(id))
            .map_or_else(|| "Community expedition".into(), |event| event.name);
        (name, String::new())
    } else {
        species.map_or_else(
            || ("Fish".into(), String::new()),
            |row| (row.name, row.sprite_asset),
        )
    };
    ctx.db.raid_delivery().insert(RaidDelivery {
        attempt_id: attempt.id,
        player_id: attempt.player_id,
        discord_user_id: player.discord_user_id,
        display_name: player.display_name,
        kind: attempt.kind.clone(),
        target_name,
        species_asset,
        grade: attempt.grade,
        awarded_fillets: attempt.awarded_fillets,
        awarded_scales: attempt.awarded_scales,
        first_clear,
        special_catch,
        completed_at: ctx.timestamp,
        available_at: ctx.timestamp,
        channel_id: 0,
        lease_owner: Identity::ZERO,
        lease_nonce: 0,
        attempts: 0,
    });
}

#[spacetimedb::reducer]
pub fn claim_raid_delivery(
    ctx: &ReducerContext,
    attempt_id: u128,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if lease_nonce == 0 {
        return Err("INVALID_LEASE".into());
    }
    let Some(mut row) = ctx.db.raid_delivery().attempt_id().find(attempt_id) else {
        return Ok(());
    };
    if row.available_at > ctx.timestamp {
        return Ok(());
    }
    if row.channel_id == 0 {
        if let Some(route) = ctx
            .db
            .achievement_route()
            .discord_user_id()
            .find(row.discord_user_id)
        {
            row.channel_id = route.channel_id;
        }
    }
    if row.channel_id == 0 {
        row.available_at = ctx.timestamp + TimeDuration::from_micros(60_000_000);
    } else {
        row.lease_owner = ctx.sender();
        row.lease_nonce = lease_nonce;
        row.attempts = row.attempts.saturating_add(1);
        row.available_at = ctx.timestamp + TimeDuration::from_micros(120_000_000);
    }
    ctx.db.raid_delivery().attempt_id().update(row);
    Ok(())
}

#[spacetimedb::reducer]
pub fn acknowledge_raid_delivery(
    ctx: &ReducerContext,
    attempt_id: u128,
    lease_nonce: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    let Some(row) = ctx.db.raid_delivery().attempt_id().find(attempt_id) else {
        return Ok(());
    };
    if lease_nonce == 0 || row.lease_nonce != lease_nonce || row.lease_owner != ctx.sender() {
        return Err("DELIVERY_LEASE_CHANGED".into());
    }
    ctx.db.raid_delivery().attempt_id().delete(attempt_id);
    Ok(())
}

#[spacetimedb::view(accessor = adapter_raid_deliveries, public)]
pub fn adapter_raid_deliveries(ctx: &ViewContext) -> Vec<RaidDelivery> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return vec![];
    }
    ctx.db
        .raid_delivery()
        .available_at()
        .filter(Timestamp::UNIX_EPOCH..)
        .take(100)
        .collect()
}
