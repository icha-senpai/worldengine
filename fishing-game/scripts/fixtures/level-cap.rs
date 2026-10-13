// Owner-only setup for an isolated old-cap to new-cap proof. Never shipped.
#[spacetimedb::reducer]
pub fn proof_previous_display_cap(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let mut previous = accounts::config(ctx);
    let candidate_version = previous.version;
    previous.version = 15;
    previous.level_cap = 10_000;
    if ctx.db.game_config().version().find(15).is_some() {
        ctx.db.game_config().version().update(previous);
    } else {
        ctx.db.game_config().insert(previous);
    }
    ctx.db.game_config().version().delete(candidate_version);
    for mut profile in ctx.db.public_profile().iter() {
        let player = ctx.db.player().player_id().find(profile.player_id).ok_or("PLAYER_NOT_FOUND")?;
        profile.level = game_rules::progression::level_for_xp_for_version(player.total_xp, 10_000, 15);
        ctx.db.public_profile().player_id().update(profile);
    }
    Ok(())
}
