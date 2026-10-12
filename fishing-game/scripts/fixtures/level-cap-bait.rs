// Only included in the isolated crafting proof module.
#[spacetimedb::reducer]
pub fn proof_previous_cap_and_trophy_price(ctx: &ReducerContext) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut config = accounts::config(ctx);
    let version = config.version;
    config.level_cap = 120;
    ctx.db.game_config().version().update(config);
    let mut listing = ctx.db.shop_listing().listing_id().find(113).ok_or("SHOP_ITEM_UNAVAILABLE")?;
    listing.price_coins = 1000;
    listing.catalog_version = 7;
    ctx.db.shop_listing().listing_id().update(listing);
    for mut bait in ctx.db.bait_definition().iter() {
        if (3..=5).contains(&bait.bait_id) { bait.size_rolls = 1; }
        ctx.db.bait_definition().bait_id().update(bait);
    }
    for mut profile in ctx.db.public_profile().iter() {
        let player = ctx.db.player().player_id().find(profile.player_id).ok_or("PLAYER_NOT_FOUND")?;
        profile.level = game_rules::progression::level_for_xp_for_version(player.total_xp, 120, version);
        ctx.db.public_profile().player_id().update(profile);
    }
    Ok(())
}
