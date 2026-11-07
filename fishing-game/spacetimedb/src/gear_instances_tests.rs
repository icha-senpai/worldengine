use crate::{
    accounts, casting, crafting, gear_instances::*, haul_equipment,
    hauls::{self, haul_job, haul_trip},
    inventory, loadout,
    raids::{self, community_raid_event, raid_action_receipt, raid_attempt, raid_badge, raid_job, rod_reinforcement, RaidBadge, RodReinforcement},
    shop, tables::*,
    tideforge_economy::{tideforge_economy_quote, tideforge_economy_receipt, tideforge_economy_wallet, TideforgeEconomyWallet},
};
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
    test.clock.set(Timestamp::from_micros_since_unix_epoch(1_800_000_000_000_000));
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
            identity: Identity::from_claims("test", subject),
            player_id: row.player_id,
            linked_at: ctx.timestamp,
        });
        Ok::<_, String>(row.player_id)
    }).unwrap()
}

fn activate(test: &TestContext) {
    test.with_reducer_tx(TestAuth::internal(), activate_gear_foundation).unwrap();
}

fn interaction(test: &TestContext, suffix: u64) -> u64 {
    let ms = test.clock.now().to_micros_since_unix_epoch() / 1000;
    (u64::try_from(ms - 1_420_070_400_000).unwrap() << 22) | suffix
}

fn select(test: &TestContext, discord: u64, id: u64) {
    test.with_reducer_tx(auth("bot"), |ctx| {
        accounts::select_discord_player(ctx, discord, "Angler".into(), id)
    }).unwrap();
}

fn copy(test: &TestContext, player_id: u64, kind: &str, definition: u32, ordinal: u64) -> u128 {
    let original = original_id(player_id, kind, definition).unwrap();
    let id = (u128::from(player_id) << 64) | (1u128 << 63) | u128::from(ordinal);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance().instance_id().find(original).unwrap();
        row.instance_id = id;
        row.protected_original = false;
        row.quality_level = 0;
        row.reinforcement_level = 0;
        row.revision = 1;
        row.source = "test_copy".into();
        ctx.db.gear_instance().insert(row);
        Ok::<(), String>(())
    }).unwrap();
    id
}

fn rename(test: &TestContext, subject: &str, instance_id: u128, name: &str) -> Result<(), String> {
    test.with_reducer_tx(auth(subject), |ctx| rename_gear_instance(ctx, instance_id, name.into()))
}

#[test]
fn gear_names_are_per_instance_unicode_safe_and_idempotent() {
    let test = setup();
    let id = player(&test, 5701, "namer");
    let rod = original_id(id, "rod", 1).unwrap();
    assert_eq!(rename(&test, "namer", rod, "Before activation").unwrap_err(), "GEAR_FOUNDATION_UNAVAILABLE");
    assert!(my_gear_instance_names(&test.view_context(auth("namer"))).is_empty());
    activate(&test);
    assert_eq!(test.db.gear_instance_name().count(), 0);
    let originals = [rod, original_id(id, "boat", 101).unwrap(), original_id(id, "net", 201).unwrap()];
    let copies = [copy(&test, id, "rod", 1, 1), copy(&test, id, "boat", 101, 2), copy(&test, id, "net", 201, 3)];
    for (index, instance) in originals.into_iter().chain(copies).enumerate() {
        let name = format!("Item {}", index + 1);
        rename(&test, "namer", instance, &name).unwrap();
        assert_eq!(test.db.gear_instance_name().instance_id().find(instance).unwrap().name, name);
    }
    assert_eq!(my_gear_instance_names(&test.view_context(auth("namer"))).len(), 6);
    rename(&test, "namer", rod, "\u{3000}潮竿 🎣\u{2003}").unwrap();
    assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, "潮竿 🎣");
    let raw_limit = format!("{}x{}", " ".repeat(512), " ".repeat(511));
    assert_eq!(raw_limit.len(), 1024);
    rename(&test, "namer", rod, &raw_limit).unwrap();
    assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, "x");
    let maximum = "🎣".repeat(32);
    rename(&test, "namer", rod, &maximum).unwrap();
    rename(&test, "namer", rod, &maximum).unwrap();
    assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, maximum);
    for invalid in ["x".repeat(33), "🎣".repeat(33), " ".repeat(1025)] {
        assert_eq!(rename(&test, "namer", rod, &invalid).unwrap_err(), "GEAR_NAME_TOO_LONG");
        assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, maximum);
    }
    for invalid in ['\n', '\0', '\r', '\u{7f}', '\u{85}', '\u{061c}', '\u{200e}', '\u{200f}', '\u{202a}', '\u{202b}',
        '\u{202c}', '\u{202d}', '\u{202e}', '\u{2066}', '\u{2067}', '\u{2068}', '\u{2069}'] {
        assert_eq!(rename(&test, "namer", rod, &format!("a{invalid}b")).unwrap_err(), "GEAR_NAME_INVALID");
        assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, maximum);
    }
    rename(&test, "namer", rod, "<b>plain</b>").unwrap();
    assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name, "<b>plain</b>");
    rename(&test, "namer", rod, "Shared").unwrap();
    rename(&test, "namer", copies[0], "Shared").unwrap();
    assert_eq!(test.db.gear_instance_name().instance_id().find(rod).unwrap().name,
        test.db.gear_instance_name().instance_id().find(copies[0]).unwrap().name);
    rename(&test, "namer", rod, "").unwrap();
    rename(&test, "namer", rod, "").unwrap();
    rename(&test, "namer", copies[0], " \u{3000}\t ").unwrap();
    rename(&test, "namer", copies[0], " \u{3000}\t ").unwrap();
    assert!(test.db.gear_instance_name().instance_id().find(rod).is_none());
    assert!(test.db.gear_instance_name().instance_id().find(copies[0]).is_none());
    assert_eq!(test.db.gear_instance_name().count(), 4);
}

#[test]
fn gear_name_reducers_and_views_enforce_owner_and_adapter_selection() {
    let test = setup();
    let first = player(&test, 5801, "first");
    let second = player(&test, 5802, "second");
    activate(&test);
    let first_rod = original_id(first, "rod", 1).unwrap();
    let second_rod = original_id(second, "rod", 1).unwrap();
    rename(&test, "first", first_rod, "First").unwrap();
    rename(&test, "second", second_rod, "Second").unwrap();
    assert_eq!(rename(&test, "unknown", first_rod, "No").unwrap_err(), "ACCOUNT_NOT_LINKED");
    assert_eq!(rename(&test, "second", first_rod, "No").unwrap_err(), "GEAR_INSTANCE_UNAVAILABLE");
    assert_eq!(rename(&test, "first", u128::MAX, "No").unwrap_err(), "GEAR_INSTANCE_UNAVAILABLE");
    assert!(my_gear_instance_names(&test.view_context(auth("unknown"))).is_empty());
    assert!(adapter_gear_instance_names(&test.view_context(auth("unknown"))).is_empty());
    assert!(adapter_gear_instance_names(&test.view_context(auth("bot"))).is_empty());
    let own = my_gear_instance_names(&test.view_context(auth("first")));
    assert_eq!(own.len(), 1);
    assert_eq!((own[0].instance_id, own[0].player_id, own[0].name.as_str()), (first_rod, first, "First"));
    let other = my_gear_instance_names(&test.view_context(auth("second")));
    assert_eq!(other.len(), 1);
    assert_eq!((other[0].instance_id, other[0].player_id, other[0].name.as_str()), (second_rod, second, "Second"));
    let selection = interaction(&test, 1);
    select(&test, 5801, selection);
    let adapter = adapter_gear_instance_names(&test.view_context(auth("bot")));
    assert_eq!(adapter.len(), 1);
    assert_eq!((adapter[0].instance_id, adapter[0].player_id), (first_rod, first));
    assert!(test.with_reducer_tx(auth("bot"), |ctx|
        rename_gear_instance_from_discord(ctx, 5802, selection, second_rod, "Spoof".into())).is_err());
    assert!(test.with_reducer_tx(auth("bot"), |ctx|
        rename_gear_instance_from_discord(ctx, 5801, interaction(&test, 2), first_rod, "Spoof".into())).is_err());
    assert!(test.with_reducer_tx(auth("bot"), |ctx|
        rename_gear_instance_from_discord(ctx, 5801, selection, second_rod, "Spoof".into())).is_err());
    assert!(test.with_reducer_tx(auth("first"), |ctx|
        rename_gear_instance_from_discord(ctx, 5801, selection, first_rod, "Spoof".into())).is_err());
    test.with_reducer_tx(auth("bot"), |ctx|
        rename_gear_instance_from_discord(ctx, 5801, selection, first_rod, "Adapter".into())).unwrap();
    assert_eq!(my_gear_instance_names(&test.view_context(auth("first")))[0].name, "Adapter");
    let selection = interaction(&test, 3);
    select(&test, 5802, selection);
    let adapter = adapter_gear_instance_names(&test.view_context(auth("bot")));
    assert_eq!(adapter.len(), 1);
    assert_eq!((adapter[0].instance_id, adapter[0].player_id, adapter[0].name.as_str()), (second_rod, second, "Second"));
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance_name().instance_id().find(first_rod).unwrap();
        row.player_id = second;
        ctx.db.gear_instance_name().instance_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    for value in ["Overwrite", ""] {
        assert_eq!(rename(&test, "first", first_rod, value).unwrap_err(), "GEAR_INSTANCE_UNAVAILABLE");
        let row = test.db.gear_instance_name().instance_id().find(first_rod).unwrap();
        assert_eq!((row.player_id, row.name.as_str()), (second, "Adapter"));
    }
}

#[test]
fn activation_is_owner_only_additive_and_idempotent() {
    let test = setup();
    let first = player(&test, 4101, "first");
    let second = player(&test, 4102, "second");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = u128::from(first) << 32 | 1;
        let mut rod = ctx.db.owned_rod().key().find(key).unwrap();
        rod.upgrade_level = 3;
        ctx.db.owned_rod().key().update(rod);
        ctx.db.rod_reinforcement().insert(RodReinforcement {
            key, player_id: first, rod_id: 1, level: 2, updated_at: ctx.timestamp,
        });
        for (id, quality) in [(101, 2), (201, 4)] {
            let key = (u128::from(first) << 32) | u128::from(id as u32);
            let mut gear = ctx.db.owned_haul_equipment().key().find(key).unwrap();
            gear.upgrade_level = quality;
            ctx.db.owned_haul_equipment().key().update(gear);
        }
        let mut p = ctx.db.player().player_id().find(first).unwrap();
        p.coins = 437;
        p.total_xp = 39;
        p.next_cast_at = ctx.timestamp + TimeDuration::from_micros(45_000_000);
        ctx.db.player().player_id().update(p);
        let mut p2 = ctx.db.player().player_id().find(second).unwrap();
        p2.equipped_rod_id = 2;
        ctx.db.player().player_id().update(p2);
        ctx.db.owned_rod().insert(OwnedRod {
            key: (u128::from(second) << 32) | 2, player_id: second, rod_id: 2, upgrade_level: 1,
        });
        for equipment_id in [102u32, 202u32] {
            ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
                key: (u128::from(second) << 32) | u128::from(equipment_id),
                player_id: second, equipment_id, upgrade_level: 1,
            });
        }
        let mut loadout = ctx.db.haul_loadout().player_id().find(second).unwrap();
        loadout.boat_id = 102;
        loadout.net_id = 202;
        ctx.db.haul_loadout().player_id().update(loadout);
        let specimen = ctx.db.owned_specimen().insert(OwnedSpecimen {
            catch_id: 0, player_id: first, species_id: 1, rarity: "F".into(),
            length_mm: 123, weight_g: 456, size_grade: 1, caught_at: ctx.timestamp,
            sale_value_coins: 17, favorite: true, rules_version: 17,
            content_version: crate::content::CONTENT_VERSION, biome_id: 1, source_guild_id: None,
        });
        ctx.db.rank_maximum().insert(RankMaximum {
            key: (u128::from(first) << 64) | 10, player_id: first,
            species_id: 1, rarity: "F".into(), first_catch_id: specimen.catch_id,
            achieved_at: ctx.timestamp, length_mm: 123, weight_g: 456,
        });
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(first) << 32) | 1, player_id: first, species_id: 1,
            count: 5, rank_counts: vec![5, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            first_caught_at: ctx.timestamp, best_length_mm: 123, best_weight_g: 456,
        });
        inventory::grant_item_with_reason(ctx, first, 2, "tin", 123, "fixture".into(), "fixture")
    }).unwrap();
    let before = test.db.player().player_id().find(first).unwrap();
    assert!(test.with_reducer_tx(auth("intruder"), activate_gear_foundation).is_err());
    assert_eq!(test.db.gear_instance().count(), 0);
    assert_eq!(test.db.gear_foundation_state().count(), 0);
    activate(&test);
    assert_eq!(test.db.gear_instance().count(), 9);
    assert_eq!(test.db.gear_family_progress().count(), 9);
    assert_eq!(test.db.gear_loadout().count(), 2);
    let original = test.db.gear_instance().instance_id().find(original_id(first, "rod", 1).unwrap()).unwrap();
    assert_eq!((original.quality_level, original.reinforcement_level, original.revision), (3, 2, 1));
    assert!(original.protected_original);
    assert_eq!(test.db.gear_instance().instance_id().find(original_id(first, "boat", 101).unwrap()).unwrap().quality_level, 2);
    assert_eq!(test.db.gear_instance().instance_id().find(original_id(first, "net", 201).unwrap()).unwrap().quality_level, 4);
    for (id, rod_id, boat_id, net_id) in [(first, 1, 101, 201), (second, 2, 102, 202)] {
        let row = test.db.gear_loadout().player_id().find(id).unwrap();
        assert_eq!(row.rod_instance_id, original_id(id, "rod", rod_id).unwrap());
        assert_eq!(row.boat_instance_id, original_id(id, "boat", boat_id).unwrap());
        assert_eq!(row.net_instance_id, original_id(id, "net", net_id).unwrap());
    }
    let after = test.db.player().player_id().find(first).unwrap();
    assert_eq!((after.coins, after.total_xp, after.next_cast_at), (before.coins, before.total_xp, before.next_cast_at));
    assert_eq!(test.db.item_stack().key().find((u128::from(first) << 32) | 2).unwrap().quantity, 123);
    assert!(test.db.owned_specimen().player_id().filter(first).next().unwrap().favorite);
    assert_eq!(test.db.rank_maximum().player_id().filter(first).next().unwrap().length_mm, 123);
    assert_eq!(test.db.player_species_progress().player_id().filter(first).next().unwrap().rank_counts[0], 5);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_instance().instance_id().find(original.instance_id).unwrap();
        row.favorite = true;
        ctx.db.gear_instance().instance_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    activate(&test);
    assert_eq!(test.db.gear_instance().count(), 9);
    assert!(test.db.gear_instance().instance_id().find(original.instance_id).unwrap().favorite);
    assert_eq!(test.db.gear_family_progress().count(), 9);
}

#[test]
fn invalid_migration_rolls_back_all_foundation_rows() {
    let test = setup();
    let first = player(&test, 4201, "first");
    let second = player(&test, 4202, "second");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.player().player_id().find(second).unwrap();
        row.equipped_rod_id = 2;
        ctx.db.player().player_id().update(row);
        Ok::<(), String>(())
    }).unwrap();
    assert!(test.with_reducer_tx(TestAuth::internal(), activate_gear_foundation).is_err());
    assert_eq!(test.db.gear_foundation_state().count(), 0);
    assert_eq!(test.db.gear_instance().count(), 0);
    assert_eq!(test.db.gear_family_progress().count(), 0);
    assert_eq!(test.db.gear_loadout().count(), 0);
    assert_eq!(test.db.owned_rod().player_id().filter(first).count(), 1);
}

#[test]
fn new_players_and_purchases_register_only_missing_originals() {
    let test = setup();
    activate(&test);
    let id = player(&test, 4301, "buyer");
    assert_eq!(test.db.gear_instance().player_id().filter(id).count(), 3);
    assert_eq!(test.db.gear_family_progress().player_id().filter(id).count(), 3);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.total_xp = 100_000_000;
        p.coins = 10_000_000;
        ctx.db.player().player_id().update(p);
        let key = family_key(id, "rod", 1).unwrap();
        let mut family = ctx.db.gear_family_progress().key().find(key).unwrap();
        family.mastery_xp = 42;
        ctx.db.gear_family_progress().key().update(family);
        haul_equipment::grant_starters(ctx, id)
    }).unwrap();
    let listing = test.db.shop_listing().iter().find(|row| row.kind == "rod" && row.target_id != 1 && row.previous_biome_id == 1).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| shop::prepare_shop_purchase(ctx, listing.listing_id)).unwrap();
    let quote = test.db.shop_quote().iter().find(|row| row.player_id == id).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| shop::commit_shop_purchase(ctx, quote.nonce)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| shop::commit_shop_purchase(ctx, quote.nonce)).unwrap();
    let purchased = test.db.gear_instance().instance_id().find(original_id(id, "rod", listing.target_id).unwrap()).unwrap();
    assert!(purchased.protected_original);
    assert_eq!((purchased.quality_level, purchased.reinforcement_level, purchased.revision), (0, 0, 1));
    assert_eq!(test.db.gear_family_progress().key().find(family_key(id, "rod", 1).unwrap()).unwrap().mastery_xp, 42);
    assert_eq!(test.db.gear_instance().player_id().filter(id).count(), 4);
}

#[test]
fn copy_selected_stats_and_private_views_are_scoped() {
    let test = setup();
    let first = player(&test, 4401, "first");
    let second = player(&test, 4402, "second");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = (u128::from(first) << 32) | 1;
        let mut rod = ctx.db.owned_rod().key().find(key).unwrap();
        rod.upgrade_level = 3;
        ctx.db.owned_rod().key().update(rod);
        ctx.db.rod_reinforcement().insert(RodReinforcement { key, player_id: first, rod_id: 1, level: 2, updated_at: ctx.timestamp });
        Ok::<(), String>(())
    }).unwrap();
    activate(&test);
    let copy_id = copy(&test, first, "rod", 1, 1);
    assert!(test.with_reducer_tx(auth("second"), |ctx| equip_gear_instance(ctx, copy_id)).is_err());
    assert!(test.with_reducer_tx(auth("second"), |ctx| set_gear_instance_favorite(ctx, copy_id, true)).is_err());
    test.with_reducer_tx(auth("first"), |ctx| equip_gear_instance(ctx, copy_id)).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.owned_rod().key().delete((u128::from(first) << 32) | 1);
        Ok::<(), String>(())
    }).unwrap();
    let cast_id = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4401, cast_id, None, 8)).unwrap();
    let receipt = test.db.cast_equipment_receipt().interaction_id().find(cast_id).unwrap();
    assert_eq!((receipt.quality_level, receipt.reinforcement_level), (0, 0));
    let snapshot = test.db.gear_activity_snapshot().key().find(format!("cast:{cast_id}")).unwrap();
    assert_eq!(snapshot.snapshot.instance_id, copy_id);
    assert_eq!(snapshot.snapshot.gear_revision, Some(1));
    assert_eq!(snapshot.snapshot.effects_version, 0);
    assert_eq!(test.db.gear_family_progress().player_id().filter(first).filter(|row| row.kind == "rod").count(), 1);
    assert!(my_gear_instances(&test.view_context(auth("unknown"))).is_empty());
    assert!(adapter_gear_instances(&test.view_context(auth("unknown"))).is_empty());
    assert_eq!(my_gear_instances(&test.view_context(auth("first"))).len(), 4);
    assert_eq!(my_gear_instances(&test.view_context(auth("second"))).len(), 3);
    assert!(adapter_gear_instances(&test.view_context(auth("bot"))).is_empty());
    let selection = interaction(&test, 2);
    select(&test, 4402, selection);
    let adapter = adapter_gear_instances(&test.view_context(auth("bot")));
    assert_eq!(adapter.len(), 3);
    assert!(adapter.iter().all(|row| row.player_id == second));
    assert!(test.with_reducer_tx(auth("bot"), |ctx| equip_gear_instance_from_discord(ctx, 4402, selection, copy_id)).is_err());
    assert!(test.with_reducer_tx(auth("bot"), |ctx| set_gear_instance_favorite_from_discord(ctx, 4401, selection, copy_id, true)).is_err());
    assert!(my_gear_family_progress(&test.view_context(auth("unknown"))).is_empty());
    assert!(my_gear_activity_snapshots(&test.view_context(auth("unknown"))).is_empty());
    assert!(my_gear_loadout(&test.view_context(auth("unknown"))).is_none());
    assert_eq!(adapter_gear_family_progress(&test.view_context(auth("bot"))).len(), 3);
    assert!(adapter_gear_activity_snapshots(&test.view_context(auth("bot"))).is_empty());
    assert_eq!(adapter_gear_loadout(&test.view_context(auth("bot"))).unwrap().player_id, second);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4401, cast_id, None, 8)).unwrap();
    assert_eq!(test.db.gear_activity_snapshot().player_id().filter(first).count(), 1);
}

#[test]
fn pending_legacy_quotes_target_original_not_selected_copy() {
    let test = setup();
    let id = player(&test, 4501, "buyer");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        inventory::grant_item_with_reason(ctx, id, 2, "tin", 1000, "fixture".into(), "fixture")?;
        inventory::grant_item_with_reason(ctx, id, 1, "scrap", 1000, "fixture".into(), "fixture")
    }).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| crafting::prepare_rod_upgrade(ctx, 1)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| haul_equipment::prepare_haul_upgrade(ctx, 101)).unwrap();
    let rod_quote = test.db.upgrade_quote().iter().find(|row| row.player_id == id).unwrap();
    let boat_quote = test.db.haul_upgrade_quote().iter().find(|row| row.player_id == id).unwrap();
    activate(&test);
    let migrated_rod_quote = test.db.upgrade_quote().iter().find(|row| row.player_id == id).unwrap();
    let migrated_boat_quote = test.db.haul_upgrade_quote().iter().find(|row| row.player_id == id).unwrap();
    assert_eq!((rod_quote.nonce, rod_quote.tin_cost, rod_quote.scrap_cost, rod_quote.expires_at),
               (migrated_rod_quote.nonce, migrated_rod_quote.tin_cost, migrated_rod_quote.scrap_cost, migrated_rod_quote.expires_at));
    assert_eq!((boat_quote.nonce, boat_quote.tin_cost, boat_quote.scrap_cost, boat_quote.expires_at),
               (migrated_boat_quote.nonce, migrated_boat_quote.tin_cost, migrated_boat_quote.scrap_cost, migrated_boat_quote.expires_at));
    let rod_copy = copy(&test, id, "rod", 1, 1);
    let boat_copy = copy(&test, id, "boat", 101, 2);
    test.with_reducer_tx(auth("buyer"), |ctx| equip_gear_instance(ctx, rod_copy)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| equip_gear_instance(ctx, boat_copy)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| crafting::commit_rod_upgrade(ctx, rod_quote.nonce)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| haul_equipment::commit_haul_upgrade(ctx, boat_quote.nonce)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| crafting::commit_rod_upgrade(ctx, rod_quote.nonce)).unwrap();
    test.with_reducer_tx(auth("buyer"), |ctx| haul_equipment::commit_haul_upgrade(ctx, boat_quote.nonce)).unwrap();
    for (kind, def, copy_id) in [("rod", 1, rod_copy), ("boat", 101, boat_copy)] {
        let original = test.db.gear_instance().instance_id().find(original_id(id, kind, def).unwrap()).unwrap();
        let copy = test.db.gear_instance().instance_id().find(copy_id).unwrap();
        assert_eq!((original.quality_level, original.revision), (1, 2));
        assert_eq!((copy.quality_level, copy.revision), (0, 1));
    }
}

fn discover(test: &TestContext, player_id: u64) -> u32 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx.db.species_definition().iter().find(|row| row.counts_for_ordinary_collection_completion).unwrap();
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

fn browser_request(test: &TestContext, suffix: u16) -> u64 {
    ((test.clock.now().to_micros_since_unix_epoch() as u64 / 1000) << 16) | u64::from(suffix)
}

#[test]
fn legacy_bookings_backfill_captured_values_and_new_haul_pins_content() {
    let test = setup();
    let id = player(&test, 4601, "booker");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for equipment in [101, 201] {
            let key = (u128::from(id) << 32) | u128::from(equipment as u32);
            let mut row = ctx.db.owned_haul_equipment().key().find(key).unwrap();
            row.upgrade_level = 2;
            ctx.db.owned_haul_equipment().key().update(row);
        }
        Ok::<(), String>(())
    }).unwrap();
    let haul_id = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 4601, haul_id, None, 8, "net".into())).unwrap();
    assert_eq!(test.db.gear_activity_snapshot().count(), 0);
    let original_trip = test.db.haul_trip().interaction_id().find(haul_id).unwrap();
    let species_id = discover(&test, id);
    let request_id = browser_request(&test, 2);
    test.with_reducer_tx(auth("booker"), |ctx| raids::prepare_raid(ctx, request_id, species_id, 0)).unwrap();
    let raid_id = (u128::from(id) << 64) | u128::from(request_id);
    let original_raid = test.db.raid_attempt().id().find(raid_id).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for equipment in [101, 201] {
            let key = (u128::from(id) << 32) | u128::from(equipment as u32);
            let mut row = ctx.db.owned_haul_equipment().key().find(key).unwrap();
            row.upgrade_level = 4;
            ctx.db.owned_haul_equipment().key().update(row);
        }
        Ok::<(), String>(())
    }).unwrap();
    activate(&test);
    let haul = test.db.gear_activity_snapshot().key().find(format!("haul:{haul_id}")).unwrap();
    assert!(haul.legacy_booking);
    assert_eq!(haul.snapshot.quality_level, 2);
    assert_eq!(haul.snapshot.gear_revision, None);
    assert_eq!(haul.snapshot.primary_biome_id, None);
    assert_eq!(haul.snapshot.content_version, None);
    assert_eq!(haul.snapshot.luck_bp, Some(original_trip.luck_bp));
    assert_eq!(haul.snapshot.xp_bonus_bp, Some(original_trip.xp_bonus_bp));
    assert_eq!(haul.booked_at, original_trip.started_at);
    let raid = test.db.gear_activity_snapshot().key().find(format!("raid:{raid_id}")).unwrap();
    assert!(raid.legacy_booking);
    assert_eq!(raid.snapshot.quality_level, 2);
    assert_eq!(raid.snapshot.content_version, Some(original_raid.content_version));
    assert_eq!((raid.snapshot.luck_bp, raid.snapshot.xp_bonus_bp), (None, None));
    assert_eq!(test.db.raid_attempt().id().find(raid_id).unwrap().ends_at, original_raid.ends_at);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        inventory::grant_item_with_reason(ctx, id, 900, "raid_supplies", 1, "fixture".into(), "fixture")
    }).unwrap();
    let deploy = browser_request(&test, 9);
    test.with_reducer_tx(auth("booker"), |ctx| raids::deploy_raid(ctx, deploy, raid_id)).unwrap();
    let deployed = test.db.raid_attempt().id().find(raid_id).unwrap();
    assert_eq!(deployed.boat_quality_level, 2);
    test.clock.set(original_trip.due_at);
    let job = test.db.haul_job().scheduled_id().find(haul_id).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| hauls::finish_scheduled_haul(ctx, job)).unwrap();
    assert_eq!(test.db.cast_equipment_receipt().interaction_id().find(haul_id).unwrap().quality_level, 2);
    let new_id = interaction(&test, 3);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 4601, new_id, None, 8, "net".into())).unwrap();
    let new_haul = test.db.gear_activity_snapshot().key().find(format!("haul:{new_id}")).unwrap();
    assert!(!new_haul.legacy_booking);
    assert_eq!(new_haul.snapshot.quality_level, 4);
    assert_eq!(new_haul.snapshot.content_version, Some(crate::content::CONTENT_VERSION));
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.gear_activity_snapshot().key().find(format!("haul:{new_id}")).unwrap();
        row.snapshot.content_version = Some(1234);
        ctx.db.gear_activity_snapshot().key().update(row);
        Ok::<(), String>(())
    }).unwrap();
    let new_due = test.db.haul_trip().interaction_id().find(new_id).unwrap().due_at;
    test.clock.set(new_due);
    let new_job = test.db.haul_job().scheduled_id().find(new_id).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| hauls::finish_scheduled_haul(ctx, new_job)).unwrap();
    assert_eq!(test.db.command_receipt().interaction_id().find(new_id).unwrap().content_version, 1234);
    test.clock.set(deployed.starts_at);
    let job = test.db.raid_job().attempt_id().filter(raid_id).find(|job| job.phase == "start").unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, job)).unwrap();
    test.clock.set(deployed.ends_at);
    let job = test.db.raid_job().attempt_id().filter(raid_id).find(|job| job.phase == "finish").unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, job)).unwrap();
    let finished = test.db.raid_attempt().id().find(raid_id).unwrap();
    assert_eq!(finished.status, "completed");
    assert_eq!((finished.awarded_fillets, finished.awarded_scales), (deployed.full_fillets, deployed.full_scales));
    assert_eq!(finished.duration_seconds, original_raid.duration_seconds);
}

#[test]
fn committed_raids_lock_real_equipment_changes_but_not_biome_or_noops() {
    let test = setup();
    let id = player(&test, 4701, "angler");
    let species = discover(&test, id);
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("angler"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let attempt_id = (u128::from(id) << 64) | u128::from(request);
    activate(&test);
    let rod = copy(&test, id, "rod", 1, 1);
    let boat = copy(&test, id, "boat", 101, 2);
    let net = copy(&test, id, "net", 201, 3);
    for instance in [rod, boat, net] {
        test.with_reducer_tx(auth("angler"), |ctx| equip_gear_instance(ctx, instance)).unwrap();
    }
    for kind in ["personal", "community"] {
        for status in ["deployed", "running"] {
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                let mut attempt = ctx.db.raid_attempt().id().find(attempt_id).unwrap();
                attempt.kind = kind.into();
                attempt.status = status.into();
                ctx.db.raid_attempt().id().update(attempt);
                Ok::<(), String>(())
            }).unwrap();
            for instance in [rod, boat, net] {
                test.with_reducer_tx(auth("angler"), |ctx| equip_gear_instance(ctx, instance)).unwrap();
            }
            assert!(test.with_reducer_tx(auth("angler"), |ctx| loadout::change_loadout(ctx, None, Some(1))).is_err());
            assert!(test.with_reducer_tx(auth("angler"), |ctx| haul_equipment::equip_haul_equipment(ctx, 101)).is_err());
            assert!(test.with_reducer_tx(auth("angler"), |ctx| haul_equipment::equip_haul_equipment(ctx, 201)).is_err());
            test.with_reducer_tx(auth("angler"), |ctx| loadout::change_loadout(ctx, Some(1), None)).unwrap();
        }
    }
    let cast = interaction(&test, 2);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4701, cast, None, 8)).unwrap();
    assert_eq!(test.db.gear_activity_snapshot().key().find(format!("cast:{cast}")).unwrap().snapshot.instance_id, rod);
}

#[test]
fn reinforcement_changes_original_and_preserves_copy_and_cast_snapshot() {
    let test = setup();
    let id = player(&test, 4801, "reinforcer");
    let species_id = discover(&test, id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.coins = 10_000;
        ctx.db.player().player_id().update(p);
        ctx.db.raid_badge().insert(RaidBadge {
            key: (u128::from(id) << 32) | u128::from(species_id),
            player_id: id,
            species_id,
            cleared_grades: 1,
            successful_clears: 1,
            special_completions: 0,
            first_clear_at: Some(ctx.timestamp),
            latest_clear_at: Some(ctx.timestamp),
        });
        for material in 0..2 {
            let (slot, item) = game_rules::raids::material(0, material).unwrap();
            inventory::grant_item_with_reason(ctx, id, slot, &item, 1000, "fixture".into(), "fixture")?;
        }
        Ok::<(), String>(())
    }).unwrap();
    activate(&test);
    let copy_id = copy(&test, id, "rod", 1, 1);
    test.with_reducer_tx(auth("reinforcer"), |ctx| equip_gear_instance(ctx, copy_id)).unwrap();
    let cast = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4801, cast, None, 8)).unwrap();
    let before = test.db.gear_activity_snapshot().key().find(format!("cast:{cast}")).unwrap().snapshot;
    let request = browser_request(&test, 2);
    test.with_reducer_tx(auth("reinforcer"), |ctx| raids::reinforce_rod(ctx, request, 1, 0)).unwrap();
    let coins = test.db.player().player_id().find(id).unwrap().coins;
    test.with_reducer_tx(auth("reinforcer"), |ctx| raids::reinforce_rod(ctx, request, 1, 0)).unwrap();
    assert_eq!(test.db.player().player_id().find(id).unwrap().coins, coins);
    let original = test.db.gear_instance().instance_id().find(original_id(id, "rod", 1).unwrap()).unwrap();
    assert_eq!((original.reinforcement_level, original.revision), (1, 2));
    assert_eq!(test.db.gear_instance().instance_id().find(copy_id).unwrap().reinforcement_level, 0);
    let after = test.db.gear_activity_snapshot().key().find(format!("cast:{cast}")).unwrap().snapshot;
    assert_eq!((before.instance_id, before.gear_revision, before.reinforcement_level, before.power, before.luck_bp),
               (after.instance_id, after.gear_revision, after.reinforcement_level, after.power, after.luck_bp));
}

#[test]
fn zero_effect_cast_matches_seeded_legacy_and_replay_does_not_duplicate() {
    let legacy = setup();
    let active = setup();
    let legacy_id = player(&legacy, 4901, "angler");
    let active_id = player(&active, 4901, "angler");
    activate(&active);
    let cast = interaction(&legacy, 1);
    legacy.rng.set_seed(49942);
    active.rng.set_seed(49942);
    legacy.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4901, cast, None, 8)).unwrap();
    active.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4901, cast, None, 8)).unwrap();
    let a = legacy.db.command_receipt().interaction_id().find(cast).unwrap();
    let b = active.db.command_receipt().interaction_id().find(cast).unwrap();
    assert_eq!((a.outcome, a.species_id, a.rarity, a.length_mm, a.weight_g, a.xp_granted, a.coins_granted),
               (b.outcome, b.species_id, b.rarity, b.length_mm, b.weight_g, b.xp_granted, b.coins_granted));
    assert_eq!(legacy.db.gear_instance().count(), 0);
    assert_eq!(legacy.db.gear_activity_snapshot().count(), 0);
    assert_eq!(active.db.gear_activity_snapshot().count(), 1);
    let before = active.db.player().player_id().find(active_id).unwrap();
    active.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 4901, cast, None, 8)).unwrap();
    let after = active.db.player().player_id().find(active_id).unwrap();
    assert_eq!((before.coins, before.total_xp, before.next_cast_at), (after.coins, after.total_xp, after.next_cast_at));
    assert_eq!(active.db.gear_activity_snapshot().count(), 1);
    assert_eq!(legacy.db.player().player_id().find(legacy_id).unwrap().coins, after.coins);
}

#[test]
fn snapshots_follow_existing_receipt_and_activity_retention() {
    let test = setup();
    let id = player(&test, 5001, "angler");
    let other = player(&test, 5002, "other");
    let first_species = discover(&test, id);
    let other_species = discover(&test, other);
    activate(&test);
    let cast = interaction(&test, 1);
    let haul = interaction(&test, 2);
    test.with_reducer_tx(auth("bot"), |ctx| casting::fish_from_discord(ctx, 5001, cast, None, 8)).unwrap();
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 5001, haul, None, 8, "net".into())).unwrap();
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 5001, haul, None, 8, "net".into())).unwrap();
    let prepare = browser_request(&test, 3);
    let other_prepare = browser_request(&test, 4);
    test.with_reducer_tx(auth("angler"), |ctx| raids::prepare_raid(ctx, prepare, first_species, 0)).unwrap();
    test.with_reducer_tx(auth("other"), |ctx| raids::prepare_raid(ctx, other_prepare, other_species, 0)).unwrap();
    let raid = (u128::from(id) << 64) | u128::from(prepare);
    let active_raid = (u128::from(other) << 64) | u128::from(other_prepare);
    test.with_reducer_tx(auth("angler"), |ctx| raids::prepare_raid(ctx, prepare, first_species, 0)).unwrap();
    assert_eq!(test.db.gear_activity_snapshot().count(), 4);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut trip = ctx.db.haul_trip().interaction_id().find(haul).unwrap();
        trip.status = "delivered".into();
        trip.available_at = ctx.timestamp;
        ctx.db.haul_trip().interaction_id().update(trip);
        let mut attempt = ctx.db.raid_attempt().id().find(raid).unwrap();
        attempt.status = "completed".into();
        attempt.prune_at = ctx.timestamp + TimeDuration::from_micros(30 * 86_400_000_000);
        ctx.db.raid_attempt().id().update(attempt);
        Ok::<(), String>(())
    }).unwrap();
    test.clock.advance(TimeDuration::from_micros(6 * 86_400_000_000));
    let job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::maintenance::prune_history(ctx, job)).unwrap();
    assert_eq!(test.db.gear_activity_snapshot().count(), 4);
    test.clock.advance(TimeDuration::from_micros(2 * 86_400_000_000));
    let job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::maintenance::prune_history(ctx, job)).unwrap();
    assert!(test.db.gear_activity_snapshot().key().find(format!("cast:{cast}")).is_none());
    assert!(test.db.gear_activity_snapshot().key().find(format!("haul:{haul}")).is_some());
    assert!(test.db.gear_activity_snapshot().key().find(format!("raid:{raid}")).is_some());
    test.clock.advance(TimeDuration::from_micros(23 * 86_400_000_000));
    let job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::maintenance::prune_history(ctx, job)).unwrap();
    assert!(test.db.gear_activity_snapshot().key().find(format!("haul:{haul}")).is_none());
    assert!(test.db.gear_activity_snapshot().key().find(format!("raid:{raid}")).is_none());
    assert!(test.db.gear_activity_snapshot().key().find(format!("raid:{active_raid}")).is_some());
}

#[test]
fn selected_future_haul_copies_need_no_legacy_ownership() {
    let test = setup();
    let id = player(&test, 5101, "angler");
    let species = discover(&test, id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut p = ctx.db.player().player_id().find(id).unwrap();
        p.total_xp = 100_000_000;
        ctx.db.player().player_id().update(p);
        for equipment_id in [102u32, 202u32] {
            ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
                key: (u128::from(id) << 32) | u128::from(equipment_id),
                player_id: id, equipment_id, upgrade_level: 2,
            });
        }
        Ok::<(), String>(())
    }).unwrap();
    activate(&test);
    let boat = copy(&test, id, "boat", 102, 1);
    let net = copy(&test, id, "net", 202, 2);
    for instance in [boat, net] {
        test.with_reducer_tx(auth("angler"), |ctx| equip_gear_instance(ctx, instance)).unwrap();
    }
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for equipment_id in [102u32, 202u32] {
            ctx.db.owned_haul_equipment().key().delete((u128::from(id) << 32) | u128::from(equipment_id));
        }
        inventory::grant_item_with_reason(ctx, id, 900, "raid_supplies", 1, "fixture".into(), "fixture")
    }).unwrap();
    let net_trip = interaction(&test, 1);
    test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 5101, net_trip, None, 8, "net".into())).unwrap();
    let net_snapshot = test.db.gear_activity_snapshot().key().find(format!("haul:{net_trip}")).unwrap();
    assert_eq!((net_snapshot.snapshot.instance_id, net_snapshot.snapshot.quality_level), (net, 0));
    let original = original_id(id, "rod", 1).unwrap();
    test.with_reducer_tx(auth("angler"), |ctx| set_gear_instance_favorite(ctx, net, true)).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.tideforge_economy_wallet().insert(TideforgeEconomyWallet {
            player_id: id, marks: 123, next_sequence: 7, revision: 3,
        });
        Ok::<(), String>(())
    }).unwrap();
    let gear_before = test.db.gear_instance().instance_id().find(net).unwrap();
    let original_before = test.db.gear_instance().instance_id().find(original).unwrap();
    let loadout_before = test.db.gear_loadout().player_id().find(id).unwrap();
    let mastery_before = test.db.gear_family_progress().key().find(family_key(id, "net", 2).unwrap()).unwrap();
    let player_before = test.db.player().player_id().find(id).unwrap();
    let wallet_before = test.db.tideforge_economy_wallet().player_id().find(id).unwrap();
    let quote_count = test.db.tideforge_economy_quote().count();
    let receipt_count = test.db.tideforge_economy_receipt().count();
    assert!(gear_before.favorite);
    assert!(original_before.protected_original);
    assert_eq!((loadout_before.rod_instance_id, loadout_before.net_instance_id), (original, net));
    rename(&test, "angler", net, "Booked and favorite").unwrap();
    rename(&test, "angler", original, "Original").unwrap();
    let gear_after = test.db.gear_instance().instance_id().find(net).unwrap();
    let original_after = test.db.gear_instance().instance_id().find(original).unwrap();
    let loadout_after = test.db.gear_loadout().player_id().find(id).unwrap();
    let mastery_after = test.db.gear_family_progress().key().find(family_key(id, "net", 2).unwrap()).unwrap();
    let player_after = test.db.player().player_id().find(id).unwrap();
    let wallet_after = test.db.tideforge_economy_wallet().player_id().find(id).unwrap();
    let snapshot_after = test.db.gear_activity_snapshot().key().find(format!("haul:{net_trip}")).unwrap();
    assert_eq!((gear_after.instance_id, gear_after.player_id, &gear_after.kind, gear_after.definition_id,
        gear_after.family_id, gear_after.primary_biome_id, gear_after.quality_level, gear_after.reinforcement_level,
        gear_after.protected_original, gear_after.favorite, gear_after.revision),
        (gear_before.instance_id, gear_before.player_id, &gear_before.kind, gear_before.definition_id,
        gear_before.family_id, gear_before.primary_biome_id, gear_before.quality_level, gear_before.reinforcement_level,
        gear_before.protected_original, gear_before.favorite, gear_before.revision));
    assert_eq!((&gear_after.source, gear_after.created_at), (&gear_before.source, gear_before.created_at));
    assert_eq!((original_after.revision, original_after.favorite, original_after.quality_level, original_after.reinforcement_level),
        (original_before.revision, original_before.favorite, original_before.quality_level, original_before.reinforcement_level));
    assert_eq!((loadout_after.rod_instance_id, loadout_after.boat_instance_id, loadout_after.net_instance_id, loadout_after.revision),
        (loadout_before.rod_instance_id, loadout_before.boat_instance_id, loadout_before.net_instance_id, loadout_before.revision));
    assert_eq!((mastery_after.mastery_xp, mastery_after.curve_version), (mastery_before.mastery_xp, mastery_before.curve_version));
    assert_eq!((player_after.coins, player_after.total_xp, player_after.next_cast_at),
        (player_before.coins, player_before.total_xp, player_before.next_cast_at));
    assert_eq!((wallet_after.marks, wallet_after.next_sequence, wallet_after.revision),
        (wallet_before.marks, wallet_before.next_sequence, wallet_before.revision));
    assert_eq!((test.db.tideforge_economy_quote().count(), test.db.tideforge_economy_receipt().count()), (quote_count, receipt_count));
    assert_eq!((&snapshot_after.key, snapshot_after.player_id, &snapshot_after.activity_kind, snapshot_after.activity_id,
        snapshot_after.booked_at, snapshot_after.recorded_at, snapshot_after.legacy_booking),
        (&net_snapshot.key, net_snapshot.player_id, &net_snapshot.activity_kind, net_snapshot.activity_id,
        net_snapshot.booked_at, net_snapshot.recorded_at, net_snapshot.legacy_booking));
    let after = &snapshot_after.snapshot;
    let before = &net_snapshot.snapshot;
    assert_eq!((after.instance_id, after.gear_revision, &after.kind, after.definition_id, after.family_id,
        after.primary_biome_id, after.quality_level, after.reinforcement_level),
        (before.instance_id, before.gear_revision, &before.kind, before.definition_id, before.family_id,
        before.primary_biome_id, before.quality_level, before.reinforcement_level));
    assert_eq!((after.effects_version, after.power, after.luck_bp, after.xp_bonus_bp, after.rules_version,
        after.content_version, after.catalog_version),
        (before.effects_version, before.power, before.luck_bp, before.xp_bonus_bp, before.rules_version,
        before.content_version, before.catalog_version));
    assert_eq!(test.db.gear_instance_name().instance_id().find(net).unwrap().name, "Booked and favorite");
    assert_eq!(test.db.gear_instance_name().instance_id().find(original).unwrap().name, "Original");
    let request = browser_request(&test, 2);
    test.with_reducer_tx(auth("angler"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
    let attempt_id = (u128::from(id) << 64) | u128::from(request);
    let raid_snapshot = test.db.gear_activity_snapshot().key().find(format!("raid:{attempt_id}")).unwrap();
    assert_eq!((raid_snapshot.snapshot.instance_id, raid_snapshot.snapshot.quality_level), (boat, 0));
    let deploy = browser_request(&test, 3);
    test.with_reducer_tx(auth("angler"), |ctx| raids::deploy_raid(ctx, deploy, attempt_id)).unwrap();
    assert_eq!(test.db.raid_attempt().id().find(attempt_id).unwrap().status, "deployed");
}

#[test]
fn legacy_special_raid_retains_original_special_payload() {
    let test = setup();
    let id = player(&test, 5201, "special");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx.db.species_definition().species_id().find(74).unwrap();
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(id) << 32) | 74,
            player_id: id,
            species_id: 74,
            count: 1,
            rank_counts: vec![0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
            first_caught_at: ctx.timestamp,
            best_length_mm: species.typical_length_mm,
            best_weight_g: species.typical_weight_g,
        });
        Ok::<(), String>(())
    }).unwrap();
    let request = browser_request(&test, 1);
    test.with_reducer_tx(auth("special"), |ctx| raids::prepare_raid(ctx, request, 74, 0)).unwrap();
    let attempt_id = (u128::from(id) << 64) | u128::from(request);
    let before = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert!(before.special_species.is_some());
    assert!(before.special_rank.is_some());
    assert!(before.special_biome.is_some());
    assert!(before.special_rules.is_some());
    activate(&test);
    let after = test.db.raid_attempt().id().find(attempt_id).unwrap();
    let snapshot = test.db.gear_activity_snapshot().key().find(format!("raid:{attempt_id}")).unwrap();
    assert_eq!((after.special_band, after.special_maximum_roll_one_in, after.special_fish_count, after.ends_at),
               (before.special_band, before.special_maximum_roll_one_in, before.special_fish_count, before.ends_at));
    assert_eq!(after.special_species.unwrap().species_id, before.special_species.unwrap().species_id);
    assert_eq!(after.special_rank.unwrap().key, before.special_rank.unwrap().key);
    assert_eq!(after.special_biome.unwrap().biome_id, before.special_biome.unwrap().biome_id);
    assert_eq!(after.special_rules.unwrap().version, before.special_rules.unwrap().version);
    assert_eq!((snapshot.snapshot.power, snapshot.snapshot.luck_bp, snapshot.snapshot.xp_bonus_bp), (None, None, None));
}

#[test]
fn combined_loadout_change_checks_final_biome_and_rolls_back_invalid_target() {
    let test = setup();
    let id = player(&test, 5301, "traveller");
    activate(&test);
    let copy_id = copy(&test, id, "rod", 1, 1);
    test.with_reducer_tx(auth("traveller"), |ctx| equip_gear_instance(ctx, copy_id)).unwrap();
    let revision = test.db.gear_loadout().player_id().find(id).unwrap().revision;
    let cooldown = test.db.player().player_id().find(id).unwrap().next_cast_at;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for (biome_id, minimum_level) in [(1, 99), (2, 1), (3, 99)] {
            let mut biome = ctx.db.biome_definition().biome_id().find(biome_id).unwrap();
            biome.minimum_level = minimum_level;
            ctx.db.biome_definition().biome_id().update(biome);
        }
        ctx.db.owned_biome_licence().insert(OwnedBiomeLicence {
            key: (u128::from(id) << 32) | 2, player_id: id, biome_id: 2,
        });
        Ok::<(), String>(())
    }).unwrap();
    test.with_reducer_tx(auth("traveller"), |ctx| loadout::change_loadout(ctx, Some(2), Some(1))).unwrap();
    let selected = test.db.gear_loadout().player_id().find(id).unwrap();
    assert_eq!(selected.rod_instance_id, original_id(id, "rod", 1).unwrap());
    assert_eq!(selected.revision, revision + 1);
    let player = test.db.player().player_id().find(id).unwrap();
    assert_eq!((player.selected_biome_id, player.equipped_rod_id, player.next_cast_at), (2, 1, cooldown));
    assert!(test.with_reducer_tx(auth("traveller"), |ctx| loadout::change_loadout(ctx, Some(3), Some(1))).is_err());
    let rejected = test.db.player().player_id().find(id).unwrap();
    assert_eq!((rejected.selected_biome_id, rejected.equipped_rod_id, rejected.next_cast_at), (2, 1, cooldown));
    assert_eq!(test.db.gear_loadout().player_id().find(id).unwrap().revision, selected.revision);
}

#[test]
fn corrupt_reinforcement_references_reject_migration_without_partial_rows() {
    for mode in 0..3 {
        let test = setup();
        let first = player(&test, 5401, "first");
        let second = player(&test, 5402, "second");
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let (key, player_id, rod_id) = match mode {
                0 => ((u128::from(first) << 32) | 1, second, 1),
                1 => ((u128::from(first) << 32) | 2, first, 1),
                _ => ((u128::from(first) << 32) | 2, first, 2),
            };
            ctx.db.rod_reinforcement().insert(RodReinforcement {
                key, player_id, rod_id, level: 2, updated_at: ctx.timestamp,
            });
            Ok::<(), String>(())
        }).unwrap();
        assert!(test.with_reducer_tx(TestAuth::internal(), activate_gear_foundation).is_err());
        assert_eq!(test.db.gear_foundation_state().count(), 0);
        assert_eq!(test.db.gear_instance().count(), 0);
        assert_eq!(test.db.gear_family_progress().count(), 0);
        assert_eq!(test.db.gear_loadout().count(), 0);
        assert_eq!(test.db.rod_reinforcement().count(), 1);
    }
}

#[test]
fn dangling_booked_originals_reject_migration_without_rewriting_bookings() {
    for kind in ["haul", "raid"] {
        let test = setup();
        let id = player(&test, 5501, "booker");
        let species = discover(&test, id);
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut player = ctx.db.player().player_id().find(id).unwrap();
            player.total_xp = 100_000_000;
            ctx.db.player().player_id().update(player);
            Ok::<(), String>(())
        }).unwrap();
        if kind == "haul" {
            let request = interaction(&test, 1);
            test.with_reducer_tx(auth("bot"), |ctx| hauls::start_haul_from_discord(ctx, 5501, request, None, 8, "net".into())).unwrap();
        } else {
            let request = browser_request(&test, 1);
            test.with_reducer_tx(auth("booker"), |ctx| raids::prepare_raid(ctx, request, species, 0)).unwrap();
        }
        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let (old, replacement) = if kind == "haul" { (201u32, 202u32) } else { (101u32, 102u32) };
            ctx.db.owned_haul_equipment().key().delete((u128::from(id) << 32) | u128::from(old));
            ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
                key: (u128::from(id) << 32) | u128::from(replacement),
                player_id: id, equipment_id: replacement, upgrade_level: 0,
            });
            let mut loadout = ctx.db.haul_loadout().player_id().find(id).unwrap();
            if kind == "haul" { loadout.net_id = replacement; } else { loadout.boat_id = replacement; }
            ctx.db.haul_loadout().player_id().update(loadout);
            Ok::<(), String>(())
        }).unwrap();
        assert!(test.with_reducer_tx(TestAuth::internal(), activate_gear_foundation).is_err());
        assert_eq!(test.db.gear_foundation_state().count(), 0);
        assert_eq!(test.db.gear_instance().count(), 0);
        assert_eq!(test.db.gear_family_progress().count(), 0);
        assert_eq!(test.db.gear_loadout().count(), 0);
        assert_eq!(test.db.gear_activity_snapshot().count(), 0);
        if kind == "haul" {
            assert_eq!(test.db.haul_trip().player_id().filter(id).next().unwrap().equipment_id, 201);
        } else {
            assert_eq!(test.db.raid_attempt().player_id().filter(id).next().unwrap().boat_id, 101);
        }
    }
}

#[test]
fn already_committed_personal_and_community_raids_preserve_captured_payloads() {
    for kind in ["personal", "community"] {
        for status in ["deployed", "running"] {
            let test = setup();
            let id = player(&test, 5601, "raider");
            let species = discover(&test, id);
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                let mut boat = ctx.db.owned_haul_equipment().key().find((u128::from(id) << 32) | 101).unwrap();
                boat.upgrade_level = 2;
                ctx.db.owned_haul_equipment().key().update(boat);
                let catalog = game_rules::raids::catalog();
                inventory::grant_item_with_reason(ctx, id, catalog.supply.slot, &catalog.supply.item, 1000, "fixture".into(), "fixture")?;
                if kind == "community" {
                    let starts_at = ctx.timestamp + TimeDuration::from_micros(600_000_000);
                    ctx.db.community_raid_event().insert(crate::raids::CommunityRaidEvent {
                        event_id: u64::MAX - 1, name: "Foundation fixture".into(),
                        signup_at: ctx.timestamp - TimeDuration::from_micros(1_000_000),
                        starts_at,
                        ends_at: starts_at + TimeDuration::from_micros(i64::from(catalog.community.duration_seconds) * 1_000_000),
                    });
                }
                Ok::<(), String>(())
            }).unwrap();
            let request = browser_request(&test, 1);
            test.with_reducer_tx(auth("raider"), |ctx| {
                if kind == "community" {
                    raids::prepare_community_raid(ctx, request, u64::MAX - 1)
                } else {
                    raids::prepare_raid(ctx, request, species, 0)
                }
            }).unwrap();
            let attempt_id = (u128::from(id) << 64) | u128::from(request);
            let deploy = browser_request(&test, 2);
            test.with_reducer_tx(auth("raider"), |ctx| raids::deploy_raid(ctx, deploy, attempt_id)).unwrap();
            if status == "running" {
                let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
                test.clock.set(attempt.starts_at);
                let job = test.db.raid_job().attempt_id().filter(attempt_id).find(|job| job.phase == "start").unwrap();
                test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, job)).unwrap();
            }
            let before = test.db.raid_attempt().id().find(attempt_id).unwrap();
            let actions = test.db.raid_action_receipt().count();
            test.with_reducer_tx(TestAuth::internal(), |ctx| {
                let mut boat = ctx.db.owned_haul_equipment().key().find((u128::from(id) << 32) | 101).unwrap();
                boat.upgrade_level = 4;
                ctx.db.owned_haul_equipment().key().update(boat);
                Ok::<(), String>(())
            }).unwrap();
            activate(&test);
            let after = test.db.raid_attempt().id().find(attempt_id).unwrap();
            assert_eq!(after.status, status);
            assert_eq!((after.boat_id, after.boat_quality_level, after.prepared_at, after.starts_at, after.ends_at,
                        after.deployment_deadline, after.deployed_at, after.started_at),
                       (before.boat_id, before.boat_quality_level, before.prepared_at, before.starts_at, before.ends_at,
                        before.deployment_deadline, before.deployed_at, before.started_at));
            assert_eq!((after.base_fillets, after.base_scales, after.full_fillets, after.full_scales,
                        after.maximum_fillets, after.maximum_scales, after.supply_units, after.supply_slot, after.duration_seconds),
                       (before.base_fillets, before.base_scales, before.full_fillets, before.full_scales,
                        before.maximum_fillets, before.maximum_scales, before.supply_units, before.supply_slot, before.duration_seconds));
            assert_eq!((after.catalog_version, after.content_version, after.rules_version, after.supply_item),
                       (before.catalog_version, before.content_version, before.rules_version, before.supply_item));
            assert_eq!(test.db.raid_action_receipt().count(), actions);
            let snapshot = test.db.gear_activity_snapshot().key().find(format!("raid:{attempt_id}")).unwrap();
            assert_eq!((snapshot.snapshot.quality_level, snapshot.snapshot.gear_revision, snapshot.legacy_booking), (2, None, true));
            assert_eq!(test.db.gear_instance().instance_id().find(original_id(id, "boat", 101).unwrap()).unwrap().quality_level, 4);
        }
    }
}
