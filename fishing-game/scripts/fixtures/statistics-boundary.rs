// Test controls never compiled into the production WASM.
include!("crafting-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_reset_catch_records(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut stats = ctx.db.angler_statistics().player_id().find(player_id).ok_or("STATISTICS_MISSING")?;
    stats.records_set = 0;
    stats.records_tracked_since = ctx.timestamp;
    stats.biggest_single_sale = 0;
    ctx.db.angler_statistics().player_id().update(stats);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_hide_fish_history(ctx: &ReducerContext, hidden: bool) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut row = ctx.db.journal_entry().iter().find(|entry| entry.catch_id.is_some() && entry.outcome == if hidden { "fish" } else { "junk" }).ok_or("JOURNAL_MISSING")?;
    row.outcome = if hidden { "junk" } else { "fish" }.into();
    ctx.db.journal_entry().key().update(row);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_mismatch_record(ctx: &ReducerContext, mismatch: bool) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut row = ctx.db.species_record().iter().min_by_key(|row| row.key).ok_or("RECORD_MISSING")?;
    row.measurement = if mismatch { row.measurement + 1 } else { row.measurement - 1 };
    ctx.db.species_record().key().update(row);
    Ok(())
}

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
