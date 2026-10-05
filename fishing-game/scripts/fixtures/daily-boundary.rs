// Only the isolated proof binary includes these owner-only fixtures.
#[spacetimedb::reducer]
pub fn prepare_delivery(ctx: &ReducerContext, discord_user_id: u64, total_claims: u64, days_ago: i64, coins: u64) -> Result<(), String> {
    let owner = ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_UNAVAILABLE")?;
    if owner.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Delivery boundary proof")?;
    let player_id = player.player_id;
    player.coins = coins;
    ctx.db.player().player_id().update(player);
    let row = DailyDelivery {
        player_id, total_claims,
        last_claim_day: game_rules::daily::utc_day(ctx.timestamp.to_micros_since_unix_epoch()) - days_ago,
    };
    if ctx.db.daily_delivery().player_id().find(player_id).is_some() {
        ctx.db.daily_delivery().player_id().update(row);
    } else { ctx.db.daily_delivery().insert(row); }
    Ok(())
}
