// Test-only controls compiled into isolated loopback proof databases.
include!("trader-boundary.rs");

fn proof_owner(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    Ok(())
}
#[spacetimedb::reducer]
pub fn proof_materials(ctx: &ReducerContext, discord_user_id: u64, tin: u64, scrap: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Crafting proof")?;
    for (slot, item, quantity) in [(2u32,"rusted_tin",tin),(1,"scrap",scrap)] {
        let key = u128::from(player.player_id) << 32 | u128::from(slot);
        let row = ItemStack { key, player_id: player.player_id, item: item.into(), quantity };
        if ctx.db.item_stack().key().find(key).is_some() {ctx.db.item_stack().key().update(row);} else {ctx.db.item_stack().insert(row);}
    }
    Ok(())
}
#[spacetimedb::reducer]
pub fn proof_cast_setup(ctx: &ReducerContext, discord_user_id: u64, twig_power: u32, category: u8) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Crafting proof")?;
    player.next_cast_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1);
    ctx.db.player().player_id().update(player);
    let mut rod = ctx.db.rod_definition().rod_id().find(1).ok_or("ROD_UNAVAILABLE")?;
    rod.power = twig_power;
    ctx.db.rod_definition().rod_id().update(rod);
    let mut biome = ctx.db.biome_definition().biome_id().find(1).ok_or("BIOME_UNAVAILABLE")?;
    (biome.fish_weight,biome.junk_weight,biome.treasure_weight) = match category {0=>(100,0,0),1=>(0,100,0),2=>(0,0,100),_=>(50,25,25)};
    ctx.db.biome_definition().biome_id().update(biome);
    Ok(())
}
#[spacetimedb::reducer]
pub fn proof_expire_upgrade(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut quote=ctx.db.upgrade_quote().nonce().find(nonce).ok_or("ACTION_NOT_FOUND")?;
    quote.expires_at=ctx.timestamp-spacetimedb::TimeDuration::from_micros(1);
    ctx.db.upgrade_quote().key().update(quote);
    Ok(())
}
#[spacetimedb::reducer]
pub fn proof_change_quality(ctx: &ReducerContext, quality_level: u8) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut quality=ctx.db.quality_definition().quality_level().find(quality_level).ok_or("MAX_QUALITY")?;
    quality.version+=1;
    ctx.db.quality_definition().quality_level().update(quality);
    Ok(())
}
