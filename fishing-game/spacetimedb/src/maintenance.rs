use crate::tables::*;
use spacetimedb::{ReducerContext, TimeDuration};

/// Indexed, bounded expiry work. Clients cannot invoke scheduler authority.
#[spacetimedb::reducer]
pub fn prune_history(ctx: &ReducerContext, _job: MaintenanceJob) -> Result<(), String> {
    if ctx.sender() != ctx.database_identity() {
        return Err("SCHEDULER_REQUIRED".into());
    }
    for row in ctx
        .db
        .shop_quote()
        .expires_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        ctx.db.shop_quote().key().delete(row.key);
    }
    let week = ctx.timestamp - TimeDuration::from_micros(7 * 86_400_000_000);
    let yesterday = ctx.timestamp - TimeDuration::from_micros(86_400_000_000);
    for row in ctx
        .db
        .sale_quote()
        .expires_at()
        .filter(..yesterday)
        .take(1000)
    {
        ctx.db.sale_quote().key().delete(row.key);
    }
    let month = ctx.timestamp - TimeDuration::from_micros(30 * 86_400_000_000);
    for row in ctx
        .db
        .upgrade_quote()
        .expires_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        ctx.db.upgrade_quote().key().delete(row.key);
    }
    for row in ctx.db.cast_pull().caught_at().filter(..week).take(1000) {
        ctx.db.cast_pull().key().delete(row.key);
    }
    for row in ctx
        .db
        .cast_equipment_receipt()
        .caught_at()
        .filter(..week)
        .take(1000)
    {
        ctx.db
            .cast_equipment_receipt()
            .interaction_id()
            .delete(row.interaction_id);
    }
    for row in ctx
        .db
        .daily_receipt()
        .created_at()
        .filter(..week)
        .take(1000)
    {
        ctx.db
            .daily_receipt()
            .interaction_id()
            .delete(row.interaction_id);
    }
    for row in ctx
        .db
        .command_receipt()
        .caught_at()
        .filter(..week)
        .take(1000)
    {
        ctx.db
            .command_receipt()
            .interaction_id()
            .delete(row.interaction_id);
    }
    for row in ctx
        .db
        .economy_ledger()
        .created_at()
        .filter(..month)
        .take(1000)
    {
        ctx.db.economy_ledger().ledger_id().delete(row.ledger_id);
    }
    for row in ctx.db.admin_audit().created_at().filter(..month).take(1000) {
        ctx.db.admin_audit().audit_id().delete(row.audit_id);
    }
    for row in ctx
        .db
        .action_nonce()
        .expires_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        ctx.db.action_nonce().identity().delete(row.identity);
    }
    for row in ctx
        .db
        .link_challenge()
        .expires_at()
        .filter(..ctx.timestamp)
        .take(1000)
    {
        ctx.db
            .link_challenge()
            .challenge_id()
            .delete(row.challenge_id);
    }
    Ok(())
}
