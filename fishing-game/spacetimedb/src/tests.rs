use crate::notifications::achievement_delivery;
use crate::{accounts, achievements, journal, notifications, tables::*};
use spacetimedb::{
    ConnectionId, Identity, Table, TimeDuration, Timestamp,
    test_utils::{TestAuth, TestContext},
};

fn auth(subject: &str) -> TestAuth {
    TestAuth::from_jwt_payload(
        format!(r#"{{"iss":"test","sub":"{subject}"}}"#),
        ConnectionId::from_u128(1),
    )
    .unwrap()
}
fn setup() -> TestContext {
    let test = TestContext::new().unwrap();
    test.clock.set(Timestamp::from_micros_since_unix_epoch(
        1_800_000_000_000_000,
    ));
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        crate::init(ctx);
        Ok(())
    })
    .unwrap();
    test
}
fn player(test: &TestContext, discord: u64) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        accounts::ensure_player(ctx, discord, "Proof Pilot").map(|p| p.player_id)
    })
    .unwrap()
}
fn adapter(test: &TestContext, subject: &str) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        accounts::configure_service(
            ctx,
            Identity::from_claims("test", subject),
            ServiceRole::DiscordAdapter,
            true,
        )
    })
    .unwrap();
}

#[test]
fn reducer_failure_rolls_back_player_and_delivery_changes() {
    let test = setup();
    let id = player(&test, 10);
    let result = test.with_reducer_tx(auth("player"), |ctx| {
        let mut player = ctx.db.player().player_id().find(id).unwrap();
        player.coins = 100;
        ctx.db.player().player_id().update(player);
        ctx.db.earned_achievement().insert(EarnedAchievement {
            key: 99,
            player_id: id,
            achievement_id: 1,
            completed_at: ctx.timestamp,
        });
        notifications::enqueue(ctx, 99);
        Err::<(), _>("reject")
    });
    assert!(result.is_err());
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, 0);
    assert_eq!(test.db.earned_achievement().count(), 0);
    assert_eq!(test.db.achievement_delivery().count(), 0);
}

#[test]
fn delivery_leases_survive_retries_and_acknowledgements_are_authorized() {
    let test = setup();
    let id = player(&test, 10);
    adapter(&test, "bot");
    test.with_reducer_tx(auth("player"), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.completed_casts = 1;
        ctx.db.player().player_id().update(p);
        achievements::refresh_player(ctx, id)
    })
    .unwrap();
    let key = test.db.achievement_delivery().iter().next().unwrap().key;
    assert!(
        notifications::adapter_achievement_deliveries(&test.view_context(auth("reader")))
            .is_empty()
    );
    assert!(
        test.with_reducer_tx(auth("reader"), |ctx| {
            notifications::acknowledge_achievement_delivery(ctx, key, 1)
        })
        .is_err()
    );
    test.with_reducer_tx(auth("bot"), |ctx| {
        notifications::record_achievement_route(ctx, 10, 55)
    })
    .unwrap();
    test.with_reducer_tx(auth("bot"), |ctx| {
        notifications::claim_achievement_delivery(ctx, key, 1)
    })
    .unwrap();
    test.with_reducer_tx(auth("bot"), |ctx| {
        notifications::claim_achievement_delivery(ctx, key, 2)
    })
    .unwrap();
    assert_eq!(
        test.db
            .achievement_delivery()
            .key()
            .find(key)
            .unwrap()
            .lease_nonce,
        1
    );
    test.clock.advance(TimeDuration::from_micros(121_000_000));
    test.with_reducer_tx(auth("bot"), |ctx| {
        notifications::claim_achievement_delivery(ctx, key, 2)
    })
    .unwrap();
    assert!(
        test.with_reducer_tx(auth("bot"), |ctx| {
            notifications::acknowledge_achievement_delivery(ctx, key, 1)
        })
        .is_err()
    );
    test.with_reducer_tx(auth("bot"), |ctx| {
        notifications::acknowledge_achievement_delivery(ctx, key, 2)
    })
    .unwrap();
    test.with_reducer_tx(auth("player"), |ctx| achievements::refresh_player(ctx, id))
        .unwrap();
    assert_eq!(
        test.db.achievement_delivery().count(),
        0,
        "award replay must not requeue an acknowledged delivery"
    );
}

#[test]
fn administrative_backfills_do_not_queue_old_awards() {
    let test = setup();
    let id = player(&test, 10);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.completed_casts = 1000;
        ctx.db.player().player_id().update(p);
        achievements::backfill_player_achievements(ctx, id)
    })
    .unwrap();
    assert!(test.db.earned_achievement().count() > 0);
    assert_eq!(test.db.achievement_delivery().count(), 0);
}

#[test]
fn indexed_journal_pages_match_full_sort_without_exposing_other_players() {
    let test = setup();
    let id = player(&test, 10);
    let other = player(&test, 20);
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        ctx.db.player_identity().insert(PlayerIdentity {
            identity: Identity::from_claims("test", "reader"),
            player_id: id,
            linked_at: ctx.timestamp,
        });
        for n in 0..2003u64 {
            ctx.db.journal_entry().insert(JournalEntry {
                key: format!("proof:{n:06}"),
                player_id: if n % 10 == 0 { other } else { id },
                catch_id: None,
                caught_at: Timestamp::from_micros_since_unix_epoch(n as i64 / 3),
                outcome: if n % 7 == 0 { "junk" } else { "fish" }.into(),
                species_id: Some(1),
                name: if n % 3 == 0 {
                    "Copper Minnow"
                } else {
                    "Minnow"
                }
                .into(),
                rarity: if n % 2 == 0 { "F" } else { "S" }.into(),
                length_mm: (n % 53) as u32,
                weight_g: n % 79,
                xp_granted: if n % 11 == 0 { None } else { Some(n % 31) },
                biome_id: Some((n % 3 + 1) as u32),
            });
        }
        journal::activate_history_queries(ctx)
    })
    .unwrap();
    for sort in ["date", "rank", "length", "weight", "xp"] {
        for descending in [true, false] {
            for (page, search, biome, rarity, outcome) in [
                (1, "", 0, "", ""),
                (45, "", 0, "", ""),
                (999, "", 0, "", ""),
                (2, "copper", 1, "S", "fish"),
                (1, "missing", 0, "", ""),
            ] {
                test.with_reducer_tx(auth("reader"), |ctx| {
                    journal::select_journal(
                        ctx,
                        page,
                        12,
                        sort.into(),
                        descending,
                        search.into(),
                        biome,
                        rarity.into(),
                        outcome.into(),
                    )
                })
                .unwrap();
                let q = test
                    .db
                    .journal_selection()
                    .identity()
                    .find(Identity::from_claims("test", "reader"))
                    .unwrap();
                let mut expected: Vec<_> = test
                    .db
                    .journal_entry()
                    .player_id()
                    .filter(id)
                    .filter(|r| {
                        (search.is_empty() || r.name.to_lowercase().contains(search))
                            && (biome == 0 || r.biome_id == Some(biome))
                            && (rarity.is_empty() || r.rarity == rarity)
                            && (outcome.is_empty() || r.outcome == outcome)
                    })
                    .collect();
                expected.sort_by(|a, b| journal::entry_order(a, b, &q));
                let actual = journal::my_journal(&test.view_context(auth("reader"))).unwrap();
                assert_eq!(actual.matching_entries, expected.len() as u64);
                let expected: Vec<_> = expected
                    .into_iter()
                    .skip((actual.page - 1) as usize * 12)
                    .take(12)
                    .map(|r| r.key)
                    .collect();
                assert_eq!(
                    actual
                        .entries
                        .into_iter()
                        .map(|r| r.key)
                        .collect::<Vec<_>>(),
                    expected
                );
            }
        }
    }
    assert!(journal::my_journal(&test.view_context(auth("stranger"))).is_none());
}
