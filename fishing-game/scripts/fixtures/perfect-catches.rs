// Test-only real-specimen recognition. Never included in the production module.
#[spacetimedb::reducer]
pub fn proof_perfect_specimen(ctx: &ReducerContext, discord_user_id: u64, species_id: u32, length_mm: u32, weight_g: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Perfect proof")?;
    let definition = ctx.db.species_definition().species_id().find(species_id).ok_or("CONTENT_UNAVAILABLE")?;
    let rules = accounts::config(ctx);
    let specimen = ctx.db.owned_specimen().insert(OwnedSpecimen { catch_id: 0, player_id: player.player_id, species_id, rarity: definition.allowed_rarities.last().ok_or("CONTENT_UNAVAILABLE")?.clone(), length_mm, weight_g, size_grade: 5, caught_at: ctx.timestamp, sale_value_coins: 10, favorite: false, rules_version: rules.version, content_version: content::CONTENT_VERSION, biome_id: definition.biome_id, source_guild_id: None });
    player.kept_count += 1;
    let player_id = player.player_id;
    ctx.db.player().player_id().update(player);
    perfection::observe(ctx, player_id, species_id, specimen.catch_id, ctx.timestamp, length_mm, weight_g)?;
    records::refresh_player(ctx, player_id)?;
    Ok(())
}
