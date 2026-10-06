// Isolated proof controls. Never compiled into the production module.
include!("crafting-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_social_history(ctx: &ReducerContext, discord_user_id: u64, bonus: bool) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Social proof")?;
    let player_id = player.player_id;
    player.completed_casts = 1000;
    player.fish_count = 1000;
    ctx.db.player().player_id().update(player);
    let mut ordinary = 0;
    for species in ctx.db.species_definition().iter() {
        if species.counts_for_ordinary_collection_completion {
            if species.biome_id != 1 && ordinary >= 100 { continue; }
            ordinary += 1;
        } else if !bonus { continue; }
        let key = u128::from(player_id) << 32 | u128::from(species.species_id);
        let mut counts = vec![0; 10]; counts[4] = 1;
        let row = PlayerSpeciesProgress { key, player_id, species_id: species.species_id, count: 1, rank_counts: counts, first_caught_at: ctx.timestamp, best_length_mm: species.typical_length_mm * 2, best_weight_g: species.typical_weight_g };
        if ctx.db.player_species_progress().key().find(key).is_some() { ctx.db.player_species_progress().key().update(row); }
        else { ctx.db.player_species_progress().insert(row); }
    }
    for biome_id in [2u32, 7] {
        let key = u128::from(player_id) << 32 | u128::from(biome_id);
        if ctx.db.owned_biome_licence().key().find(key).is_none() { ctx.db.owned_biome_licence().insert(OwnedBiomeLicence { key, player_id, biome_id }); }
    }
    let mut rod = ctx.db.owned_rod().key().find(u128::from(player_id) << 32 | 1).ok_or("ROD_NOT_OWNED")?;
    rod.upgrade_level = 6;
    ctx.db.owned_rod().key().update(rod);
    // Deliberately do not refresh milestones: exercise retroactive backfill.
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_expire_sale(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut row = ctx.db.sale_quote().nonce().find(nonce).ok_or("ACTION_NOT_FOUND")?;
    row.expires_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1_000_000);
    ctx.db.sale_quote().key().update(row);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_sale_value(ctx: &ReducerContext, catch_id: u64, coins: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut fish = ctx.db.owned_specimen().catch_id().find(catch_id).ok_or("CATCH_UNAVAILABLE")?;
    fish.sale_value_coins = coins;
    ctx.db.owned_specimen().catch_id().update(fish);
    Ok(())
}
