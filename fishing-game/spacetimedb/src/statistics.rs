use crate::tables::*;
use spacetimedb::{ReducerContext, Table, Timestamp};
use std::collections::{BTreeMap, BTreeSet};

fn add(value: &mut u64, amount: u64) -> Result<(), String> {
    *value = value.checked_add(amount).ok_or("STATISTICS_OVERFLOW")?;
    Ok(())
}

fn day(ctx: &ReducerContext, stats: &mut AnglerStatistics, time: Timestamp) -> Result<(), String> {
    let utc_day = u64::try_from(time.to_micros_since_unix_epoch() / 86_400_000_000)
        .map_err(|_| "INVALID_CATCH_TIME")?;
    let key = u128::from(stats.player_id) << 64 | u128::from(utc_day);
    if ctx.db.fishing_day().key().find(key).is_none() {
        ctx.db.fishing_day().insert(FishingDay {
            key,
            player_id: stats.player_id,
            utc_day,
        });
        stats.days_fished = stats
            .days_fished
            .checked_add(1)
            .ok_or("STATISTICS_OVERFLOW")?;
    }
    Ok(())
}

fn biome(ctx: &ReducerContext, player_id: u64, biome_id: u32, casts: u64) -> Result<(), String> {
    let key = u128::from(player_id) << 32 | u128::from(biome_id);
    if let Some(mut row) = ctx.db.fishing_biome_activity().key().find(key) {
        add(&mut row.casts, casts)?;
        ctx.db.fishing_biome_activity().key().update(row);
    } else {
        ctx.db
            .fishing_biome_activity()
            .insert(FishingBiomeActivity {
                key,
                player_id,
                biome_id,
                casts,
            });
    }
    Ok(())
}

fn measurement(
    ctx: &ReducerContext,
    stats: &mut AnglerStatistics,
    species_id: u32,
    catch_id: Option<u64>,
    length_mm: u32,
    weight_g: u64,
) -> Result<(), String> {
    add(&mut stats.total_weight_g, weight_g)?;
    add(&mut stats.measured_fish_count, 1)?;
    let species = ctx
        .db
        .species_definition()
        .species_id()
        .find(species_id)
        .ok_or("CONTENT_UNAVAILABLE")?;
    if species.typical_length_mm == 0 {
        return Err("INVALID_MEASUREMENTS".into());
    }
    let ratio = u64::from(length_mm) * 1_000_000 / u64::from(species.typical_length_mm);
    if ratio > stats.best_relative_length_millionths {
        stats.best_relative_length_millionths = ratio;
        stats.best_relative_species_id = Some(species_id);
        stats.best_relative_catch_id = catch_id;
        stats.best_relative_length_mm = length_mm;
        stats.best_relative_weight_g = weight_g;
    }
    Ok(())
}

fn bonus(stats: &mut AnglerStatistics, outcome: &str) -> Result<(), String> {
    match outcome {
        "fish" => add(&mut stats.bonus_fish, 1),
        "junk" => add(&mut stats.bonus_junk, 1),
        "treasure" => add(&mut stats.bonus_treasure, 1),
        _ => Err("INVALID_OUTCOME".into()),
    }
}

/// Recover once from durable evidence. Repeating this never resets new statistics.
pub fn ensure(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    if ctx
        .db
        .angler_statistics()
        .player_id()
        .find(player_id)
        .is_some()
    {
        return Ok(());
    }
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut stats = AnglerStatistics {
        player_id,
        display_name: player.display_name.clone(),
        total_casts: 0,
        bonus_pulls: 0,
        fish_count: 0,
        junk_count: 0,
        treasure_count: 0,
        bonus_fish: 0,
        bonus_junk: 0,
        bonus_treasure: 0,
        bonus_history_complete: false,
        rank_counts: vec![0; 10],
        collection_pairs: 0,
        most_caught_species_id: None,
        most_caught_species_count: 0,
        total_weight_g: 0,
        measured_fish_count: 0,
        recorded_casts: 0,
        history_complete: false,
        days_fished: 0,
        favorite_biome_id: None,
        favorite_biome_casts: 0,
        biome_history_complete: false,
        best_relative_species_id: None,
        best_relative_catch_id: None,
        best_relative_length_millionths: 0,
        best_relative_length_mm: 0,
        best_relative_weight_g: 0,
        sale_earnings: 0,
        biggest_single_sale: 0,
        biggest_sale_batch: 0,
        sale_history_complete: player.completed_casts == 0,
        sales_tracked_since: ctx.timestamp,
        records_set: 0,
        records_tracked_since: ctx.timestamp,
    };
    // Timestamp is shared by both pulls; cooldown makes it unique to a cast.
    let mut casts = BTreeMap::new();
    let mut dates = BTreeSet::new();
    for entry in ctx.db.journal_entry().player_id().filter(player_id) {
        dates.insert(entry.caught_at);
        if let Some(biome_id) = entry.biome_id {
            casts.insert(entry.caught_at.to_micros_since_unix_epoch(), biome_id);
        }
        if entry.outcome == "fish" {
            if let Some(species_id) = entry.species_id {
                measurement(
                    ctx,
                    &mut stats,
                    species_id,
                    entry.catch_id,
                    entry.length_mm,
                    entry.weight_g,
                )?;
            }
        }
        // Original cast keys preserve the pull index. Legacy reconciliation keys do not.
        if entry
            .key
            .strip_prefix("pull:")
            .and_then(|k| k.parse::<u128>().ok())
            .is_some_and(|k| k & 255 == 1)
        {
            bonus(&mut stats, &entry.outcome)?;
        }
    }
    stats.recorded_casts = dates.len() as u64;
    for time in dates {
        day(ctx, &mut stats, time)?;
    }
    for (_, biome_id) in casts {
        biome(ctx, player_id, biome_id, 1)?;
    }
    for entry in ctx.db.economy_ledger().player_id().filter(player_id) {
        if entry.reason == "fish_sale" && entry.item == "coins" && entry.delta > 0 {
            let amount = entry.delta as u64;
            add(&mut stats.sale_earnings, amount)?;
            stats.biggest_sale_batch = stats.biggest_sale_batch.max(amount);
        }
    }
    ctx.db.angler_statistics().insert(stats);
    Ok(())
}

pub fn observe_cast(ctx: &ReducerContext, player_id: u64, biome_id: u32) -> Result<(), String> {
    ensure(ctx, player_id)?;
    let mut stats = ctx
        .db
        .angler_statistics()
        .player_id()
        .find(player_id)
        .ok_or("STATISTICS_MISSING")?;
    add(&mut stats.recorded_casts, 1)?;
    day(ctx, &mut stats, ctx.timestamp)?;
    biome(ctx, player_id, biome_id, 1)?;
    ctx.db.angler_statistics().player_id().update(stats);
    Ok(())
}

pub fn observe_pull(
    ctx: &ReducerContext,
    receipt: &CommandReceipt,
    is_bonus: bool,
    sets_record: bool,
) -> Result<(), String> {
    crate::quests::observe_pull(ctx, receipt)?;
    let mut stats = ctx
        .db
        .angler_statistics()
        .player_id()
        .find(receipt.player_id)
        .ok_or("STATISTICS_MISSING")?;
    if receipt.outcome == "fish" {
        measurement(
            ctx,
            &mut stats,
            receipt.species_id.ok_or("CONTENT_UNAVAILABLE")?,
            receipt.catch_id,
            receipt.length_mm,
            receipt.weight_g,
        )?;
        if sets_record {
            add(&mut stats.records_set, 1)?;
        }
        // The persisted field keeps its original name for client/schema compatibility.
        // Its value is now the highest fish value, regardless of whether it is sold.
        stats.biggest_single_sale = stats.biggest_single_sale.max(receipt.sale_value_coins);
    }
    if is_bonus {
        bonus(&mut stats, &receipt.outcome)?;
    }
    ctx.db.angler_statistics().player_id().update(stats);
    Ok(())
}

pub fn observe_sale(
    ctx: &ReducerContext,
    player_id: u64,
    coins: u64,
    biggest_fish: u64,
) -> Result<(), String> {
    ensure(ctx, player_id)?;
    let mut stats = ctx
        .db
        .angler_statistics()
        .player_id()
        .find(player_id)
        .ok_or("STATISTICS_MISSING")?;
    add(&mut stats.sale_earnings, coins)?;
    stats.biggest_single_sale = stats.biggest_single_sale.max(biggest_fish);
    stats.biggest_sale_batch = stats.biggest_sale_batch.max(coins);
    ctx.db.angler_statistics().player_id().update(stats);
    Ok(())
}

pub fn refresh(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    ensure(ctx, player_id)?;
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut stats = ctx
        .db
        .angler_statistics()
        .player_id()
        .find(player_id)
        .ok_or("STATISTICS_MISSING")?;
    stats.display_name = player.display_name;
    stats.total_casts = player.completed_casts;
    stats.fish_count = player.fish_count;
    stats.junk_count = player.junk_count;
    stats.treasure_count = player.treasure_count;
    stats.bonus_pulls = player
        .fish_count
        .checked_add(player.junk_count)
        .and_then(|n| n.checked_add(player.treasure_count))
        .and_then(|n| n.checked_sub(player.completed_casts))
        .ok_or("INVALID_LIFETIME_TOTALS")?;
    stats.bonus_history_complete = stats
        .bonus_fish
        .checked_add(stats.bonus_junk)
        .and_then(|n| n.checked_add(stats.bonus_treasure))
        == Some(stats.bonus_pulls);
    stats.history_complete = stats.measured_fish_count == player.fish_count
        && stats.recorded_casts == player.completed_casts;
    stats.rank_counts = vec![0; 10];
    stats.collection_pairs = 0;
    stats.most_caught_species_id = None;
    stats.most_caught_species_count = 0;
    let mut progress: Vec<_> = ctx
        .db
        .player_species_progress()
        .player_id()
        .filter(player_id)
        .collect();
    progress.sort_by_key(|p| p.species_id);
    for row in progress {
        let ordinary = ctx
            .db
            .species_definition()
            .species_id()
            .find(row.species_id)
            .ok_or("CONTENT_UNAVAILABLE")?
            .counts_for_ordinary_collection_completion;
        for (i, count) in row.rank_counts.iter().take(10).enumerate() {
            add(&mut stats.rank_counts[i], *count)?;
            if ordinary && *count > 0 {
                stats.collection_pairs += 1;
            }
        }
        if row.count > stats.most_caught_species_count {
            stats.most_caught_species_id = Some(row.species_id);
            stats.most_caught_species_count = row.count;
        }
    }
    let mut known_biome_casts = 0;
    stats.favorite_biome_id = None;
    stats.favorite_biome_casts = 0;
    let mut activity: Vec<_> = ctx
        .db
        .fishing_biome_activity()
        .player_id()
        .filter(player_id)
        .collect();
    activity.sort_by_key(|b| b.biome_id);
    for row in activity {
        add(&mut known_biome_casts, row.casts)?;
        if row.casts > stats.favorite_biome_casts {
            stats.favorite_biome_id = Some(row.biome_id);
            stats.favorite_biome_casts = row.casts;
        }
    }
    stats.biome_history_complete = known_biome_casts == player.completed_casts;
    ctx.db.angler_statistics().player_id().update(stats);
    Ok(())
}

#[spacetimedb::reducer]
pub fn backfill_player_statistics(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    require_owner(ctx)?;
    refresh(ctx, player_id)
}

fn require_owner(ctx: &ReducerContext) -> Result<(), String> {
    if ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_REQUIRED")?
        .identity
        != ctx.sender()
    {
        return Err("OWNER_REQUIRED".into());
    }
    Ok(())
}

/// Reconstruct historical record events only when the entire game's fish history
/// is present and its final winners exactly match the current record book.
#[spacetimedb::reducer]
pub fn backfill_catch_records(ctx: &ReducerContext) -> Result<(), String> {
    require_owner(ctx)?;
    let players: Vec<_> = ctx.db.player().iter().collect();
    let mut counts: BTreeMap<u64, u64> = players.iter().map(|p| (p.player_id, 0)).collect();
    let mut wins = counts.clone();
    let mut fish: Vec<_> = ctx
        .db
        .journal_entry()
        .iter()
        .filter(|e| e.outcome == "fish")
        .collect();
    let mut ids = BTreeSet::new();
    for entry in &fish {
        let id = entry.catch_id.ok_or("INCOMPLETE_CATCH_HISTORY")?;
        if entry.species_id.is_none() || !ids.insert(id) {
            return Err("INCOMPLETE_CATCH_HISTORY".into());
        }
        add(
            counts
                .get_mut(&entry.player_id)
                .ok_or("INVALID_CATCH_HISTORY")?,
            1,
        )?;
    }
    for player in &players {
        if counts[&player.player_id] != player.fish_count {
            return Err("INCOMPLETE_CATCH_HISTORY".into());
        }
    }
    fish.sort_by_key(|entry| (entry.caught_at, entry.catch_id));
    let mut best: BTreeMap<u64, (u64, Timestamp, u64, u64)> = BTreeMap::new();
    for entry in &fish {
        let catch_id = entry.catch_id.ok_or("INCOMPLETE_CATCH_HISTORY")?;
        let species_id = entry.species_id.ok_or("INCOMPLETE_CATCH_HISTORY")?;
        let mut sets_record = false;
        for (metric, value) in [(0, u64::from(entry.length_mm)), (1, entry.weight_g)] {
            let key = u64::from(species_id) * 2 + metric;
            if best.get(&key).is_none_or(|old| {
                game_rules::progression::record_wins(
                    value,
                    entry.caught_at.to_micros_since_unix_epoch(),
                    catch_id,
                    (old.0, old.1.to_micros_since_unix_epoch(), old.2),
                )
            }) {
                best.insert(key, (value, entry.caught_at, catch_id, entry.player_id));
                sets_record = true;
            }
        }
        if sets_record {
            add(
                wins.get_mut(&entry.player_id)
                    .ok_or("INVALID_CATCH_HISTORY")?,
                1,
            )?;
        }
    }
    if best.len() as u64 != ctx.db.species_record().count() {
        return Err("RECORD_HISTORY_MISMATCH".into());
    }
    for record in ctx.db.species_record().iter() {
        if best.get(&record.key)
            != Some(&(
                record.measurement,
                record.caught_at,
                record.catch_id,
                record.player_id,
            ))
        {
            return Err("RECORD_HISTORY_MISMATCH".into());
        }
    }

    // Prices come from original saved specimens/receipts, never today's catalog.
    // Capture the maximum now before retry receipts expire.
    let mut values: BTreeMap<u64, u64> = BTreeMap::new();
    for specimen in ctx.db.owned_specimen().iter() {
        values.insert(specimen.catch_id, specimen.sale_value_coins);
    }
    for receipt in ctx
        .db
        .cast_pull()
        .iter()
        .map(|pull| pull.receipt)
        .chain(ctx.db.command_receipt().iter())
    {
        if receipt.outcome == "fish" {
            if let Some(id) = receipt.catch_id {
                if values
                    .get(&id)
                    .is_some_and(|value| *value != receipt.sale_value_coins)
                {
                    return Err("FISH_VALUE_HISTORY_MISMATCH".into());
                }
                values.insert(id, receipt.sale_value_coins);
            }
        }
    }
    let mut maxima: BTreeMap<u64, u64> = players.iter().map(|p| (p.player_id, 0)).collect();
    for entry in &fish {
        let id = entry.catch_id.ok_or("INCOMPLETE_CATCH_HISTORY")?;
        let value = *values.get(&id).ok_or("INCOMPLETE_FISH_VALUE_HISTORY")?;
        let maximum = maxima
            .get_mut(&entry.player_id)
            .ok_or("INVALID_CATCH_HISTORY")?;
        *maximum = (*maximum).max(value);
    }
    // All validation completes before touching projections. Reducer rollback also
    // protects against failures; repeated recovery assigns totals, not increments.
    for player in players {
        ensure(ctx, player.player_id)?;
        let mut stats = ctx
            .db
            .angler_statistics()
            .player_id()
            .find(player.player_id)
            .ok_or("STATISTICS_MISSING")?;
        stats.records_set = wins[&player.player_id];
        stats.records_tracked_since = player.created_at;
        stats.biggest_single_sale = stats.biggest_single_sale.max(maxima[&player.player_id]);
        ctx.db.angler_statistics().player_id().update(stats);
    }
    Ok(())
}
