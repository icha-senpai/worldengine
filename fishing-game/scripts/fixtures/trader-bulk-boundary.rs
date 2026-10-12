// Owner-only controls included only in isolated trader proof modules.
include!("trader-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_bait_uses(ctx: &ReducerContext, discord_user_id: u64, bait_id: u32, uses: u64) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_UNAVAILABLE")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let player = accounts::ensure_player(ctx, discord_user_id, "Bulk trader proof")?;
    let key = u128::from(player.player_id) << 32 | u128::from(bait_id);
    let row = BaitStack { key, player_id: player.player_id, bait_id, uses_left: uses };
    if ctx.db.bait_stack().key().find(key).is_some() { ctx.db.bait_stack().key().update(row); }
    else { ctx.db.bait_stack().insert(row); }
    Ok(())
}
