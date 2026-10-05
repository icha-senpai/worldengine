use crate::tables::*;
use spacetimedb::{ReducerContext, Table};

pub fn refresh_player(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut discoveries = 0u32;
    let mut uur_count = 0u64;
    for progress in ctx
        .db
        .player_species_progress()
        .player_id()
        .filter(player_id)
    {
        let species = ctx
            .db
            .species_definition()
            .species_id()
            .find(progress.species_id)
            .ok_or("CONTENT_UNAVAILABLE")?;
        if species.counts_for_ordinary_collection_completion {
            discoveries = discoveries.checked_add(1).ok_or("COUNT_OVERFLOW")?;
        } else {
            let row = LegendaryFind {
                key: progress.key,
                player_id,
                display_name: player.display_name.clone(),
                species_id: progress.species_id,
                first_caught_at: progress.first_caught_at,
                count: progress.count,
            };
            if ctx.db.legendary_find().key().find(row.key).is_some() {
                ctx.db.legendary_find().key().update(row);
            } else {
                ctx.db.legendary_find().insert(row);
            }
        }
        uur_count = uur_count
            .checked_add(*progress.rank_counts.get(9).ok_or("CONTENT_UNAVAILABLE")?)
            .ok_or("COUNT_OVERFLOW")?;
    }
    // At most two records per species. Recompute so transfers remove the old holder's point.
    let records_held = ctx
        .db
        .species_record()
        .iter()
        .filter(|row| row.player_id == player_id)
        .count() as u32;
    let row = AnglerStanding {
        player_id,
        display_name: player.display_name,
        discoveries,
        fish_count: player.fish_count,
        uur_count,
        records_held,
    };
    if ctx
        .db
        .angler_standing()
        .player_id()
        .find(player_id)
        .is_some()
    {
        ctx.db.angler_standing().player_id().update(row);
    } else {
        ctx.db.angler_standing().insert(row);
    }
    Ok(())
}

/// Bounded, repeatable backfill from durable progress, including sold catches.
#[spacetimedb::reducer]
pub fn rebuild_player_records(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_UNAVAILABLE")?;
    if ctx.sender() != owner.identity {
        return Err("OWNER_REQUIRED".into());
    }
    refresh_player(ctx, player_id)
}
