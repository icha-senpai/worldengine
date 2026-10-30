use crate::notifications::achievement_delivery;
use crate::{accounts, achievements, journal, notifications, tables::*};
use crate::hauls::haul_trip;
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

fn biome(test: &TestContext) -> crate::tables::BiomeDefinition {
    test.db.biome_definition().biome_id().find(1).unwrap()
}
fn rules(test: &TestContext) -> crate::tables::GameConfig {
    test.db.game_config().iter().next().unwrap()
}
fn plan(player: crate::tables::Player, biome: crate::tables::BiomeDefinition, rules: crate::tables::GameConfig, id: u64, command: &str, pulls: usize) -> crate::casting::CastPlan {
    crate::casting::CastPlan {
        player, biome, rules,
        interaction_id: id, guild_id: Some(7), channel_id: 8,
        command: command.into(), equipment_id: 1, quality_level: 0,
        power: 0, luck_bp: 0, xp_bonus_bp: 0,
        bait: crate::crafting::UsedBaits { primary: Default::default(), secondary: Default::default(), luck_bp: 0, xp_bonus_bp: 0, size_rolls: 1 },
        pull_count: pulls, collection_focus_enabled: false,
    }
}
fn running_trip(test: &TestContext, player_id: u64, id: u64, kind: &str, name: &str) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.haul_trip().insert(crate::hauls::HaulTrip {
            interaction_id: id, player_id, discord_user_id: 10, guild_id: Some(7), channel_id: 8,
            kind: kind.into(), equipment_id: if kind == "boat" { 10 } else { 20 },
            equipment_name: name.into(), quality_level: 0, luck_bp: 0, xp_bonus_bp: 0,
            biome: ctx.db.biome_definition().biome_id().find(1).unwrap(),
            rules: ctx.db.game_config().iter().next().unwrap(),
            collection_focus_enabled: false, started_at: ctx.timestamp, due_at: ctx.timestamp,
            completed_at: None, status: "running".into(), results: vec![], total_xp: 0, total_coins: 0, available_at: ctx.timestamp, attempts: 0,
            lease_owner: Identity::ZERO, lease_nonce: 0,
        });
        Ok::<(), String>(())
    }).unwrap();
}
fn finish(test: &TestContext, id: u64) {
    adapter(test, "bot");
    test.with_reducer_tx(auth("bot"), |ctx| crate::hauls::finish_haul_from_adapter(ctx, id)).unwrap();
}
fn rod(test: &TestContext, player_id: u64, id: u64, pulls: usize) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let player = ctx.db.player().player_id().find(player_id).unwrap();
        crate::casting::resolve(ctx, plan(player, ctx.db.biome_definition().biome_id().find(1).unwrap(), ctx.db.game_config().iter().next().unwrap(), id, "fish", pulls))
    }).unwrap();
}
fn force_weights(test: &TestContext, fish: u64, junk: u64, treasure: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut b = ctx.db.biome_definition().biome_id().find(1).unwrap();
        b.fish_weight = fish; b.junk_weight = junk; b.treasure_weight = treasure;
        ctx.db.biome_definition().biome_id().update(b);
        Ok::<(), String>(())
    }).unwrap();
}
fn stats(test: &TestContext, id: u64) -> crate::tables::AnglerStatistics {
    test.db.angler_statistics().player_id().find(id).unwrap()
}
fn refresh(test: &TestContext, id: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::statistics::refresh(ctx, id)).unwrap();
}

#[test]
fn hauls_count_one_cast_and_zero_bonus_pulls() {
    let test = setup();
    let id = player(&test, 10);
    force_weights(&test, 1_000_000, 0, 0);
    rod(&test, id, 1001, 1);
    rod(&test, id, 1002, 2);
    running_trip(&test, id, 1003, "boat", "Twig Boat");
    finish(&test, 1003);
    running_trip(&test, id, 1004, "net", "Twig Net");
    finish(&test, 1004);
    let p = test.db.player().player_id().find(id).unwrap();
    assert_eq!(p.fish_count, 17);
    assert_eq!(p.completed_casts, 4);
    assert_eq!(test.db.haul_statistics().player_id().find(id).unwrap().extra_pulls, 12);
    let s = stats(&test, id);
    assert_eq!(s.bonus_pulls, 1);
    assert_eq!((s.bonus_fish, s.bonus_junk, s.bonus_treasure), (1, 0, 0));
    assert!(s.bonus_history_complete);
    // Replay finish is idempotent: no second resolve, no double accounting.
    finish(&test, 1004);
    assert_eq!(test.db.player().player_id().find(id).unwrap().completed_casts, 4);
    assert_eq!(test.db.haul_statistics().player_id().find(id).unwrap().extra_pulls, 12);
}

#[test]
fn haul_catches_never_increment_bonus_types_but_rewards_survive() {
    let test = setup();
    let id = player(&test, 10);
    force_weights(&test, 1_000_000, 0, 0);
    rod(&test, id, 2001, 2);
    force_weights(&test, 0, 1_000_000, 0);
    rod(&test, id, 2004, 2);
    running_trip(&test, id, 2002, "boat", "Twig Boat");
    finish(&test, 2002);
    force_weights(&test, 0, 0, 1_000_000);
    rod(&test, id, 2005, 2);
    running_trip(&test, id, 2003, "net", "Twig Net");
    finish(&test, 2003);
    refresh(&test, id);
    let p = test.db.player().player_id().find(id).unwrap();
    assert_eq!(p.fish_count, 2);
    assert_eq!(p.junk_count, 12);
    assert_eq!(p.treasure_count, 6);
    assert_eq!(p.completed_casts, 5);
    let s = stats(&test, id);
    assert_eq!(s.bonus_pulls, 3);
    assert_eq!((s.bonus_fish, s.bonus_junk, s.bonus_treasure), (1, 1, 1));
    assert!(s.bonus_history_complete);
    assert_eq!(test.db.journal_entry().player_id().filter(id).count(), 20);
    assert!(p.total_xp > 0);
    assert!(test.db.cast_pull().interaction_id().filter(2002u64).count() > 0);
    assert!(test.db.item_stack().iter().filter(|i| i.item == "scrap").count() > 0);
}

#[test]
fn lazy_repair_recovers_counter_and_genuine_bonus_from_journal() {
    let test = setup();
    let id = player(&test, 10);
    force_weights(&test, 1_000_000, 0, 0);
    rod(&test, id, 3001, 2);
    running_trip(&test, id, 3002, "boat", "Twig Boat");
    finish(&test, 3002);
    running_trip(&test, id, 3003, "net", "Twig Net");
    finish(&test, 3003);
    // Simulate the upgraded projection: inflated counters, missing new row, expired evidence.
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.haul_statistics().player_id().delete(id);
        let mut s = ctx.db.angler_statistics().player_id().find(id).unwrap();
        s.bonus_pulls = 13;
        s.bonus_fish = 13; s.bonus_junk = 0; s.bonus_treasure = 0;
        s.bonus_history_complete = true;
        ctx.db.angler_statistics().player_id().update(s);
        for pull in ctx.db.cast_pull().iter().filter(|p| p.player_id == id).collect::<Vec<_>>() { ctx.db.cast_pull().key().delete(pull.key); }
        for receipt in ctx.db.command_receipt().iter().collect::<Vec<_>>() { ctx.db.command_receipt().interaction_id().delete(receipt.interaction_id); }
        for trip in ctx.db.haul_trip().player_id().filter(id).collect::<Vec<_>>() { ctx.db.haul_trip().interaction_id().delete(trip.interaction_id); }
        crate::statistics::ensure(ctx, id)?;
        crate::statistics::refresh(ctx, id)
    }).unwrap();
    let p = test.db.player().player_id().find(id).unwrap();
    let player_snapshot = (p.fish_count, p.junk_count, p.treasure_count, p.completed_casts, p.coins, p.total_xp, p.next_cast_at);
    assert_eq!(player_snapshot.0, 16);
    assert_eq!(player_snapshot.3, 3);
    let journal_keys: Vec<String> = test.db.journal_entry().player_id().filter(id).map(|e| e.key).collect();
    let items: Vec<(String, u64)> = test.db.item_stack().iter().map(|i| (i.item.clone(), i.quantity)).collect();
    assert_eq!(test.db.haul_statistics().player_id().find(id).unwrap().extra_pulls, 12);
    let deliveries = test.db.achievement_delivery().count();
    let s = stats(&test, id);
    let stats_snapshot = (s.bonus_pulls, s.bonus_fish, s.bonus_junk, s.bonus_treasure, s.bonus_history_complete, s.total_casts, s.fish_count, s.junk_count, s.treasure_count, s.recorded_casts, s.measured_fish_count);
    assert_eq!(stats_snapshot.0, 1);
    assert_eq!((stats_snapshot.1, stats_snapshot.2, stats_snapshot.3), (1, 0, 0));
    assert!(stats_snapshot.4);
    // Repeating repair is byte-for-byte stable and queues no announcements.
    refresh(&test, id);
    let s2 = stats(&test, id);
    assert_eq!(
        (s2.bonus_pulls, s2.bonus_fish, s2.bonus_junk, s2.bonus_treasure, s2.bonus_history_complete, s2.total_casts, s2.fish_count, s2.junk_count, s2.treasure_count, s2.recorded_casts, s2.measured_fish_count),
        stats_snapshot
    );
    let p2 = test.db.player().player_id().find(id).unwrap();
    assert_eq!(
        (p2.fish_count, p2.junk_count, p2.treasure_count, p2.completed_casts, p2.coins, p2.total_xp, p2.next_cast_at),
        player_snapshot
    );
    assert_eq!(
        test.db.journal_entry().player_id().filter(id).map(|e| e.key).collect::<Vec<_>>(),
        journal_keys
    );
    assert_eq!(
        test.db.item_stack().iter().map(|i| (i.item.clone(), i.quantity)).collect::<Vec<_>>(),
        items
    );
    assert_eq!(test.db.achievement_delivery().count(), deliveries);
}

#[test]
fn incomplete_legacy_coverage_keeps_inferred_lifetime_total() {
    let test = setup();
    let id = player(&test, 10);
    force_weights(&test, 1_000_000, 0, 0);
    rod(&test, id, 4001, 2);
    running_trip(&test, id, 4002, "boat", "Twig Boat");
    finish(&test, 4002);
    running_trip(&test, id, 4003, "net", "Twig Net");
    finish(&test, 4003);
    // Six journal-less catches across four casts: two inferred legitimate bonuses.
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.fish_count += 6; p.completed_casts += 4;
        ctx.db.player().player_id().update(p);
        crate::statistics::refresh(ctx, id)
    }).unwrap();
    let s = stats(&test, id);
    assert_eq!(s.bonus_pulls, 3);
    assert_eq!((s.bonus_fish, s.bonus_junk, s.bonus_treasure), (1, 0, 0));
    assert!(!s.bonus_history_complete);
}

#[test]
fn counter_survives_evidence_pruning_and_partial_haul_journal_fails_closed() {
    let test = setup();
    let id = player(&test, 10);
    force_weights(&test, 1_000_000, 0, 0);
    running_trip(&test, id, 5001, "boat", "Twig Boat");
    finish(&test, 5001);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for pull in ctx.db.cast_pull().iter().filter(|p| p.player_id == id).collect::<Vec<_>>() { ctx.db.cast_pull().key().delete(pull.key); }
        for receipt in ctx.db.command_receipt().iter().collect::<Vec<_>>() { ctx.db.command_receipt().interaction_id().delete(receipt.interaction_id); }
        for trip in ctx.db.haul_trip().player_id().filter(id).collect::<Vec<_>>() { ctx.db.haul_trip().interaction_id().delete(trip.interaction_id); }
        crate::statistics::refresh(ctx, id)
    }).unwrap();
    assert_eq!(test.db.haul_statistics().player_id().find(id).unwrap().extra_pulls, 9);
    assert_eq!(stats(&test, id).bonus_pulls, 0);
    refresh(&test, id);
    assert_eq!(stats(&test, id).bonus_pulls, 0);
    // A haul-shaped journal group missing an index is rejected, never guessed.
    // ensure_player already initialized both projections; withhold only the new
    // counter in its own setup tx so the failed repair leaves state untouched.
    let id2 = player(&test, 20);
    assert_ne!(id2, id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for (interaction, index) in [(7000u64, 0u8), (7000, 1), (7000, 3)] {
            ctx.db.journal_entry().insert(JournalEntry {
                key: format!("pull:{}", (u128::from(interaction) << 8) | u128::from(index)),
                player_id: id2, catch_id: None, caught_at: ctx.timestamp,
                outcome: "fish".into(), species_id: None, name: "Anchovy".into(),
                rarity: "C".into(), length_mm: 100, weight_g: 50, xp_granted: None, biome_id: Some(1),
            });
        }
        ctx.db.haul_statistics().player_id().delete(id2);
        Ok::<(), String>(())
    }).unwrap();
    let before = test.db.angler_statistics().player_id().find(id2).unwrap();
    let before_tuple = (before.bonus_fish, before.bonus_junk, before.bonus_treasure, before.bonus_pulls, before.bonus_history_complete, before.total_casts, before.recorded_casts);
    let result = test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| crate::statistics::ensure(ctx, id2));
    assert_eq!(result.unwrap_err(), "INCOMPLETE_PULL_HISTORY");
    let after = test.db.angler_statistics().player_id().find(id2).unwrap();
    assert_eq!(
        (after.bonus_fish, after.bonus_junk, after.bonus_treasure, after.bonus_pulls, after.bonus_history_complete, after.total_casts, after.recorded_casts),
        before_tuple
    );
    assert!(test.db.haul_statistics().player_id().find(id2).is_none());
}
