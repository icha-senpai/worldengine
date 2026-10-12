//! Lifetime recognition is independent of inventory, sale and receipt retention.
use crate::tables::*;
use game_rules::measurements::{SpeciesMeasurements, is_perfect};
use spacetimedb::{ReducerContext, Table, Timestamp};

pub fn qualifies(
    definition: &SpeciesDefinition,
    length_mm: u32,
    weight_g: u64,
) -> Result<bool, String> {
    if definition.key == "nidalees-lost-sock" {
        return Ok(false);
    }
    let band = game_rules::RARITY_TIERS
        .iter()
        .rposition(|rank| {
            definition
                .allowed_rarities
                .iter()
                .any(|allowed| allowed == rank)
        })
        .ok_or("CONTENT_UNAVAILABLE")? as u8;
    Ok(is_perfect(
        SpeciesMeasurements {
            typical_length_mm: definition.typical_length_mm,
            min_length_mm: definition.min_length_mm,
            max_length_mm: definition.max_length_mm,
            typical_weight_g: definition.typical_weight_g,
            min_weight_g: definition.min_weight_g,
            max_weight_g: definition.max_weight_g,
        },
        band,
        length_mm,
        weight_g,
    )?)
}

pub fn observe(
    ctx: &ReducerContext,
    player_id: u64,
    species_id: u32,
    catch_id: u64,
    caught_at: Timestamp,
    length_mm: u32,
    weight_g: u64,
) -> Result<bool, String> {
    let definition = ctx
        .db
        .species_definition()
        .species_id()
        .find(species_id)
        .ok_or("CONTENT_UNAVAILABLE")?;
    if !qualifies(&definition, length_mm, weight_g)? {
        return Ok(false);
    }
    let key = u128::from(player_id) << 32 | u128::from(species_id);
    if ctx.db.perfect_species().key().find(key).is_none() {
        ctx.db.perfect_species().insert(PerfectSpecies {
            key,
            player_id,
            species_id,
            first_catch_id: catch_id,
            perfected_at: caught_at,
            length_mm,
            weight_g,
        });
    }
    Ok(true)
}

/// Recover only paired measurements from retained evidence; independent bests
/// in collection progress cannot prove one specimen had both maxima.
#[spacetimedb::reducer]
pub fn activate_perfect_catches(ctx: &ReducerContext) -> Result<(), String> {
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
    crate::achievements::seed(ctx);
    let mut evidence = Vec::new();
    for row in ctx.db.owned_specimen().iter() {
        evidence.push((
            row.caught_at,
            row.catch_id,
            row.player_id,
            row.species_id,
            row.length_mm,
            row.weight_g,
        ));
    }
    for row in ctx
        .db
        .journal_entry()
        .iter()
        .filter(|row| row.outcome == "fish")
    {
        if let (Some(catch_id), Some(species_id)) = (row.catch_id, row.species_id) {
            evidence.push((
                row.caught_at,
                catch_id,
                row.player_id,
                species_id,
                row.length_mm,
                row.weight_g,
            ));
        }
    }
    for row in ctx
        .db
        .cast_pull()
        .iter()
        .map(|row| row.receipt)
        .chain(ctx.db.command_receipt().iter())
        .filter(|row| row.outcome == "fish")
    {
        if let (Some(catch_id), Some(species_id)) = (row.catch_id, row.species_id) {
            evidence.push((
                row.caught_at,
                catch_id,
                row.player_id,
                species_id,
                row.length_mm,
                row.weight_g,
            ));
        }
    }
    evidence.sort_by_key(|row| (row.0, row.1));
    for (at, catch_id, player_id, species_id, length_mm, weight_g) in evidence {
        observe(
            ctx, player_id, species_id, catch_id, at, length_mm, weight_g,
        )?;
    }
    for player_id in ctx
        .db
        .perfect_species()
        .iter()
        .map(|row| row.player_id)
        .collect::<std::collections::BTreeSet<_>>()
    {
        let key = u128::from(player_id) << 32 | 19;
        let progress = AchievementProgress {
            key,
            player_id,
            achievement_id: 19,
            current: 1,
        };
        if ctx.db.achievement_progress().key().find(key).is_some() {
            ctx.db.achievement_progress().key().update(progress);
        } else {
            ctx.db.achievement_progress().insert(progress);
        }
        if ctx.db.earned_achievement().key().find(key).is_none() {
            let at = ctx
                .db
                .perfect_species()
                .player_id()
                .filter(player_id)
                .map(|row| row.perfected_at)
                .min()
                .ok_or("PERFECT_CATCH_MISSING")?;
            ctx.db.earned_achievement().insert(EarnedAchievement {
                key,
                player_id,
                achievement_id: 19,
                completed_at: at,
            });
        }
    }
    Ok(())
}
