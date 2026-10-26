// Owner-only setup compiled exclusively into a uniquely named loopback proof module.
include!("trader-boundary.rs");

fn haul_proof_owner(ctx: &ReducerContext) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_haul_materials(ctx: &ReducerContext, discord_user_id: u64, tin: u64, scrap: u64) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Haul upgrade proof")?;
    for (slot, item, quantity) in [(2u32,"rusted_tin",tin),(1,"scrap",scrap)] {
        let key = (u128::from(player.player_id) << 32) | u128::from(slot);
        let row = ItemStack { key, player_id: player.player_id, item: item.into(), quantity };
        if ctx.db.item_stack().key().find(key).is_some() { ctx.db.item_stack().key().update(row); }
        else { ctx.db.item_stack().insert(row); }
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_change_haul_quote(ctx: &ReducerContext, nonce: u128, change: u8) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let mut quote = ctx.db.haul_upgrade_quote().nonce().find(nonce).ok_or("ACTION_NOT_FOUND")?;
    match change {
        0 => { quote.expires_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1); ctx.db.haul_upgrade_quote().key().update(quote); }
        1 | 3 => {
            let key = (u64::from(quote.rod_id) << 8) | u64::from(quote.from_quality + 1);
            let mut recipe = ctx.db.rod_upgrade_recipe().key().find(key).ok_or("RECIPE_UNAVAILABLE")?;
            if change == 1 { recipe.version += 1; } else { recipe.tin_cost += 1; }
            ctx.db.rod_upgrade_recipe().key().update(recipe);
        }
        2 => {
            let mut quality = ctx.db.quality_definition().quality_level().find(quote.from_quality + 1).ok_or("MAX_QUALITY")?;
            quality.version += 1;
            ctx.db.quality_definition().quality_level().update(quality);
        }
        _ => return Err("INVALID_PROOF_CHANGE".into()),
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_restore_haul_crafting(ctx: &ReducerContext) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    crafting::seed(ctx);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_haul_quality(ctx: &ReducerContext, discord_user_id: u64, equipment_id: u32, quality: u8) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    if quality > 6 { return Err("MAX_QUALITY".into()); }
    let player = accounts::ensure_player(ctx, discord_user_id, "Haul upgrade proof")?;
    let key = (u128::from(player.player_id) << 32) | u128::from(equipment_id);
    let mut owned = ctx.db.owned_haul_equipment().key().find(key).ok_or("EQUIPMENT_NOT_OWNED")?;
    owned.upgrade_level = quality;
    ctx.db.owned_haul_equipment().key().update(owned);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_link_haul_player(ctx: &ReducerContext, discord_user_id: u64, identity: spacetimedb::Identity) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let player = accounts::ensure_player(ctx, discord_user_id, "Boat and net proof")?;
    ctx.db.player_identity().insert(PlayerIdentity { identity, player_id: player.player_id, linked_at: ctx.timestamp });
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_grant_haul_licences(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    if ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let player = accounts::ensure_player(ctx, discord_user_id, "Boat and net proof")?;
    for biome_id in 2..=7 {
        let key = (u128::from(player.player_id) << 32) | u128::from(biome_id);
        if ctx.db.owned_biome_licence().key().find(key).is_none() {
            ctx.db.owned_biome_licence().insert(OwnedBiomeLicence { key, player_id: player.player_id, biome_id });
        }
    }
    Ok(())
}
