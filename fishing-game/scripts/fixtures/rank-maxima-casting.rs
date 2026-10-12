// This source is only included in the new isolated proof module.
#[spacetimedb::reducer]
pub fn proof_rank_cast_setup(ctx: &ReducerContext, discord_user_id: u64, species_id: u32) -> Result<(), String> {
    proof_cast_setup(ctx,discord_user_id,0,0)?;
    let mut fish = ctx.db.species_definition().species_id().find(species_id).ok_or("SPECIES_UNAVAILABLE")?;
    fish.typical_length_mm=2;fish.min_length_mm=1;fish.max_length_mm=4;
    fish.typical_weight_g=1;fish.min_weight_g=1;fish.max_weight_g=8;
    ctx.db.species_definition().species_id().update(fish);
    for mut rank in ctx.db.species_rank_definition().iter().filter(|rank| rank.biome_id==1).collect::<Vec<_>>() {
        rank.encounter_weight = if rank.species_id == species_id && rank.ordinal == 0 {1000000} else {0};
        ctx.db.species_rank_definition().key().update(rank);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_rank_prune(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let player=accounts::ensure_player(ctx,discord_user_id,"Rank maxima proof")?;
    for row in ctx.db.owned_specimen().player_id().filter(player.player_id).collect::<Vec<_>>() {ctx.db.owned_specimen().catch_id().delete(row.catch_id);}
    for row in ctx.db.journal_entry().player_id().filter(player.player_id).collect::<Vec<_>>() {ctx.db.journal_entry().key().delete(row.key);}
    for row in ctx.db.command_receipt().player_id().filter(player.player_id).collect::<Vec<_>>() {ctx.db.command_receipt().interaction_id().delete(row.interaction_id);}
    for row in ctx.db.cast_pull().iter().filter(|row|row.player_id==player.player_id).collect::<Vec<_>>() {ctx.db.cast_pull().key().delete(row.key);}
    Ok(())
}
