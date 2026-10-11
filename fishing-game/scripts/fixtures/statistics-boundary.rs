// Test controls never compiled into the production WASM.
include!("crafting-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_statistics_history(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let earliest = ctx.db.journal_entry().player_id().filter(player_id).map(|row| row.caught_at).min().ok_or("JOURNAL_MISSING")?;
    for mut row in ctx.db.journal_entry().player_id().filter(player_id).collect::<Vec<_>>() {
        if row.caught_at == earliest {
            row.caught_at = row.caught_at - spacetimedb::TimeDuration::from_micros(86_400_000_000);
            ctx.db.journal_entry().key().update(row);
        }
    }
    ctx.db.angler_statistics().player_id().delete(player_id);
    for row in ctx.db.fishing_day().player_id().filter(player_id).collect::<Vec<_>>() { ctx.db.fishing_day().key().delete(row.key); }
    for row in ctx.db.fishing_biome_activity().player_id().filter(player_id).collect::<Vec<_>>() { ctx.db.fishing_biome_activity().key().delete(row.key); }
    Ok(())
}
