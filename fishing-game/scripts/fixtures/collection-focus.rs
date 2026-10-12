// Owner-only controls for fresh, isolated Collection Focus proof databases.
#[spacetimedb::reducer]
pub fn proof_focus_setup(ctx: &ReducerContext, discord_user_id: u64, biome_id: u32, species_id: u32, known_mask: u16, power: u32, master: bool, exceptional: bool) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Focus proof")?;
    player.selected_biome_id = biome_id;
    player.equipped_rod_id = 1;
    player.total_xp = 10_000_000;
    player.next_cast_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1);
    let id = player.player_id;
    ctx.db.player().player_id().update(player);
    for fish in ctx.db.species_definition().biome_id().filter(biome_id).collect::<Vec<_>>() {
        let key = (u128::from(id) << 32) | u128::from(fish.species_id);
        if !master || !fish.counts_for_ordinary_collection_completion { ctx.db.player_species_progress().key().delete(key); continue; }
        let mask = if fish.species_id == species_id { known_mask } else { 1 };
        let row = PlayerSpeciesProgress { key, player_id: id, species_id: fish.species_id, count: 1, rank_counts: (0..10).map(|i| u64::from(mask & (1 << i) != 0)).collect(), first_caught_at: ctx.timestamp, best_length_mm: 1, best_weight_g: 1 };
        if ctx.db.player_species_progress().key().find(key).is_some() { ctx.db.player_species_progress().key().update(row); } else { ctx.db.player_species_progress().insert(row); }
    }
    if biome_id != 1 {
        let key = (u128::from(id) << 32) | u128::from(biome_id);
        if ctx.db.owned_biome_licence().key().find(key).is_none() { ctx.db.owned_biome_licence().insert(OwnedBiomeLicence { key, player_id: id, biome_id }); }
    }
    let mut biome = ctx.db.biome_definition().biome_id().find(biome_id).ok_or("BIOME_UNAVAILABLE")?;
    biome.fish_weight=100; biome.junk_weight=0; biome.treasure_weight=0;
    ctx.db.biome_definition().biome_id().update(biome);
    for mut band in ctx.db.species_rank_definition().biome_id().filter(biome_id).collect::<Vec<_>>() {
        let fish = ctx.db.species_definition().species_id().find(band.species_id).ok_or("CONTENT_UNAVAILABLE")?;
        band.encounter_weight = if exceptional { if fish.key=="fihs" {128} else if fish.key=="nidalees-lost-sock" {64} else if band.species_id==species_id && band.ordinal==0 {1} else {0} }
            else if band.species_id==species_id && band.ordinal==0 {900} else if band.species_id==species_id && band.ordinal==9 {100} else {0};
        ctx.db.species_rank_definition().key().update(band);
    }
    let mut rod = ctx.db.rod_definition().rod_id().find(1).ok_or("ROD_UNAVAILABLE")?; rod.power=power; ctx.db.rod_definition().rod_id().update(rod);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_focus_cast(ctx: &ReducerContext, discord_user_id: u64, interaction_id: u64, species_id: u32, known_mask: u16) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player=ctx.db.player().discord_user_id().find(discord_user_id).ok_or("PLAYER_NOT_FOUND")?;
    player.next_cast_at=ctx.timestamp-spacetimedb::TimeDuration::from_micros(1);
    let key=(u128::from(player.player_id)<<32)|u128::from(species_id);
    ctx.db.player().player_id().update(player);
    let mut progress=ctx.db.player_species_progress().key().find(key).ok_or("CONTENT_UNAVAILABLE")?;
    progress.rank_counts=(0..10).map(|i|u64::from(known_mask&(1<<i)!=0)).collect();
    ctx.db.player_species_progress().key().update(progress);
    casting::fish_from_discord(ctx,discord_user_id,interaction_id,None,42)
}
