use crate::tables::deployment_owner;
use game_rules::daily::{utc_day, DAY_MICROS};
use spacetimedb::{ReducerContext, ScheduleAt, Table, Timestamp};

#[spacetimedb::table(accessor = calendar_job, scheduled(advance_calendar))]
pub struct CalendarJob {
    #[primary_key]
    #[auto_inc]
    pub scheduled_id: u64,
    pub scheduled_at: ScheduleAt,
}

pub fn next_calendar_boundary(micros: i64) -> Result<i64, String> {
    utc_day(micros)
        .checked_add(1)
        .and_then(|day| day.checked_mul(DAY_MICROS))
        .ok_or_else(|| "CALENDAR_TIME_OVERFLOW".into())
}

pub fn schedule_next_boundary(ctx: &ReducerContext) -> Result<(), String> {
    let next = Timestamp::from_micros_since_unix_epoch(next_calendar_boundary(
        ctx.timestamp.to_micros_since_unix_epoch(),
    )?);
    if !ctx.db.calendar_job().iter().any(|job| matches!(job.scheduled_at, ScheduleAt::Time(at) if at == next)) {
        ctx.db.calendar_job().insert(CalendarJob { scheduled_id: 0, scheduled_at: next.into() });
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn advance_calendar(ctx: &ReducerContext, job: CalendarJob) -> Result<(), String> {
    if ctx.sender() != ctx.database_identity() {
        return Err("SCHEDULER_REQUIRED".into());
    }
    match job.scheduled_at {
        ScheduleAt::Time(at) if at <= ctx.timestamp => {}
        _ => return Err("CALENDAR_NOT_DUE".into()),
    }
    crate::quests::refresh_calendar(ctx);
    crate::raids::refresh_calendar(ctx)?;
    schedule_next_boundary(ctx)
}

#[spacetimedb::reducer]
pub fn activate_live_calendar(ctx: &ReducerContext) -> Result<(), String> {
    let owner = ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?;
    if owner.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    crate::quests::refresh_calendar(ctx);
    crate::raids::refresh_calendar(ctx)?;
    schedule_next_boundary(ctx)
}
