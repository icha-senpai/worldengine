use crate::{
    accounts, casting, crafting,
    gear_instances::{self, gear_activity_snapshot, gear_family_progress, gear_instance, gear_loadout},
    hauls::{self, haul_trip},
    inventory,
    raids::{self, raid_attempt, raid_job},
    tables::*,
    tideforge::{self, *},
};
use spacetimedb::{
    ConnectionId, Identity, Table, TimeDuration, Timestamp,
    rand::Rng,
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
    test.clock.set(Timestamp::from_micros_since_unix_epoch(1_800_000_000_000_000));
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        crate::init(ctx);
        accounts::configure_service(
            ctx,
            Identity::from_claims("test", "bot"),
            ServiceRole::DiscordAdapter,
            true,
        )
    })
    .unwrap();
    test
}

fn player(test: &TestContext, discord: u64, subject: &str) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let player = accounts::ensure_player(ctx, discord, subject)?;
        ctx.db.player_identity().insert(PlayerIdentity {
            identity: Identity::from_claims("test", subject),
            player_id: player.player_id,
            linked_at: ctx.timestamp,
        });
        Ok::<_, String>(player.player_id)
    })
    .unwrap()
}

fn activate_foundation(test: &TestContext) {
    test.with_reducer_tx(TestAuth::internal(), gear_instances::activate_gear_foundation)
        .unwrap();
}

fn activate_effects(test: &TestContext) {
    test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects)
        .unwrap();
}

fn interaction(test: &TestContext, suffix: u64) -> u64 {
    let ms = test.clock.now().to_micros_since_unix_epoch() / 1000;
    (u64::try_from(ms - 1_420_070_400_000).unwrap() << 22) | suffix
}

fn browser_request(test: &TestContext, suffix: u16) -> u64 {
    ((test.clock.now().to_micros_since_unix_epoch() as u64 / 1000) << 16) | u64::from(suffix)
}

fn select_adapter(test: &TestContext, discord: u64, request: u64) {
    test.with_reducer_tx(auth("bot"), |ctx| {
        accounts::select_discord_player(ctx, discord, "Angler".into(), request)
    })
    .unwrap();
}

fn original(player_id: u64, kind: &str, definition_id: u32) -> u128 {
    gear_instances::original_id(player_id, kind, definition_id).unwrap()
}

fn family(player_id: u64, kind: &str, family_id: u32) -> u128 {
    gear_instances::family_key(player_id, kind, family_id).unwrap()
}

fn book(
    test: &TestContext,
    player_id: u64,
    kind: &str,
    activity_kind: &str,
    activity_id: u128,
    biome_id: u32,
) -> TideforgeActivitySnapshot {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        tideforge::book(ctx, player_id, kind, activity_kind, activity_id, biome_id)
    })
    .unwrap()
    .unwrap()
}

fn award(test: &TestContext, snapshot: &TideforgeActivitySnapshot, ordinary_fish: bool) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        tideforge::award_mastery(ctx, snapshot, ordinary_fish)
    })
    .unwrap()
}

fn add_library(test: &TestContext, player_id: u64, signature_id: &str) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_library().insert(TideforgeLibrary {
            key: format!("{player_id}:{signature_id}"),
            player_id,
            signature_id: signature_id.into(),
        });
        Ok::<(), String>(())
    })
    .unwrap();
}

fn set_native(test: &TestContext, player_id: u64, instance_id: u128, signature_id: &str) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id,
            player_id,
            affixes_json: "[]".into(),
            native_signature: Some(signature_id.into()),
        });
        Ok::<(), String>(())
    })
    .unwrap();
}

fn assert_same_snapshot(a: &TideforgeActivitySnapshot, b: &TideforgeActivitySnapshot) {
    assert_eq!(a.key, b.key);
    assert_eq!(a.player_id, b.player_id);
    assert_eq!(a.activity_kind, b.activity_kind);
    assert_eq!(a.activity_id, b.activity_id);
    assert_eq!(a.gear_instance_id, b.gear_instance_id);
    assert_eq!(a.gear_revision, b.gear_revision);
    assert_eq!(a.family_id, b.family_id);
    assert_eq!(a.gear_kind, b.gear_kind);
    assert_eq!(a.biome_id, b.biome_id);
    assert_eq!(a.effects_json, b.effects_json);
    assert_eq!(a.catalog_version, b.catalog_version);
    assert_eq!(a.mastery_curve_version, b.mastery_curve_version);
    assert_eq!(a.mastery_xp_at_booking, b.mastery_xp_at_booking);
    assert_eq!(a.mastery_level, b.mastery_level);
    assert_eq!(a.base_mastery_xp, b.base_mastery_xp);
    assert_eq!(a.booked_at, b.booked_at);
}

#[test]
fn activation_requires_owner_and_foundation_preserves_legacy_and_is_idempotent() {
    let test = setup();
    let id = player(&test, 6101, "angler");
    assert_eq!(
        test.with_reducer_tx(auth("outsider"), tideforge::activate_tideforge_effects)
            .unwrap_err(),
        "OWNER_REQUIRED"
    );
    assert_eq!(
        test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects)
            .unwrap_err(),
        "GEAR_FOUNDATION_UNAVAILABLE"
    );
    let disabled = test
        .with_reducer_tx(TestAuth::internal(), |ctx| tideforge::book(ctx, id, "rod", "cast", 1, 1))
        .unwrap();
    assert!(disabled.is_none());
    assert_eq!(test.db.tideforge_state().count(), 0);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.coins = 731;
        p.total_xp = 51;
        p.next_cast_at = ctx.timestamp + TimeDuration::from_micros(45_000_000);
        ctx.db.player().player_id().update(p);
        let mut rod = ctx.db.owned_rod().key().find((u128::from(id) << 32) | 1).unwrap();
        rod.upgrade_level = 2;
        ctx.db.owned_rod().key().update(rod);
        Ok::<(), String>(())
    }).unwrap();
    let haul_id = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| {
        hauls::start_haul_from_discord(ctx, 6101, haul_id, None, 8, "net".into())
    }).unwrap();
    activate_foundation(&test);
    let rod_key = family(id, "rod", 1);
    let net_key = family(id, "net", 1);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut progress = ctx.db.gear_family_progress().key().find(rod_key).unwrap();
        progress.mastery_xp = 987;
        ctx.db.gear_family_progress().key().update(progress);
        let mut progress = ctx.db.gear_family_progress().key().find(net_key).unwrap();
        progress.mastery_xp = 222;
        progress.curve_version = 1;
        ctx.db.gear_family_progress().key().update(progress);
        Ok::<(), String>(())
    }).unwrap();
    let before_player = test.db.player().player_id().find(id).unwrap();
    let before_rod = test.db.gear_instance().instance_id().find(original(id, "rod", 1)).unwrap();
    let before_trip = test.db.haul_trip().interaction_id().find(haul_id).unwrap();
    let before_gear_snapshot = test.db.gear_activity_snapshot().key().find(format!("haul:{haul_id}")).unwrap();
    activate_effects(&test);
    let state = test.db.tideforge_state().singleton().find(1).unwrap();
    assert_eq!((state.version, state.catalog_version, state.curve_version), (1, 1, 1));
    for row in test.db.gear_family_progress().player_id().filter(id) {
        assert_eq!(row.curve_version, 1);
    }
    assert_eq!(test.db.gear_family_progress().key().find(rod_key).unwrap().mastery_xp, 987);
    assert_eq!(test.db.gear_family_progress().key().find(net_key).unwrap().mastery_xp, 222);
    assert_eq!(test.db.tideforge_activity_snapshot().count(), 0);
    let after_player = test.db.player().player_id().find(id).unwrap();
    assert_eq!((after_player.coins, after_player.total_xp, after_player.next_cast_at),
               (before_player.coins, before_player.total_xp, before_player.next_cast_at));
    let after_rod = test.db.gear_instance().instance_id().find(before_rod.instance_id).unwrap();
    assert_eq!((after_rod.quality_level, after_rod.reinforcement_level, after_rod.revision),
               (before_rod.quality_level, before_rod.reinforcement_level, before_rod.revision));
    let after_trip = test.db.haul_trip().interaction_id().find(haul_id).unwrap();
    assert_eq!((after_trip.quality_level, after_trip.luck_bp, after_trip.xp_bonus_bp, after_trip.due_at, &after_trip.status),
               (before_trip.quality_level, before_trip.luck_bp, before_trip.xp_bonus_bp, before_trip.due_at, &before_trip.status));
    let after_gear_snapshot = test.db.gear_activity_snapshot().key().find(format!("haul:{haul_id}")).unwrap();
    assert_eq!((after_gear_snapshot.booked_at, after_gear_snapshot.snapshot.quality_level),
               (before_gear_snapshot.booked_at, before_gear_snapshot.snapshot.quality_level));
    test.clock.advance(TimeDuration::from_micros(1_000_000));
    activate_effects(&test);
    assert_eq!(test.db.tideforge_state().singleton().find(1).unwrap().activated_at, state.activated_at);
    let repeated_progress = test.db.gear_family_progress().key().find(rod_key).unwrap();
    assert_eq!((repeated_progress.mastery_xp, repeated_progress.curve_version), (987, 1));
    let repeated_player = test.db.player().player_id().find(id).unwrap();
    assert_eq!((repeated_player.coins, repeated_player.total_xp, repeated_player.next_cast_at),
               (before_player.coins, before_player.total_xp, before_player.next_cast_at));
    let repeated_rod = test.db.gear_instance().instance_id().find(before_rod.instance_id).unwrap();
    assert_eq!((repeated_rod.quality_level, repeated_rod.reinforcement_level, repeated_rod.revision),
               (before_rod.quality_level, before_rod.reinforcement_level, before_rod.revision));
    let repeated_trip = test.db.haul_trip().interaction_id().find(haul_id).unwrap();
    assert_eq!((repeated_trip.quality_level, repeated_trip.due_at, &repeated_trip.status),
               (before_trip.quality_level, before_trip.due_at, &before_trip.status));
    assert_eq!(test.db.tideforge_activity_snapshot().count(), 0);
}

#[test]
fn activation_rejects_unknown_curve_and_invalid_dormant_affix_atomically() {
    for corrupt_curve in [true, false] {
        let test = setup();
        let id = player(&test, 6201, "angler");
        activate_foundation(&test);
        let rod_key = family(id, "rod", 1);
        let boat_key = family(id, "boat", 1);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut rod = ctx.db.gear_family_progress().key().find(rod_key).unwrap();
            rod.mastery_xp = 35;
            rod.curve_version = if corrupt_curve { 2 } else { 0 };
            ctx.db.gear_family_progress().key().update(rod);
            if !corrupt_curve {
                ctx.db.tideforge_gear().insert(TideforgeGear {
                    instance_id: original(id, "rod", 1),
                    player_id: id,
                    affixes_json: r#"[{"slot":3,"id":"fortune","tier":99,"finishBp":0,"secondaryBiomeId":null}]"#.into(),
                    native_signature: None,
                });
            }
            Ok::<(), String>(())
        }).unwrap();
        assert!(test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects).is_err());
        assert_eq!(test.db.tideforge_state().count(), 0);
        let rod = test.db.gear_family_progress().key().find(rod_key).unwrap();
        assert_eq!((rod.mastery_xp, rod.curve_version), (35, if corrupt_curve { 2 } else { 0 }));
        assert_eq!(test.db.gear_family_progress().key().find(boat_key).unwrap().curve_version, 0);
        assert_eq!(test.db.tideforge_activity_snapshot().count(), 0);
    }
}

#[test]
fn booking_is_immutable_owner_checked_and_does_not_consume_rng() {
    let test = setup();
    let id = player(&test, 6301, "first");
    let other = player(&test, 6302, "other");
    activate_foundation(&test);
    activate_effects(&test);
    let rod = original(id, "rod", 1);
    let snapshot = book(&test, id, "rod", "cast", 101, 2);
    assert_eq!((snapshot.gear_instance_id, snapshot.gear_revision, snapshot.family_id), (rod, 1, 1));
    let effects = tideforge::decode(&snapshot).unwrap();
    assert_eq!((effects.version, effects.kind.as_str(), effects.goal.as_str()), (1, "rod", "ranks"));
    assert!(effects.stats.is_empty());
    assert!(effects.extra_pull_bp.is_empty());
    assert!(effects.signatures.is_empty());
    assert_eq!(snapshot.base_mastery_xp, 100);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut gear = ctx.db.gear_instance().instance_id().find(rod).unwrap();
        gear.quality_level = 3;
        gear.revision = 2;
        ctx.db.gear_instance().instance_id().update(gear);
        let mut progress = ctx.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap();
        progress.mastery_xp = 1500;
        ctx.db.gear_family_progress().key().update(progress);
        Ok::<(), String>(())
    }).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| tideforge::set_tideforge_goal(ctx, "perfects".into())).unwrap();
    let repeated = book(&test, id, "rod", "cast", 101, 1);
    assert_same_snapshot(&snapshot, &repeated);
    assert_eq!(test.db.tideforge_activity_snapshot().count(), 1);
    assert!(test.with_reducer_tx(TestAuth::internal(), |ctx| tideforge::lookup(ctx, other, "cast", 101)).is_err());
    test.rng.set_seed(12345);
    let after_booking = test.with_reducer_tx(TestAuth::internal(), |ctx| {
        tideforge::book(ctx, id, "rod", "cast", 102, 2)?;
        Ok::<_, String>(ctx.rng().r#gen::<u64>())
    }).unwrap();
    test.rng.set_seed(12345);
    let without_booking = test.with_reducer_tx(TestAuth::internal(), |ctx| {
        Ok::<_, String>(ctx.rng().r#gen::<u64>())
    }).unwrap();
    assert_eq!(after_booking, without_booking);
}

#[test]
fn mastery_is_per_activity_shared_by_family_and_settles_from_frozen_snapshot() {
    let test = setup();
    let id = player(&test, 6401, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    let original_id = original(id, "rod", 1);
    let copy_id = (u128::from(id) << 64) | (1u128 << 63) | 1;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut rod = ctx.db.gear_instance().instance_id().find(original_id).unwrap();
        rod.quality_level = 3;
        rod.reinforcement_level = 2;
        rod.revision = 2;
        ctx.db.gear_instance().instance_id().update(rod.clone());
        rod.instance_id = copy_id;
        rod.protected_original = false;
        rod.quality_level = 0;
        rod.reinforcement_level = 0;
        rod.revision = 1;
        rod.source = "test_copy".into();
        ctx.db.gear_instance().insert(rod);
        Ok::<(), String>(())
    }).unwrap();
    let first = book(&test, id, "rod", "cast", 201, 2);
    assert_eq!(award(&test, &first, true), 100);
    assert_eq!(award(&test, &first, true), 0);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 100);
    test.with_reducer_tx(auth("angler"), |ctx| gear_instances::equip_gear_instance(ctx, copy_id)).unwrap();
    let second = book(&test, id, "rod", "cast", 202, 2);
    assert_eq!((first.gear_instance_id, first.gear_revision), (original_id, 2));
    assert_eq!((second.gear_instance_id, second.gear_revision), (copy_id, 1));
    assert_eq!(second.mastery_xp_at_booking, 100);
    let developed = test.db.gear_instance().instance_id().find(original_id).unwrap();
    let copy = test.db.gear_instance().instance_id().find(copy_id).unwrap();
    assert_eq!((developed.quality_level, developed.reinforcement_level), (3, 2));
    assert_eq!((copy.quality_level, copy.reinforcement_level), (0, 0));
    assert_eq!(test.db.gear_family_progress().player_id().filter(id).filter(|row| row.kind == "rod").count(), 1);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_state().singleton().delete(1);
        Ok::<(), String>(())
    }).unwrap();
    assert_eq!(award(&test, &second, true), 100);
    assert_eq!(award(&test, &second, true), 0);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 200);
    let loaded = test.with_reducer_tx(TestAuth::internal(), |ctx| tideforge::lookup(ctx, id, "cast", 202)).unwrap().unwrap();
    assert_same_snapshot(&second, &loaded);
}

fn discover(test: &TestContext, player_id: u64) -> u32 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx
            .db
            .species_definition()
            .iter()
            .find(|row| row.counts_for_ordinary_collection_completion)
            .unwrap();
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(player_id) << 32) | u128::from(species.species_id),
            player_id,
            species_id: species.species_id,
            count: 1,
            rank_counts: vec![1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            first_caught_at: ctx.timestamp,
            best_length_mm: species.typical_length_mm,
            best_weight_g: species.typical_weight_g,
        });
        Ok::<_, String>(species.species_id)
    }).unwrap()
}

#[test]
fn signature_ownership_kind_and_committed_raid_lock_are_enforced() {
    let test = setup();
    let id = player(&test, 6501, "first");
    let other = player(&test, 6502, "other");
    activate_foundation(&test);
    activate_effects(&test);
    for signature in ["tidal_memory", "ghost_trawler", "nets_of_plenty", "atlas_of_seven_waters"] {
        add_library(&test, id, signature);
    }
    add_library(&test, other, "echoed_line");
    assert_eq!(
        test.with_reducer_tx(auth("unlinked"), |ctx| {
            tideforge::select_tideforge_signatures(ctx, None, None, None)
        }).unwrap_err(),
        "ACCOUNT_NOT_LINKED"
    );
    assert!(test.with_reducer_tx(auth("first"), |ctx| {
        tideforge::select_tideforge_signatures_from_discord(ctx, 6501, interaction(&test, 19), None, None, None)
    }).is_err());
    assert_eq!(
        test.with_reducer_tx(auth("first"), |ctx| {
            tideforge::select_tideforge_signatures(ctx, Some("echoed_line".into()), None, None)
        }).unwrap_err(),
        "TIDEFORGE_SIGNATURE_NOT_OWNED"
    );
    for (rod, haul, waters) in [
        (Some("ghost_trawler"), None, None),
        (None, Some("tidal_memory"), None),
        (None, None, Some("nets_of_plenty")),
    ] {
        assert_eq!(
            test.with_reducer_tx(auth("first"), |ctx| {
                tideforge::select_tideforge_signatures(
                    ctx,
                    rod.map(str::to_owned),
                    haul.map(str::to_owned),
                    waters.map(str::to_owned),
                )
            }).unwrap_err(),
            "TIDEFORGE_SIGNATURE_KIND_INVALID"
        );
    }
    assert_eq!(test.db.tideforge_loadout().count(), 0);
    test.with_reducer_tx(auth("first"), |ctx| {
        tideforge::select_tideforge_signatures(
            ctx, Some("tidal_memory".into()), Some("nets_of_plenty".into()),
            Some("atlas_of_seven_waters".into()),
        )
    }).unwrap();
    let selected_net = book(&test, id, "net", "haul", 304, 1);
    assert!(tideforge::decode(&selected_net).unwrap().has("nets_of_plenty"));
    let choices = || (
        Some("tidal_memory".to_owned()),
        Some("ghost_trawler".to_owned()),
        Some("atlas_of_seven_waters".to_owned()),
    );
    test.with_reducer_tx(auth("first"), |ctx| {
        let (rod, haul, waters) = choices();
        tideforge::select_tideforge_signatures(ctx, rod, haul, waters)
    }).unwrap();
    let rod = book(&test, id, "rod", "cast", 301, 1);
    let boat = book(&test, id, "boat", "haul", 302, 1);
    let net = book(&test, id, "net", "haul", 303, 1);
    assert!(tideforge::decode(&rod).unwrap().has("tidal_memory"));
    assert!(tideforge::decode(&boat).unwrap().has("ghost_trawler"));
    assert!(!tideforge::decode(&net).unwrap().has("ghost_trawler"));
    assert!(tideforge::decode(&net).unwrap().has("atlas_of_seven_waters"));
    let species = discover(&test, id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let supply = game_rules::raids::catalog().supply;
        inventory::grant_item_with_reason(
            ctx, id, supply.slot, &supply.item, 1, "fixture".into(), "fixture",
        )
    }).unwrap();
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let attempt_id = (u128::from(id) << 64) | u128::from(request);
    let deploy = browser_request(&test, 2);
    test.with_reducer_tx(auth("first"), |ctx| raids::deploy_raid(ctx, deploy, attempt_id)).unwrap();
    assert_eq!(test.db.raid_attempt().id().find(attempt_id).unwrap().status, "deployed");
    test.with_reducer_tx(auth("first"), |ctx| {
        let (rod, haul, waters) = choices();
        tideforge::select_tideforge_signatures(ctx, rod, haul, waters)
    }).unwrap();
    assert_eq!(
        test.with_reducer_tx(auth("first"), |ctx| {
            tideforge::select_tideforge_signatures(
                ctx,
                Some("tidal_memory".into()),
                Some("nets_of_plenty".into()),
                Some("atlas_of_seven_waters".into()),
            )
        }).unwrap_err(),
        "RAID_EQUIPMENT_LOCKED"
    );
    assert_eq!(
        test.with_reducer_tx(auth("first"), |ctx| {
            tideforge::select_tideforge_signatures(
                ctx, None, Some("ghost_trawler".into()), Some("atlas_of_seven_waters".into()),
            )
        }).unwrap_err(),
        "RAID_EQUIPMENT_LOCKED"
    );
    test.with_reducer_tx(auth("first"), |ctx| tideforge::set_tideforge_goal(ctx, "maxima".into())).unwrap();
    let unchanged = test.db.tideforge_activity_snapshot().key().find("cast:301".to_owned()).unwrap();
    assert_same_snapshot(&rod, &unchanged);
    let selected = interaction(&test, 20);
    select_adapter(&test, 6501, selected);
    test.with_reducer_tx(auth("bot"), |ctx| {
        let (rod, haul, waters) = choices();
        tideforge::select_tideforge_signatures_from_discord(ctx, 6501, selected, rod, haul, waters)
    }).unwrap();
    assert!(test.with_reducer_tx(auth("bot"), |ctx| {
        tideforge::set_tideforge_goal_from_discord(ctx, 6502, selected, "perfects".into())
    }).is_err());
    assert_eq!(test.db.tideforge_loadout().player_id().find(id).unwrap().goal, "maxima");
}

#[test]
fn fractional_credit_preserves_remainders_and_isolates_owner_and_resource() {
    let test = setup();
    let first = player(&test, 6601, "first");
    let second = player(&test, 6602, "second");
    activate_foundation(&test);
    activate_effects(&test);
    let mut results = Vec::new();
    for _ in 0..4 {
        results.push(test.with_reducer_tx(TestAuth::internal(), |ctx| {
            tideforge::credit(ctx, first, "rusted_tin", 1, 12_500)
        }).unwrap());
    }
    assert_eq!(results, vec![1, 1, 1, 2]);
    assert_eq!(results.iter().sum::<u64>(), 5);
    let key = format!("{first}:rusted_tin");
    assert_eq!(test.db.tideforge_fractional_credit().key().find(&key).unwrap().remainder_bp, 0);
    for (player, resource) in [(first, "scrap"), (second, "rusted_tin")] {
        assert_eq!(test.with_reducer_tx(TestAuth::internal(), |ctx| {
            tideforge::credit(ctx, player, resource, 1, 12_500)
        }).unwrap(), 1);
        assert_eq!(test.db.tideforge_fractional_credit().key().find(format!("{player}:{resource}")).unwrap().remainder_bp, 2500);
    }
    assert_eq!(test.db.tideforge_fractional_credit().key().find(&key).unwrap().remainder_bp, 0);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_fractional_credit().key().find(&key).unwrap();
        row.remainder_bp = 10_000;
        ctx.db.tideforge_fractional_credit().key().update(row);
        Ok::<(), String>(())
    }).unwrap();
    assert!(test.with_reducer_tx(TestAuth::internal(), |ctx| tideforge::credit(ctx, first, "rusted_tin", 1, 12_500)).is_err());
    assert_eq!(test.db.tideforge_fractional_credit().key().find(&key).unwrap().remainder_bp, 10_000);
    assert!(test.with_reducer_tx(TestAuth::internal(), |ctx| tideforge::credit(ctx, first, "coins", 1, 10_000)).is_err());
}

#[test]
fn atlas_and_tidal_daily_uses_are_separate_shared_and_reset_at_utc_midnight() {
    let test = setup();
    let id = player(&test, 6701, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    add_library(&test, id, "tidal_memory");
    set_native(&test, id, original(id, "rod", 1), "atlas_of_seven_waters");
    set_native(&test, id, original(id, "boat", 101), "atlas_of_seven_waters");
    test.with_reducer_tx(auth("angler"), |ctx| {
        tideforge::select_tideforge_signatures(ctx, Some("tidal_memory".into()), None, None)
    }).unwrap();
    let junk = book(&test, id, "rod", "cast", 401, 1);
    let frozen = tideforge::decode(&junk).unwrap();
    assert!(frozen.has("atlas_of_seven_waters"));
    assert!(frozen.has("tidal_memory"));
    assert_eq!(frozen.stat("masteryXpBp"), 0);
    assert_eq!(frozen.parameter("atlas_of_seven_waters", "dailyBaseMasteryBp"), 5000);
    assert_eq!(frozen.parameter("tidal_memory", "dailyBaseMasteryBp"), 5000);
    assert_eq!(award(&test, &junk, false), 100);
    assert_eq!(test.db.tideforge_daily_use().count(), 0);
    let first = book(&test, id, "rod", "cast", 402, 1);
    assert_eq!(award(&test, &first, true), 200);
    assert_eq!(test.db.tideforge_daily_use().count(), 2);
    assert_eq!(award(&test, &first, true), 0);
    assert_eq!(test.db.tideforge_daily_use().count(), 2);
    let next_rod = book(&test, id, "rod", "cast", 403, 1);
    assert_eq!(award(&test, &next_rod, true), 100);
    let boat = book(&test, id, "boat", "haul", 404, 1);
    assert!(tideforge::decode(&boat).unwrap().has("atlas_of_seven_waters"));
    assert_eq!(award(&test, &boat, true), 100);
    assert_eq!(test.db.tideforge_daily_use().count(), 2);
    const DAY: i64 = 86_400_000_000;
    let today = test.clock.now().to_micros_since_unix_epoch().div_euclid(DAY);
    test.clock.set(Timestamp::from_micros_since_unix_epoch((today + 1) * DAY));
    let tomorrow = book(&test, id, "rod", "cast", 405, 1);
    assert_eq!(award(&test, &tomorrow, true), 200);
    assert_eq!(test.db.tideforge_daily_use().count(), 4);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 600);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp, 100);
}

#[test]
fn activity_deletion_removes_only_its_snapshot_and_receipt() {
    let test = setup();
    let id = player(&test, 6801, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    let first = book(&test, id, "rod", "cast", 501, 1);
    let other = book(&test, id, "rod", "cast", 502, 1);
    assert_eq!(award(&test, &first, false), 100);
    assert_eq!(award(&test, &other, false), 100);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        gear_instances::snapshot_selected(
            ctx, id, "rod", "cast", 501, Some(0), Some(0), Some(0), 17,
            Some(crate::content::CONTENT_VERSION), None,
        )?;
        tideforge::credit(ctx, id, "scrap", 1, 12_500)?;
        ctx.db.tideforge_daily_use().insert(TideforgeDailyUse {
            key: format!("{id}:1:1:tidal_memory"),
            player_id: id,
            biome_id: 1,
            utc_day: 1,
            signature_id: "tidal_memory".into(),
            activity_key: first.key.clone(),
        });
        ctx.db.tideforge_specimen_modifiers().insert(TideforgeSpecimenModifiers {
            catch_id: 123,
            player_id: id,
            sale_bp: 0,
            fillet_bp: 0,
            scale_bp: 0,
            effects_version: 1,
            activity_key: first.key.clone(),
        });
        tideforge::delete_activity(ctx, "cast", 501);
        Ok::<(), String>(())
    }).unwrap();
    assert!(test.db.tideforge_activity_snapshot().key().find("cast:501".to_owned()).is_none());
    assert!(test.db.tideforge_activity_receipt().key().find("cast:501".to_owned()).is_none());
    let retained = test.db.tideforge_activity_snapshot().key().find("cast:502".to_owned()).unwrap();
    assert_same_snapshot(&other, &retained);
    assert!(test.db.tideforge_activity_receipt().key().find("cast:502".to_owned()).is_some());
    assert!(test.db.gear_activity_snapshot().key().find("cast:501".to_owned()).is_some());
    assert_eq!(test.db.tideforge_daily_use().count(), 1);
    assert_eq!(test.db.tideforge_fractional_credit().key().find(format!("{id}:scrap")).unwrap().remainder_bp, 2500);
    assert_eq!(test.db.tideforge_specimen_modifiers().count(), 1);
}

fn seed_private_views(test: &TestContext, player_id: u64, subject: &str, activity_id: u128) {
    set_native(test, player_id, original(player_id, "rod", 1), "atlas_of_seven_waters");
    add_library(test, player_id, "tidal_memory");
    test.with_reducer_tx(auth(subject), |ctx| {
        tideforge::select_tideforge_signatures(ctx, Some("tidal_memory".into()), None, None)
    }).unwrap();
    let snapshot = book(test, player_id, "rod", "cast", activity_id, 1);
    assert_eq!(award(test, &snapshot, true), 200);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        tideforge::credit(ctx, player_id, "scrap", 1, 12_500)?;
        ctx.db.tideforge_specimen_modifiers().insert(TideforgeSpecimenModifiers {
            catch_id: activity_id as u64,
            player_id,
            sale_bp: 12,
            fillet_bp: 34,
            scale_bp: 56,
            effects_version: 1,
            activity_key: snapshot.key.clone(),
        });
        Ok::<(), String>(())
    }).unwrap();
}

fn assert_browser_views(test: &TestContext, subject: &str, player_id: u64) {
    let ctx = test.view_context(auth(subject));
    let gear = my_tideforge_gear(&ctx);
    assert_eq!(gear.len(), 1);
    assert!(gear.iter().all(|row| row.player_id == player_id));
    let library = my_tideforge_library(&ctx);
    assert_eq!(library.len(), 1);
    assert!(library.iter().all(|row| row.player_id == player_id));
    assert_eq!(my_tideforge_loadout(&ctx).unwrap().player_id, player_id);
    let snapshots = my_tideforge_activity_snapshots(&ctx);
    assert_eq!(snapshots.len(), 1);
    assert!(snapshots.iter().all(|row| row.player_id == player_id));
    let credits = my_tideforge_fractional_credits(&ctx);
    assert_eq!(credits.len(), 1);
    assert!(credits.iter().all(|row| row.player_id == player_id));
    let daily = my_tideforge_daily_uses(&ctx);
    assert_eq!(daily.len(), 2);
    assert!(daily.iter().all(|row| row.player_id == player_id));
    let receipts = my_tideforge_activity_receipts(&ctx);
    assert_eq!(receipts.len(), 1);
    assert!(receipts.iter().all(|row| row.player_id == player_id));
    let specimens = my_tideforge_specimen_modifiers(&ctx);
    assert_eq!(specimens.len(), 1);
    assert!(specimens.iter().all(|row| row.player_id == player_id));
}

fn assert_adapter_views(test: &TestContext, player_id: u64) {
    let ctx = test.view_context(auth("bot"));
    let gear = adapter_tideforge_gear(&ctx);
    assert_eq!(gear.len(), 1);
    assert!(gear.iter().all(|row| row.player_id == player_id));
    let library = adapter_tideforge_library(&ctx);
    assert_eq!(library.len(), 1);
    assert!(library.iter().all(|row| row.player_id == player_id));
    assert_eq!(adapter_tideforge_loadout(&ctx).unwrap().player_id, player_id);
    let snapshots = adapter_tideforge_activity_snapshots(&ctx);
    assert_eq!(snapshots.len(), 1);
    assert!(snapshots.iter().all(|row| row.player_id == player_id));
    let credits = adapter_tideforge_fractional_credits(&ctx);
    assert_eq!(credits.len(), 1);
    assert!(credits.iter().all(|row| row.player_id == player_id));
    let daily = adapter_tideforge_daily_uses(&ctx);
    assert_eq!(daily.len(), 2);
    assert!(daily.iter().all(|row| row.player_id == player_id));
    let receipts = adapter_tideforge_activity_receipts(&ctx);
    assert_eq!(receipts.len(), 1);
    assert!(receipts.iter().all(|row| row.player_id == player_id));
    let specimens = adapter_tideforge_specimen_modifiers(&ctx);
    assert_eq!(specimens.len(), 1);
    assert!(specimens.iter().all(|row| row.player_id == player_id));
}

#[test]
fn all_private_views_are_scoped_to_linked_owner_or_selected_adapter() {
    let test = setup();
    let first = player(&test, 6901, "first");
    let second = player(&test, 6902, "second");
    activate_foundation(&test);
    activate_effects(&test);
    seed_private_views(&test, first, "first", 601);
    seed_private_views(&test, second, "second", 602);
    let anonymous = test.view_context(auth("unlinked"));
    assert!(my_tideforge_gear(&anonymous).is_empty());
    assert!(my_tideforge_library(&anonymous).is_empty());
    assert!(my_tideforge_loadout(&anonymous).is_none());
    assert!(my_tideforge_activity_snapshots(&anonymous).is_empty());
    assert!(my_tideforge_fractional_credits(&anonymous).is_empty());
    assert!(my_tideforge_daily_uses(&anonymous).is_empty());
    assert!(my_tideforge_activity_receipts(&anonymous).is_empty());
    assert!(my_tideforge_specimen_modifiers(&anonymous).is_empty());
    assert!(adapter_tideforge_gear(&anonymous).is_empty());
    assert!(adapter_tideforge_library(&anonymous).is_empty());
    assert!(adapter_tideforge_loadout(&anonymous).is_none());
    assert!(adapter_tideforge_activity_snapshots(&anonymous).is_empty());
    assert!(adapter_tideforge_fractional_credits(&anonymous).is_empty());
    assert!(adapter_tideforge_daily_uses(&anonymous).is_empty());
    assert!(adapter_tideforge_activity_receipts(&anonymous).is_empty());
    assert!(adapter_tideforge_specimen_modifiers(&anonymous).is_empty());
    let bot = test.view_context(auth("bot"));
    assert!(adapter_tideforge_gear(&bot).is_empty());
    assert!(adapter_tideforge_library(&bot).is_empty());
    assert!(adapter_tideforge_loadout(&bot).is_none());
    assert!(adapter_tideforge_activity_snapshots(&bot).is_empty());
    assert!(adapter_tideforge_fractional_credits(&bot).is_empty());
    assert!(adapter_tideforge_daily_uses(&bot).is_empty());
    assert!(adapter_tideforge_activity_receipts(&bot).is_empty());
    assert!(adapter_tideforge_specimen_modifiers(&bot).is_empty());
    assert_browser_views(&test, "first", first);
    assert_browser_views(&test, "second", second);
    select_adapter(&test, 6901, interaction(&test, 1));
    assert_adapter_views(&test, first);
    select_adapter(&test, 6902, interaction(&test, 2));
    assert_adapter_views(&test, second);
    let wrong = test.view_context(auth("first"));
    assert!(adapter_tideforge_gear(&wrong).is_empty());
    assert!(adapter_tideforge_activity_snapshots(&wrong).is_empty());
}

fn fixture_bait(test: &TestContext, player_id: u64, uses_left: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.bait_stack().insert(BaitStack {
            key: (u128::from(player_id) << 32) | 13,
            player_id,
            bait_id: 13,
            uses_left,
        });
        ctx.db.bait_loadout().insert(BaitLoadout {
            player_id,
            bait_id: 13,
            secondary_bait_id: 13,
        });
        Ok::<(), String>(())
    }).unwrap();
}

#[test]
fn preservation_reserves_shared_stock_before_rolls_and_zero_rate_is_legacy() {
    for count in [1, 2] {
        let test = setup();
        let id = player(&test, 7001, "angler");
        fixture_bait(&test, id, count);
        let used = test.with_reducer_tx(TestAuth::internal(), |ctx| {
            crafting::consume_bait_with_preservation(ctx, id, 10_000)
        }).unwrap();
        assert_eq!(used.primary.bait_id, 13);
        assert_eq!(used.secondary.bait_id, if count == 2 { 13 } else { 0 });
        assert_eq!(used.size_rolls, if count == 2 { 16 } else { 8 });
        assert_eq!(used.primary.uses_left, count);
        assert_eq!(test.db.bait_stack().key().find((u128::from(id) << 32) | 13).unwrap().uses_left, count);
        let loadout = test.db.bait_loadout().player_id().find(id).unwrap();
        assert_eq!(loadout.bait_id, 13);
        assert_eq!(loadout.secondary_bait_id, if count == 2 { 13 } else { 0 });
    }
    let old = setup();
    let new = setup();
    let old_id = player(&old, 7002, "angler");
    let new_id = player(&new, 7002, "angler");
    fixture_bait(&old, old_id, 2);
    fixture_bait(&new, new_id, 2);
    old.rng.set_seed(4521);
    new.rng.set_seed(4521);
    let (old_bait, old_random) = old.with_reducer_tx(TestAuth::internal(), |ctx| {
        let used = crafting::consume_bait(ctx, old_id)?;
        Ok::<_, String>((used, ctx.rng().r#gen::<u64>()))
    }).unwrap();
    let (new_bait, new_random) = new.with_reducer_tx(TestAuth::internal(), |ctx| {
        let used = crafting::consume_bait_with_preservation(ctx, new_id, 0)?;
        Ok::<_, String>((used, ctx.rng().r#gen::<u64>()))
    }).unwrap();
    assert_eq!((old_bait.primary.bait_id, old_bait.secondary.bait_id, old_bait.size_rolls, old_bait.luck_bp),
               (new_bait.primary.bait_id, new_bait.secondary.bait_id, new_bait.size_rolls, new_bait.luck_bp));
    assert_eq!((old_bait.primary.uses_left, old_bait.secondary.uses_left),
               (new_bait.primary.uses_left, new_bait.secondary.uses_left));
    assert_eq!(old_random, new_random);
}

#[test]
fn actual_off_primary_zero_effect_cast_matches_legacy_catch_bait_and_rng() {
    let legacy = setup();
    let active = setup();
    let a = player(&legacy, 7101, "angler");
    let b = player(&active, 7101, "angler");
    for (test, id) in [(&legacy, a), (&active, b)] {
        activate_foundation(test);
        fixture_bait(test, id, 2);
        force_single_ordinary_rank(test);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut rod = ctx.db.gear_instance().instance_id().find(original(id, "rod", 1)).unwrap();
            rod.primary_biome_id = 2;
            ctx.db.gear_instance().instance_id().update(rod);
            Ok::<(), String>(())
        }).unwrap();
    }
    activate_effects(&active);
    legacy.rng.set_seed(49942);
    active.rng.set_seed(49942);
    let request = interaction(&legacy, 1);
    legacy.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7101, request, None, 8)).unwrap();
    active.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7101, request, None, 8)).unwrap();
    let left = legacy.db.command_receipt().interaction_id().find(request).unwrap();
    let right = active.db.command_receipt().interaction_id().find(request).unwrap();
    assert_eq!((left.outcome, left.species_id, left.rarity, left.length_mm, left.weight_g,
                left.size_grade, left.xp_granted, left.coins_granted, left.item_quantity),
               (right.outcome, right.species_id, right.rarity, right.length_mm, right.weight_g,
                right.size_grade, right.xp_granted, right.coins_granted, right.item_quantity));
    let old_bait = legacy.db.cast_equipment_receipt().interaction_id().find(request).unwrap();
    let new_bait = active.db.cast_equipment_receipt().interaction_id().find(request).unwrap();
    assert_eq!((old_bait.bait_id, old_bait.secondary_bait_id, old_bait.bait_uses_left,
                old_bait.secondary_bait_uses_left, old_bait.bait_size_rolls, old_bait.luck_bp),
               (new_bait.bait_id, new_bait.secondary_bait_id, new_bait.bait_uses_left,
                new_bait.secondary_bait_uses_left, new_bait.bait_size_rolls, new_bait.luck_bp));
    let pulls = |test: &TestContext| {
        let mut rows: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
        rows.sort_by_key(|row| row.key);
        rows.into_iter().map(|row| (
            row.receipt.outcome, row.receipt.species_id, row.receipt.rarity,
            row.receipt.length_mm, row.receipt.weight_g, row.receipt.size_grade,
            row.receipt.xp_granted, row.receipt.coins_granted, row.receipt.item_quantity,
        )).collect::<Vec<_>>()
    };
    assert_eq!(pulls(&legacy), pulls(&active));
    assert!(pulls(&active).iter().all(|pull| pull.0 == "fish"));
    let fx = active.db.tideforge_activity_snapshot().key().find(format!("cast:{request}")).unwrap();
    assert!(tideforge::decode(&fx).unwrap().stats.is_empty());
    assert_eq!(active.db.gear_activity_snapshot().key().find(format!("cast:{request}")).unwrap().snapshot.effects_version, 1);
    assert_eq!(legacy.db.gear_activity_snapshot().key().find(format!("cast:{request}")).unwrap().snapshot.effects_version, 0);
    assert_eq!(legacy.db.tideforge_activity_snapshot().count(), 0);
    assert_eq!(active.db.tideforge_activity_receipt().count(), 1);
    let next = |test: &TestContext| test.with_reducer_tx(
        TestAuth::internal(), |ctx| Ok::<_, String>(ctx.rng().r#gen::<u64>()),
    ).unwrap();
    assert_eq!(next(&legacy), next(&active));
}

#[test]
fn new_haul_pins_luck_due_and_mastery_while_old_haul_stays_legacy() {
    let test = setup();
    let id = player(&test, 7201, "angler");
    activate_foundation(&test);
    let old = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 7201, old, None, 8, "net".into())).unwrap();
    activate_effects(&test);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut progress = ctx.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap();
        progress.mastery_xp = 1620;
        ctx.db.gear_family_progress().key().update(progress);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "boat", 101),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"efficient_hull","tier":10,"finishBp":10000,"secondaryBiomeId":null},{"slot":1,"id":"fortune","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: None,
        });
        Ok::<(), String>(())
    }).unwrap();
    let new = interaction(&test, 2);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 7201, new, None, 8, "boat".into())).unwrap();
    let booked = test.db.haul_trip().interaction_id().find(new).unwrap();
    let fx = test.db.tideforge_activity_snapshot().key().find(format!("haul:{new}")).unwrap();
    let effect = tideforge::decode(&fx).unwrap();
    let definition = test.db.haul_equipment_definition().equipment_id().find(booked.equipment_id).unwrap();
    let quality = test.db.quality_definition().quality_level().find(booked.quality_level).unwrap();
    assert_eq!(booked.luck_bp, definition.luck_bp + quality.luck_bp * definition.quality_bonus_multiplier + effect.stat("luckBp"));
    assert!(effect.stat("luckBp") > 0 && effect.stat("durationBp") > 0);
    assert_eq!(booked.due_at.to_micros_since_unix_epoch() - booked.started_at.to_micros_since_unix_epoch(),
               i64::from(game_rules::tideforge::normal_haul_seconds(600, effect.stat("durationBp")).unwrap()) * 1_000_000);
    let foundation = test.db.gear_activity_snapshot().key().find(format!("haul:{new}")).unwrap();
    assert_eq!((foundation.snapshot.effects_version, foundation.snapshot.luck_bp), (1, Some(booked.luck_bp)));
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut gear = ctx.db.gear_instance().instance_id().find(original(id, "boat", 101)).unwrap();
        gear.quality_level = 3;
        gear.revision += 1;
        ctx.db.gear_instance().instance_id().update(gear);
        let mut row = ctx.db.tideforge_gear().instance_id().find(original(id, "boat", 101)).unwrap();
        row.affixes_json = "[]".into();
        ctx.db.tideforge_gear().instance_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    test.with_reducer_tx(auth("angler"), |ctx| tideforge::set_tideforge_goal(ctx, "perfects".into())).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_state().singleton().delete(1);
        Ok::<(), String>(())
    }).unwrap();
    let old_due = test.db.haul_trip().interaction_id().find(old).unwrap().due_at;
    assert!(test.db.tideforge_activity_snapshot().key().find(format!("haul:{old}")).is_none());
    assert_eq!(test.db.gear_activity_snapshot().key().find(format!("haul:{old}")).unwrap().snapshot.effects_version, 0);
    test.clock.set(old_due);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, old)).unwrap();
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "net", 1)).unwrap().mastery_xp, 0);
    assert!(test.db.tideforge_activity_receipt().key().find(format!("haul:{old}")).is_none());
    test.clock.set(booked.due_at);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, new)).unwrap();
    let finished = test.db.haul_trip().interaction_id().find(new).unwrap();
    assert_eq!((finished.luck_bp, finished.due_at), (booked.luck_bp, booked.due_at));
    assert_same_snapshot(&fx, &test.db.tideforge_activity_snapshot().key().find(format!("haul:{new}")).unwrap());
    assert_eq!(test.db.cast_equipment_receipt().interaction_id().find(new).unwrap().luck_bp, booked.luck_bp);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp, 1720);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, new)).unwrap();
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp, 1720);
}

fn force_single_ordinary_rank(test: &TestContext) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut biome = ctx.db.biome_definition().biome_id().find(1).unwrap();
        biome.fish_weight = 1;
        biome.junk_weight = 0;
        biome.treasure_weight = 0;
        ctx.db.biome_definition().biome_id().update(biome);
        let species = ctx.db.species_definition().biome_id().filter(1u32)
            .find(|row| row.counts_for_ordinary_collection_completion && row.allowed_rarities.iter().any(|rank| rank == "F")).unwrap();
        let rows: Vec<_> = ctx.db.species_rank_definition().biome_id().filter(1u32).collect();
        for mut row in rows {
            row.encounter_weight = if row.species_id == species.species_id && row.ordinal == 0 { 1 } else { 0 };
            ctx.db.species_rank_definition().key().update(row);
        }
        Ok::<(), String>(())
    }).unwrap();
}

#[test]
fn scholar_and_ocean_apply_after_legacy_allocation_only_to_qualifying_pull() {
    let test = setup();
    let id = player(&test, 7301, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    force_single_ordinary_rank(&test);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut rod = ctx.db.rod_definition().rod_id().find(1).unwrap();
        rod.power = 200;
        ctx.db.rod_definition().rod_id().update(rod);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "rod", 1),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"scholar","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("oceans_memory".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    test.rng.set_seed(731);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7301, request, None, 8)).unwrap();
    let mut pulls: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
    pulls.sort_by_key(|row| row.key);
    assert_eq!(pulls.len(), 2);
    assert!(pulls.iter().all(|row| row.receipt.outcome == "fish"));
    assert_eq!(pulls[0].receipt.species_id, pulls[1].receipt.species_id);
    assert_eq!(pulls[0].receipt.rarity, pulls[1].receipt.rarity);
    let equipment = test.db.cast_equipment_receipt().interaction_id().find(request).unwrap();
    let legacy_total = game_rules::rods::fishing_xp(pulls[0].base_xp + pulls[1].base_xp, equipment.xp_bonus_bp).unwrap();
    let first = game_rules::rods::fishing_xp(pulls[0].base_xp, equipment.xp_bonus_bp).unwrap();
    let second = legacy_total - first;
    let (bonus_first, carry) = game_rules::tideforge::scale_credit(first, 12_000, 0).unwrap();
    let (bonus_second, _) = game_rules::tideforge::scale_credit(second, 2_000, carry).unwrap();
    assert_eq!(pulls[0].receipt.xp_granted, first + bonus_first);
    assert_eq!(pulls[1].receipt.xp_granted, second + bonus_second);
    let total = first + second + bonus_first + bonus_second;
    assert_eq!(test.db.command_receipt().interaction_id().find(request).unwrap().xp_granted, total);
    assert_eq!(test.db.player().player_id().find(id).unwrap().total_xp, total);
    let recent: u64 = test.db.recent_catch().player_id().filter(id).map(|row| row.xp_granted).sum();
    assert_eq!(recent, total);
    for pull in &pulls {
        assert_eq!(
            test.db.journal_entry().key().find(format!("pull:{}", pull.key)).unwrap().xp_granted,
            Some(pull.receipt.xp_granted),
        );
    }
    assert_eq!(test.db.tideforge_daily_use().player_id().filter(id)
        .filter(|row| row.signature_id == "oceans_memory").count(), 1);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 100);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7301, request, None, 8)).unwrap();
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 100);
}

#[test]
fn line_aftercast_and_rift_are_bounded_single_action_and_nonrecursive() {
    let test = setup();
    let id = player(&test, 7401, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    force_single_ordinary_rank(&test);
    add_library(&test, id, "rift_current");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut rod = ctx.db.rod_definition().rod_id().find(1).unwrap();
        rod.power = 200;
        ctx.db.rod_definition().rod_id().update(rod);
        let mut progress = ctx.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap();
        progress.mastery_xp = 1620;
        ctx.db.gear_family_progress().key().update(progress);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "rod", 1),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"line_tension","tier":10,"finishBp":10000,"secondaryBiomeId":null},{"slot":1,"id":"aftercast","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("echoed_line".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    test.with_reducer_tx(auth("angler"), |ctx| {
        tideforge::select_tideforge_signatures(ctx, None, None, Some("rift_current".into()))
    }).unwrap();
    let request = interaction(&test, 1);
    test.rng.set_seed(742);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7401, request, None, 8)).unwrap();
    let pulls: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
    assert!((3..=6).contains(&pulls.len()));
    assert!(pulls.iter().all(|row| row.receipt.outcome == "fish"));
    assert_eq!(test.db.tideforge_daily_use().player_id().filter(id)
        .filter(|row| row.signature_id == "rift_current").count(), 1);
    let snapshot = test.db.tideforge_activity_snapshot().key().find(format!("cast:{request}")).unwrap();
    let effects = tideforge::decode(&snapshot).unwrap();
    assert_eq!(effects.stat("powerCenti"), 4000);
    assert_eq!(effects.extra_pull_bp.len(), 2);
    assert_eq!(test.db.cast_equipment_receipt().interaction_id().find(request).unwrap().power, 200);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap().mastery_xp, 1720);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7401, request, None, 8)).unwrap();
    assert_eq!(test.db.cast_pull().interaction_id().filter(request).count(), pulls.len());
    assert_eq!(test.db.tideforge_activity_receipt().player_id().filter(id).count(), 1);
}

#[test]
fn future_rod_composition_that_can_exceed_the_bound_is_rejected_atomically() {
    let test = setup();
    let id = player(&test, 7451, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    fixture_bait(&test, id, 2);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut rod = ctx.db.rod_definition().rod_id().find(1).unwrap();
        rod.power = 500;
        ctx.db.rod_definition().rod_id().update(rod);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "rod", 1),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"aftercast","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("echoed_line".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    assert_eq!(
        test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7451, request, None, 8)).unwrap_err(),
        "INVALID_PULL_COUNT",
    );
    assert_eq!(test.db.bait_stack().key().find((u128::from(id) << 32) | 13).unwrap().uses_left, 2);
    assert!(test.db.tideforge_activity_snapshot().key().find(format!("cast:{request}")).is_none());
    assert!(test.db.gear_activity_snapshot().key().find(format!("cast:{request}")).is_none());
    assert!(test.db.command_receipt().interaction_id().find(request).is_none());
}

fn fire_raid_job(test: &TestContext, attempt_id: u128, phase: &str) -> raids::RaidJob {
    let job = test.db.raid_job().attempt_id().filter(attempt_id)
        .find(|job| job.phase == phase).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, job.clone())).unwrap();
    job
}

#[test]
fn raid_mastery_is_booked_once_only_for_full_completion_including_special() {
    for scenario in ["completed", "cancelled", "missed", "special"] {
        let test = setup();
        let id = player(&test, 7501, "angler");
        activate_foundation(&test);
        activate_effects(&test);
        let species = if scenario == "special" {
            let species = test.db.species_definition().species_id().find(74).unwrap();
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                ctx.db.tideforge_gear().insert(TideforgeGear {
                    instance_id: original(id, "boat", 101),
                    player_id: id,
                    affixes_json: r#"[{"slot":0,"id":"coinwright","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
                    native_signature: Some("oceans_memory".into()),
                });
                ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
                    key: (u128::from(id) << 32) | u128::from(species.species_id),
                    player_id: id,
                    species_id: species.species_id,
                    count: 1,
                    rank_counts: vec![0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
                    first_caught_at: ctx.timestamp,
                    best_length_mm: species.typical_length_mm,
                    best_weight_g: species.typical_weight_g,
                });
                Ok::<(), String>(())
            }).unwrap();
            species.species_id
        } else {
            discover(&test, id)
        };
        let request = browser_request(&test, 1);
        test.with_reducer_tx(auth("angler"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
        let attempt_id = (u128::from(id) << 64) | u128::from(request);
        let booked = test.db.tideforge_activity_snapshot().key().find(format!("raid:{attempt_id}")).unwrap();
        assert_eq!((booked.gear_kind.as_str(), booked.family_id), ("boat", 1));
        if scenario == "completed" {
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                let mut player = ctx.db.player().player_id().find(id).unwrap();
                player.total_xp = 1_000_000;
                ctx.db.player().player_id().update(player);
                ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
                    key: (u128::from(id) << 32) | 102,
                    player_id: id,
                    equipment_id: 102,
                    upgrade_level: 0,
                });
                gear_instances::grant_original(ctx, id, "boat", 102, 0, 0)
            }).unwrap();
            assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 2)).unwrap().curve_version, 1);
            test.with_reducer_tx(auth("angler"), |ctx| {
                gear_instances::equip_gear_instance(ctx, original(id, "boat", 102))
            }).unwrap();
        }
        if scenario != "missed" {
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                inventory::grant_item(ctx, id, 900, "raid_supplies", 100, "fixture".into())
            }).unwrap();
            let deploy = browser_request(&test, 2);
            test.with_reducer_tx(auth("angler"), |ctx| raids::deploy_raid(ctx, deploy, attempt_id)).unwrap();
        }
        let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
        if scenario == "cancelled" {
            let cancel = browser_request(&test, 3);
            test.with_reducer_tx(auth("angler"), |ctx| raids::cancel_raid(ctx, cancel, attempt_id)).unwrap();
        } else {
            test.clock.set(attempt.starts_at);
            fire_raid_job(&test, attempt_id, "start");
            if scenario != "missed" {
                test.clock.set(attempt.ends_at);
                let finish = fire_raid_job(&test, attempt_id, "finish");
                test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, finish)).unwrap();
            }
        }
        let finished = test.db.raid_attempt().id().find(attempt_id).unwrap();
        let completed = matches!(scenario, "completed" | "special");
        assert_eq!(finished.status, if completed { "completed" } else { scenario });
        assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp,
                   if completed { 100 } else { 0 });
        assert_eq!(test.db.tideforge_activity_receipt().player_id().filter(id).count(),
                   if completed { 1 } else { 0 });
        if scenario == "completed" {
            assert_eq!(test.db.gear_loadout().player_id().find(id).unwrap().boat_instance_id, original(id, "boat", 102));
            assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 2)).unwrap().mastery_xp, 0);
        }
        if scenario == "special" {
            let catch = test.db.owned_specimen().catch_id().find(finished.catch_id.unwrap()).unwrap();
            assert_eq!(catch.species_id, 74);
            assert!(test.db.tideforge_specimen_modifiers().catch_id().find(catch.catch_id).is_none());
            assert_eq!(test.db.tideforge_activity_snapshot().player_id().filter(id).count(), 1);
            assert_eq!(test.db.tideforge_daily_use().player_id().filter(id).count(), 0);
        }
    }
}

#[test]
fn junk_percentage_and_folly_packet_are_separate_and_once_per_haul() {
    let test = setup();
    let id = player(&test, 7601, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut biome = ctx.db.biome_definition().biome_id().find(1).unwrap();
        biome.fish_weight = 0;
        biome.junk_weight = 1;
        biome.treasure_weight = 0;
        ctx.db.biome_definition().biome_id().update(biome);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "boat", 101),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"scavenger","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("fishermans_folly".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 7601, request, None, 8, "boat".into())).unwrap();
    let due = test.db.haul_trip().interaction_id().find(request).unwrap().due_at;
    test.clock.set(due);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, request)).unwrap();
    let pulls: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
    assert_eq!(pulls.len(), 10);
    assert!(pulls.iter().all(|row| row.receipt.outcome == "junk"));
    assert_eq!(pulls.iter().map(|row| row.receipt.item_quantity).sum::<u64>(), 12);
    let quantity = |slot: u32| test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(slot)).unwrap().quantity;
    assert_eq!((quantity(2), quantity(800), quantity(801)), (12, 6, 4));
    assert_eq!(test.db.tideforge_fractional_credit().key().find(format!("{id}:rusted_tin")).unwrap().remainder_bp, 5000);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp, 100);
}

#[test]
fn sermon_and_crown_junk_extras_are_absolute_and_not_amplified() {
    let test = setup();
    let id = player(&test, 7651, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    add_library(&test, id, "rusted_crown");
    test.with_reducer_tx(auth("angler"), |ctx| {
        tideforge::select_tideforge_signatures(ctx, None, None, Some("rusted_crown".into()))
    }).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut biome = ctx.db.biome_definition().biome_id().find(1).unwrap();
        biome.fish_weight = 0;
        biome.junk_weight = 1;
        biome.treasure_weight = 0;
        ctx.db.biome_definition().biome_id().update(biome);
        let mut rod = ctx.db.rod_definition().rod_id().find(1).unwrap();
        rod.power = 200;
        ctx.db.rod_definition().rod_id().update(rod);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "rod", 1),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"scavenger","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("salvage_sermon".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7651, request, None, 8)).unwrap();
    let pulls: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
    assert_eq!(pulls.len(), 2);
    assert_eq!(pulls.iter().map(|row| row.receipt.item_quantity).sum::<u64>(), 2);
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | 2).unwrap().quantity, 3);
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | 1).unwrap().quantity, 1);
    assert_eq!(test.db.tideforge_fractional_credit().key().find(format!("{id}:rusted_tin")).unwrap().remainder_bp, 5000);
}

#[test]
fn treasure_percentage_precedes_one_optional_double_and_never_amplifies_scrap() {
    let legacy = setup();
    let active = setup();
    let old_id = player(&legacy, 7652, "angler");
    let new_id = player(&active, 7652, "angler");
    for test in [&legacy, &active] {
        activate_foundation(test);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut biome = ctx.db.biome_definition().biome_id().find(1).unwrap();
            biome.fish_weight = 0;
            biome.junk_weight = 0;
            biome.treasure_weight = 1;
            ctx.db.biome_definition().biome_id().update(biome);
            Ok::<(), String>(())
        }).unwrap();
    }
    activate_effects(&active);
    active.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(new_id, "boat", 101),
            player_id: new_id,
            affixes_json: r#"[{"slot":0,"id":"treasurekeeper","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("gilded_bilge".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&legacy, 1);
    for test in [&legacy, &active] {
        test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 7652, request, None, 8, "boat".into())).unwrap();
        let due = test.db.haul_trip().interaction_id().find(request).unwrap().due_at;
        test.clock.set(due);
        test.rng.set_seed(7652);
    }
    legacy.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, request)).unwrap();
    active.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, request)).unwrap();
    let mut pulls: Vec<_> = active.db.cast_pull().interaction_id().filter(request).collect();
    pulls.sort_by_key(|row| row.key);
    let old_first = legacy.db.cast_pull().key().find(u128::from(request) << 8).unwrap().receipt.coins_granted;
    assert_eq!(pulls.len(), 10);
    assert!(pulls.iter().all(|row| row.receipt.outcome == "treasure"));
    assert!([old_first * 2, old_first * 4].contains(&pulls[0].receipt.coins_granted));
    assert!(pulls.iter().skip(1).all(|row| (50..=120).contains(&row.receipt.coins_granted) && row.receipt.coins_granted % 2 == 0));
    assert!(pulls.iter().all(|row| (1..=3).contains(&row.receipt.item_quantity)));
    let scrap: u64 = pulls.iter().map(|row| row.receipt.item_quantity).sum();
    assert_eq!(active.db.item_stack().key().find((u128::from(new_id) << 32) | 1).unwrap().quantity, scrap);
    let coins: u64 = pulls.iter().map(|row| row.receipt.coins_granted).sum();
    assert_eq!(active.db.player().player_id().find(new_id).unwrap().coins, coins);
    assert_eq!(legacy.db.player().player_id().find(old_id).unwrap().completed_casts, 1);
}

#[test]
fn boat_extra_gates_and_rift_allow_the_booked_twelve_or_thirteen_pulls() {
    let test = setup();
    let id = player(&test, 7661, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    force_single_ordinary_rank(&test);
    add_library(&test, id, "rift_current");
    test.with_reducer_tx(auth("angler"), |ctx| {
        tideforge::select_tideforge_signatures(ctx, None, None, Some("rift_current".into()))
    }).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "boat", 101),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"deep_trawler","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("ghost_trawler".into()),
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    test.rng.set_seed(766);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 7661, request, None, 8, "boat".into())).unwrap();
    let trip = test.db.haul_trip().interaction_id().find(request).unwrap();
    test.clock.set(trip.due_at);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, request)).unwrap();
    let pulls: Vec<_> = test.db.cast_pull().interaction_id().filter(request).collect();
    assert!((12..=13).contains(&pulls.len()));
    assert!(pulls.iter().all(|row| row.receipt.outcome == "fish"));
    assert_eq!(test.db.tideforge_daily_use().player_id().filter(id).filter(|row| row.signature_id == "rift_current").count(), 1);
    assert_eq!(test.db.gear_family_progress().key().find(family(id, "boat", 1)).unwrap().mastery_xp, 100);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::finish_haul_from_adapter(ctx, request)).unwrap();
    assert_eq!(test.db.cast_pull().interaction_id().filter(request).count(), pulls.len());
    assert_eq!(test.db.tideforge_activity_receipt().player_id().filter(id).count(), 1);
}

#[test]
fn cache_sale_and_processing_modifiers_are_pinned_and_only_sold_fish_is_cleaned() {
    let test = setup();
    let id = player(&test, 7701, "angler");
    let other = player(&test, 7702, "other");
    activate_foundation(&test);
    activate_effects(&test);
    force_single_ordinary_rank(&test);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut rod = ctx.db.rod_definition().rod_id().find(1).unwrap();
        rod.power = 200;
        ctx.db.rod_definition().rod_id().update(rod);
        let mut progress = ctx.db.gear_family_progress().key().find(family(id, "rod", 1)).unwrap();
        progress.mastery_xp = 7920;
        ctx.db.gear_family_progress().key().update(progress);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(id, "rod", 1),
            player_id: id,
            affixes_json: r#"[{"slot":0,"id":"coinwright","tier":10,"finishBp":10000,"secondaryBiomeId":null},{"slot":1,"id":"provisioner","tier":10,"finishBp":10000,"secondaryBiomeId":null},{"slot":2,"id":"scaleweaver","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: Some("forgotten_cache".into()),
        });
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original(other, "rod", 1),
            player_id: other,
            affixes_json: r#"[{"slot":0,"id":"coinwright","tier":10,"finishBp":10000,"secondaryBiomeId":null}]"#.into(),
            native_signature: None,
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7701, request, None, 8)).unwrap();
    let mut catches: Vec<_> = test.db.owned_specimen().player_id().filter(id).collect();
    catches.sort_by_key(|fish| fish.catch_id);
    assert_eq!(catches.len(), 2);
    let first = &catches[0];
    let definition = test.db.species_definition().species_id().find(first.species_id).unwrap();
    let ordinal = game_rules::RARITY_TIERS.iter().position(|rank| *rank == first.rarity.as_str()).unwrap();
    let rank = test.db.species_rank_definition().key().find(u64::from(first.species_id) * 10 + ordinal as u64).unwrap();
    let ratio = (u64::from(first.length_mm) * 1_000_000 / u64::from(definition.typical_length_mm)).clamp(600_000, 2_000_000);
    let legacy_value = rank.base_value * ratio / 1_000_000;
    assert_eq!(first.sale_value_coins, game_rules::tideforge::scale_credit(legacy_value, 12_000, 0).unwrap().0);
    let pull = test.db.cast_pull().interaction_id().filter(request)
        .find(|row| row.receipt.catch_id == Some(first.catch_id)).unwrap();
    assert_eq!(pull.receipt.sale_value_coins, first.sale_value_coins);
    let modifiers = test.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).unwrap();
    assert_eq!((modifiers.sale_bp, modifiers.fillet_bp, modifiers.scale_bp, modifiers.effects_version), (2000, 1200, 1200, 1));
    let quantity = |slot: u32| test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(slot)).unwrap().quantity;
    assert_eq!((quantity(800), quantity(801)), (6, 4));
    let other_request = interaction(&test, 2);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 7702, other_request, None, 8)).unwrap();
    let other_fish = test.db.owned_specimen().player_id().filter(other).next().unwrap();
    test.with_reducer_tx(auth("angler"), |ctx| {
        inventory::prepare_inventory_action(ctx, "sell".into(), vec![first.catch_id], false)
    }).unwrap();
    let nonce = test.db.action_nonce().identity().find(Identity::from_claims("test", "angler")).unwrap().nonce;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut gear = ctx.db.tideforge_gear().instance_id().find(original(id, "rod", 1)).unwrap();
        gear.affixes_json = "[]".into();
        gear.native_signature = None;
        ctx.db.tideforge_gear().instance_id().update(gear);
        gear_instances::delete_snapshot(ctx, "cast", u128::from(request));
        Ok::<(), String>(())
    }).unwrap();
    assert_eq!(test.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).unwrap().sale_bp, 2000);
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).unwrap();
        row.player_id = other;
        ctx.db.tideforge_specimen_modifiers().catch_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    assert_eq!(
        test.with_reducer_tx(auth("angler"), |ctx| inventory::commit_inventory_action(ctx, nonce)).unwrap_err(),
        "TIDEFORGE_SPECIMEN_INVALID",
    );
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    assert!(test.db.owned_specimen().catch_id().find(first.catch_id).is_some());
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).unwrap();
        row.player_id = id;
        row.effects_version = 2;
        ctx.db.tideforge_specimen_modifiers().catch_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    assert_eq!(
        test.with_reducer_tx(auth("angler"), |ctx| inventory::commit_inventory_action(ctx, nonce)).unwrap_err(),
        "TIDEFORGE_SPECIMEN_INVALID",
    );
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).unwrap();
        row.effects_version = 1;
        ctx.db.tideforge_specimen_modifiers().catch_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    test.with_reducer_tx(auth("angler"), |ctx| inventory::commit_inventory_action(ctx, nonce)).unwrap();
    assert!(test.db.owned_specimen().catch_id().find(first.catch_id).is_none());
    assert!(test.db.tideforge_specimen_modifiers().catch_id().find(first.catch_id).is_none());
    assert!(test.db.tideforge_specimen_modifiers().catch_id().find(catches[1].catch_id).is_some());
    assert!(test.db.tideforge_specimen_modifiers().catch_id().find(other_fish.catch_id).is_some());
}

#[test]
fn daily_pruning_keeps_eight_utc_days_and_deletes_at_most_one_thousand_rows() {
    let test = setup();
    let id = player(&test, 7801, "angler");
    activate_foundation(&test);
    activate_effects(&test);
    let active = book(&test, id, "rod", "cast", 88001, 1);
    let today = test.clock.now().to_micros_since_unix_epoch().div_euclid(86_400_000_000);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for offset in 0..1001i64 {
            let day = today - 8 - offset;
            ctx.db.tideforge_daily_use().insert(TideforgeDailyUse {
                key: format!("{id}:1:{day}:tidal_memory"),
                player_id: id,
                biome_id: 1,
                utc_day: day,
                signature_id: "tidal_memory".into(),
                activity_key: active.key.clone(),
            });
        }
        let day = today - 7;
        ctx.db.tideforge_daily_use().insert(TideforgeDailyUse {
            key: format!("{id}:1:{day}:tidal_memory"),
            player_id: id,
            biome_id: 1,
            utc_day: day,
            signature_id: "tidal_memory".into(),
            activity_key: active.key.clone(),
        });
        Ok::<(), String>(())
    }).unwrap();
    let job = MaintenanceJob { scheduled_id: 1, scheduled_at: test.clock.now().into() };
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::maintenance::prune_history(ctx, job)).unwrap();
    assert_eq!(test.db.tideforge_daily_use().count(), 2);
    assert!(test.db.tideforge_daily_use().key().find(format!("{id}:1:{}:tidal_memory", today - 7)).is_some());
    assert!(test.db.tideforge_activity_snapshot().key().find(active.key).is_some());
}

#[test]
fn starter_families_created_after_activation_use_curve_one_without_xp() {
    let test = setup();
    activate_foundation(&test);
    activate_effects(&test);
    let id = player(&test, 7901, "newcomer");
    let rows: Vec<_> = test.db.gear_family_progress().player_id().filter(id).collect();
    assert_eq!(rows.len(), 3);
    assert!(rows.iter().all(|row| row.curve_version == 1 && row.mastery_xp == 0));
    assert_eq!(test.db.tideforge_activity_snapshot().player_id().filter(id).count(), 0);
    assert_eq!(test.db.tideforge_activity_receipt().player_id().filter(id).count(), 0);
}

#[test]
fn activation_validates_default_gear_and_orphan_families_without_partial_promotion() {
    for scenario in ["quality", "primary", "owner", "family"] {
        let test = setup();
        let id = player(&test, 7951, "angler");
        activate_foundation(&test);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            if matches!(scenario, "quality" | "primary") {
                let mut gear = ctx.db.gear_instance().instance_id().find(original(id, "rod", 1)).unwrap();
                if scenario == "quality" { gear.quality_level = 255; }
                else { gear.primary_biome_id = 0; }
                ctx.db.gear_instance().instance_id().update(gear);
            } else {
                let owner = if scenario == "owner" { id + 999 } else { id };
                let family_id = if scenario == "family" { 999 } else { 1 };
                ctx.db.gear_family_progress().insert(gear_instances::GearFamilyProgress {
                    key: family(owner, "rod", family_id),
                    player_id: owner,
                    kind: "rod".into(),
                    family_id,
                    mastery_xp: 17,
                    curve_version: 0,
                });
            }
            Ok::<(), String>(())
        }).unwrap();
        assert_eq!(test.db.tideforge_gear().count(), 0);
        let progress = || {
            let mut rows: Vec<_> = test.db.gear_family_progress().iter().map(|row| (
                row.key, row.player_id, row.kind, row.family_id, row.mastery_xp, row.curve_version,
            )).collect();
            rows.sort_unstable();
            rows
        };
        let before = progress();
        assert!(test.with_reducer_tx(
            TestAuth::internal(), tideforge::activate_tideforge_effects,
        ).is_err(), "{scenario}");
        assert_eq!(test.db.tideforge_state().count(), 0, "{scenario}");
        assert_eq!(progress(), before, "{scenario}");
        assert!(test.db.gear_family_progress().iter().all(|row| row.curve_version == 0));
    }
}
