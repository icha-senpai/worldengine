//! Authoritative fishing module. Private state is exposed only by scoped views.
mod accounts;
mod casting;
mod content;
mod daily;
mod inventory;
mod loadout;
mod maintenance;
mod records;
mod tables;

use spacetimedb::{ReducerContext, Table};
use tables::*;

#[spacetimedb::reducer(init)]
pub fn init(ctx: &ReducerContext) {
    ctx.db.deployment_owner().insert(DeploymentOwner {
        singleton: 1,
        identity: ctx.sender(),
    });
    content::seed(ctx);
    ctx.db.maintenance_job().insert(MaintenanceJob {
        scheduled_id: 1,
        scheduled_at: spacetimedb::TimeDuration::from_micros(60_000_000).into(),
    });
}
