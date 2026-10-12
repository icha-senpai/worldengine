use crate::{
    accounts::{config, current_player},
    tables::*,
};
use spacetimedb::{ReducerContext, Table, ViewContext};

fn key(player_id: u64, biome_id: u32) -> u128 {
    (u128::from(player_id) << 32) | u128::from(biome_id)
}

pub fn mastered(ctx: &ReducerContext, player_id: u64, species: &[SpeciesDefinition]) -> bool {
    let ordinary: Vec<_> = species
        .iter()
        .filter(|fish| fish.counts_for_ordinary_collection_completion)
        .collect();
    !ordinary.is_empty()
        && ordinary.iter().all(|fish| {
            ctx.db
                .player_species_progress()
                .key()
                .find(key(player_id, fish.species_id))
                .is_some_and(|row| row.count > 0)
        })
}

pub fn active(
    ctx: &ReducerContext,
    player_id: u64,
    biome_id: u32,
    species: &[SpeciesDefinition],
) -> bool {
    config(ctx).version >= game_rules::collection_focus::MIN_RULES_VERSION
        && ctx
            .db
            .collection_focus_preference()
            .key()
            .find(key(player_id, biome_id))
            .is_some_and(|row| row.enabled)
        && mastered(ctx, player_id, species)
}

fn set(ctx: &ReducerContext, player_id: u64, biome_id: u32, enabled: bool) -> Result<(), String> {
    if ctx
        .db
        .biome_definition()
        .biome_id()
        .find(biome_id)
        .is_none()
    {
        return Err("BIOME_UNAVAILABLE".into());
    }
    if enabled {
        if config(ctx).version < game_rules::collection_focus::MIN_RULES_VERSION {
            return Err("COLLECTION_FOCUS_UNAVAILABLE".into());
        }
        if !crate::shop::owns_licence(ctx, player_id, biome_id) {
            return Err("BIOME_LICENCE_REQUIRED".into());
        }
        let species: Vec<_> = ctx
            .db
            .species_definition()
            .biome_id()
            .filter(biome_id)
            .collect();
        if !mastered(ctx, player_id, &species) {
            return Err("COLLECTION_FOCUS_LOCKED".into());
        }
    }
    let row = CollectionFocusPreference {
        key: key(player_id, biome_id),
        player_id,
        biome_id,
        enabled,
    };
    if ctx
        .db
        .collection_focus_preference()
        .key()
        .find(row.key)
        .is_some()
    {
        ctx.db.collection_focus_preference().key().update(row);
    } else {
        ctx.db.collection_focus_preference().insert(row);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn set_collection_focus(
    ctx: &ReducerContext,
    biome_id: u32,
    enabled: bool,
) -> Result<(), String> {
    set(ctx, crate::shop::browser_player(ctx)?, biome_id, enabled)
}

#[spacetimedb::reducer]
pub fn set_collection_focus_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    biome_id: u32,
    enabled: bool,
) -> Result<(), String> {
    set(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        biome_id,
        enabled,
    )
}

#[spacetimedb::view(accessor=my_collection_focus,public)]
pub fn my_collection_focus(ctx: &ViewContext) -> Vec<CollectionFocusPreference> {
    current_player(ctx)
        .map(|id| {
            ctx.db
                .collection_focus_preference()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}

#[spacetimedb::view(accessor=adapter_collection_focus,public)]
pub fn adapter_collection_focus(ctx: &ViewContext) -> Vec<CollectionFocusPreference> {
    crate::shop::selected(ctx)
        .map(|id| {
            ctx.db
                .collection_focus_preference()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}
