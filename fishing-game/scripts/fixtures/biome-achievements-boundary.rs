// Only included in the isolated achievement proof WASM.
include!("social-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_collection_history(ctx: &ReducerContext, discord_user_id: u64, biome_id: u32, rank_mask: u16, omit_species: u32, repeats: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Collection proof")?;
    for species in ctx.db.species_definition().iter() {
        if !species.counts_for_ordinary_collection_completion || species.species_id == omit_species || (biome_id != 0 && species.biome_id != biome_id) { continue; }
        let key = u128::from(player.player_id) << 32 | u128::from(species.species_id);
        let mut row = ctx.db.player_species_progress().key().find(key).unwrap_or(PlayerSpeciesProgress {
            key, player_id: player.player_id, species_id: species.species_id, count: 0, rank_counts: vec![0;10], first_caught_at: ctx.timestamp,
            best_length_mm: species.typical_length_mm, best_weight_g: species.typical_weight_g,
        });
        for ordinal in 0..10 { if rank_mask & (1 << ordinal) != 0 { row.rank_counts[ordinal] = repeats; } }
        row.count = row.rank_counts.iter().sum();
        if ctx.db.player_species_progress().key().find(key).is_some() { ctx.db.player_species_progress().key().update(row); }
        else { ctx.db.player_species_progress().insert(row); }
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_last_collection_fish(ctx: &ReducerContext, discord_user_id: u64, species_id: u32) -> Result<(), String> {
    proof_cast_setup(ctx, discord_user_id, 0, 0)?;
    for mut band in ctx.db.species_rank_definition().biome_id().filter(1u32) {
        band.encounter_weight = if band.species_id == species_id && band.ordinal == 0 { 1 } else { 0 };
        ctx.db.species_rank_definition().key().update(band);
    }
    Ok(())
}
