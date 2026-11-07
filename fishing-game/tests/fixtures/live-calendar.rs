use calendar::calendar_job;

#[spacetimedb::reducer]
pub fn seed_live_calendar_proof(ctx: &ReducerContext) -> Result<(), String> {
    let owner = ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?;
    if owner.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let day = game_rules::daily::utc_day(ctx.timestamp.to_micros_since_unix_epoch()) - 1;
    let week = game_rules::quests::utc_week(day);
    let row = QuestCalendar {
        singleton: 1,
        day,
        week,
        next_week_at: spacetimedb::Timestamp::from_micros_since_unix_epoch(
            ((week + 1) * 7 - 3) * game_rules::daily::DAY_MICROS,
        ),
    };
    ctx.db.quest_calendar().singleton().update(row);
    ctx.db.calendar_job().insert(calendar::CalendarJob {
        scheduled_id: 0,
        scheduled_at: (ctx.timestamp + spacetimedb::TimeDuration::from_micros(2_000_000)).into(),
    });
    Ok(())
}
