// Compiled into isolated loopback proof modules only.
include!("haul-equipment.rs");
use crate::hauls::{haul_trip, haul_job, HaulJob};

#[spacetimedb::reducer]
pub fn proof_timed_gear(ctx: &ReducerContext, discord_user_id: u64, equipment_id: u32, quality: u8, biome_id: u32) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Timed haul proof")?;
    player.total_xp = 10_000_000;
    player.selected_biome_id = biome_id;
    let id = player.player_id;
    ctx.db.player().player_id().update(player);
    let key = (u128::from(id) << 32) | u128::from(equipment_id);
    let row = OwnedHaulEquipment { key, player_id: id, equipment_id, upgrade_level: quality };
    if ctx.db.owned_haul_equipment().key().find(key).is_some() { ctx.db.owned_haul_equipment().key().update(row); }
    else { ctx.db.owned_haul_equipment().insert(row); }
    let mut loadout = ctx.db.haul_loadout().player_id().find(id).ok_or("LOADOUT_MISSING")?;
    if equipment_id < 200 { loadout.boat_id = equipment_id; } else { loadout.net_id = equipment_id; }
    ctx.db.haul_loadout().player_id().update(loadout);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_timed_due(ctx: &ReducerContext, interaction_id: u64, seconds: i64, schedule: bool) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let mut trip = ctx.db.haul_trip().interaction_id().find(interaction_id).ok_or("TRIP_MISSING")?;
    trip.due_at = ctx.timestamp + spacetimedb::TimeDuration::from_micros(seconds * 1_000_000);
    trip.available_at = trip.due_at;
    let due = trip.due_at;
    ctx.db.haul_trip().interaction_id().update(trip);
    ctx.db.haul_job().scheduled_id().delete(interaction_id);
    if schedule { ctx.db.haul_job().insert(HaulJob { scheduled_id: interaction_id, scheduled_at: due.into() }); }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_timed_category(ctx: &ReducerContext, category: u8) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let mut biome = ctx.db.biome_definition().biome_id().find(1).ok_or("BIOME_MISSING")?;
    (biome.fish_weight, biome.junk_weight, biome.treasure_weight) = match category {
        1 => (1,0,0), 2 => (0,1,0), 3 => (0,0,1), _ => (86,12,2),
    };
    ctx.db.biome_definition().biome_id().update(biome);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_timed_expire_lease(ctx: &ReducerContext, interaction_id: u64) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let mut trip = ctx.db.haul_trip().interaction_id().find(interaction_id).ok_or("TRIP_MISSING")?;
    trip.available_at = ctx.timestamp - spacetimedb::TimeDuration::from_micros(1);
    ctx.db.haul_trip().interaction_id().update(trip);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_timed_bait(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    haul_proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Timed haul proof")?;
    let key = (u128::from(player.player_id) << 32) | 7;
    ctx.db.bait_stack().insert(BaitStack { key, player_id: player.player_id, bait_id: 7, uses_left: 20 });
    let row = BaitLoadout { player_id: player.player_id, bait_id: 7, secondary_bait_id: 7 };
    if ctx.db.bait_loadout().player_id().find(player.player_id).is_some() { ctx.db.bait_loadout().player_id().update(row); }
    else { ctx.db.bait_loadout().insert(row); }
    Ok(())
}
