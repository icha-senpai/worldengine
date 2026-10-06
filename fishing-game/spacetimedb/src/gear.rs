use crate::tables::*;
use serde::Deserialize;
use spacetimedb::{ReducerContext, Table};

#[derive(Deserialize)]
struct World {
    rods: Vec<Modifiers>,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Modifiers {
    rod_id: u32,
    luck_bp: u32,
    xp_bonus_bp: u32,
    sprite_asset: String,
}

pub fn seed(ctx: &ReducerContext) {
    let world: World = serde_json::from_str(include_str!("../../content/world.json"))
        .expect("validated rod bonuses");
    for rod in world.rods {
        let row = RodBonuses {
            rod_id: rod.rod_id,
            version: 1,
            luck_bp: rod.luck_bp,
            xp_bonus_bp: rod.xp_bonus_bp,
            sprite_asset: rod.sprite_asset,
        };
        if ctx.db.rod_bonuses().rod_id().find(row.rod_id).is_some() {
            ctx.db.rod_bonuses().rod_id().update(row);
        } else {
            ctx.db.rod_bonuses().insert(row);
        }
    }
}

/// Additive live upgrade: metadata only, preserving players, owned gear and catches.
#[spacetimedb::reducer]
pub fn activate_rod_bonuses(ctx: &ReducerContext) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_MISSING")?;
    if owner.identity != ctx.sender() {
        return Err("NOT_AUTHORIZED".into());
    }
    seed(ctx);
    if ctx.db.game_config().version().find(5).is_none() {
        let mut config = crate::accounts::config(ctx);
        config.version = 5;
        ctx.db.game_config().insert(config);
    }
    Ok(())
}
