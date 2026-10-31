//! Authoritative fishing module. Private state is exposed only by scoped views.
mod accounts;
mod achievements;
mod casting;
mod collection_focus;
mod content;
mod crafting;
mod daily;
mod gear;
mod haul_equipment;
mod hauls;
mod inventory;
mod journal;
mod loadout;
mod maintenance;
mod notifications;
mod perfection;
mod quests;
mod raid_notifications;
mod raids;
mod records;
mod shop;
mod statistics;
mod tables;
#[cfg(all(test, feature = "module-tests"))]
mod tests;
#[cfg(all(test, feature = "module-tests"))]
mod raids_tests;

use spacetimedb::{ReducerContext, Table};
use tables::*;

#[spacetimedb::reducer(init)]
pub fn init(ctx: &ReducerContext) {
    ctx.db.deployment_owner().insert(DeploymentOwner {
        singleton: 1,
        identity: ctx.sender(),
    });
    content::seed(ctx);
    gear::seed(ctx);
    haul_equipment::seed(ctx);
    crafting::seed(ctx);
    shop::seed(ctx);
    achievements::seed(ctx);
    quests::seed(ctx);
    raids::seed(ctx).expect("validated raids catalog");
    ctx.db.maintenance_job().insert(MaintenanceJob {
        scheduled_id: 1,
        scheduled_at: spacetimedb::TimeDuration::from_micros(60_000_000).into(),
    });
}
