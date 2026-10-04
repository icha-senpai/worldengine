use crate::{
    accounts::{config, require_service},
    inventory::ledger,
    tables::*,
};
use game_rules::progression::{check_interaction_freshness, level_for_xp};
use spacetimedb::{ReducerContext, Table};

// Both transports use the same transactional access checks. Equipment changes never
// touch next_cast_at; changing destination cannot reset the global cast cooldown.
fn change(
    ctx: &ReducerContext,
    player_id: u64,
    biome_id: Option<u32>,
    rod_id: Option<u32>,
) -> Result<(), String> {
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    let level = level_for_xp(player.total_xp, config(ctx).level_cap);
    let rod = ctx
        .db
        .rod_definition()
        .rod_id()
        .find(rod_id.unwrap_or(player.equipped_rod_id))
        .ok_or("ROD_UNAVAILABLE")?;
    if level < rod.minimum_level {
        return Err(format!("ROD_LOCKED:{}", rod.minimum_level));
    }
    let biome = ctx
        .db
        .biome_definition()
        .biome_id()
        .find(biome_id.unwrap_or(player.selected_biome_id))
        .ok_or("BIOME_UNAVAILABLE")?;
    if level < biome.minimum_level {
        return Err(format!("BIOME_LEVEL_REQUIRED:{}", biome.minimum_level));
    }
    if rod.power < biome.required_power {
        return Err(format!("BIOME_POWER_REQUIRED:{}", biome.required_power));
    }
    // Rods are earned once through level progression. Claiming a milestone costs
    // nothing, does not affect odds, and cannot duplicate an ownership reward.
    let key = u128::from(player_id) << 32 | u128::from(rod.rod_id);
    if ctx.db.owned_rod().key().find(key).is_none() {
        ctx.db.owned_rod().insert(OwnedRod {
            key,
            player_id,
            rod_id: rod.rod_id,
            upgrade_level: 0,
        });
        ledger(
            ctx,
            player_id,
            format!("rod-unlock:{}", rod.rod_id),
            &format!("rod_{}", rod.rod_id),
            1,
            "level_unlock",
        );
    }
    player.equipped_rod_id = rod.rod_id;
    player.selected_biome_id = biome.biome_id;
    ctx.db.player().player_id().update(player);
    Ok(())
}

#[spacetimedb::reducer]
pub fn change_loadout(
    ctx: &ReducerContext,
    biome_id: Option<u32>,
    rod_id: Option<u32>,
) -> Result<(), String> {
    let player_id = ctx
        .db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .ok_or("ACCOUNT_NOT_LINKED")?
        .player_id;
    change(ctx, player_id, biome_id, rod_id)
}

#[spacetimedb::reducer]
pub fn change_loadout_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    biome_id: Option<u32>,
    rod_id: Option<u32>,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    check_interaction_freshness(interaction_id, ctx.timestamp.to_micros_since_unix_epoch())?;
    let selection = ctx
        .db
        .service_selection()
        .identity()
        .find(ctx.sender())
        .ok_or("PLAYER_UNAVAILABLE")?;
    let player = ctx
        .db
        .player()
        .player_id()
        .find(selection.player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    if player.discord_user_id != discord_user_id || selection.interaction_id != interaction_id {
        return Err("REQUEST_CONFLICT".into());
    }
    change(ctx, player.player_id, biome_id, rod_id)
}
