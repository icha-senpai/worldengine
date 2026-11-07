use crate::calendar::{self, calendar_job, CalendarJob};
use crate::tables::*;
use crate::tideforge_economy::{tideforge_economy_wallet, TideforgeEconomyWallet};
use game_rules::daily::DAY_MICROS;
use spacetimedb::{
    ConnectionId, Identity, ScheduleAt, Table, TimeDuration, Timestamp,
    test_utils::{TestAuth, TestContext},
};

const MONDAY: i64 = (7 * 3000 - 3) * DAY_MICROS;

fn auth() -> TestAuth {
    TestAuth::from_jwt_payload(
        r#"{"iss":"test","sub":"calendar-player"}"#.to_owned(),
        ConnectionId::from_u128(1),
    ).unwrap()
}

fn setup(micros: i64) -> TestContext {
    let test = TestContext::new().unwrap();
    test.clock.set(Timestamp::from_micros_since_unix_epoch(micros));
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        crate::init(ctx);
        Ok(())
    }).unwrap();
    test
}

fn due(job: &CalendarJob) -> i64 {
    match job.scheduled_at {
        ScheduleAt::Time(at) => at.to_micros_since_unix_epoch(),
        _ => panic!("expected a one-shot job"),
    }
}

fn invoke(test: &TestContext, job: &CalendarJob) -> Result<(), String> {
    test.with_reducer_tx(TestAuth::internal(), |ctx| calendar::advance_calendar(ctx, CalendarJob {
        scheduled_id: job.scheduled_id, scheduled_at: job.scheduled_at,
    }))
}

#[test]
fn boundaries_cover_normal_midnight_negative_epoch_and_overflow() {
    assert_eq!(calendar::next_calendar_boundary(12 * DAY_MICROS + 1).unwrap(), 13 * DAY_MICROS);
    assert_eq!(calendar::next_calendar_boundary(12 * DAY_MICROS).unwrap(), 13 * DAY_MICROS);
    assert_eq!(calendar::next_calendar_boundary(-1).unwrap(), 0);
    assert_eq!(calendar::next_calendar_boundary(-DAY_MICROS).unwrap(), 0);
    assert!(calendar::next_calendar_boundary(i64::MAX).is_err());
}

#[test]
fn init_activation_and_scheduler_authority_are_safe_and_idempotent() {
    let test = setup(MONDAY - 1);
    assert_eq!(test.db.calendar_job().count(), 1);
    let job = test.db.calendar_job().iter().next().unwrap();
    assert_eq!(due(&job), MONDAY);
    assert!(invoke(&test, &job).is_err());
    assert!(test.with_reducer_tx(auth(), |ctx| calendar::advance_calendar(ctx, CalendarJob {
        scheduled_id: job.scheduled_id, scheduled_at: job.scheduled_at,
    })).is_err());
    assert!(test.with_reducer_tx(auth(), calendar::activate_live_calendar).is_err());
    assert!(test.with_reducer_tx(TestAuth::internal(), |ctx| calendar::advance_calendar(ctx, CalendarJob {
        scheduled_id: 0, scheduled_at: TimeDuration::from_micros(1).into(),
    })).is_err());
    for _ in 0..3 {
        test.with_reducer_tx(TestAuth::internal(), calendar::activate_live_calendar).unwrap();
        test.with_reducer_tx(TestAuth::internal(), calendar::schedule_next_boundary).unwrap();
    }
    assert_eq!(test.db.calendar_job().count(), 1);
    assert_eq!(test.db.maintenance_job().count(), 1);
}

#[test]
fn midnight_refreshes_monday_and_dockside_without_changing_player_data() {
    let test = setup(MONDAY - 1);
    let player_id = test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let player = crate::accounts::ensure_player(ctx, 8001, "Calendar Pilot")?;
        let id = player.player_id;
        ctx.db.player_identity().insert(PlayerIdentity {
            identity: Identity::from_claims("test", "calendar-player"), player_id: id, linked_at: ctx.timestamp,
        });
        let mut player = player;
        player.coins = 975; player.total_xp = 441; player.fish_count = 7;
        ctx.db.player().player_id().update(player);
        ctx.db.daily_delivery().insert(DailyDelivery { player_id: id, last_claim_day: MONDAY / DAY_MICROS - 1, total_claims: 5 });
        ctx.db.item_stack().insert(ItemStack { key: (u128::from(id) << 32) | 800, player_id: id, item: "normal_fillets".into(), quantity: 37 });
        ctx.db.owned_specimen().insert(OwnedSpecimen {
            catch_id: 0, player_id: id, species_id: 1, rarity: "F".into(), length_mm: 100, weight_g: 50,
            size_grade: 1, caught_at: ctx.timestamp, sale_value_coins: 3, favorite: true,
            rules_version: 1, content_version: 1, biome_id: 1, source_guild_id: None,
        });
        ctx.db.quest_claim().insert(QuestClaim {
            key: (u128::from(id) << 32) | 101, player_id: id, quest_id: 101, bait_id: 1,
            delivered_catch_id: None, claimed_at: ctx.timestamp,
        });
        ctx.db.weekly_quest_progress().insert(WeeklyQuestProgress {
            player_id: id, week: 2999, fish_count: 23, junk_count: 4, species_ids: vec![1, 2], claimed_quest_id: 301, bait_id: 0,
        });
        ctx.db.repeat_quest_progress().insert(RepeatQuestProgress {
            key: (u128::from(id) << 32) | 1101, player_id: id, quest_id: 1101, cycle: 3, current: 7, species_ids: vec![1],
        });
        ctx.db.tideforge_economy_wallet().insert(TideforgeEconomyWallet { player_id: id, marks: 19, next_sequence: 4, revision: 8 });
        Ok::<u64, String>(id)
    }).unwrap();
    let before = crate::daily::my_dockside(&test.view_context(auth())).unwrap();
    assert!(!before.available);
    assert_eq!(before.total_claims, 5);
    let job = test.db.calendar_job().iter().next().unwrap();
    test.clock.set(Timestamp::from_micros_since_unix_epoch(MONDAY));
    invoke(&test, &job).unwrap();
    let calendar = test.db.quest_calendar().singleton().find(1).unwrap();
    assert_eq!((calendar.day, calendar.week), (MONDAY / DAY_MICROS, 3000));
    let after = crate::daily::my_dockside(&test.view_context(auth())).unwrap();
    assert!(after.available);
    assert_eq!(after.total_claims, 5);
    let player = test.db.player().player_id().find(player_id).unwrap();
    assert_eq!((player.coins, player.total_xp, player.fish_count), (975, 441, 7));
    let wallet = test.db.tideforge_economy_wallet().player_id().find(player_id).unwrap();
    assert_eq!((wallet.marks, wallet.next_sequence, wallet.revision), (19, 4, 8));
    assert_eq!(test.db.item_stack().key().find((u128::from(player_id) << 32) | 800).unwrap().quantity, 37);
    assert_eq!(test.db.owned_specimen().count(), 1);
    assert!(test.db.owned_specimen().iter().next().unwrap().favorite);
    let claim = test.db.quest_claim().key().find((u128::from(player_id) << 32) | 101).unwrap();
    assert_eq!((claim.quest_id, claim.bait_id), (101, 1));
    let weekly = test.db.weekly_quest_progress().player_id().find(player_id).unwrap();
    assert_eq!((weekly.week, weekly.fish_count, weekly.junk_count, weekly.claimed_quest_id), (2999, 23, 4, 301));
    assert_eq!(weekly.species_ids, vec![1, 2]);
    let repeat = test.db.repeat_quest_progress().key().find((u128::from(player_id) << 32) | 1101).unwrap();
    assert_eq!((repeat.cycle, repeat.current, repeat.species_ids), (3, 7, vec![1]));
    assert_eq!(test.db.calendar_job().count(), 2);
    let next = test.db.calendar_job().iter().find(|row| row.scheduled_id != job.scheduled_id).unwrap();
    assert_eq!(due(&next), MONDAY + DAY_MICROS);
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        ctx.db.calendar_job().scheduled_id().delete(job.scheduled_id);
        Ok(())
    }).unwrap();
    assert_eq!(test.db.calendar_job().count(), 1);
    assert_eq!(test.db.calendar_job().iter().next().unwrap().scheduled_id, next.scheduled_id);
}

#[test]
fn late_scheduler_fast_forwards_and_keeps_one_future_job() {
    let test = setup(MONDAY - 1);
    let job = test.db.calendar_job().iter().next().unwrap();
    let late = MONDAY + 9 * DAY_MICROS + 123;
    test.clock.set(Timestamp::from_micros_since_unix_epoch(late));
    invoke(&test, &job).unwrap();
    let current = test.db.quest_calendar().singleton().find(1).unwrap();
    assert_eq!(current.day, late.div_euclid(DAY_MICROS));
    assert_eq!(current.week, game_rules::quests::utc_week(current.day));
    let future = test.db.calendar_job().iter().find(|row| row.scheduled_id != job.scheduled_id).unwrap();
    assert_eq!(due(&future), MONDAY + 10 * DAY_MICROS);
    test.with_reducer_tx(TestAuth::internal(), calendar::activate_live_calendar).unwrap();
    assert_eq!(test.db.calendar_job().count(), 2);
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        ctx.db.calendar_job().scheduled_id().delete(job.scheduled_id);
        Ok(())
    }).unwrap();
    assert_eq!(test.db.calendar_job().count(), 1);
    assert_eq!(test.db.calendar_job().iter().next().unwrap().scheduled_id, future.scheduled_id);
}
