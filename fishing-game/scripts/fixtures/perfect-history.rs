// Isolated proof only. This also compiles against the previous schema.
#[spacetimedb::reducer]
pub fn proof_perfect_history(ctx: &ReducerContext, discord_user_id: u64, species_id: u32, length_mm: u32, weight_g: u64, catch_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Perfect proof")?;
    ctx.db.journal_entry().insert(JournalEntry { key: format!("perfect-proof:{catch_id}"), player_id: player.player_id, catch_id: Some(catch_id), caught_at: ctx.timestamp, outcome: "fish".into(), species_id: Some(species_id), name: "Perfect history proof".into(), rarity: "UUR".into(), length_mm, weight_g, xp_granted: Some(0), biome_id: Some(1) });
    Ok(())
}
