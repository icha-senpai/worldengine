use crate::{
    accounts, achievements, gear_instances::{self, gear_family_progress, gear_instance, gear_instance_name},
    raids::{self, community_raid_event, raid_attempt, raid_badge, rod_reinforcement, RaidBadge, RodReinforcement},
    tables::*,
    tideforge::{self, tideforge_fractional_credit, tideforge_gear, tideforge_library, tideforge_specimen_modifiers,
        TideforgeFractionalCredit, TideforgeGear, TideforgeSpecimenModifiers},
    tideforge_economy::{self as economy, *},
};
use game_rules::tideforge::{self as effects, AffixRoll};
use serde_json::json;
use spacetimedb::{
    ConnectionId, Identity, Table, TimeDuration, Timestamp,
    test_utils::{TestAuth, TestContext},
};

fn auth(subject: &str) -> TestAuth {
    TestAuth::from_jwt_payload(
        format!(r#"{{"iss":"test","sub":"{subject}"}}"#),
        ConnectionId::from_u128(1),
    ).unwrap()
}

fn setup() -> TestContext {
    let test = TestContext::new().unwrap();
    test.clock.set(Timestamp::from_micros_since_unix_epoch(1_793_491_200_000_000));
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        crate::init(ctx);
        accounts::configure_service(ctx, Identity::from_claims("test", "bot"), ServiceRole::DiscordAdapter, true)
    }).unwrap();
    test
}

fn player(test: &TestContext, discord: u64, subject: &str) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let row = accounts::ensure_player(ctx, discord, subject)?;
        ctx.db.player_identity().insert(PlayerIdentity {
            identity: Identity::from_claims("test", subject), player_id: row.player_id, linked_at: ctx.timestamp,
        });
        Ok::<_, String>(row.player_id)
    }).unwrap()
}

fn activate(test: &TestContext) {
    test.with_reducer_tx(TestAuth::internal(), gear_instances::activate_gear_foundation).unwrap();
    test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects).unwrap();
    test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).unwrap();
}

fn fixtures(test: &TestContext, player_id: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut player = ctx.db.player().player_id().find(player_id).unwrap();
        player.coins = 1_000_000;
        ctx.db.player().player_id().update(player);
        for (kind, definition_id) in [("rod", 2u32), ("boat", 102), ("net", 202)] {
            gear_instances::grant_original(ctx, player_id, kind, definition_id, 2, if kind == "rod" { 3 } else { 0 })?;
        }
        ctx.db.tideforge_economy_wallet().insert(TideforgeEconomyWallet {
            player_id, marks: 1000, next_sequence: 1, revision: 1,
        });
        Ok::<_, String>(())
    }).unwrap();
}

fn prepare(test: &TestContext, subject: &str, player_id: u64, value: serde_json::Value) -> Result<u128, String> {
    test.with_reducer_tx(auth(subject), |ctx| economy::prepare_tideforge_action(ctx, value.to_string()))?;
    let identity = Identity::from_claims("test", subject);
    Ok(test.db.tideforge_economy_quote().key().find(format!("{identity}:{player_id}")).unwrap().nonce)
}

fn commit(test: &TestContext, subject: &str, nonce: u128) -> Result<(), String> {
    test.with_reducer_tx(auth(subject), |ctx| economy::commit_tideforge_action(ctx, nonce))
}

fn reject_without_random(
    test: &TestContext,
    control: &TestContext,
    seed: u64,
    action: impl FnOnce(&spacetimedb::ReducerContext) -> Result<(), String>,
) -> String {
    use spacetimedb::rand::Rng;
    test.rng.set_seed(seed);
    control.rng.set_seed(seed);
    let mut observed = None;
    let error = test.with_reducer_tx(auth("first"), |ctx| {
        let error = action(ctx).expect_err("action unexpectedly succeeded");
        observed = Some(ctx.rng().r#gen::<u64>());
        Err::<(), String>(error)
    }).unwrap_err();
    let expected = control.with_reducer_tx(auth("first"),
        |ctx| Ok::<_, String>(ctx.rng().r#gen::<u64>())).unwrap();
    assert_eq!(observed, Some(expected));
    error
}

fn tick(test: &TestContext) {
    test.clock.advance(TimeDuration::from_micros(1_000_000));
}

fn add_copy(test: &TestContext, player_id: u64, ordinal: u64, affixes: Vec<AffixRoll>, signature: Option<&str>) -> u128 {
    let source = gear_instances::original_id(player_id, "rod", 2).unwrap();
    let id = (u128::from(player_id) << 64) | (1u128 << 63) | u128::from(ordinal);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut instance = ctx.db.gear_instance().instance_id().find(source).unwrap();
        instance.instance_id = id;
        instance.protected_original = false;
        instance.favorite = false;
        instance.quality_level = 0;
        instance.reinforcement_level = 0;
        instance.revision = 1;
        instance.source = "test_copy".into();
        ctx.db.gear_instance().insert(instance);
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: id, player_id, affixes_json: serde_json::to_string(&affixes).unwrap(),
            native_signature: signature.map(str::to_owned),
        });
        let mut wallet = ctx.db.tideforge_economy_wallet().player_id().find(player_id).unwrap();
        wallet.next_sequence = wallet.next_sequence.max(ordinal + 1);
        ctx.db.tideforge_economy_wallet().player_id().update(wallet);
        Ok::<_, String>(())
    }).unwrap();
    id
}

fn discover_and_shard(test: &TestContext, player_id: u64, affix_id: &str, amount: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = format!("{player_id}:{affix_id}");
        ctx.db.tideforge_codex().insert(TideforgeCodex {
            key: key.clone(), player_id, affix_id: affix_id.into(), highest_tier: 1, best_finish_bp: 500,
            natural_count: 1,
        });
        ctx.db.tideforge_affix_shard().insert(TideforgeAffixShard {
            key, player_id, affix_id: affix_id.into(), count: amount,
        });
        Ok::<_, String>(())
    }).unwrap();
}

fn grade_clear(test: &TestContext, player_id: u64, grades: u8) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.raid_badge().insert(RaidBadge {
            key: (u128::from(player_id) << 32) | 1, player_id, species_id: 1,
            cleared_grades: grades, successful_clears: u64::from(grades), special_completions: 0,
            first_clear_at: Some(ctx.timestamp), latest_clear_at: Some(ctx.timestamp),
        });
        Ok::<_, String>(())
    }).unwrap();
}

fn grade_materials(test: &TestContext, player_id: u64, grades: std::ops::Range<u8>, quantity: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for grade in grades {
            for material in 0..=1 {
                let (slot, item) = game_rules::raids::material(grade, material).map_err(str::to_owned)?;
                crate::inventory::grant_item_with_reason(
                    ctx, player_id, slot, &item, quantity, "fixture".into(), "fixture",
                )?;
            }
        }
        Ok::<_, String>(())
    }).unwrap();
}

fn set_mastery(test: &TestContext, player_id: u64, level: u8) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = gear_instances::family_key(player_id, "rod", 2)?;
        let mut row = ctx.db.gear_family_progress().key().find(key).unwrap();
        row.mastery_xp = effects::mastery_threshold(level).map_err(str::to_owned)?;
        ctx.db.gear_family_progress().key().update(row);
        Ok::<_, String>(())
    }).unwrap();
}

#[test]
fn activation_requires_owner_and_prior_stages_and_preserves_existing_rows() {
    let test = setup();
    let id = player(&test, 101, "first");
    assert!(prepare(&test, "first", id, json!({"action":"camp_copy","kind":"rod","definition_id":1,"secondary_biome_id":2})).is_err());
    assert!(test.with_reducer_tx(auth("first"), economy::activate_tideforge_economy).is_err());
    assert!(test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).is_err());
    test.with_reducer_tx(TestAuth::internal(), gear_instances::activate_gear_foundation).unwrap();
    assert!(test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).is_err());
    test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects).unwrap();
    let before = test.db.player().player_id().find(id).unwrap();
    let gear_count = test.db.gear_instance().count();
    let family_count = test.db.gear_family_progress().count();
    test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).unwrap();
    let state = test.db.tideforge_economy_state().singleton().find(1).unwrap();
    tick(&test);
    test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).unwrap();
    let after = test.db.player().player_id().find(id).unwrap();
    assert_eq!((before.coins, before.total_xp, before.kept_count), (after.coins, after.total_xp, after.kept_count));
    assert_eq!(test.db.gear_instance().count(), gear_count);
    assert_eq!(test.db.gear_family_progress().count(), family_count);
    assert_eq!(test.db.tideforge_economy_wallet().count(), 0);
    assert_eq!(test.db.tideforge_economy_state().singleton().find(1).unwrap().activated_at, state.activated_at);
}

#[test]
fn activation_does_not_backfill_old_synthetic_affixes_or_marks() {
    let test = setup();
    let id = player(&test, 132, "first");
    test.with_reducer_tx(TestAuth::internal(), gear_instances::activate_gear_foundation).unwrap();
    test.with_reducer_tx(TestAuth::internal(), tideforge::activate_tideforge_effects).unwrap();
    let original = gear_instances::original_id(id, "rod", 1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_gear().insert(TideforgeGear {
            instance_id: original, player_id: id,
            affixes_json: serde_json::to_string(&vec![AffixRoll {
                slot: 0, id: "fortune".into(), tier: 9, finish_bp: 8000, secondary_biome_id: None,
            }]).unwrap(),
            native_signature: None,
        });
        Ok::<_, String>(())
    }).unwrap();
    test.with_reducer_tx(TestAuth::internal(), economy::activate_tideforge_economy).unwrap();
    assert_eq!(test.db.tideforge_codex().count(), 0);
    assert_eq!(test.db.tideforge_mark_award().count(), 0);
    assert!(test.db.tideforge_economy_wallet().player_id().find(id).is_none());
    assert_eq!(test.db.tideforge_gear().instance_id().find(original).unwrap().player_id, id);
}

#[test]
fn purchase_quotes_do_not_roll_and_replay_is_inert_for_all_three_kinds() {
    let test = setup();
    let id = player(&test, 102, "first");
    activate(&test);
    fixtures(&test, id);
    for (kind, definition_id) in [("rod", 2u32), ("boat", 102), ("net", 202)] {
        let before = test.db.gear_instance().count();
        let codex_before = test.db.tideforge_codex().count();
        let previous_coins = test.db.player().player_id().find(id).unwrap().coins;
        let nonce = prepare(&test, "first", id, json!({
            "action": "camp_copy", "kind": kind, "definition_id": definition_id, "secondary_biome_id": 1
        })).unwrap();
        assert_eq!(test.db.gear_instance().count(), before);
        assert_eq!(test.db.tideforge_codex().count(), codex_before);
        commit(&test, "first", nonce).unwrap();
        assert_eq!(test.db.gear_instance().count(), before + 1);
        assert_eq!(test.db.player().player_id().find(id).unwrap().coins, previous_coins - 500);
        let instance = test.db.gear_instance().player_id().filter(id)
            .find(|row| !row.protected_original && row.kind == kind).unwrap();
        assert_eq!((instance.quality_level, instance.reinforcement_level, instance.primary_biome_id), (0, 0, 2));
        let gear = test.db.tideforge_gear().instance_id().find(instance.instance_id).unwrap();
        let rolls: Vec<AffixRoll> = serde_json::from_str(&gear.affixes_json).unwrap();
        assert_eq!(rolls.len(), 1);
        assert!((1..=3).contains(&rolls[0].tier));
        let coins = test.db.player().player_id().find(id).unwrap().coins;
        let gear_json = gear.affixes_json;
        commit(&test, "first", nonce).unwrap();
        assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
        assert_eq!(test.db.tideforge_gear().instance_id().find(instance.instance_id).unwrap().affixes_json, gear_json);
        tick(&test);
    }
}

#[test]
fn wandering_rolls_are_legal_and_old_receipts_survive_later_quotes() {
    let test = setup();
    let id = player(&test, 103, "first");
    activate(&test);
    fixtures(&test, id);
    let before_marks = test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks;
    let nonce = prepare(&test, "first", id, json!({
        "action": "wandering_gamble", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    let copy = test.db.gear_instance().player_id().filter(id)
        .find(|row| !row.protected_original && row.kind == "rod").unwrap();
    let row = test.db.tideforge_gear().instance_id().find(copy.instance_id).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&row.affixes_json).unwrap();
    assert_eq!(rolls.len(), 4);
    game_rules::tideforge_economy::validate_affix_set("rod", &rolls).unwrap();
    assert!(rolls.iter().all(|roll| (8..=10).contains(&roll.tier)));
    assert!(rolls.iter().filter(|roll| effects::affix_condition(&roll.id).unwrap().is_some()).count() <= 2);
    for roll in &rolls {
        if let Some(water) = roll.secondary_biome_id {
            assert_ne!(water, copy.primary_biome_id);
            assert!(test.db.biome_definition().biome_id().find(water).is_some());
        }
        let codex = test.db.tideforge_codex().key().find(format!("{id}:{}", roll.id)).unwrap();
        assert_eq!((codex.highest_tier, codex.best_finish_bp, codex.natural_count), (roll.tier, roll.finish_bp, 1));
    }
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, before_marks - 1);
    tick(&test);
    let next = prepare(&test, "first", id, json!({
        "action": "camp_copy", "kind": "boat", "definition_id": 102, "secondary_biome_id": 1
    })).unwrap();
    assert_ne!(nonce, next);
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.gear_instance().instance_id().find(copy.instance_id).unwrap().revision, 1);
    assert_eq!(test.db.tideforge_gear().instance_id().find(copy.instance_id).unwrap().affixes_json, row.affixes_json);
    commit(&test, "first", next).unwrap();
}

#[test]
fn natural_purchase_requires_defined_nonprimary_selection_without_granting_access() {
    let rejected = setup();
    let control = setup();
    let id = player(&rejected, 140, "first");
    let other = player(&control, 140, "first");
    for (test, player_id) in [(&rejected, id), (&control, other)] {
        activate(test);
        fixtures(test, player_id);
    }
    let coins = rejected.db.player().player_id().find(id).unwrap().coins;
    let gear_count = rejected.db.gear_instance().count();
    for action in ["camp_copy", "wandering_gamble"] {
        for (bad, expected) in [
            (json!({"action":action,"kind":"rod","definition_id":2}), "TIDEFORGE_INTENT_INVALID"),
            (json!({"action":action,"kind":"rod","definition_id":2,"secondary_biome_id":2}), "TIDEFORGE_SECONDARY_BIOME_INVALID"),
            (json!({"action":action,"kind":"rod","definition_id":2,"secondary_biome_id":999}), "TIDEFORGE_SECONDARY_BIOME_INVALID"),
        ] {
            let error = reject_without_random(&rejected, &control, 7212, |ctx|
                economy::prepare_tideforge_action(ctx, bad.to_string()));
            assert_eq!(error, expected);
        }
    }
    assert_eq!(rejected.db.player().player_id().find(id).unwrap().coins, coins);
    assert_eq!(rejected.db.gear_instance().count(), gear_count);
    assert_eq!(rejected.db.tideforge_codex().count(), 0);
    assert!(!rejected.with_reducer_tx(TestAuth::internal(), |ctx|
        Ok::<_, String>(crate::shop::owns_licence(ctx, id, 7))).unwrap());
    let nonce = prepare(&rejected, "first", id, json!({
        "action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":7
    })).unwrap();
    let identity = Identity::from_claims("test", "first");
    let quote = rejected.db.tideforge_economy_quote().key().find(format!("{identity}:{id}")).unwrap();
    let preview: serde_json::Value = serde_json::from_str(&quote.preview_json).unwrap();
    assert_eq!(preview["secondary_biome_id"], 7);
    rejected.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut changed = ctx.db.tideforge_economy_quote().key().find(format!("{identity}:{id}")).unwrap();
        changed.intent_json = json!({
            "action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1
        }).to_string();
        ctx.db.tideforge_economy_quote().key().update(changed);
        Ok::<_, String>(())
    }).unwrap();
    assert_eq!(commit(&rejected, "first", nonce).unwrap_err(), "QUOTE_CHANGED");
    assert_eq!(rejected.db.player().player_id().find(id).unwrap().coins, coins);
    assert_eq!(rejected.db.gear_instance().count(), gear_count);
    assert_eq!(rejected.db.tideforge_codex().count(), 0);
    rejected.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut restored = ctx.db.tideforge_economy_quote().key().find(format!("{identity}:{id}")).unwrap();
        restored.intent_json = quote.intent_json.clone();
        ctx.db.tideforge_economy_quote().key().update(restored);
        Ok::<_, String>(())
    }).unwrap();
    commit(&rejected, "first", nonce).unwrap();
    assert!(!rejected.with_reducer_tx(TestAuth::internal(), |ctx|
        Ok::<_, String>(crate::shop::owns_licence(ctx, id, 7))).unwrap());
}

#[test]
fn copy_ids_do_not_reuse_after_dismantle_and_family_mastery_is_shared() {
    let test = setup();
    let id = player(&test, 104, "first");
    activate(&test);
    fixtures(&test, id);
    let family = gear_instances::family_key(id, "rod", 2).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_family_progress().key().find(family).unwrap();
        row.mastery_xp = 1234;
        ctx.db.gear_family_progress().key().update(row);
        Ok::<_, String>(())
    }).unwrap();
    let first = prepare(&test, "first", id, json!({"action": "camp_copy", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1})).unwrap();
    commit(&test, "first", first).unwrap();
    let old = test.db.gear_instance().player_id().filter(id).find(|row| !row.protected_original).unwrap();
    tick(&test);
    let dismantle = prepare(&test, "first", id, json!({"action": "dismantle", "instance_id": old.instance_id.to_string()})).unwrap();
    commit(&test, "first", dismantle).unwrap();
    assert!(test.db.gear_instance().instance_id().find(old.instance_id).is_none());
    tick(&test);
    let next = prepare(&test, "first", id, json!({"action": "camp_copy", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1})).unwrap();
    commit(&test, "first", next).unwrap();
    let new = test.db.gear_instance().player_id().filter(id).find(|row| !row.protected_original).unwrap();
    assert!(new.instance_id > old.instance_id);
    assert_eq!(test.db.gear_family_progress().key().find(family).unwrap().mastery_xp, 1234);
}

#[test]
fn natural_copy_dismantle_add_upgrade_and_refine_preserve_progression() {
    let test = setup();
    let id = player(&test, 142, "first");
    activate(&test);
    fixtures(&test, id);
    grade_clear(&test, id, 10);
    grade_materials(&test, id, 0..2, 500);
    let original = gear_instances::original_id(id, "rod", 2).unwrap();
    let family = gear_instances::family_key(id, "rod", 2).unwrap();
    let owned_key = (u128::from(id) << 32) | 2;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.owned_rod().insert(OwnedRod {
            key: owned_key, player_id: id, rod_id: 2, upgrade_level: 2,
        });
        ctx.db.rod_reinforcement().insert(RodReinforcement {
            key: owned_key, player_id: id, rod_id: 2, level: 3, updated_at: ctx.timestamp,
        });
        Ok::<_, String>(())
    }).unwrap();
    let xp = test.db.gear_family_progress().key().find(family).unwrap().mastery_xp;
    let starting_coins = test.db.player().player_id().find(id).unwrap().coins;
    let nonce = prepare(&test, "first", id, json!({
        "action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, starting_coins - 500);
    let copy = test.db.gear_instance().player_id().filter(id)
        .find(|row| !row.protected_original && row.kind == "rod").unwrap();
    let gear = test.db.tideforge_gear().instance_id().find(copy.instance_id).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&gear.affixes_json).unwrap();
    assert_eq!(rolls.len(), 1);
    let natural = &rolls[0];
    let codex_key = format!("{id}:{}", natural.id);
    let discovered = test.db.tideforge_codex().key().find(&codex_key).unwrap();
    assert_eq!((discovered.highest_tier, discovered.best_finish_bp, discovered.natural_count),
        (natural.tier, natural.finish_bp, 1));
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"dismantle","instance_id":copy.instance_id.to_string()
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.gear_instance().instance_id().find(copy.instance_id).is_none());
    assert_eq!(test.db.tideforge_affix_shard().key().find(&codex_key).unwrap().count, 1);
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"add_affix","instance_id":original.to_string(),"affix_id":natural.id,
        "secondary_biome_id":natural.secondary_biome_id
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.tideforge_affix_shard().key().find(&codex_key).unwrap().count, 0);
    for (grade, material, expected) in [(0, 0, 485), (0, 1, 490)] {
        let (slot, _) = game_rules::raids::material(grade, material).unwrap();
        assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(slot)).unwrap().quantity, expected);
    }
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"upgrade_affix","instance_id":original.to_string(),"slot":0,"target_tier":2
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    for (grade, material, expected) in [(1, 0, 473), (1, 1, 482)] {
        let (slot, _) = game_rules::raids::material(grade, material).unwrap();
        assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(slot)).unwrap().quantity, expected);
    }
    let (expected_finish, expected_coins) = game_rules::tideforge_economy::refine_affix(&natural.id, 2, 0).unwrap();
    let before_values = effects::affix_values(&natural.id, 2, 0).unwrap();
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":original.to_string(),"slot":0
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    commit(&test, "first", nonce).unwrap();
    let row = test.db.tideforge_gear().instance_id().find(original).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&row.affixes_json).unwrap();
    assert_eq!((rolls[0].id.as_str(), rolls[0].tier, rolls[0].finish_bp), (natural.id.as_str(), 2, expected_finish));
    assert_ne!(effects::affix_values(&natural.id, 2, expected_finish).unwrap(), before_values);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, starting_coins - 500 - expected_coins);
    let codex = test.db.tideforge_codex().key().find(&codex_key).unwrap();
    assert_eq!((codex.highest_tier, codex.best_finish_bp, codex.natural_count),
        (discovered.highest_tier, discovered.best_finish_bp, discovered.natural_count));
    assert_eq!(test.db.gear_family_progress().key().find(family).unwrap().mastery_xp, xp);
    let gear = test.db.gear_instance().instance_id().find(original).unwrap();
    assert_eq!((gear.protected_original, gear.quality_level, gear.reinforcement_level), (true, 2, 3));
    assert_eq!(test.db.owned_rod().key().find(owned_key).unwrap().upgrade_level, 2);
    assert_eq!(test.db.rod_reinforcement().key().find(owned_key).unwrap().level, 3);
    tick(&test);
    assert!(prepare(&test, "first", id, json!({"action":"dismantle","instance_id":original.to_string()})).is_err());
}

#[test]
fn original_and_price_gates_apply_and_copy_counter_starts_above_existing_ids() {
    let test = setup();
    let id = player(&test, 129, "first");
    activate(&test);
    fixtures(&test, id);
    assert!(prepare(&test, "first", id, json!({"action":"camp_copy","kind":"rod","definition_id":1,"secondary_biome_id":2})).is_err());
    assert!(prepare(&test, "first", id, json!({"action":"camp_copy","kind":"rod","definition_id":7,"secondary_biome_id":1})).is_err());
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        gear_instances::grant_original(ctx, id, "rod", 7, 4, 5)
    }).unwrap();
    let existing = add_copy(&test, id, 17, vec![], None);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_economy_wallet().player_id().delete(id);
        Ok::<_, String>(())
    }).unwrap();
    let nonce = prepare(&test, "first", id, json!({"action":"camp_copy","kind":"rod","definition_id":7,"secondary_biome_id":1})).unwrap();
    let before = test.db.player().player_id().find(id).unwrap().coins;
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, before - 130_000);
    let copy = test.db.gear_instance().player_id().filter(id)
        .find(|row| !row.protected_original && row.definition_id == 7).unwrap();
    assert!(copy.instance_id > existing);
    assert_eq!((copy.quality_level, copy.reinforcement_level), (0, 0));
}

#[test]
fn quote_rejects_spoof_expiry_stale_revision_and_corrupt_intents_atomically() {
    let test = setup();
    let id = player(&test, 105, "first");
    let other = player(&test, 106, "second");
    activate(&test);
    fixtures(&test, id);
    let intent = json!({"action": "camp_copy", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1});
    let nonce = prepare(&test, "first", id, intent.clone()).unwrap();
    assert!(commit(&test, "second", nonce).is_err());
    assert!(commit(&test, "first", nonce + 1).is_err());
    let original_id = gear_instances::original_id(id, "rod", 2).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance().instance_id().find(original_id).unwrap();
        row.revision += 1;
        ctx.db.gear_instance().instance_id().update(row);
        Ok::<_, String>(())
    }).unwrap();
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    assert!(commit(&test, "first", nonce).is_err());
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    assert_eq!(test.db.gear_instance().player_id().filter(other).filter(|row| !row.protected_original).count(), 0);
    tick(&test);
    let expired = prepare(&test, "first", id, intent).unwrap();
    test.clock.advance(TimeDuration::from_micros(120_000_000));
    assert!(commit(&test, "first", expired).is_err());
    for malformed in [
        r#"{"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1,"price":0}"#,
        r#"{"action":"dismantle","instance_id":12}"#,
        r#"{"action":"dismantle","instance_id":"00012"}"#,
        r#"{"action":"unknown"}"#,
    ] {
        assert!(test.with_reducer_tx(auth("first"), |ctx| economy::prepare_tideforge_action(ctx, malformed.into())).is_err());
    }
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
}

#[test]
fn wandering_daily_windows_preserve_state_at_boundaries() {
    const HOUR: i64 = 3_600_000_000;
    let midnight = 1_793_491_200_000_000 + 6 * game_rules::daily::DAY_MICROS;
    for hour in [0, 6, 12, 18] {
        let test = setup();
        let id = player(&test, 107, "first");
        activate(&test);
        fixtures(&test, id);
        let start = midnight + hour * HOUR;
        let close = start + 2 * HOUR;
        let state = || (
            test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks,
            test.db.player().player_id().find(id).unwrap().coins,
            test.db.gear_instance().count(),
            test.db.tideforge_economy_receipt().count(),
        );
        let intent = || json!({"action": "wandering_gamble", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1});
        let before = state();
        test.clock.set(Timestamp::from_micros_since_unix_epoch(start - 1));
        assert!(prepare(&test, "first", id, intent()).is_err());
        assert_eq!(state(), before);
        test.clock.set(Timestamp::from_micros_since_unix_epoch(start));
        let nonce = prepare(&test, "first", id, intent()).unwrap();
        commit(&test, "first", nonce).unwrap();
        let after = state();
        assert_eq!(after, (before.0 - 1, before.1, before.2 + 1, before.3 + 1));
        test.clock.set(Timestamp::from_micros_since_unix_epoch(close - 500_000));
        let nonce = prepare(&test, "first", id, intent()).unwrap();
        let identity = Identity::from_claims("test", "first");
        let quote = test.db.tideforge_economy_quote().key().find(format!("{identity}:{id}")).unwrap();
        assert_eq!(quote.expires_at.to_micros_since_unix_epoch(), close);
        test.clock.set(Timestamp::from_micros_since_unix_epoch(close));
        assert!(commit(&test, "first", nonce).is_err());
        assert_eq!(state(), after);
        assert!(prepare(&test, "first", id, intent()).is_err());
        assert_eq!(state(), after);
        test.clock.set(Timestamp::from_micros_since_unix_epoch(close + HOUR));
        assert!(prepare(&test, "first", id, intent()).is_err());
        assert_eq!(state(), after);
        assert!(prepare(&test, "first", id, json!({"action": "camp_copy", "kind": "rod", "definition_id": 2, "secondary_biome_id": 1})).is_ok());
        assert_eq!(state(), after);
    }
}

#[test]
fn secondary_affinity_input_validation_and_retarget_preserve_tier_and_finish() {
    let test = setup();
    let id = player(&test, 108, "first");
    activate(&test);
    fixtures(&test, id);
    set_mastery(&test, id, 10);
    grade_clear(&test, id, 10);
    grade_materials(&test, id, 0..1, 200);
    discover_and_shard(&test, id, "secondary_affinity", 3);
    discover_and_shard(&test, id, "resonance", 1);
    let existing = AffixRoll { slot: 0, id: "resonance".into(), tier: 2, finish_bp: 3000, secondary_biome_id: None };
    let copy = add_copy(&test, id, 1, vec![existing], None);
    for invalid in [
        json!({"action":"add_affix","instance_id":copy.to_string(),"affix_id":"secondary_affinity"}),
        json!({"action":"add_affix","instance_id":copy.to_string(),"affix_id":"secondary_affinity","secondary_biome_id":2}),
        json!({"action":"add_affix","instance_id":copy.to_string(),"affix_id":"secondary_affinity","secondary_biome_id":999}),
        json!({"action":"add_affix","instance_id":copy.to_string(),"affix_id":"resonance","secondary_biome_id":1}),
    ] {
        assert!(prepare(&test, "first", id, invalid).is_err());
    }
    let before_coins = test.db.player().player_id().find(id).unwrap().coins;
    let before_shards = test.db.tideforge_affix_shard().key().find(format!("{id}:secondary_affinity")).unwrap().count;
    assert_eq!(before_shards, 3);
    let nonce = prepare(&test, "first", id, json!({
        "action":"add_affix","instance_id":copy.to_string(),"affix_id":"secondary_affinity","secondary_biome_id":1
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, before_coins);
    assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:secondary_affinity")).unwrap().count, 2);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_gear().instance_id().find(copy).unwrap();
        let mut affixes: Vec<AffixRoll> = serde_json::from_str(&row.affixes_json).unwrap();
        let affix = affixes.iter_mut().find(|roll| roll.id == "secondary_affinity").unwrap();
        affix.tier = 5;
        affix.finish_bp = 4321;
        row.affixes_json = serde_json::to_string(&affixes).unwrap();
        ctx.db.tideforge_gear().instance_id().update(row);
        Ok::<_, String>(())
    }).unwrap();
    tick(&test);
    assert!(prepare(&test, "first", id, json!({
        "action":"change_affix","instance_id":copy.to_string(),"slot":1,
        "affix_id":"secondary_affinity","secondary_biome_id":1
    })).is_err());
    let nonce = prepare(&test, "first", id, json!({
        "action":"change_affix","instance_id":copy.to_string(),"slot":1,
        "affix_id":"secondary_affinity","secondary_biome_id":3
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    let gear = test.db.tideforge_gear().instance_id().find(copy).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&gear.affixes_json).unwrap();
    let roll = rolls.iter().find(|roll| roll.id == "secondary_affinity").unwrap();
    assert_eq!((roll.tier, roll.finish_bp, roll.secondary_biome_id), (5, 4321, Some(3)));
    assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:secondary_affinity")).unwrap().count, 1);
}

#[test]
fn upgrades_charge_every_destination_and_refinement_keeps_and_prices_finish() {
    let test = setup();
    let id = player(&test, 109, "first");
    activate(&test);
    fixtures(&test, id);
    grade_materials(&test, id, 1..3, 500);
    let copy = add_copy(&test, id, 1, vec![AffixRoll {
        slot: 0, id: "resonance".into(), tier: 1, finish_bp: 9999, secondary_biome_id: None,
    }], None);
    let upgrade = json!({"action":"upgrade_affix","instance_id":copy.to_string(),"slot":0,"target_tier":3});
    assert!(prepare(&test, "first", id, upgrade.clone()).is_err());
    grade_clear(&test, id, 10);
    let nonce = prepare(&test, "first", id, upgrade).unwrap();
    commit(&test, "first", nonce).unwrap();
    for (grade, material, expected) in [(1, 0, 473), (1, 1, 482), (2, 0, 464), (2, 1, 476)] {
        let (slot, _) = game_rules::raids::material(grade, material).unwrap();
        let key = (u128::from(id) << 32) | u128::from(slot);
        assert_eq!(test.db.item_stack().key().find(key).unwrap().quantity, expected);
    }
    let row = test.db.tideforge_gear().instance_id().find(copy).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&row.affixes_json).unwrap();
    assert_eq!((rolls[0].tier, rolls[0].finish_bp), (3, 9999));
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":copy.to_string(),"slot":0
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    let row = test.db.tideforge_gear().instance_id().find(copy).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&row.affixes_json).unwrap();
    assert_eq!((rolls[0].tier, rolls[0].finish_bp), (3, 10_000));
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
}

#[test]
fn forge_rejects_undiscovered_duplicate_locked_and_excess_conditional_affixes() {
    let test = setup();
    let id = player(&test, 110, "first");
    activate(&test);
    fixtures(&test, id);
    grade_clear(&test, id, 10);
    grade_materials(&test, id, 0..1, 200);
    discover_and_shard(&test, id, "secondary_affinity", 1);
    discover_and_shard(&test, id, "attuned_wisdom", 1);
    let copy = add_copy(&test, id, 1, vec![
        AffixRoll { slot: 0, id: "resonance".into(), tier: 1, finish_bp: 0, secondary_biome_id: None },
    ], None);
    assert!(prepare(&test, "first", id, json!({"action":"add_affix","instance_id":copy.to_string(),
        "affix_id":"secondary_affinity","secondary_biome_id":1})).is_err());
    set_mastery(&test, id, 50);
    assert!(prepare(&test, "first", id, json!({"action":"add_affix","instance_id":copy.to_string(),
        "affix_id":"undiscovered"})).is_err());
    let nonce = prepare(&test, "first", id, json!({"action":"add_affix","instance_id":copy.to_string(),
        "affix_id":"secondary_affinity","secondary_biome_id":1})).unwrap();
    commit(&test, "first", nonce).unwrap();
    tick(&test);
    assert!(prepare(&test, "first", id, json!({"action":"add_affix","instance_id":copy.to_string(),
        "affix_id":"attuned_wisdom"})).is_err());
    assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:attuned_wisdom")).unwrap().count, 1);
}

#[test]
fn recovery_protects_original_favorite_and_equipped_and_returns_all_dormant_shards() {
    let test = setup();
    let id = player(&test, 111, "first");
    activate(&test);
    fixtures(&test, id);
    let original = gear_instances::original_id(id, "rod", 2).unwrap();
    assert!(prepare(&test, "first", id, json!({"action":"dismantle","instance_id":original.to_string()})).is_err());
    let affixes = ["fortune", "scholar", "old_salt", "coinwright"].into_iter().enumerate()
        .map(|(slot, name)| AffixRoll {
            slot: slot as u8, id: name.into(), tier: 8, finish_bp: 3456, secondary_biome_id: None,
        }).collect();
    let copy = add_copy(&test, id, 1, affixes, None);
    test.with_reducer_tx(auth("first"), |ctx| gear_instances::rename_gear_instance(ctx, original, "Sibling".into())).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| gear_instances::rename_gear_instance(ctx, copy, "Target".into())).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance().instance_id().find(copy).unwrap();
        row.favorite = true;
        ctx.db.gear_instance().instance_id().update(row);
        Ok::<_, String>(())
    }).unwrap();
    assert!(prepare(&test, "first", id, json!({"action":"dismantle","instance_id":copy.to_string()})).is_err());
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance().instance_id().find(copy).unwrap();
        row.favorite = false;
        ctx.db.gear_instance().instance_id().update(row);
        gear_instances::equip_instance(ctx, id, copy)
    }).unwrap();
    assert!(prepare(&test, "first", id, json!({"action":"dismantle","instance_id":copy.to_string()})).is_err());
    test.with_reducer_tx(TestAuth::internal(), |ctx| gear_instances::equip_instance(
        ctx, id, gear_instances::original_id(id, "rod", 1)?
    )).unwrap();
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    let nonce = prepare(&test, "first", id, json!({"action":"dismantle","instance_id":copy.to_string()})).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| gear_instances::rename_gear_instance(ctx, copy, "Renamed after preview".into())).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.gear_instance().instance_id().find(copy).is_none());
    assert!(test.db.gear_instance_name().instance_id().find(copy).is_none());
    assert_eq!(test.db.gear_instance_name().instance_id().find(original).unwrap().name, "Sibling");
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    for affix in ["fortune", "scholar", "old_salt", "coinwright"] {
        assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:{affix}")).unwrap().count, 3);
    }
    assert_eq!(test.db.tideforge_codex().count(), 0);
}

#[test]
fn dismantle_mixed_tiers_preserves_progress_and_rejects_old_preview() {
    let test = setup();
    let id = player(&test, 151, "first");
    activate(&test);
    fixtures(&test, id);
    grade_materials(&test, id, 0..1, 31);
    let fish_id = fish(&test, id, ordinary_species(&test), false, false, None);
    let names = ["fortune", "scholar", "old_salt", "coinwright"];
    for name in names {
        discover_and_shard(&test, id, name, 10);
    }
    set_mastery(&test, id, 50);
    let family = gear_instances::family_key(id, "rod", 2).unwrap();
    let mastery = test.db.gear_family_progress().key().find(family).unwrap().mastery_xp;
    let original = gear_instances::original_id(id, "rod", 2).unwrap();
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    let material_key = (u128::from(id) << 32) | u128::from(game_rules::raids::material(0, 0).unwrap().0);
    let material = test.db.item_stack().key().find(material_key).unwrap().quantity;
    let quote_key = format!("{}:{id}", Identity::from_claims("test", "first"));
    let mut totals = [10u64; 4];
    for (ordinal, tiers, expected) in [
        (1, [1, 3, 4, 7], [1, 1, 2, 2]),
        (2, [7, 8, 9, 10], [2, 3, 3, 3]),
    ] {
        let rolls = names.iter().zip(tiers).enumerate().map(|(slot, (name, tier))| AffixRoll {
            slot: slot as u8, id: (*name).into(), tier, finish_bp: 2000, secondary_biome_id: None,
        }).collect();
        let copy = add_copy(&test, id, ordinal, rolls, None);
        let nonce = prepare(&test, "first", id, json!({
            "action":"dismantle", "instance_id":copy.to_string()
        })).unwrap();
        let quote = test.db.tideforge_economy_quote().key().find(&quote_key).unwrap();
        let preview: serde_json::Value = serde_json::from_str(&quote.preview_json).unwrap();
        let shards = preview["shards"].as_array().unwrap();
        assert_eq!(shards.len(), 4);
        for index in 0..4 {
            assert_eq!(shards[index]["affix_id"], names[index]);
            assert_eq!(shards[index]["before"], totals[index]);
            assert_eq!(shards[index]["produced"], expected[index]);
            totals[index] += expected[index];
            assert_eq!(shards[index]["after"], totals[index]);
        }
        commit(&test, "first", nonce).unwrap();
        commit(&test, "first", nonce).unwrap();
        assert!(test.db.gear_instance().instance_id().find(copy).is_none());
        for index in 0..4 {
            assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:{}", names[index])).unwrap().count, totals[index]);
        }
        tick(&test);
    }
    let copy = add_copy(&test, id, 3, vec![AffixRoll {
        slot: 0, id: "fortune".into(), tier: 8, finish_bp: 2000, secondary_biome_id: None,
    }], None);
    let nonce = prepare(&test, "first", id, json!({
        "action":"dismantle", "instance_id":copy.to_string()
    })).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut quote = ctx.db.tideforge_economy_quote().key().find(&quote_key).unwrap();
        let mut preview: serde_json::Value = serde_json::from_str(&quote.preview_json).unwrap();
        preview["shards"][0]["produced"] = json!(1);
        preview["shards"][0]["after"] = json!(totals[0] + 1);
        quote.preview_json = preview.to_string();
        ctx.db.tideforge_economy_quote().key().update(quote);
        Ok::<_, String>(())
    }).unwrap();
    assert_eq!(commit(&test, "first", nonce).unwrap_err(), "QUOTE_CHANGED");
    assert!(test.db.gear_instance().instance_id().find(copy).is_some());
    assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:fortune")).unwrap().count, totals[0]);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    assert_eq!(test.db.item_stack().key().find(material_key).unwrap().quantity, material);
    assert!(test.db.owned_specimen().catch_id().find(fish_id).is_some());
    assert!(test.db.gear_instance().instance_id().find(original).is_some());
    assert_eq!(test.db.gear_family_progress().key().find(family).unwrap().mastery_xp, mastery);
    for name in names {
        let entry = test.db.tideforge_codex().key().find(format!("{id}:{name}")).unwrap();
        assert_eq!((entry.highest_tier, entry.best_finish_bp, entry.natural_count), (1, 500, 1));
    }
}

#[test]
fn extraction_learns_once_without_shards_or_loss_of_permanent_history() {
    let test = setup();
    let id = player(&test, 112, "first");
    activate(&test);
    fixtures(&test, id);
    discover_and_shard(&test, id, "fortune", 4);
    let roll = AffixRoll { slot: 0, id: "fortune".into(), tier: 5, finish_bp: 2000, secondary_biome_id: None };
    let first = add_copy(&test, id, 1, vec![roll.clone()], Some("echoed_line"));
    let second = add_copy(&test, id, 2, vec![roll], Some("echoed_line"));
    test.with_reducer_tx(auth("first"), |ctx| gear_instances::rename_gear_instance(ctx, first, "Target".into())).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| gear_instances::rename_gear_instance(ctx, second, "Sibling".into())).unwrap();
    let key = gear_instances::family_key(id, "rod", 2).unwrap();
    let xp = test.db.gear_family_progress().key().find(key).unwrap().mastery_xp;
    let nonce = prepare(&test, "first", id, json!({"action":"extract_signature","instance_id":first.to_string()})).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.gear_instance().instance_id().find(first).is_none());
    assert!(test.db.gear_instance_name().instance_id().find(first).is_none());
    assert_eq!(test.db.gear_instance_name().instance_id().find(second).unwrap().name, "Sibling");
    assert!(test.db.tideforge_library().key().find(format!("{id}:echoed_line")).is_some());
    assert_eq!(test.db.tideforge_affix_shard().key().find(format!("{id}:fortune")).unwrap().count, 4);
    assert_eq!(test.db.tideforge_codex().key().find(format!("{id}:fortune")).unwrap().natural_count, 1);
    assert_eq!(test.db.gear_family_progress().key().find(key).unwrap().mastery_xp, xp);
    tick(&test);
    assert!(prepare(&test, "first", id, json!({"action":"extract_signature","instance_id":second.to_string()})).is_err());
    assert!(test.db.gear_instance().instance_id().find(second).is_some());
}

fn discover_ordinary(test: &TestContext, player_id: u64) -> u32 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx.db.species_definition().iter()
            .find(|row| row.counts_for_ordinary_collection_completion).unwrap();
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(player_id) << 32) | u128::from(species.species_id),
            player_id, species_id: species.species_id, count: 1,
            rank_counts: vec![1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            first_caught_at: ctx.timestamp, best_length_mm: species.typical_length_mm,
            best_weight_g: species.typical_weight_g,
        });
        Ok::<_, String>(species.species_id)
    }).unwrap()
}

fn browser_request(test: &TestContext, suffix: u16) -> u64 {
    ((test.clock.now().to_micros_since_unix_epoch() as u64 / 1000) << 16) | u64::from(suffix)
}

#[test]
fn committed_raid_locks_selected_preset_but_not_unused_gear() {
    let test = setup();
    let id = player(&test, 113, "first");
    activate(&test);
    fixtures(&test, id);
    test.with_reducer_tx(TestAuth::internal(), raids::activate_raids).unwrap();
    let species = discover_ordinary(&test, id);
    let copy = add_copy(&test, id, 1, vec![AffixRoll {
        slot: 0, id: "fortune".into(), tier: 1, finish_bp: 0, secondary_biome_id: None,
    }], None);
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let attempt_id = (u128::from(id) << 64) | u128::from(request);
    let supply = game_rules::raids::catalog().supply;
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, supply.slot, &supply.item, 1000, "fixture".into(), "fixture",
    )).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| raids::deploy_raid(ctx, browser_request(&test, 2), attempt_id)).unwrap();
    let equipped_rod = gear_instances::original_id(id, "rod", 1).unwrap();
    assert_eq!(prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":equipped_rod.to_string(),"slot":0
    })).unwrap_err(), "RAID_EQUIPMENT_LOCKED");
    let nonce = prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":copy.to_string(),"slot":0
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    let gear = test.db.tideforge_gear().instance_id().find(copy).unwrap();
    let rolls: Vec<AffixRoll> = serde_json::from_str(&gear.affixes_json).unwrap();
    assert!(rolls[0].finish_bp > 0);
}

#[test]
fn material_conversion_and_multihop_refining_preserve_preexisting_intermediates() {
    let test = setup();
    let id = player(&test, 114, "first");
    activate(&test);
    fixtures(&test, id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, 800, "normal_fillets", 100, "fixture".into(), "fixture",
    )).unwrap();
    let convert = json!({"action":"convert_normal","material_id":0,"batches":10});
    assert!(prepare(&test, "first", id, convert.clone()).is_err());
    grade_clear(&test, id, 10);
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    let nonce = prepare(&test, "first", id, convert).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.item_stack().key().find((u128::from(id) << 32) | 800).is_none());
    let (f_slot, _) = game_rules::raids::material(0, 0).unwrap();
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(f_slot)).unwrap().quantity, 10);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    grade_materials(&test, id, 0..1, 210);
    let (grade1_slot, grade1_item) = game_rules::raids::material(1, 0).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, grade1_slot, &grade1_item, 50, "fixture".into(), "fixture",
    )).unwrap();
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({
        "action":"refine_material","material_id":0,"from_grade":0,"to_grade":2,"batches":11
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(f_slot)).is_none());
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(grade1_slot)).unwrap().quantity, 68);
    let (grade2_slot, _) = game_rules::raids::material(2, 0).unwrap();
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | u128::from(grade2_slot)).unwrap().quantity, 162);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins - 20);
}

#[test]
fn legacy_refine_uses_old_recipe_until_activation_and_replays_old_receipt_inertly() {
    let test = setup();
    let id = player(&test, 115, "first");
    test.with_reducer_tx(TestAuth::internal(), raids::activate_raids).unwrap();
    grade_clear(&test, id, 10);
    grade_materials(&test, id, 0..1, 500);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut player = ctx.db.player().player_id().find(id).unwrap();
        player.coins = 1000;
        ctx.db.player().player_id().update(player);
        Ok::<_, String>(())
    }).unwrap();
    let old = game_rules::raids::catalog().refining;
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("first"), |ctx| raids::refine_raid_material(ctx, request, 0, 0, 1)).unwrap();
    let (slot, _) = game_rules::raids::material(0, 0).unwrap();
    let stack_key = (u128::from(id) << 32) | u128::from(slot);
    assert_eq!(test.db.item_stack().key().find(stack_key).unwrap().quantity, 500 - old.input_quantity);
    activate(&test);
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    test.with_reducer_tx(auth("first"), |ctx| raids::refine_raid_material(ctx, request, 0, 0, 1)).unwrap();
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    assert_eq!(test.db.item_stack().key().find(stack_key).unwrap().quantity, 500 - old.input_quantity);
    test.with_reducer_tx(auth("first"), |ctx| raids::refine_raid_material(
        ctx, browser_request(&test, 2), 0, 0, 1,
    )).unwrap();
    assert_eq!(test.db.item_stack().key().find(stack_key).unwrap().quantity, 500 - old.input_quantity - 20);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins - 1);
}

fn fish(test: &TestContext, player_id: u64, species_id: u32, trophy: bool, favorite: bool, bonus: Option<(u32, u32)>) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx.db.species_definition().species_id().find(species_id).unwrap();
        let model = game_rules::measurements::SpeciesMeasurements {
            typical_length_mm: species.typical_length_mm, min_length_mm: species.min_length_mm,
            max_length_mm: species.max_length_mm, typical_weight_g: species.typical_weight_g,
            min_weight_g: species.min_weight_g, max_weight_g: species.max_weight_g,
        };
        let (length_mm, weight_g) = if trophy {
            let maximum = game_rules::measurements::maximum_in_size_band(model, 0).map_err(str::to_owned)?;
            (maximum.length_mm, maximum.weight_g)
        } else {
            (species.min_length_mm, species.min_weight_g)
        };
        let row = ctx.db.owned_specimen().insert(OwnedSpecimen {
            catch_id: 0, player_id, species_id, rarity: "F".into(),
            length_mm, weight_g,
            size_grade: 0, caught_at: ctx.timestamp, sale_value_coins: 17, favorite,
            rules_version: 17, content_version: crate::content::CONTENT_VERSION, biome_id: species.biome_id,
            source_guild_id: None,
        });
        let mut player = ctx.db.player().player_id().find(player_id).unwrap();
        player.kept_count += 1;
        ctx.db.player().player_id().update(player);
        if let Some((fillet_bp, scale_bp)) = bonus {
            ctx.db.tideforge_specimen_modifiers().insert(TideforgeSpecimenModifiers {
                catch_id: row.catch_id, player_id, sale_bp: 0, fillet_bp, scale_bp,
                effects_version: effects::EFFECTS_VERSION, activity_key: "fixture".into(),
            });
        }
        Ok::<_, String>(row.catch_id)
    }).unwrap()
}

fn ordinary_species(test: &TestContext) -> u32 {
    test.db.species_definition().iter().find(|row| row.counts_for_ordinary_collection_completion
        && row.allowed_rarities.iter().any(|rank| rank == "F")).unwrap().species_id
}

#[test]
fn fillet_rejects_duplicate_foreign_favorite_exception_and_trophies_by_default() {
    let test = setup();
    let id = player(&test, 116, "first");
    let other = player(&test, 117, "second");
    activate(&test);
    fixtures(&test, id);
    let species = ordinary_species(&test);
    let normal = fish(&test, id, species, false, false, None);
    let foreign = fish(&test, other, species, false, false, None);
    let favorite = fish(&test, id, species, false, true, None);
    let trophy = fish(&test, id, species, true, false, None);
    let exception = test.db.species_definition().iter()
        .find(|row| !row.counts_for_ordinary_collection_completion && row.allowed_rarities.iter().any(|rank| rank == "F"))
        .unwrap().species_id;
    let special = fish(&test, id, exception, false, false, None);
    for ids in [
        vec![normal.to_string(), normal.to_string()],
        vec![foreign.to_string()],
        vec![favorite.to_string()],
        vec![trophy.to_string()],
        vec![special.to_string()],
    ] {
        assert!(prepare(&test, "first", id, json!({"action":"fillet","catch_ids":ids,"allow_trophies":false})).is_err());
    }
    assert!(prepare(&test, "first", id, json!({
        "action":"fillet","catch_ids":[special.to_string()],"allow_trophies":true
    })).is_err());
    let nonce = prepare(&test, "first", id, json!({
        "action":"fillet","catch_ids":[trophy.to_string()],"allow_trophies":true
    })).unwrap();
    commit(&test, "first", nonce).unwrap();
    assert!(test.db.owned_specimen().catch_id().find(trophy).is_none());
    for kept in [normal, favorite, special] {
        assert!(test.db.owned_specimen().catch_id().find(kept).is_some());
    }
}

#[test]
fn fillet_uses_pinned_credit_and_bulk_matches_single_without_changing_lifetime() {
    let test = setup();
    let first = player(&test, 118, "first");
    let second = player(&test, 119, "second");
    activate(&test);
    fixtures(&test, first);
    fixtures(&test, second);
    let species = ordinary_species(&test);
    let bulk = [
        fish(&test, first, species, false, false, None),
        fish(&test, first, species, false, false, Some((1000, 1000))),
        fish(&test, first, species, false, false, Some((1000, 1000))),
    ];
    let singles = [
        fish(&test, second, species, false, false, None),
        fish(&test, second, species, false, false, Some((1000, 1000))),
        fish(&test, second, species, false, false, Some((1000, 1000))),
    ];
    let before = test.db.player().player_id().find(first).unwrap();
    let nonce = prepare(&test, "first", first, json!({
        "action":"fillet","catch_ids":bulk.iter().map(u64::to_string).collect::<Vec<_>>(),"allow_trophies":false
    })).unwrap();
    let identity = Identity::from_claims("test", "first");
    let quote = test.db.tideforge_economy_quote().key().find(format!("{identity}:{first}")).unwrap();
    let preview: serde_json::Value = serde_json::from_str(&quote.preview_json).unwrap();
    assert_eq!(preview["saved_sale_coins_forgone"], 51);
    commit(&test, "first", nonce).unwrap();
    for id in singles {
        let nonce = prepare(&test, "second", second, json!({
            "action":"fillet","catch_ids":[id.to_string()],"allow_trophies":false
        })).unwrap();
        commit(&test, "second", nonce).unwrap();
        tick(&test);
    }
    for (slot, amount) in [(800, 19), (801, 12)] {
        let get = |player_id| test.db.item_stack().key().find((u128::from(player_id) << 32) | slot).unwrap().quantity;
        assert_eq!(get(first), amount);
        assert_eq!(get(first), get(second));
    }
    for (resource, remainder) in [("normal_fillets", 2000), ("normal_scales", 8000)] {
        let get = |player_id| test.db.tideforge_fractional_credit().key().find(format!("{player_id}:{resource}")).unwrap().remainder_bp;
        assert_eq!(get(first), remainder);
        assert_eq!(get(first), get(second));
    }
    let after = test.db.player().player_id().find(first).unwrap();
    assert_eq!((after.total_xp, after.fish_count, after.completed_casts, after.coins),
        (before.total_xp, before.fish_count, before.completed_casts, before.coins));
    assert_eq!(after.kept_count, before.kept_count - 3);
    assert!(bulk.iter().all(|id| test.db.tideforge_specimen_modifiers().catch_id().find(*id).is_none()));
}

#[test]
fn fillet_stale_carry_and_competing_sale_do_not_double_pay() {
    let test = setup();
    let id = player(&test, 120, "first");
    activate(&test);
    fixtures(&test, id);
    let species = ordinary_species(&test);
    let first = fish(&test, id, species, false, false, None);
    let nonce = prepare(&test, "first", id, json!({"action":"fillet","catch_ids":[first.to_string()],"allow_trophies":false})).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_fractional_credit().insert(TideforgeFractionalCredit {
            key: format!("{id}:normal_fillets"), player_id: id, resource: "normal_fillets".into(), remainder_bp: 1,
        });
        Ok::<_, String>(())
    }).unwrap();
    assert!(commit(&test, "first", nonce).is_err());
    assert!(test.db.owned_specimen().catch_id().find(first).is_some());
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({"action":"fillet","catch_ids":[first.to_string()],"allow_trophies":false})).unwrap();
    test.with_reducer_tx(auth("first"), |ctx| crate::inventory::prepare_inventory_action(
        ctx, "sell".into(), vec![first], false,
    )).unwrap();
    let identity = Identity::from_claims("test", "first");
    let sale = test.db.action_nonce().identity().find(identity).unwrap().nonce;
    test.with_reducer_tx(auth("first"), |ctx| crate::inventory::commit_inventory_action(ctx, sale)).unwrap();
    assert!(commit(&test, "first", nonce).is_err());
    assert!(test.db.item_stack().key().find((u128::from(id) << 32) | 800).is_none());
}

fn deploy_ordinary(test: &TestContext, player_id: u64, species_id: u32, grade: u8, suffix: u16) -> u128 {
    let request = browser_request(test, suffix);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, species_id, grade)).unwrap();
    let attempt = (u128::from(player_id) << 64) | u128::from(request);
    test.with_reducer_tx(auth("first"), |ctx| raids::deploy_raid(ctx, browser_request(test, suffix + 1), attempt)).unwrap();
    attempt
}

fn complete_ordinary(test: &TestContext, attempt_id: u128, suffix: u16) {
    let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
    test.clock.set(attempt.ends_at);
    test.with_reducer_tx(auth("first"), |ctx| raids::claim_raid(ctx, browser_request(test, suffix), attempt_id)).unwrap();
    assert_eq!(test.db.raid_attempt().id().find(attempt_id).unwrap().status, "completed");
}

#[test]
fn only_new_full_personal_raid_bookings_pay_and_two_ladders_total_130() {
    let test = setup();
    let id = player(&test, 121, "first");
    test.with_reducer_tx(TestAuth::internal(), raids::activate_raids).unwrap();
    let species = discover_ordinary(&test, id);
    let supply = game_rules::raids::catalog().supply;
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, supply.slot, &supply.item, 1000, "fixture".into(), "fixture",
    )).unwrap();
    let old = deploy_ordinary(&test, id, species, 0, 1);
    activate(&test);
    assert!(test.db.tideforge_raid_mark_booking().attempt_id().find(old).is_none());
    complete_ordinary(&test, old, 3);
    assert!(test.db.tideforge_economy_wallet().player_id().find(id).is_none());
    let mut last = 0;
    for cycle in 0..2u16 {
        for grade in 0..10u8 {
            let suffix = 10 + cycle * 40 + u16::from(grade) * 3;
            let attempt = deploy_ordinary(&test, id, species, grade, suffix);
            let booking = test.db.tideforge_raid_mark_booking().attempt_id().find(attempt).unwrap();
            assert_eq!(booking.marks, game_rules::tideforge_economy::catalog().unwrap()
                .marks.ordinary_raid_by_grade[usize::from(grade)]);
            complete_ordinary(&test, attempt, suffix + 2);
            assert!(test.db.tideforge_raid_mark_booking().attempt_id().find(attempt).unwrap().settled);
            last = attempt;
        }
    }
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 130);
    test.with_reducer_tx(auth("first"), |ctx| raids::claim_raid(ctx, browser_request(&test, 201), last)).unwrap();
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 130);
}

#[test]
fn raid_settlement_pays_the_frozen_booking_once() {
    let test = setup();
    let id = player(&test, 141, "first");
    activate(&test);
    test.with_reducer_tx(TestAuth::internal(), raids::activate_raids).unwrap();
    let species = discover_ordinary(&test, id);
    let supply = game_rules::raids::catalog().supply;
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, supply.slot, &supply.item, 100, "fixture".into(), "fixture",
    )).unwrap();
    let attempt = deploy_ordinary(&test, id, species, 0, 1);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut booking = ctx.db.tideforge_raid_mark_booking().attempt_id().find(attempt).unwrap();
        booking.marks = 17;
        ctx.db.tideforge_raid_mark_booking().attempt_id().update(booking);
        Ok::<_, String>(())
    }).unwrap();
    complete_ordinary(&test, attempt, 3);
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 17);
    test.with_reducer_tx(auth("first"), |ctx| raids::claim_raid(ctx, browser_request(&test, 4), attempt)).unwrap();
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 17);
}

#[test]
fn achievements_pay_once_and_explicit_backfills_never_pay() {
    let test = setup();
    let id = player(&test, 122, "first");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut player = ctx.db.player().player_id().find(id).unwrap();
        player.completed_casts = 1;
        ctx.db.player().player_id().update(player);
        Ok::<_, String>(())
    }).unwrap();
    activate(&test);
    test.with_reducer_tx(TestAuth::internal(), |ctx| achievements::backfill_player_achievements(ctx, id)).unwrap();
    assert!(test.db.earned_achievement().key().find((u128::from(id) << 32) | 1).is_some());
    assert!(test.db.tideforge_economy_wallet().player_id().find(id).is_none());
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut player = ctx.db.player().player_id().find(id).unwrap();
        player.completed_casts = 100;
        ctx.db.player().player_id().update(player);
        achievements::refresh_player(ctx, id)
    }).unwrap();
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 1);
    test.with_reducer_tx(TestAuth::internal(), |ctx| achievements::refresh_player(ctx, id)).unwrap();
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 1);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut player = ctx.db.player().player_id().find(id).unwrap();
        player.completed_casts = 1000;
        player.junk_count = 1000;
        ctx.db.player().player_id().update(player);
        crate::records::rebuild_player_records(ctx, id)
    }).unwrap();
    assert!(test.db.earned_achievement().key().find((u128::from(id) << 32) | 4).is_some());
    assert_eq!(test.db.tideforge_economy_wallet().player_id().find(id).unwrap().marks, 1);
    let award = test.db.tideforge_mark_award().key().find(format!("{id}:achievement:3")).unwrap();
    assert!(award.prune_at.is_none());
}

#[test]
fn receipt_pruning_and_subsequent_quotes_never_replay_a_purchase() {
    let test = setup();
    let id = player(&test, 123, "first");
    activate(&test);
    fixtures(&test, id);
    let intent = json!({"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1});
    let nonce = prepare(&test, "first", id, intent.clone()).unwrap();
    commit(&test, "first", nonce).unwrap();
    let count = test.db.gear_instance().count();
    let balance = test.db.player().player_id().find(id).unwrap().coins;
    test.clock.advance(TimeDuration::from_micros(8 * game_rules::daily::DAY_MICROS + 1));
    test.with_reducer_tx(TestAuth::internal(), economy::prune_history).unwrap();
    assert_eq!(test.db.tideforge_economy_receipt().count(), 0);
    commit(&test, "first", nonce).unwrap();
    assert_eq!(test.db.gear_instance().count(), count);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, balance);
    let next = prepare(&test, "first", id, intent).unwrap();
    assert_ne!(next, nonce);
    assert!(commit(&test, "first", nonce).is_err());
    assert_eq!(test.db.gear_instance().count(), count);
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, balance);
}

#[test]
fn stale_catalog_conflicting_slots_and_counter_overflow_do_not_spend() {
    let test = setup();
    let id = player(&test, 124, "first");
    activate(&test);
    fixtures(&test, id);
    grade_clear(&test, id, 10);
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, 800, "normal_fillets", 50, "fixture".into(), "fixture",
    )).unwrap();
    let nonce = prepare(&test, "first", id, json!({"action":"convert_normal","material_id":0,"batches":1})).unwrap();
    let identity = Identity::from_claims("test", "first");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut quote = ctx.db.tideforge_economy_quote().key().find(format!("{identity}:{id}")).unwrap();
        quote.catalog_version += 1;
        ctx.db.tideforge_economy_quote().key().update(quote);
        Ok::<_, String>(())
    }).unwrap();
    assert!(commit(&test, "first", nonce).is_err());
    tick(&test);
    let nonce = prepare(&test, "first", id, json!({"action":"convert_normal","material_id":0,"batches":1})).unwrap();
    let (output, _) = game_rules::raids::material(0, 0).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.item_stack().insert(ItemStack {
            key: (u128::from(id) << 32) | u128::from(output), player_id: id,
            item: "conflicting_resource".into(), quantity: 1,
        });
        Ok::<_, String>(())
    }).unwrap();
    assert!(commit(&test, "first", nonce).is_err());
    assert_eq!(test.db.item_stack().key().find((u128::from(id) << 32) | 800).unwrap().quantity, 50);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut wallet = ctx.db.tideforge_economy_wallet().player_id().find(id).unwrap();
        wallet.next_sequence = 0;
        ctx.db.tideforge_economy_wallet().player_id().update(wallet);
        Ok::<_, String>(())
    }).unwrap();
    tick(&test);
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    assert!(prepare(&test, "first", id, json!({"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1})).is_err());
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
}

#[test]
fn corrupt_affix_json_after_preview_rejects_confirmation_without_payment() {
    let test = setup();
    let id = player(&test, 133, "first");
    activate(&test);
    fixtures(&test, id);
    let copy = add_copy(&test, id, 1, vec![AffixRoll {
        slot: 0, id: "fortune".into(), tier: 1, finish_bp: 0, secondary_biome_id: None,
    }], None);
    let nonce = prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":copy.to_string(),"slot":0
    })).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.tideforge_gear().instance_id().find(copy).unwrap();
        row.affixes_json = "{bad json".into();
        ctx.db.tideforge_gear().instance_id().update(row);
        Ok::<_, String>(())
    }).unwrap();
    let balance = test.db.player().player_id().find(id).unwrap().coins;
    assert!(commit(&test, "first", nonce).is_err());
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, balance);
    assert_eq!(test.db.gear_instance().instance_id().find(copy).unwrap().revision, 1);
}

#[test]
fn rejected_insufficient_or_overflow_requests_do_not_advance_rng() {
    let rejected = setup();
    let control = setup();
    let a = player(&rejected, 125, "first");
    let b = player(&control, 125, "first");
    for (test, id) in [(&rejected, a), (&control, b)] {
        activate(test);
        fixtures(test, id);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut player = ctx.db.player().player_id().find(id).unwrap();
            player.coins = 0;
            ctx.db.player().player_id().update(player);
            Ok::<_, String>(())
        }).unwrap();
    }
    let error = reject_without_random(&rejected, &control, 5001, |ctx| economy::prepare_tideforge_action(
        ctx, json!({"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1}).to_string(),
    ));
    assert_eq!(error, "INSUFFICIENT_COINS");
    let before = rejected.db.gear_instance().count();
    rejected.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut wallet = ctx.db.tideforge_economy_wallet().player_id().find(a).unwrap();
        wallet.marks = u64::MAX;
        ctx.db.tideforge_economy_wallet().player_id().update(wallet);
        Ok::<_, String>(())
    }).unwrap();
    assert!(rejected.with_reducer_tx(TestAuth::internal(), |ctx| economy::award_achievement(ctx, a, 999)).is_err());
    assert_eq!(rejected.db.tideforge_economy_wallet().player_id().find(a).unwrap().marks, u64::MAX);
    assert_eq!(rejected.db.gear_instance().count(), before);
}

#[test]
fn corrupt_natural_codex_rejects_confirmation_before_property_rng() {
    let rejected = setup();
    let control = setup();
    let a = player(&rejected, 130, "first");
    let b = player(&control, 130, "first");
    for (test, id) in [(&rejected, a), (&control, b)] {
        activate(test);
        fixtures(test, id);
    }
    let intent = json!({"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1});
    let nonce = prepare(&rejected, "first", a, intent.clone()).unwrap();
    prepare(&control, "first", b, intent).unwrap();
    rejected.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_codex().insert(TideforgeCodex {
            key: format!("{a}:fortune"), player_id: a, affix_id: "fortune".into(),
            highest_tier: 1, best_finish_bp: 0, natural_count: u64::MAX,
        });
        Ok::<_, String>(())
    }).unwrap();
    let before = rejected.db.player().player_id().find(a).unwrap().coins;
    let error = reject_without_random(&rejected, &control, 6142, |ctx| economy::commit_tideforge_action(ctx, nonce));
    assert_eq!(error, "TIDEFORGE_CODEX_OVERFLOW");
    assert_eq!(rejected.db.player().player_id().find(a).unwrap().coins, before);
}

#[test]
fn active_booked_haul_instance_cannot_be_forged() {
    let test = setup();
    let id = player(&test, 131, "first");
    activate(&test);
    let trip = interaction(&test, 9);
    test.with_reducer_tx(auth("bot"), |ctx| crate::hauls::start_haul_from_discord(
        ctx, 131, trip, None, 8, "net".into(),
    )).unwrap();
    let net = gear_instances::original_id(id, "net", 201).unwrap();
    assert_eq!(prepare(&test, "first", id, json!({
        "action":"refine_affix","instance_id":net.to_string(),"slot":0
    })).unwrap_err(), "TIDEFORGE_GEAR_IN_USE");
}

fn interaction(test: &TestContext, suffix: u64) -> u64 {
    let ms = test.clock.now().to_micros_since_unix_epoch() / 1000;
    (u64::try_from(ms - 1_420_070_400_000).unwrap() << 22) | suffix
}

#[test]
fn economy_views_are_owner_scoped_and_selected_adapter_only() {
    let test = setup();
    let first = player(&test, 126, "first");
    let second = player(&test, 127, "second");
    activate(&test);
    fixtures(&test, first);
    fixtures(&test, second);
    for (id, subject) in [(first, "first"), (second, "second")] {
        discover_and_shard(&test, id, "fortune", 1);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            economy::award_achievement(ctx, id, 99)?;
            economy::book_raid(ctx, (u128::from(id) << 64) | 555, id, 0)
        }).unwrap();
        let nonce = prepare(&test, subject, id, json!({"action":"camp_copy","kind":"rod","definition_id":2,"secondary_biome_id":1})).unwrap();
        commit(&test, subject, nonce).unwrap();
    }
    let unlinked = test.view_context(auth("unlinked"));
    assert!(economy::my_tideforge_economy_wallet(&unlinked).is_none());
    assert!(economy::my_tideforge_vault(&unlinked).is_empty());
    assert!(economy::my_tideforge_codex(&unlinked).is_empty());
    assert!(economy::my_tideforge_economy_quote(&unlinked).is_none());
    assert!(economy::my_tideforge_economy_receipts(&unlinked).is_empty());
    assert!(economy::my_tideforge_mark_awards(&unlinked).is_empty());
    assert!(economy::my_tideforge_raid_mark_bookings(&unlinked).is_empty());
    let owner = test.view_context(auth("first"));
    assert_eq!(economy::my_tideforge_economy_wallet(&owner).unwrap().player_id, first);
    assert!(economy::my_tideforge_vault(&owner).iter().all(|row| row.player_id == first));
    assert!(economy::my_tideforge_codex(&owner).iter().all(|row| row.player_id == first));
    assert_eq!(economy::my_tideforge_economy_quote(&owner).unwrap().player_id, first);
    assert!(economy::my_tideforge_economy_receipts(&owner).iter().all(|row| row.player_id == first));
    assert!(economy::my_tideforge_mark_awards(&owner).iter().all(|row| row.player_id == first));
    assert!(economy::my_tideforge_raid_mark_bookings(&owner).iter().all(|row| row.player_id == first));
    assert!(economy::adapter_tideforge_vault(&owner).is_empty());
    test.with_reducer_tx(auth("bot"), |ctx| accounts::select_discord_player(
        ctx, 127, "Angler".into(), interaction(&test, 1),
    )).unwrap();
    let adapter = test.view_context(auth("bot"));
    assert_eq!(economy::adapter_tideforge_economy_wallet(&adapter).unwrap().player_id, second);
    assert!(economy::adapter_tideforge_vault(&adapter).iter().all(|row| row.player_id == second));
    assert!(economy::adapter_tideforge_codex(&adapter).iter().all(|row| row.player_id == second));
    assert!(economy::adapter_tideforge_economy_quote(&adapter).is_none());
    assert!(economy::adapter_tideforge_economy_receipts(&adapter).iter().all(|row| row.player_id == second));
    assert!(economy::adapter_tideforge_mark_awards(&adapter).iter().all(|row| row.player_id == second));
    assert!(economy::adapter_tideforge_raid_mark_bookings(&adapter).iter().all(|row| row.player_id == second));
}

#[test]
fn cancelled_partial_missed_special_and_community_raids_do_not_award_marks() {
    let test = setup();
    let id = player(&test, 128, "first");
    activate(&test);
    test.with_reducer_tx(TestAuth::internal(), raids::activate_raids).unwrap();
    let species = discover_ordinary(&test, id);
    let supply = game_rules::raids::catalog().supply;
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::inventory::grant_item_with_reason(
        ctx, id, supply.slot, &supply.item, 1000, "fixture".into(), "fixture",
    )).unwrap();
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let cancelled = (u128::from(id) << 64) | u128::from(request);
    test.with_reducer_tx(auth("first"), |ctx| raids::cancel_raid(ctx, browser_request(&test, 2), cancelled)).unwrap();
    assert!(!test.db.tideforge_raid_mark_booking().attempt_id().find(cancelled).unwrap().settled);
    let partial = deploy_ordinary(&test, id, species, 0, 3);
    let attempt = test.db.raid_attempt().id().find(partial).unwrap();
    test.clock.set(attempt.starts_at + TimeDuration::from_micros(i64::from(attempt.duration_seconds) * 500_000));
    test.with_reducer_tx(auth("first"), |ctx| raids::cancel_raid(ctx, browser_request(&test, 5), partial)).unwrap();
    assert_eq!(test.db.raid_attempt().id().find(partial).unwrap().status, "cancelled");
    assert!(!test.db.tideforge_raid_mark_booking().attempt_id().find(partial).unwrap().settled);
    let request = browser_request(&test, 6);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let missed = (u128::from(id) << 64) | u128::from(request);
    test.clock.set(test.db.raid_attempt().id().find(missed).unwrap().starts_at);
    test.with_reducer_tx(auth("first"), |ctx| raids::claim_raid(ctx, browser_request(&test, 7), missed)).unwrap();
    assert_eq!(test.db.raid_attempt().id().find(missed).unwrap().status, "missed");
    assert!(!test.db.tideforge_raid_mark_booking().attempt_id().find(missed).unwrap().settled);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let fihs = ctx.db.species_definition().species_id().find(74).unwrap();
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(id) << 32) | 74, player_id: id, species_id: 74, count: 1,
            rank_counts: vec![0, 0, 0, 0, 0, 0, 0, 0, 0, 1], first_caught_at: ctx.timestamp,
            best_length_mm: fihs.typical_length_mm, best_weight_g: fihs.typical_weight_g,
        });
        Ok::<_, String>(())
    }).unwrap();
    let request = browser_request(&test, 8);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_raid(ctx, request, 74, 0)).unwrap();
    let special = (u128::from(id) << 64) | u128::from(request);
    assert!(test.db.tideforge_raid_mark_booking().attempt_id().find(special).is_none());
    test.with_reducer_tx(auth("first"), |ctx| raids::cancel_raid(ctx, browser_request(&test, 9), special)).unwrap();
    let event_id = u64::MAX - 1;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let start = ctx.timestamp + TimeDuration::from_micros(60_000_000);
        ctx.db.community_raid_event().insert(raids::CommunityRaidEvent {
            event_id, name: "Fixture".into(), signup_at: ctx.timestamp, starts_at: start,
            ends_at: start + TimeDuration::from_micros(i64::from(game_rules::raids::catalog().community.duration_seconds) * 1_000_000),
        });
        Ok::<_, String>(())
    }).unwrap();
    let request = browser_request(&test, 10);
    test.with_reducer_tx(auth("first"), |ctx| raids::prepare_community_raid(ctx, request, event_id)).unwrap();
    let community = (u128::from(id) << 64) | u128::from(request);
    assert!(test.db.tideforge_raid_mark_booking().attempt_id().find(community).is_none());
    test.with_reducer_tx(auth("first"), |ctx| raids::deploy_raid(ctx, browser_request(&test, 11), community)).unwrap();
    complete_ordinary(&test, community, 12);
    assert!(test.db.tideforge_economy_wallet().player_id().find(id).is_none());
    assert_eq!(test.db.tideforge_mark_award().count(), 0);
}
