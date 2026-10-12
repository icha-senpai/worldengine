// Owner-only setup in a fresh multi-bait proof database; never in production.
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


#[spacetimedb::reducer]
pub fn proof_multi_setup(ctx:&ReducerContext,discord_user_id:u64,first:u32,first_uses:u64,second:u32,second_uses:u64,power:u32,category:u8) -> Result<(),String> {
    proof_owner(ctx)?;
    let mut player=accounts::ensure_player(ctx,discord_user_id,"Multi-bait proof")?;
    player.total_xp=0;player.selected_biome_id=1;player.equipped_rod_id=1;
    let key=u128::from(player.player_id)<<32|1;
    let mut rod=ctx.db.owned_rod().key().find(key).ok_or("ROD_NOT_OWNED")?;rod.upgrade_level=0;ctx.db.owned_rod().key().update(rod);
    let id=player.player_id;ctx.db.player().player_id().update(player);
    ctx.db.bait_loadout().player_id().delete(id);
    if first!=0 {proof_bait_uses(ctx,discord_user_id,first,first_uses)?;}
    if second!=0 && first!=second {proof_bait_uses(ctx,discord_user_id,second,second_uses)?;}
    proof_cast_setup(ctx,discord_user_id,power,category)
}
