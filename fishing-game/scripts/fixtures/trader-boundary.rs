// Owner-only setup for isolated proof databases. Never included in production.
#[spacetimedb::reducer]
pub fn prepare_trader_player(ctx: &ReducerContext, discord_user_id: u64, xp: u64, coins: u64, biome_id: u32) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_UNAVAILABLE")?.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Trader proof")?;
    player.total_xp = xp;
    player.coins = coins;
    player.selected_biome_id = biome_id;
    let id = player.player_id;
    ctx.db.player().player_id().update(player);
    records::refresh_player(ctx, id)?;
    Ok(())
}

#[spacetimedb::reducer]
pub fn change_proof_offer(ctx: &ReducerContext, listing_id: u32, price: u64) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_UNAVAILABLE")?.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    let mut listing = ctx.db.shop_listing().listing_id().find(listing_id).ok_or("SHOP_ITEM_UNAVAILABLE")?;
    listing.price_coins = price;
    listing.catalog_version += 1;
    ctx.db.shop_listing().listing_id().update(listing);
    Ok(())
}

#[spacetimedb::reducer]
pub fn expire_proof_quote(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_UNAVAILABLE")?.identity != ctx.sender() { return Err("OWNER_REQUIRED".into()); }
    let mut quote = ctx.db.shop_quote().nonce().find(nonce).ok_or("ACTION_NOT_FOUND")?;
    quote.expires_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1_000_000);
    ctx.db.shop_quote().key().update(quote);
    Ok(())
}
