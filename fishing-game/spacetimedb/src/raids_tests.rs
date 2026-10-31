use crate::{
    accounts,
    casting::{self, CastPlan},
    crafting::UsedBaits,
    hauls,
    inventory::grant_item_with_reason,
    raids,
    tables::*,
};
use crate::hauls::{haul_job, haul_trip};
use crate::notifications::achievement_delivery;
use crate::raid_notifications::{self, raid_delivery};
use crate::raids::*;
use spacetimedb::{
    ConnectionId, Identity, Table, TimeDuration, Timestamp,
    rand::{Rng, RngCore, SeedableRng, rngs::StdRng},
    test_utils::{TestAuth, TestContext},
};

const BASE_TIME: i64 = 1_800_000_000_000_000;

fn auth(subject: &str) -> TestAuth {
    TestAuth::from_jwt_payload(
        format!(r#"{{"iss":"test","sub":"{subject}"}}"#),
        ConnectionId::from_u128(1),
    )
    .unwrap()
}

fn setup() -> TestContext {
    let test = TestContext::new().unwrap();
    test.clock.set(Timestamp::from_micros_since_unix_epoch(BASE_TIME));
    test.with_reducer_tx::<_, String>(TestAuth::internal(), |ctx| {
        crate::init(ctx);
        Ok(())
    })
    .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        accounts::configure_service(
            ctx,
            Identity::from_claims("test", "raid-bot"),
            ServiceRole::DiscordAdapter,
            true,
        )
    })
    .unwrap();
    test
}

fn player(test: &TestContext, discord_id: u64) -> u64 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        accounts::ensure_player(ctx, discord_id, "Raid Tester").map(|row| row.player_id)
    })
    .unwrap()
}

fn snowflake(test: &TestContext, suffix: u64) -> u64 {
    const DISCORD_EPOCH_MILLIS: i64 = 1_420_070_400_000;
    let now_millis = test.clock.now().to_micros_since_unix_epoch() / 1000;
    ((u64::try_from(now_millis - DISCORD_EPOCH_MILLIS).unwrap()) << 22) | suffix
}

fn select_discord_player(test: &TestContext, discord_id: u64, interaction_id: u64) {
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        accounts::select_discord_player(
            ctx,
            discord_id,
            "Raid Tester".into(),
            interaction_id,
        )
    })
    .unwrap();
}

fn link_browser(test: &TestContext, player_id: u64, subject: &str) {
    let identity = Identity::from_claims("test", subject);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.player_identity().insert(PlayerIdentity {
            identity,
            player_id,
            linked_at: ctx.timestamp,
        });
        Ok::<(), String>(())
    })
    .unwrap();
}

fn browser_request(test: &TestContext, suffix: u16) -> u64 {
    ((test.clock.now().to_micros_since_unix_epoch() as u64 / 1000) << 16)
        | u64::from(suffix)
}

fn grant_item(test: &TestContext, player_id: u64, slot: u32, item: &str, quantity: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        grant_item_with_reason(
            ctx,
            player_id,
            slot,
            item,
            quantity,
            format!("raid-test:{player_id}:{slot}:{quantity}"),
            "raid_test",
        )
    })
    .unwrap();
}

fn add_coins(test: &TestContext, player_id: u64, amount: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.player().player_id().find(player_id).unwrap();
        row.coins = row.coins.checked_add(amount).unwrap();
        ctx.db.player().player_id().update(row);
        Ok::<(), String>(())
    })
    .unwrap();
}

fn set_coins(test: &TestContext, player_id: u64, amount: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut row = ctx.db.player().player_id().find(player_id).unwrap();
        row.coins = amount;
        ctx.db.player().player_id().update(row);
        Ok::<(), String>(())
    })
    .unwrap();
}

fn seed_sold_natural_discovery(test: &TestContext, player_id: u64, species_id: u32, band: u8) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let definition = ctx
            .db
            .species_definition()
            .species_id()
            .find(species_id)
            .unwrap();
        let key = (u128::from(player_id) << 32) | u128::from(species_id);
        let mut rank_counts = vec![0; game_rules::RARITY_TIERS.len()];
        rank_counts[usize::from(band)] = 1;
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key,
            player_id,
            species_id,
            count: 1,
            rank_counts,
            first_caught_at: ctx.timestamp,
            best_length_mm: definition.typical_length_mm,
            best_weight_g: definition.typical_weight_g,
        });
        let mut player = ctx.db.player().player_id().find(player_id).unwrap();
        player.fish_count = 1;
        player.completed_casts = 1;
        ctx.db.player().player_id().update(player);
        let mut profile = ctx.db.public_profile().player_id().find(player_id).unwrap();
        profile.fish_count = 1;
        ctx.db.public_profile().player_id().update(profile);
        crate::records::refresh_player(ctx, player_id)
    })
    .unwrap();
}

fn set_stack(test: &TestContext, player_id: u64, slot: u32, item: &str, quantity: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = (u128::from(player_id) << 32) | u128::from(slot);
        if quantity == 0 {
            ctx.db.item_stack().key().delete(key);
        } else if let Some(mut row) = ctx.db.item_stack().key().find(key) {
            row.item = item.into();
            row.quantity = quantity;
            ctx.db.item_stack().key().update(row);
        } else {
            ctx.db.item_stack().insert(ItemStack {
                key,
                player_id,
                item: item.into(),
                quantity,
            });
        }
        Ok::<(), String>(())
    })
    .unwrap();
}

fn force_only_species_rank(test: &TestContext, species_id: u32, ordinal: u8) -> u32 {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let species = ctx
            .db
            .species_definition()
            .species_id()
            .find(species_id)
            .unwrap();
        let biome_id = species.biome_id;
        let mut biome = ctx.db.biome_definition().biome_id().find(biome_id).unwrap();
        biome.fish_weight = 1_000_000;
        biome.junk_weight = 0;
        biome.treasure_weight = 0;
        ctx.db.biome_definition().biome_id().update(biome);
        let ranks: Vec<_> = ctx
            .db
            .species_rank_definition()
            .biome_id()
            .filter(biome_id)
            .collect::<Vec<_>>()
            ;
        let needs_anchor = ordinal == 9 || species.key == "nidalees-lost-sock";
        let anchor = if needs_anchor {
            if species.key != "nidalees-lost-sock" && ordinal == 9 {
                ranks
                    .iter()
                    .find(|rank| rank.species_id == species_id && rank.ordinal == 0)
            } else {
                None
            }
            .or_else(|| {
                ranks.iter().find(|rank| {
                    rank.species_id != species_id
                        && rank.ordinal < 9
                        && ctx
                            .db
                            .species_definition()
                            .species_id()
                            .find(rank.species_id)
                            .is_some_and(|definition| definition.key != "nidalees-lost-sock")
                })
            })
                .map(|rank| (rank.species_id, rank.ordinal))
        } else {
            None
        };
        for mut rank in ranks {
            if rank.species_id == species_id && rank.ordinal == ordinal {
                rank.encounter_weight = 1000;
                ctx.db.species_rank_definition().key().update(rank);
            } else if anchor == Some((rank.species_id, rank.ordinal)) {
                rank.encounter_weight = 1;
                ctx.db.species_rank_definition().key().update(rank);
            } else {
                ctx.db.species_rank_definition().key().delete(rank.key);
            }
        }
        Ok::<_, String>(biome_id)
    })
    .unwrap()
}

fn set_biome_weights(test: &TestContext, biome_id: u32, fish: u64, junk: u64, treasure: u64) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut biome = ctx.db.biome_definition().biome_id().find(biome_id).unwrap();
        biome.fish_weight = fish;
        biome.junk_weight = junk;
        biome.treasure_weight = treasure;
        ctx.db.biome_definition().biome_id().update(biome);
        Ok::<(), String>(())
    })
    .unwrap();
}

fn enable_focus_with_mastery(
    test: &TestContext,
    player_id: u64,
    biome_id: u32,
    perfected_species: u32,
) {
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for species in ctx
            .db
            .species_definition()
            .biome_id()
            .filter(biome_id)
            .filter(|row| row.counts_for_ordinary_collection_completion)
        {
            let key = (u128::from(player_id) << 32) | u128::from(species.species_id);
            let rank_counts = if species.species_id == perfected_species {
                vec![1; 10]
            } else {
                vec![1, 0, 0, 0, 0, 0, 0, 0, 0, 0]
            };
            let row = PlayerSpeciesProgress {
                key,
                player_id,
                species_id: species.species_id,
                count: 1,
                rank_counts,
                first_caught_at: ctx.timestamp,
                best_length_mm: species.typical_length_mm,
                best_weight_g: species.typical_weight_g,
            };
            if ctx.db.player_species_progress().key().find(key).is_some() {
                ctx.db.player_species_progress().key().update(row);
            } else {
                ctx.db.player_species_progress().insert(row);
            }
        }
        let key = (u128::from(player_id) << 32) | u128::from(biome_id);
        ctx.db.collection_focus_preference().insert(CollectionFocusPreference {
            key,
            player_id,
            biome_id,
            enabled: true,
        });
        Ok::<(), String>(())
    })
    .unwrap();
}

fn cast_one(test: &TestContext, player_id: u64, interaction_id: u64, biome_id: u32) -> u32 {
    let bands: Vec<_> = test
        .db
        .species_rank_definition()
        .biome_id()
        .filter(biome_id)
        .collect();
    let target_index = bands
        .iter()
        .enumerate()
        .max_by_key(|(_, band)| band.encounter_weight)
        .map(|(index, _)| index)
        .unwrap();
    let tickets: Vec<_> = bands
        .iter()
        .map(|band| {
            let species = test
                .db
                .species_definition()
                .species_id()
                .find(band.species_id)
                .unwrap();
            (
                band.encounter_weight,
                if species.key == "nidalees-lost-sock" {
                    9
                } else {
                    band.ordinal
                },
            )
        })
        .collect();
    let weights = game_rules::rods::luck_weights(&tickets, 0).unwrap();
    let total = game_rules::sampling::total_weight(&weights).unwrap();
    let seed = (0..10_000u64)
        .find(|root_seed| {
            let mut source = StdRng::seed_from_u64(*root_seed);
            let reducer_seed = source.next_u64();
            let mut rng = StdRng::seed_from_u64(reducer_seed);
            let _category_roll = rng.gen_range(0..1_000_000u64);
            let rank_roll = rng.gen_range(0..total);
            game_rules::sampling::select_from_roll(&weights, rank_roll)
                .is_ok_and(|index| index == target_index)
        })
        .unwrap();
    test.rng.set_seed(seed);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let player = ctx.db.player().player_id().find(player_id).unwrap();
        let biome = ctx.db.biome_definition().biome_id().find(biome_id).unwrap();
        let rules = accounts::config(ctx);
        casting::resolve(
            ctx,
            CastPlan {
                player,
                biome,
                rules,
                interaction_id,
                guild_id: Some(7),
                channel_id: 8,
                command: "fish".into(),
                equipment_id: 1,
                quality_level: 0,
                power: 0,
                luck_bp: 0,
                xp_bonus_bp: 0,
                bait: UsedBaits {
                    primary: Default::default(),
                    secondary: Default::default(),
                    luck_bp: 0,
                    xp_bonus_bp: 0,
                    size_rolls: 1,
                },
                pull_count: 1,
                collection_focus_enabled: false,
                reinforcement_level: 0,
                special_raid_species_id: None,
                special_raid_species_definition: None,
                special_raid_rank_definition: None,
                special_raid_band: None,
                special_raid_maximum_roll_one_in: 0,
                content_version: crate::content::CONTENT_VERSION,
            },
        )?;
        ctx.db
            .command_receipt()
            .interaction_id()
            .find(interaction_id)
            .and_then(|receipt| receipt.species_id)
            .ok_or_else(|| "EXPECTED_FISH".to_string())
    })
    .unwrap()
}

fn last_attempt(test: &TestContext, player_id: u64) -> RaidAttempt {
    test.db
        .raid_attempt()
        .player_id()
        .filter(player_id)
        .max_by_key(|row| row.prepared_at)
        .unwrap()
}

fn submit_prepare(test: &TestContext, discord_id: u64, interaction_id: u64, species: u32, grade: u8) {
    select_discord_player(test, discord_id, interaction_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::prepare_raid_from_discord(ctx, discord_id, interaction_id, species, grade)
    })
    .unwrap();
}

fn submit_deploy(test: &TestContext, discord_id: u64, interaction_id: u64, attempt_id: u128) {
    select_discord_player(test, discord_id, interaction_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::deploy_raid_from_discord(ctx, discord_id, interaction_id, attempt_id)
    })
    .unwrap();
}

fn fire_job(test: &TestContext, attempt_id: u128, phase: &str) -> RaidJob {
    let job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == phase)
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, job.clone())
    })
    .unwrap();
    job
}

fn advance_to(test: &TestContext, timestamp: Timestamp) {
    test.clock.set(timestamp);
}

fn give_supply(test: &TestContext, player_id: u64, units: u64) {
    grant_item(test, player_id, 900, "raid_supplies", units);
}

fn complete_personal(
    test: &TestContext,
    discord_id: u64,
    species_id: u32,
    grade: u8,
    suffix: u64,
) -> RaidAttempt {
    let supply = game_rules::raids::grade(grade).unwrap().supply_units;
    let player_id = test
        .db
        .player()
        .discord_user_id()
        .find(discord_id)
        .unwrap()
        .player_id;
    give_supply(test, player_id, u64::from(supply));
    let prepare_id = snowflake(test, suffix);
    submit_prepare(test, discord_id, prepare_id, species_id, grade);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    let deploy_id = snowflake(test, suffix + 1);
    submit_deploy(test, discord_id, deploy_id, attempt_id);
    let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
    advance_to(test, attempt.starts_at);
    fire_job(test, attempt_id, "start");
    advance_to(test, attempt.ends_at);
    fire_job(test, attempt_id, "finish");
    test.db.raid_attempt().id().find(attempt_id).unwrap()
}

fn root_seed_for_special_roll(maximum: bool) -> u64 {
    (0..100_000u64)
        .find(|root_seed| {
            let mut source = StdRng::seed_from_u64(*root_seed);
            let reducer_seed = source.next_u64();
            let mut reducer_rng = StdRng::seed_from_u64(reducer_seed);
            (reducer_rng.gen_range(0..5000u32) == 0) == maximum
        })
        .expect("a deterministic test seed exists for either special outcome")
}

fn complete_special(
    test: &TestContext,
    discord_id: u64,
    species_id: u32,
    suffix: u64,
    maximum_seed: u64,
) -> RaidAttempt {
    let player_id = test
        .db
        .player()
        .discord_user_id()
        .find(discord_id)
        .unwrap()
        .player_id;
    give_supply(test, player_id, 20);
    let prepare_id = snowflake(test, suffix);
    submit_prepare(test, discord_id, prepare_id, species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    let deploy_id = snowflake(test, suffix + 1);
    submit_deploy(test, discord_id, deploy_id, attempt_id);
    let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
    advance_to(test, attempt.starts_at);
    fire_job(test, attempt_id, "start");
    let finish_job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == "finish")
        .unwrap();
    advance_to(test, attempt.ends_at);
    test.rng.set_seed(maximum_seed);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, finish_job)
    })
    .unwrap();
    test.db.raid_attempt().id().find(attempt_id).unwrap()
}

#[test]
fn personal_f_raid_deploys_settles_and_replays_without_duplicate_rewards() {
    let test = setup();
    let discord_id = 1001;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| {
            row.counts_for_ordinary_collection_completion
                && row.allowed_rarities.iter().any(|rarity| rarity == "F")
        })
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    let natural_interaction = 10001;
    assert_eq!(
        cast_one(&test, player_id, natural_interaction, biome_id),
        ordinary.species_id
    );
    give_supply(&test, player_id, 1);
    let before = test.db.player().player_id().find(player_id).unwrap();

    let locked_id = snowflake(&test, 10);
    select_discord_player(&test, discord_id, locked_id);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_raid_from_discord(
                ctx,
                discord_id,
                locked_id,
                ordinary.species_id,
                1,
            )
        })
        .unwrap_err(),
        "RAID_GRADE_LOCKED"
    );
    assert_eq!(test.db.raid_attempt().player_id().filter(player_id).count(), 0);

    let prepare_id = snowflake(&test, 11);
    submit_prepare(&test, discord_id, prepare_id, ordinary.species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    let prepared = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(prepared.status, "prepared");
    submit_prepare(&test, discord_id, prepare_id, ordinary.species_id, 0);
    let pending_other = snowflake(&test, 14);
    select_discord_player(&test, discord_id, pending_other);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_raid_from_discord(
                ctx,
                discord_id,
                pending_other,
                ordinary.species_id,
                0,
            )
        })
        .unwrap_err(),
        "RAID_ALREADY_ACTIVE"
    );
    select_discord_player(&test, discord_id, prepare_id);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_raid_from_discord(
                ctx,
                discord_id,
                prepare_id,
                ordinary.species_id,
                1,
            )
        })
        .unwrap_err(),
        "RAID_REQUEST_CONFLICT"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        1
    );

    let untrusted_start = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == "start")
        .unwrap();
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::run_raid_job(ctx, untrusted_start)
        })
        .unwrap_err(),
        "SCHEDULER_REQUIRED"
    );
    select_discord_player(&test, discord_id, prepare_id);
    assert!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::deploy_raid_from_discord(ctx, discord_id, prepare_id, attempt_id)
        })
        .unwrap_err()
            == "RAID_REQUEST_CONFLICT"
    );
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "prepared"
    );

    let deploy_id = snowflake(&test, 12);
    submit_deploy(&test, discord_id, deploy_id, attempt_id);
    submit_deploy(&test, discord_id, deploy_id, attempt_id);
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "deployed"
    );
    assert!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .is_none()
    );

    let starts_at = test.db.raid_attempt().id().find(attempt_id).unwrap().starts_at;
    advance_to(&test, starts_at);
    let start_job = fire_job(&test, attempt_id, "start");
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "running"
    );
    let ends_at = test.db.raid_attempt().id().find(attempt_id).unwrap().ends_at;
    advance_to(&test, ends_at);
    let finish_job = fire_job(&test, attempt_id, "finish");
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, start_job)
    })
    .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, finish_job)
    })
    .unwrap();

    let completed = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(completed.status, "completed");
    let delivery = test.db.raid_delivery().attempt_id().find(attempt_id).unwrap();
    assert_eq!(test.db.raid_delivery().count(), 1);
    assert_eq!(delivery.kind, "personal");
    assert_eq!(delivery.target_name, ordinary.name);
    assert_eq!(delivery.species_asset, ordinary.sprite_asset);
    assert_eq!(delivery.grade, Some(0));
    assert!(delivery.first_clear);
    assert_eq!((delivery.awarded_fillets, delivery.awarded_scales), (completed.awarded_fillets, completed.awarded_scales));
    assert!(delivery.special_catch.is_none());
    assert_eq!(completed.awarded_fillets, completed.full_fillets);
    assert_eq!(completed.awarded_scales, completed.full_scales);
    assert!(completed.full_fillets >= completed.base_fillets);
    assert!(completed.full_scales >= completed.base_scales);
    assert!(completed.full_fillets <= completed.maximum_fillets);
    assert!(completed.full_scales <= completed.maximum_scales);
    assert!(completed.awarded_fillets <= completed.maximum_fillets);
    assert!(completed.awarded_scales <= completed.maximum_scales);
    let badge = test
        .db
        .raid_badge()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(ordinary.species_id))
        .unwrap();
    assert_eq!(badge.cleared_grades, 1);
    assert_eq!(badge.successful_clears, 1);
    let stats = test.db.raid_statistics().player_id().find(player_id).unwrap();
    assert_eq!(stats.personal_completions, 1);
    assert_eq!(stats.mastered_bosses, 0);

    let claim_id = snowflake(&test, 13);
    select_discord_player(&test, discord_id, claim_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::claim_raid_from_discord(ctx, discord_id, claim_id, attempt_id)
    })
    .unwrap();
    let claim_before_replay = test.db.raid_statistics().player_id().find(player_id).unwrap();
    select_discord_player(&test, discord_id, claim_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::claim_raid_from_discord(ctx, discord_id, claim_id, attempt_id)
    })
    .unwrap();
    let claim_after_replay = test.db.raid_statistics().player_id().find(player_id).unwrap();
    assert_eq!(claim_after_replay.personal_completions, claim_before_replay.personal_completions);

    let after = test.db.player().player_id().find(player_id).unwrap();
    assert_eq!(
        (after.fish_count, after.junk_count, after.treasure_count, after.total_xp, after.coins, after.completed_casts, after.next_cast_at),
        (before.fish_count, before.junk_count, before.treasure_count, before.total_xp, before.coins, before.completed_casts, before.next_cast_at)
    );
    let book = test.db.angler_statistics().player_id().find(player_id).unwrap();
    assert_eq!(book.bonus_pulls, 0);
    assert_eq!(book.bonus_fish + book.bonus_junk + book.bonus_treasure, 0);

    let repeat = complete_personal(&test, discord_id, ordinary.species_id, 0, 20);
    assert_eq!(repeat.status, "completed");
    assert!(!test.db.raid_delivery().attempt_id().find(repeat.id).unwrap().first_clear);
    let badge = test
        .db
        .raid_badge()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(ordinary.species_id))
        .unwrap();
    assert_eq!(badge.cleared_grades, 1);
    assert_eq!(badge.successful_clears, 2);
    assert_eq!(
        test.db.raid_statistics().player_id().find(player_id).unwrap().personal_completions,
        2
    );

    let next_grade_id = snowflake(&test, 30);
    submit_prepare(&test, discord_id, next_grade_id, ordinary.species_id, 1);
    let next_grade_attempt = (u128::from(player_id) << 64) | u128::from(next_grade_id);
    assert_eq!(
        test.db
            .raid_attempt()
            .id()
            .find(next_grade_attempt)
            .unwrap()
            .grade,
        Some(1)
    );
}

#[test]
fn ordinary_personal_raid_requires_collection_discovery_without_side_effects() {
    let test = setup();
    let discord_id = 1202;
    let player_id = player(&test, discord_id);
    let species_id = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap()
        .species_id;
    give_supply(&test, player_id, 20);
    let request_id = snowflake(&test, 191);
    select_discord_player(&test, discord_id, request_id);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_raid_from_discord(ctx, discord_id, request_id, species_id, 0)
        })
        .unwrap_err(),
        "RAID_DISCOVERY_REQUIRED"
    );
    assert_eq!(test.db.raid_attempt().player_id().filter(player_id).count(), 0);
    assert_eq!(test.db.raid_action_receipt().player_id().filter(player_id).count(), 0);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        20
    );
}

#[test]
fn claim_settles_a_due_raid_when_the_scheduler_has_not_run() {
    let test = setup();
    let discord_id = 1051;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 10501, biome_id);
    give_supply(&test, player_id, 1);
    let prepare = snowflake(&test, 15);
    submit_prepare(&test, discord_id, prepare, ordinary.species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare);
    let deploy = snowflake(&test, 16);
    submit_deploy(&test, discord_id, deploy, attempt_id);
    let start_job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == "start")
        .unwrap();
    let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
    advance_to(&test, attempt.ends_at);
    let claim = snowflake(&test, 17);
    select_discord_player(&test, discord_id, claim);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::claim_raid_from_discord(ctx, discord_id, claim, attempt_id)
    })
    .unwrap();
    let completed = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(completed.status, "completed");
    assert_eq!(completed.started_at, Some(attempt.starts_at));
    assert_eq!(completed.awarded_fillets, completed.full_fillets);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, start_job)
    })
    .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "completed"
    );
    assert_eq!(test.db.raid_badge().player_id().filter(player_id).next().unwrap().successful_clears, 1);
}

#[test]
fn prepared_raids_do_not_hold_boats_but_deployed_raids_enforce_player_wide_exclusion() {
    let test = setup();
    let discord_id = 1101;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 11001, biome_id);
    give_supply(&test, player_id, 1);
    let prepare_id = snowflake(&test, 101);
    submit_prepare(&test, discord_id, prepare_id, ordinary.species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);

    let boat_haul_id = snowflake(&test, 102);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        hauls::start_haul_from_discord(
            ctx,
            discord_id,
            boat_haul_id,
            Some(7),
            8,
            "boat".into(),
        )
    })
    .unwrap();
    let blocked_deploy_id = snowflake(&test, 103);
    select_discord_player(&test, discord_id, blocked_deploy_id);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::deploy_raid_from_discord(ctx, discord_id, blocked_deploy_id, attempt_id)
        })
        .unwrap_err(),
        "BOAT_HAUL_ACTIVE"
    );
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "prepared"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        1
    );

    let missed_job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == "start")
        .unwrap();
    advance_to(&test, test.db.raid_attempt().id().find(attempt_id).unwrap().starts_at);
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, missed_job))
        .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "missed"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        1
    );

    let second_discord = 1102;
    let second_player = player(&test, second_discord);
    let second_fish = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let second_biome = force_only_species_rank(&test, second_fish.species_id, 0);
    assert_eq!(
        cast_one(&test, second_player, 11002, second_biome),
        second_fish.species_id
    );
    let second_prepare = snowflake(&test, 104);
    submit_prepare(&test, second_discord, second_prepare, second_fish.species_id, 0);
    let second_attempt = (u128::from(second_player) << 64) | u128::from(second_prepare);
    give_supply(&test, second_player, 10);
    let deploy = snowflake(&test, 105);
    submit_deploy(&test, second_discord, deploy, second_attempt);

    let other_boat = test
        .db
        .haul_equipment_definition()
        .iter()
        .find(|row| row.kind == "boat" && row.equipment_id != 101)
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = (u128::from(second_player) << 32) | u128::from(other_boat.equipment_id);
        ctx.db.owned_haul_equipment().insert(OwnedHaulEquipment {
            key,
            player_id: second_player,
            equipment_id: other_boat.equipment_id,
            upgrade_level: 0,
        });
        Ok::<(), String>(())
    })
    .unwrap();
    link_browser(&test, second_player, "second-browser");
    test.with_reducer_tx(auth("second-browser"), |ctx| {
        crate::haul_equipment::equip_haul_equipment(ctx, other_boat.equipment_id)
    })
    .unwrap();
    let rejected_boat_haul = snowflake(&test, 106);
    assert!(test
        .with_reducer_tx(auth("raid-bot"), |ctx| {
            hauls::start_haul_from_discord(
                ctx,
                second_discord,
                rejected_boat_haul,
                Some(7),
                8,
                "boat".into(),
            )
        })
        .is_err());
    let net_haul = snowflake(&test, 107);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        hauls::start_haul_from_discord(
            ctx,
            second_discord,
            net_haul,
            Some(7),
            8,
            "net".into(),
        )
    })
    .unwrap();

    let third_discord = 1103;
    player(&test, third_discord);
    let outsider_action = snowflake(&test, 108);
    select_discord_player(&test, third_discord, outsider_action);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::claim_raid_from_discord(
                ctx,
                third_discord,
                outsider_action,
                second_attempt,
            )
        })
        .unwrap_err(),
        "RAID_ATTEMPT_UNAVAILABLE"
    );
    let outsider_cancel = snowflake(&test, 109);
    select_discord_player(&test, third_discord, outsider_cancel);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::cancel_raid_from_discord(
                ctx,
                third_discord,
                outsider_cancel,
                second_attempt,
            )
        })
        .unwrap_err(),
        "RAID_ATTEMPT_UNAVAILABLE"
    );
    assert_eq!(
        test.db.raid_attempt().id().find(second_attempt).unwrap().status,
        "deployed"
    );
}

#[test]
fn raid_supply_shop_quantity_uses_the_existing_quote_path_without_bait_stacks() {
    let test = setup();
    let player_id = player(&test, 1201);
    link_browser(&test, player_id, "supply-browser");
    add_coins(&test, player_id, 20_000);
    let listing = test.db.shop_listing().listing_id().find(150).unwrap();
    assert_eq!(listing.kind, "raid_supply");
    assert_eq!(listing.target_id, 900);
    assert!(
        test.db
            .bait_definition()
            .iter()
            .all(|bait| bait.resource_item != "raid_supplies")
    );
    let identity = Identity::from_claims("test", "supply-browser");

    for (quantity, expected_units, expected_coins) in [(1, 5, 100), (5, 30, 600), (10, 80, 1600)] {
        if quantity != 1 {
            test.clock.advance(TimeDuration::from_micros(1_000_001));
        }
        test.with_reducer_tx(auth("supply-browser"), |ctx| {
            crate::shop::prepare_shop_purchase(ctx, 150)
        })
        .unwrap();
        let quote = test
            .db
            .shop_quote()
            .key()
            .find(format!("{identity}:{player_id}"))
            .unwrap();
        test.with_reducer_tx(auth("supply-browser"), |ctx| {
            crate::shop::commit_shop_purchase_quantity(ctx, quote.nonce, quantity)
        })
        .unwrap();
        let supply = test
            .db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap();
        assert_eq!(supply.item, "raid_supplies");
        assert_eq!(supply.quantity, expected_units);
        assert_eq!(
            test.db.player().player_id().find(player_id).unwrap().coins,
            20_000 - expected_coins
        );
    }
    assert_eq!(test.db.bait_stack().player_id().filter(player_id).count(), 0);
    assert!(test.db.bait_loadout().player_id().find(player_id).is_none());
}

#[test]
fn refining_and_per_rod_reinforcement_validate_unlocks_costs_and_stale_levels() {
    let test = setup();
    let discord_id = 1301;
    let player_id = player(&test, discord_id);
    link_browser(&test, player_id, "reinforce-browser");
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 13001, biome_id);
    let browser = "reinforce-browser";

    fn refine_call(
        test: &TestContext,
        browser: &str,
        request_id: u64,
        material: u8,
        from_grade: u8,
        batches: u32,
    ) -> Result<(), String> {
        test.with_reducer_tx(auth(browser), |ctx| {
            raids::refine_raid_material(ctx, request_id, material, from_grade, batches)
        })
    }
    fn reinforce_call(
        test: &TestContext,
        browser: &str,
        request_id: u64,
        rod: u32,
        expected: u8,
    ) -> Result<(), String> {
        test.with_reducer_tx(auth(browser), |ctx| {
            raids::reinforce_rod(ctx, request_id, rod, expected)
        })
    }

    let f_lock = browser_request(&test, 1);
    assert_eq!(
        refine_call(&test, &browser, f_lock, 0, 0, 1).unwrap_err(),
        "RAID_GRADE_NOT_CLEARED"
    );
    let no_material = browser_request(&test, 2);
    assert_eq!(
        refine_call(&test, &browser, no_material, 0, 0, 0).unwrap_err(),
        "RAID_REFINEMENT_INVALID"
    );
    let too_many = browser_request(&test, 3);
    assert_eq!(
        refine_call(&test, &browser, too_many, 0, 0, 101).unwrap_err(),
        "RAID_REFINEMENT_INVALID"
    );
    let no_next = browser_request(&test, 4);
    assert_eq!(
        refine_call(&test, &browser, no_next, 0, 9, 1).unwrap_err(),
        "RAID_REFINEMENT_INVALID"
    );

    complete_personal(&test, discord_id, ordinary.species_id, 0, 100);
    let locked_d = browser_request(&test, 5);
    assert_eq!(
        refine_call(&test, &browser, locked_d, 0, 0, 1).unwrap_err(),
        "RAID_GRADE_NOT_CLEARED"
    );
    complete_personal(&test, discord_id, ordinary.species_id, 1, 110);
    assert_eq!(test.db.raid_badge().player_id().filter(player_id).next().unwrap().cleared_grades, 2);
    add_coins(&test, player_id, 1000);

    let (f_slot, f_item) = game_rules::raids::material(0, 0).unwrap();
    let (d_slot, _d_item) = game_rules::raids::material(1, 0).unwrap();
    set_stack(&test, player_id, f_slot, &f_item, 59);
    let coins_before = test.db.player().player_id().find(player_id).unwrap().coins;
    let insufficient = browser_request(&test, 6);
    assert_eq!(
        refine_call(&test, &browser, insufficient, 0, 0, 1).unwrap_err(),
        "RAID_MATERIALS_REQUIRED"
    );
    assert_eq!(test.db.player().player_id().find(player_id).unwrap().coins, coins_before);
    assert_eq!(
        test.db.item_stack().key().find((u128::from(player_id) << 32) | u128::from(f_slot)).unwrap().quantity,
        59
    );
    grant_item(&test, player_id, f_slot, &f_item, 1);
    set_coins(&test, player_id, 4);
    let insufficient_coins = browser_request(&test, 7);
    assert_eq!(
        refine_call(&test, &browser, insufficient_coins, 0, 0, 1).unwrap_err(),
        "INSUFFICIENT_COINS"
    );
    assert_eq!(test.db.player().player_id().find(player_id).unwrap().coins, 4);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(f_slot))
            .unwrap()
            .quantity,
        60
    );
    set_coins(&test, player_id, 1000);
    let coins_before_refine = test.db.player().player_id().find(player_id).unwrap().coins;
    let existing_d_before = test
        .db
        .item_stack()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(d_slot))
        .map_or(0, |row| row.quantity);
    let refine = browser_request(&test, 8);
    refine_call(&test, &browser, refine, 0, 0, 1).unwrap();
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        coins_before_refine - 5
    );
    assert!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(f_slot))
            .is_none()
    );
    let existing_d_after = test
        .db
        .item_stack()
        .key()
        .find((u128::from(player_id) << 32) | u128::from(d_slot))
        .map_or(0, |row| row.quantity);
    assert_eq!(existing_d_after, existing_d_before + 50);
    let post_refine_coins = test.db.player().player_id().find(player_id).unwrap().coins;
    refine_call(&test, &browser, refine, 0, 0, 1).unwrap();
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        post_refine_coins
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(d_slot))
            .unwrap()
            .quantity,
        existing_d_after
    );
    assert_eq!(
        test.db
            .economy_ledger()
            .player_id()
            .filter(player_id)
            .filter(|row| row.reason == "raid_refining" && row.item == "coins")
            .map(|row| row.delta)
            .sum::<i64>(),
        -5
    );

    let unowned_rod = browser_request(&test, 9);
    assert_eq!(
        reinforce_call(&test, &browser, unowned_rod, u32::MAX, 0).unwrap_err(),
        "ROD_NOT_OWNED"
    );
    let (f_fillets_slot, f_fillets) = game_rules::raids::material(0, 0).unwrap();
    let (f_scales_slot, f_scales) = game_rules::raids::material(0, 1).unwrap();
    set_stack(&test, player_id, f_fillets_slot, &f_fillets, 60);
    set_stack(&test, player_id, f_scales_slot, &f_scales, 40);
    let rod = test.db.owned_rod().player_id().filter(player_id).next().unwrap();
    let quality_before = rod.upgrade_level;
    set_coins(&test, player_id, 49);
    let insufficient_reinforce_coins = browser_request(&test, 10);
    assert_eq!(
        reinforce_call(&test, &browser, insufficient_reinforce_coins, rod.rod_id, 0)
            .unwrap_err(),
        "INSUFFICIENT_COINS"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(f_fillets_slot))
            .unwrap()
            .quantity,
        60
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(f_scales_slot))
            .unwrap()
            .quantity,
        40
    );
    assert!(test.db.rod_reinforcement().key().find(rod.key).is_none());
    set_coins(&test, player_id, 1000);
    let coins_before_reinforce = test.db.player().player_id().find(player_id).unwrap().coins;
    let reinforce_f = browser_request(&test, 11);
    reinforce_call(&test, &browser, reinforce_f, rod.rod_id, 0).unwrap();
    assert_eq!(test.db.rod_reinforcement().key().find(rod.key).unwrap().level, 1);
    assert_eq!(test.db.owned_rod().key().find(rod.key).unwrap().upgrade_level, quality_before);
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        coins_before_reinforce - 50
    );
    let post_reinforce_coins = test.db.player().player_id().find(player_id).unwrap().coins;
    reinforce_call(&test, &browser, reinforce_f, rod.rod_id, 0).unwrap();
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        post_reinforce_coins
    );
    let stale = browser_request(&test, 12);
    let stale_coins = test.db.player().player_id().find(player_id).unwrap().coins;
    assert_eq!(
        reinforce_call(&test, &browser, stale, rod.rod_id, 0).unwrap_err(),
        "REINFORCEMENT_LEVEL_CHANGED"
    );
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        stale_coins
    );

    let (d_fillets_slot, d_fillets) = game_rules::raids::material(1, 0).unwrap();
    let (d_scales_slot, d_scales) = game_rules::raids::material(1, 1).unwrap();
    let d_grade = game_rules::raids::grade(1).unwrap();
    grant_item(&test, player_id, d_fillets_slot, &d_fillets, d_grade.fillets);
    grant_item(&test, player_id, d_scales_slot, &d_scales, d_grade.scales);
    let next_rod_level = browser_request(&test, 13);
    reinforce_call(&test, &browser, next_rod_level, rod.rod_id, 1).unwrap();
    assert_eq!(test.db.rod_reinforcement().key().find(rod.key).unwrap().level, 2);
    assert_eq!(test.db.owned_rod().key().find(rod.key).unwrap().upgrade_level, quality_before);

    let other_rod = test
        .db
        .rod_definition()
        .iter()
        .find(|row| row.rod_id != rod.rod_id)
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let key = (u128::from(player_id) << 32) | u128::from(other_rod.rod_id);
        ctx.db.owned_rod().insert(OwnedRod {
            key,
            player_id,
            rod_id: other_rod.rod_id,
            upgrade_level: 0,
        });
        Ok::<(), String>(())
    })
    .unwrap();
    grant_item(&test, player_id, f_fillets_slot, &f_fillets, 60);
    grant_item(&test, player_id, f_scales_slot, &f_scales, 40);
    let independent = browser_request(&test, 14);
    reinforce_call(&test, &browser, independent, other_rod.rod_id, 0).unwrap();
    assert_eq!(
        test.db
            .rod_reinforcement()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(other_rod.rod_id))
            .unwrap()
            .level,
        1
    );
    assert_eq!(test.db.rod_reinforcement().key().find(rod.key).unwrap().level, 2);
}

#[test]
fn community_raids_require_deployment_and_award_only_one_completion_stat() {
    let test = setup();
    let now = test.clock.now();
    let event = test
        .db
        .community_raid_event()
        .iter()
        .find(|event| event.signup_at <= now && now < event.starts_at)
        .unwrap();
    let discord_id = 1401;
    let player_id = player(&test, discord_id);
    give_supply(&test, player_id, 20);
    let player_before = test.db.player().player_id().find(player_id).unwrap();
    let prepare_id = snowflake(&test, 201);
    select_discord_player(&test, discord_id, prepare_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::prepare_community_raid_from_discord(ctx, discord_id, prepare_id, event.event_id)
    })
    .unwrap();
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "prepared"
    );
    let competing = snowflake(&test, 202);
    select_discord_player(&test, discord_id, competing);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_raid_from_discord(
                ctx,
                discord_id,
                competing,
                1,
                0,
            )
        })
        .unwrap_err(),
        "RAID_ALREADY_ACTIVE"
    );
    let cancel_id = snowflake(&test, 210);
    select_discord_player(&test, discord_id, cancel_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::cancel_raid_from_discord(ctx, discord_id, cancel_id, attempt_id)
    })
    .unwrap();
    assert_eq!(test.db.raid_attempt().id().find(attempt_id).unwrap().status, "cancelled");
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        20
    );
    let prepare_id = snowflake(&test, 211);
    select_discord_player(&test, discord_id, prepare_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::prepare_community_raid_from_discord(
            ctx,
            discord_id,
            prepare_id,
            event.event_id,
        )
    })
    .unwrap();
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    let deploy_id = snowflake(&test, 203);
    submit_deploy(&test, discord_id, deploy_id, attempt_id);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        10
    );

    advance_to(&test, event.starts_at);
    fire_job(&test, attempt_id, "start");
    advance_to(&test, event.ends_at);
    let finish = test
        .db
        .raid_job()
        .attempt_id()
        .filter(attempt_id)
        .find(|job| job.phase == "finish")
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, finish.clone()))
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, finish))
        .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "completed"
    );
    let delivery = test.db.raid_delivery().attempt_id().find(attempt_id).unwrap();
    assert_eq!(delivery.kind, "community");
    assert_eq!(delivery.target_name, event.name);
    assert!(delivery.species_asset.is_empty());
    assert!(!delivery.first_clear);
    assert!(delivery.special_catch.is_none());
    assert_eq!((delivery.awarded_fillets, delivery.awarded_scales), (0, 0));
    let stats = test.db.raid_statistics().player_id().find(player_id).unwrap();
    assert_eq!(stats.community_completions, 1);
    assert_eq!(stats.personal_completions, 0);
    assert_eq!(stats.special_completions, 0);
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().completed_casts,
        player_before.completed_casts
    );
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().total_xp,
        player_before.total_xp
    );
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().coins,
        player_before.coins
    );
    assert_eq!(test.db.owned_specimen().player_id().filter(player_id).count(), 0);
    assert_eq!(test.db.raid_badge().player_id().filter(player_id).count(), 0);
    let duplicate = snowflake(&test, 204);
    select_discord_player(&test, discord_id, duplicate);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_community_raid_from_discord(
                ctx,
                discord_id,
                duplicate,
                event.event_id,
            )
        })
        .unwrap_err(),
        "COMMUNITY_RAID_ALREADY_JOINED"
    );

    let missed_test = setup();
    let signup_event = missed_test
        .db
        .community_raid_event()
        .iter()
        .find(|event| {
            event.signup_at <= missed_test.clock.now()
                && missed_test.clock.now() < event.starts_at
        })
        .unwrap();
    let missed_discord = 1402;
    let missed_player = player(&missed_test, missed_discord);
    give_supply(&missed_test, missed_player, 20);
    let missed_prepare = snowflake(&missed_test, 205);
    select_discord_player(&missed_test, missed_discord, missed_prepare);
    missed_test
        .with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_community_raid_from_discord(
                ctx,
                missed_discord,
                missed_prepare,
                signup_event.event_id,
            )
        })
        .unwrap();
    let missed_id = (u128::from(missed_player) << 64) | u128::from(missed_prepare);
    advance_to(&missed_test, signup_event.starts_at);
    let late_deploy = snowflake(&missed_test, 206);
    select_discord_player(&missed_test, missed_discord, late_deploy);
    missed_test
        .with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::deploy_raid_from_discord(ctx, missed_discord, late_deploy, missed_id)
        })
        .unwrap();
    assert_eq!(
        missed_test
            .db
            .raid_attempt()
            .id()
            .find(missed_id)
            .unwrap()
            .status,
        "missed"
    );
    assert_eq!(
        missed_test
            .db
            .item_stack()
            .key()
            .find((u128::from(missed_player) << 32) | 900)
            .unwrap()
            .quantity,
        20
    );
    assert!(missed_test
        .db
        .raid_statistics()
        .player_id()
        .find(missed_player)
        .is_none());

    let cancel_test = setup();
    let cancel_event = cancel_test
        .db
        .community_raid_event()
        .iter()
        .find(|event| {
            event.signup_at <= cancel_test.clock.now()
                && cancel_test.clock.now() < event.starts_at
        })
        .unwrap();
    let cancel_discord = 1403;
    let cancel_player = player(&cancel_test, cancel_discord);
    give_supply(&cancel_test, cancel_player, 10);
    let cancel_prepare = snowflake(&cancel_test, 207);
    select_discord_player(&cancel_test, cancel_discord, cancel_prepare);
    cancel_test
        .with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_community_raid_from_discord(
                ctx,
                cancel_discord,
                cancel_prepare,
                cancel_event.event_id,
            )
        })
        .unwrap();
    let cancel_attempt = (u128::from(cancel_player) << 64) | u128::from(cancel_prepare);
    let cancel_deploy = snowflake(&cancel_test, 208);
    submit_deploy(&cancel_test, cancel_discord, cancel_deploy, cancel_attempt);
    advance_to(&cancel_test, cancel_event.starts_at);
    fire_job(&cancel_test, cancel_attempt, "start");
    advance_to(
        &cancel_test,
        cancel_event.starts_at + TimeDuration::from_micros(24 * 60 * 60 * 1_000_000),
    );
    let cancel_id = snowflake(&cancel_test, 209);
    select_discord_player(&cancel_test, cancel_discord, cancel_id);
    cancel_test
        .with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::cancel_raid_from_discord(ctx, cancel_discord, cancel_id, cancel_attempt)
        })
        .unwrap();
    assert_eq!(
        cancel_test
            .db
            .raid_attempt()
            .id()
            .find(cancel_attempt)
            .unwrap()
            .status,
        "cancelled"
    );
    assert!(cancel_test
        .db
        .raid_statistics()
        .player_id()
        .find(cancel_player)
        .is_none());
    assert!(
        cancel_test
            .db
            .item_stack()
            .key()
            .find((u128::from(cancel_player) << 32) | 900)
            .is_none()
    );
}

#[test]
fn failed_deployment_missed_attempts_and_partial_withdrawal_never_award_badges() {
    let test = setup();
    let discord_id = 1501;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 15001, biome_id);
    let player_before = test.db.player().player_id().find(player_id).unwrap();
    let prepare_id = snowflake(&test, 301);
    submit_prepare(&test, discord_id, prepare_id, ordinary.species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare_id);
    let failed_deploy = snowflake(&test, 302);
    select_discord_player(&test, discord_id, failed_deploy);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::deploy_raid_from_discord(ctx, discord_id, failed_deploy, attempt_id)
        })
        .unwrap_err(),
        "RAID_MATERIALS_REQUIRED"
    );
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "prepared"
    );
    assert!(
        test.db
            .raid_action_receipt()
            .key()
            .find((u128::from(player_id) << 64) | u128::from(failed_deploy))
            .is_none()
    );

    give_supply(&test, player_id, 1);
    let deploy_id = snowflake(&test, 303);
    submit_deploy(&test, discord_id, deploy_id, attempt_id);
    let start_at = test.db.raid_attempt().id().find(attempt_id).unwrap().starts_at;
    advance_to(&test, start_at);
    fire_job(&test, attempt_id, "start");
    let running = test.db.raid_attempt().id().find(attempt_id).unwrap();
    let half_micros =
        i64::from(running.duration_seconds) * game_rules::raids::MICROS_PER_SECOND / 2;
    advance_to(&test, start_at + TimeDuration::from_micros(half_micros));
    let cancel_id = snowflake(&test, 304);
    select_discord_player(&test, discord_id, cancel_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::cancel_raid_from_discord(ctx, discord_id, cancel_id, attempt_id)
    })
    .unwrap();
    let cancelled = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(cancelled.status, "cancelled");
    assert!(test.db.raid_delivery().attempt_id().find(attempt_id).is_none());
    let expected_fillets = u64::try_from(
        u128::from(cancelled.full_fillets)
            * u128::try_from(half_micros).unwrap()
            / (u128::from(cancelled.duration_seconds) * 1_000_000),
    )
    .unwrap()
    .min(cancelled.maximum_fillets);
    let expected_scales = u64::try_from(
        u128::from(cancelled.full_scales)
            * u128::try_from(half_micros).unwrap()
            / (u128::from(cancelled.duration_seconds) * 1_000_000),
    )
    .unwrap()
    .min(cancelled.maximum_scales);
    assert_eq!(cancelled.awarded_fillets, expected_fillets);
    assert_eq!(cancelled.awarded_scales, expected_scales);
    assert!(cancelled.awarded_fillets <= cancelled.maximum_fillets);
    assert!(cancelled.awarded_scales <= cancelled.maximum_scales);
    assert!(
        test.db
            .raid_badge()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(ordinary.species_id))
            .is_none()
    );
    let player_after = test.db.player().player_id().find(player_id).unwrap();
    assert_eq!(
        (player_after.fish_count, player_after.completed_casts, player_after.total_xp, player_after.coins, player_after.next_cast_at),
        (player_before.fish_count, player_before.completed_casts, player_before.total_xp, player_before.coins, player_before.next_cast_at)
    );

    let missed_discord = 1502;
    let missed_player = player(&test, missed_discord);
    let missed_species = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let missed_biome = force_only_species_rank(&test, missed_species.species_id, 0);
    cast_one(&test, missed_player, 15002, missed_biome);
    give_supply(&test, missed_player, 1);
    let missed_prepare = snowflake(&test, 305);
    submit_prepare(
        &test,
        missed_discord,
        missed_prepare,
        missed_species.species_id,
        0,
    );
    let missed_attempt = (u128::from(missed_player) << 64) | u128::from(missed_prepare);
    let missed_start = test
        .db
        .raid_attempt()
        .id()
        .find(missed_attempt)
        .unwrap()
        .starts_at;
    let missed_job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(missed_attempt)
        .find(|job| job.phase == "start")
        .unwrap();
    advance_to(&test, missed_start);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, missed_job)
    })
    .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(missed_attempt).unwrap().status,
        "missed"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(missed_player) << 32) | 900)
            .unwrap()
            .quantity,
        1
    );
    assert!(test
        .db
        .raid_statistics()
        .player_id()
        .find(missed_player)
        .is_none());
}

#[test]
fn deployed_community_raid_can_rejoin_before_signup_closes_without_supply_refund() {
    let test = setup();
    let now = test.clock.now();
    let event = test
        .db
        .community_raid_event()
        .iter()
        .find(|event| event.signup_at <= now && now < event.starts_at)
        .unwrap();
    let discord_id = 1403;
    let player_id = player(&test, discord_id);
    give_supply(&test, player_id, 20);

    let first_prepare = snowflake(&test, 301);
    select_discord_player(&test, discord_id, first_prepare);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::prepare_community_raid_from_discord(
            ctx,
            discord_id,
            first_prepare,
            event.event_id,
        )
    })
    .unwrap();
    let first_attempt = (u128::from(player_id) << 64) | u128::from(first_prepare);
    let first_deploy = snowflake(&test, 302);
    submit_deploy(&test, discord_id, first_deploy, first_attempt);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        10
    );
    let cancel = snowflake(&test, 303);
    select_discord_player(&test, discord_id, cancel);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::cancel_raid_from_discord(ctx, discord_id, cancel, first_attempt)
    })
    .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(first_attempt).unwrap().status,
        "cancelled"
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        10
    );

    let second_prepare = snowflake(&test, 304);
    select_discord_player(&test, discord_id, second_prepare);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::prepare_community_raid_from_discord(
            ctx,
            discord_id,
            second_prepare,
            event.event_id,
        )
    })
    .unwrap();
    let second_attempt = (u128::from(player_id) << 64) | u128::from(second_prepare);
    let second_deploy = snowflake(&test, 305);
    submit_deploy(&test, discord_id, second_deploy, second_attempt);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .map(|stack| stack.quantity)
            .unwrap_or(0),
        0
    );

    advance_to(&test, event.starts_at);
    fire_job(&test, second_attempt, "start");
    let after_start = snowflake(&test, 306);
    select_discord_player(&test, discord_id, after_start);
    assert_eq!(
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::prepare_community_raid_from_discord(
                ctx,
                discord_id,
                after_start,
                event.event_id,
            )
        })
        .unwrap_err(),
        "RAID_ALREADY_ACTIVE"
    );
    assert_eq!(test.db.raid_attempt().player_id().filter(player_id).count(), 2);

    advance_to(&test, event.ends_at);
    let finish = test
        .db
        .raid_job()
        .attempt_id()
        .filter(second_attempt)
        .find(|job| job.phase == "finish")
        .unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, finish))
        .unwrap();
    assert_eq!(
        test.db
            .raid_statistics()
            .player_id()
            .find(player_id)
            .unwrap()
            .community_completions,
        1
    );
}

#[test]
fn special_raids_require_natural_discovery_and_award_one_exceptional_catch() {
    let test = setup();
    let maximum_seed = root_seed_for_special_roll(true);
    for (discord_id, species_id, band) in [(1601, 74, 9), (1602, 165, 0)] {
        let player_id = player(&test, discord_id);
        let biome_id = test
            .db
            .species_definition()
            .species_id()
            .find(species_id)
            .unwrap()
            .biome_id;
        give_supply(&test, player_id, 40);
        let denied = snowflake(&test, 401 + u64::from(band));
        select_discord_player(&test, discord_id, denied);
        assert_eq!(
            test.with_reducer_tx(auth("raid-bot"), |ctx| {
                raids::prepare_raid_from_discord(ctx, discord_id, denied, species_id, 0)
            })
            .unwrap_err(),
            "RAID_DISCOVERY_REQUIRED"
        );
        assert_eq!(test.db.raid_attempt().player_id().filter(player_id).count(), 0);

        seed_sold_natural_discovery(&test, player_id, species_id, band);
        let invalid_grade = snowflake(&test, 409 + u64::from(band));
        select_discord_player(&test, discord_id, invalid_grade);
        assert_eq!(
            test.with_reducer_tx(auth("raid-bot"), |ctx| {
                raids::prepare_raid_from_discord(
                    ctx,
                    discord_id,
                    invalid_grade,
                    species_id,
                    1,
                )
            })
            .unwrap_err(),
            "INVALID_SPECIAL_RAID"
        );
        if species_id == 74 {
            assert!(
                test.db
                    .perfect_species()
                    .key()
                    .find((u128::from(player_id) << 32) | u128::from(species_id))
                    .is_none()
            );
        }
        let before = test.db.player().player_id().find(player_id).unwrap();
        set_biome_weights(&test, biome_id, 0, 0, 1_000_000);
        let attempt = complete_special(
            &test,
            discord_id,
            species_id,
            410 + u64::from(band) * 3,
            maximum_seed,
        );
        assert_eq!(attempt.kind, "special");
        assert_eq!(attempt.grade, None);
        assert_eq!(attempt.special_fish_count, 1);
        assert_eq!(attempt.status, "completed");
        assert!(attempt.catch_id.is_some());
        assert_eq!(attempt.awarded_fillets, 0);
        assert_eq!(attempt.awarded_scales, 0);
        let catch_id = attempt.catch_id.unwrap();
        let player_after = test.db.player().player_id().find(player_id).unwrap();
        assert_eq!(player_after.fish_count, before.fish_count + 1);
        assert_eq!(player_after.completed_casts, before.completed_casts + 1);
        assert_eq!(player_after.next_cast_at, before.next_cast_at);
        assert_eq!(player_after.coins, before.coins);
        let receipt = test
            .db
            .command_receipt()
            .iter()
            .find(|row| row.catch_id == Some(catch_id))
            .unwrap();
        assert_eq!(receipt.command, "fishraid");
        let delivery = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap();
        let frozen = delivery.special_catch.unwrap();
        assert_eq!(frozen.catch_id, receipt.catch_id);
        assert_eq!(frozen.species_id, receipt.species_id);
        assert_eq!(frozen.rarity, receipt.rarity);
        assert_eq!((frozen.length_mm, frozen.weight_g), (receipt.length_mm, receipt.weight_g));
        assert!(!delivery.first_clear);
        assert_ne!(receipt.interaction_id & (1u64 << 63), 0);
        assert_eq!(receipt.species_id, Some(species_id));
        assert_eq!(receipt.coins_granted, 0);
        let journal_key = format!("pull:{}", u128::from(receipt.interaction_id) << 8);
        let journal = test.db.journal_entry().key().find(&journal_key).unwrap();
        assert_eq!(journal.outcome, "fish");
        assert_eq!(journal.catch_id, Some(catch_id));
        assert_eq!(
            test.db
                .cast_pull()
                .interaction_id()
                .filter(receipt.interaction_id)
                .count(),
            1
        );
        let equipment = test
            .db
            .cast_equipment_receipt()
            .interaction_id()
            .find(receipt.interaction_id)
            .unwrap();
        assert_eq!(equipment.reinforcement_level, 0);
        assert_eq!(equipment.xp_bonus_bp, 0);
        assert_eq!(equipment.bait_id, 0);
        assert_eq!(
            test.db.public_profile().player_id().find(player_id).unwrap().fish_count,
            player_after.fish_count
        );
        assert!(test
            .db
            .recent_catch()
            .player_id()
            .filter(player_id)
            .any(|row| row.species_id == Some(species_id) && row.caught_at == receipt.caught_at));
        let progress = test
            .db
            .player_species_progress()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(species_id))
            .unwrap();
        assert_eq!(progress.count, 2);
        assert_eq!(progress.rank_counts.iter().sum::<u64>(), 2);
        let specimen = test.db.owned_specimen().catch_id().find(catch_id).unwrap();
        let definition = test
            .db
            .species_definition()
            .species_id()
            .find(species_id)
            .unwrap();
        let expected_maximum = game_rules::measurements::maximum_in_size_band(
            game_rules::measurements::SpeciesMeasurements {
                typical_length_mm: definition.typical_length_mm,
                min_length_mm: definition.min_length_mm,
                max_length_mm: definition.max_length_mm,
                typical_weight_g: definition.typical_weight_g,
                min_weight_g: definition.min_weight_g,
                max_weight_g: definition.max_weight_g,
            },
            band,
        )
        .unwrap();
        if species_id == 74 {
            assert_eq!(specimen.rarity, "UUR");
            assert_eq!(specimen.length_mm, expected_maximum.length_mm);
            assert_eq!(specimen.weight_g, expected_maximum.weight_g);
            let perfect = test
                .db
                .perfect_species()
                .key()
                .find((u128::from(player_id) << 32) | u128::from(species_id))
                .unwrap();
            assert_eq!(perfect.first_catch_id, catch_id);
            assert!(test.db.rank_maximum().iter().any(|row| {
                row.player_id == player_id
                    && row.species_id == species_id
                    && row.rarity == "UUR"
                    && row.first_catch_id == catch_id
            }));
        } else {
            assert_eq!(specimen.rarity, "F");
            assert_eq!(specimen.length_mm, expected_maximum.length_mm);
            assert_eq!(specimen.weight_g, expected_maximum.weight_g);
            assert!(
                test.db
                    .perfect_species()
                    .key()
                    .find((u128::from(player_id) << 32) | u128::from(species_id))
                    .is_none()
            );
            assert!(test.db.rank_maximum().iter().any(|row| {
                row.player_id == player_id
                    && row.species_id == species_id
                    && row.rarity == "F"
                    && row.first_catch_id == catch_id
            }));
        }
        let stats = test.db.angler_statistics().player_id().find(player_id).unwrap();
        assert_eq!(stats.bonus_pulls, 0);
        assert_eq!(stats.bonus_fish + stats.bonus_junk + stats.bonus_treasure, 0);
        let badge = test
            .db
            .raid_badge()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(species_id))
            .unwrap();
        assert_eq!(badge.special_completions, 1);
        assert_eq!(badge.cleared_grades, 0);
        let after_claim = snowflake(&test, 420 + u64::from(band) * 3);
        select_discord_player(&test, discord_id, after_claim);
        test.with_reducer_tx(auth("raid-bot"), |ctx| {
            raids::claim_raid_from_discord(ctx, discord_id, after_claim, attempt.id)
        })
        .unwrap();
        assert_eq!(
            test.db.player().player_id().find(player_id).unwrap().fish_count,
            player_after.fish_count
        );
    }
    assert_eq!(
        test.db.raid_catch_counter().singleton().find(1).unwrap().next_sequence,
        3
    );
}

#[test]
fn cancelled_or_missed_special_raid_never_creates_an_exceptional_specimen() {
    let test = setup();
    let discord_id = 1701;
    let player_id = player(&test, discord_id);
    seed_sold_natural_discovery(&test, player_id, 165, 0);
    let baseline_fish = test.db.player().player_id().find(player_id).unwrap().fish_count;
    give_supply(&test, player_id, 40);

    let prepare = snowflake(&test, 501);
    submit_prepare(&test, discord_id, prepare, 165, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare);
    let deploy = snowflake(&test, 502);
    submit_deploy(&test, discord_id, deploy, attempt_id);
    let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
    advance_to(&test, attempt.starts_at);
    fire_job(&test, attempt_id, "start");
    advance_to(
        &test,
        attempt.starts_at
            + TimeDuration::from_micros(i64::from(attempt.duration_seconds) * 1_000_000 / 2),
    );
    let cancel = snowflake(&test, 503);
    select_discord_player(&test, discord_id, cancel);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        raids::cancel_raid_from_discord(ctx, discord_id, cancel, attempt_id)
    })
    .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(attempt_id).unwrap().status,
        "cancelled"
    );
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().fish_count,
        baseline_fish
    );
    assert!(
        test.db
            .command_receipt()
            .player_id()
            .filter(player_id)
            .all(|receipt| receipt.command != "fishraid")
    );
    assert!(test.db.raid_badge().player_id().filter(player_id).next().is_none());
    assert!(test.db.raid_delivery().attempt_id().find(attempt_id).is_none());
    assert!(test.db.raid_statistics().player_id().find(player_id).is_none());
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .unwrap()
            .quantity,
        20
    );

    let missed_discord = 1702;
    let missed_player = player(&test, missed_discord);
    seed_sold_natural_discovery(&test, missed_player, 74, 9);
    give_supply(&test, missed_player, 20);
    let missed_prepare = snowflake(&test, 504);
    submit_prepare(&test, missed_discord, missed_prepare, 74, 0);
    let missed_id = (u128::from(missed_player) << 64) | u128::from(missed_prepare);
    let missed_start = test.db.raid_attempt().id().find(missed_id).unwrap().starts_at;
    let missed_job = test
        .db
        .raid_job()
        .attempt_id()
        .filter(missed_id)
        .find(|job| job.phase == "start")
        .unwrap();
    advance_to(&test, missed_start);
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, missed_job))
        .unwrap();
    assert_eq!(
        test.db.raid_attempt().id().find(missed_id).unwrap().status,
        "missed"
    );
    assert!(test.db.raid_delivery().attempt_id().find(missed_id).is_none());
    assert_eq!(
        test.db.player().player_id().find(missed_player).unwrap().fish_count,
        1
    );
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(missed_player) << 32) | 900)
            .unwrap()
            .quantity,
        20
    );
    assert!(
        test.db
            .command_receipt()
            .player_id()
            .filter(missed_player)
            .all(|receipt| receipt.command != "fishraid")
    );
    assert!(test.db.raid_statistics().player_id().find(missed_player).is_none());
}

#[test]
fn normal_cast_records_owned_rod_grade_while_haul_remains_unreinforced() {
    let test = setup();
    let discord_id = 1801;
    let player_id = player(&test, discord_id);
    let species = test
        .db
        .species_definition()
        .biome_id()
        .filter(1u32)
        .find(|row| row.counts_for_ordinary_collection_completion && row.allowed_rarities.len() == 10)
        .unwrap();
    let biome_id = force_only_species_rank(&test, species.species_id, 9);
    enable_focus_with_mastery(&test, player_id, biome_id, species.species_id);
    let rod_id = test.db.player().player_id().find(player_id).unwrap().equipped_rod_id;
    let rod_key = (u128::from(player_id) << 32) | u128::from(rod_id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.rod_reinforcement().insert(RodReinforcement {
            key: rod_key,
            player_id,
            rod_id,
            level: 1,
            updated_at: ctx.timestamp,
        });
        Ok::<(), String>(())
    })
    .unwrap();
    let cast_id = snowflake(&test, 601);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        casting::fish_from_discord(ctx, discord_id, cast_id, Some(7), 8)
    })
    .unwrap();
    let cast_record = test
        .db
        .cast_equipment_receipt()
        .interaction_id()
        .find(cast_id)
        .unwrap();
    assert_eq!(cast_record.reinforcement_level, 1);
    assert!(cast_record.collection_focus_enabled);

    let cooldown = test.db.player().player_id().find(player_id).unwrap().next_cast_at;
    let haul_id = snowflake(&test, 602);
    test.with_reducer_tx(auth("raid-bot"), |ctx| {
        hauls::start_haul_from_discord(
            ctx,
            discord_id,
            haul_id,
            Some(7),
            8,
            "boat".into(),
        )
    })
    .unwrap();
    let due = test.db.haul_trip().interaction_id().find(haul_id).unwrap().due_at;
    advance_to(&test, due);
    let job = test.db.haul_job().scheduled_id().find(haul_id).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        hauls::finish_scheduled_haul(ctx, job)
    })
    .unwrap();
    let haul_record = test
        .db
        .cast_equipment_receipt()
        .interaction_id()
        .find(haul_id)
        .unwrap();
    assert_eq!(haul_record.reinforcement_level, 0);
    assert!(haul_record.collection_focus_enabled);
    assert_eq!(
        test.db.player().player_id().find(player_id).unwrap().next_cast_at,
        cooldown
    );
}

#[test]
fn prepared_attempt_pins_duration_supply_reward_and_catalog_versions() {
    let test = setup();
    let discord_id = 1901;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 19001, biome_id);
    give_supply(&test, player_id, 1);
    let prepare = snowflake(&test, 801);
    submit_prepare(&test, discord_id, prepare, ordinary.species_id, 0);
    let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare);
    let pinned = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(pinned.supply_units, 1);
    assert_eq!(pinned.duration_seconds, 1800);
    assert_eq!(pinned.catalog_version, game_rules::raids::catalog().version);
    assert_eq!(pinned.rules_version, 17);
    assert_eq!(pinned.base_fillets, 60);
    assert_eq!(pinned.base_scales, 40);
    assert_eq!(pinned.full_fillets, 0);

    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        let mut grade = ctx.db.raid_grade_definition().grade().find(0).unwrap();
        grade.catalog_version += 1;
        grade.duration_seconds = 1;
        grade.supply_units = 99;
        grade.fillets = 999;
        grade.scales = 999;
        ctx.db.raid_grade_definition().grade().update(grade);
        Ok::<(), String>(())
    })
    .unwrap();
    let deploy = snowflake(&test, 802);
    submit_deploy(&test, discord_id, deploy, attempt_id);
    assert_eq!(
        test.db
            .item_stack()
            .key()
            .find((u128::from(player_id) << 32) | 900)
            .is_none(),
        true
    );
    let deployed = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(deployed.duration_seconds, 1800);
    assert_eq!(deployed.supply_units, 1);
    assert_eq!(deployed.base_fillets, 60);
    advance_to(&test, deployed.starts_at);
    fire_job(&test, attempt_id, "start");
    advance_to(&test, deployed.ends_at);
    fire_job(&test, attempt_id, "finish");
    let completed = test.db.raid_attempt().id().find(attempt_id).unwrap();
    assert_eq!(completed.status, "completed");
    assert!(completed.awarded_fillets <= 75);
    assert!(completed.awarded_scales <= 50);
    assert!(completed.awarded_fillets >= 60);
    assert!(completed.awarded_scales >= 40);
}

#[test]
fn activation_preserves_legacy_prepared_deployed_and_running_attempts() {
    for state in ["prepared", "deployed", "running"] {
        let test = setup();
        let discord_id = 1902;
        let player_id = player(&test, discord_id);
        let species_id = test
            .db
            .species_definition()
            .iter()
            .find(|row| row.counts_for_ordinary_collection_completion)
            .unwrap()
            .species_id;
        seed_sold_natural_discovery(&test, player_id, species_id, 0);
        give_supply(&test, player_id, 2);
        let prepare = snowflake(&test, 811);
        submit_prepare(&test, discord_id, prepare, species_id, 0);
        let attempt_id = (u128::from(player_id) << 64) | u128::from(prepare);

        test.with_reducer_tx(TestAuth::internal(), |ctx| {
            let mut grade = ctx.db.raid_grade_definition().grade().find(0).unwrap();
            grade.catalog_version = 1;
            grade.duration_seconds = 3600;
            ctx.db.raid_grade_definition().grade().update(grade);
            let mut attempt = ctx.db.raid_attempt().id().find(attempt_id).unwrap();
            attempt.catalog_version = 1;
            attempt.duration_seconds = 3600;
            attempt.ends_at = attempt.starts_at + TimeDuration::from_micros(3_600_000_000);
            ctx.db.raid_attempt().id().update(attempt);
            Ok::<(), String>(())
        })
        .unwrap();

        if state != "prepared" {
            let deploy = snowflake(&test, 812);
            submit_deploy(&test, discord_id, deploy, attempt_id);
        }
        if state == "running" {
            let attempt = test.db.raid_attempt().id().find(attempt_id).unwrap();
            advance_to(&test, attempt.starts_at);
            fire_job(&test, attempt_id, "start");
        }

        let before = test.db.raid_attempt().id().find(attempt_id).unwrap();
        assert_eq!(before.status, state);
        assert_eq!(before.catalog_version, 1);
        assert_eq!(before.rules_version, 17);
        assert_eq!(before.duration_seconds, 3600);
        assert_eq!(before.supply_units, 1);
        assert_eq!((before.base_fillets, before.base_scales), (60, 40));
        assert_eq!((before.maximum_fillets, before.maximum_scales), (75, 50));
        assert!(before.special_species.is_none());
        assert!(before.special_rank.is_none());
        assert!(before.special_biome.is_none());
        assert!(before.special_rules.is_none());

        let jobs = || {
            let mut rows: Vec<_> = test.db.raid_job().attempt_id().filter(attempt_id)
                .map(|row| (row.scheduled_id, row.attempt_id, row.phase, row.scheduled_at))
                .collect();
            rows.sort_by_key(|row| row.0);
            rows
        };
        let items = || {
            let mut rows: Vec<_> = test.db.item_stack().player_id().filter(player_id)
                .map(|row| (row.key, row.item, row.quantity))
                .collect();
            rows.sort_by_key(|row| row.0);
            rows
        };
        let rods = || {
            let mut rows: Vec<_> = test.db.owned_rod().player_id().filter(player_id)
                .map(|row| (row.key, row.rod_id, row.upgrade_level))
                .collect();
            rows.sort_by_key(|row| row.0);
            rows
        };
        let before_jobs = jobs();
        let expected_phase = if state == "running" { "finish" } else { "start" };
        let expected_at = if state == "running" { before.ends_at } else { before.starts_at };
        assert_eq!(before_jobs.len(), 1);
        assert_eq!(before_jobs[0].2, expected_phase);
        assert_eq!(before_jobs[0].3, expected_at.into());
        let before_items = items();
        let before_rods = rods();
        let before_coins = test.db.player().player_id().find(player_id).unwrap().coins;
        let before_ledger = test.db.economy_ledger().player_id().filter(player_id).count();
        let before_badges = test.db.raid_badge().player_id().filter(player_id).count();
        let before_achievements = test.db.earned_achievement().count();
        let before_deliveries = test.db.achievement_delivery().count();

        for _ in 0..2 {
            test.with_reducer_tx(TestAuth::internal(), |ctx| raids::activate_raids(ctx)).unwrap();
            assert_eq!(test.db.raid_delivery().count(), 0);
            let grade = test.db.raid_grade_definition().grade().find(0).unwrap();
            assert_eq!((grade.catalog_version, grade.duration_seconds), (2, 1800));
            assert_eq!(test.db.game_config().version().find(17).unwrap().version, 17);
            let after = test.db.raid_attempt().id().find(attempt_id).unwrap();
            macro_rules! unchanged {
                ($($field:ident),+ $(,)?) => {
                    $(assert_eq!(after.$field, before.$field, "{state}: {}", stringify!($field));)+
                };
            }
            unchanged!(
                id, player_id, kind, species_id, grade, event_id, boat_id, boat_name,
                boat_quality_level, catalog_version, content_version, rules_version, status,
                prepared_at, deployment_deadline, starts_at, ends_at, deployed_at, started_at,
                settled_at, duration_seconds, supply_units, supply_slot, supply_item,
                base_fillets, base_scales, full_fillets, full_scales, maximum_fillets,
                maximum_scales, fillets_slot, fillets_item, scales_slot, scales_item,
                reward_bonus_divisor, special_maximum_roll_one_in, special_fish_count,
                special_band, awarded_fillets, awarded_scales, catch_id, prune_at,
            );
            assert!(after.special_species.is_none());
            assert!(after.special_rank.is_none());
            assert!(after.special_biome.is_none());
            assert!(after.special_rules.is_none());
            assert_eq!(jobs(), before_jobs);
            assert_eq!(items(), before_items);
            assert_eq!(rods(), before_rods);
            assert_eq!(test.db.player().player_id().find(player_id).unwrap().coins, before_coins);
            assert_eq!(test.db.economy_ledger().player_id().filter(player_id).count(), before_ledger);
            assert_eq!(test.db.raid_badge().player_id().filter(player_id).count(), before_badges);
            assert_eq!(test.db.earned_achievement().count(), before_achievements);
            assert_eq!(test.db.achievement_delivery().count(), before_deliveries);
        }

        if state == "prepared" {
            let deploy = snowflake(&test, 813);
            submit_deploy(&test, discord_id, deploy, attempt_id);
        }
        if state != "running" {
            advance_to(&test, before.starts_at);
            fire_job(&test, attempt_id, "start");
        }
        let material_quantity = |slot| {
            test.db.item_stack().key()
                .find((u128::from(player_id) << 32) | u128::from(slot))
                .map_or(0, |row| row.quantity)
        };
        let initial_fillets = material_quantity(before.fillets_slot);
        let initial_scales = material_quantity(before.scales_slot);
        advance_to(&test, before.starts_at + TimeDuration::from_micros(1_801_000_000));
        fire_job(&test, attempt_id, "finish");
        let early = test.db.raid_attempt().id().find(attempt_id).unwrap();
        assert_eq!(early.status, "running");
        assert_eq!((early.awarded_fillets, early.awarded_scales), (0, 0));
        assert_eq!(test.db.raid_badge().player_id().filter(player_id).count(), before_badges);
        assert_eq!(material_quantity(before.fillets_slot), initial_fillets);
        assert_eq!(material_quantity(before.scales_slot), initial_scales);
        assert_eq!(jobs()[0].3, before.ends_at.into());

        advance_to(&test, before.ends_at);
        let finished_job = fire_job(&test, attempt_id, "finish");
        let completed = test.db.raid_attempt().id().find(attempt_id).unwrap();
        assert_eq!(completed.status, "completed");
        assert!((60..=75).contains(&completed.awarded_fillets));
        assert!((40..=50).contains(&completed.awarded_scales));
        assert_eq!(completed.awarded_fillets, completed.full_fillets);
        assert_eq!(completed.awarded_scales, completed.full_scales);
        assert_eq!(material_quantity(before.fillets_slot), initial_fillets + completed.awarded_fillets);
        assert_eq!(material_quantity(before.scales_slot), initial_scales + completed.awarded_scales);
        let badge_key = (u128::from(player_id) << 32) | u128::from(species_id);
        let badge = test.db.raid_badge().key().find(badge_key).unwrap();
        assert_eq!((badge.cleared_grades, badge.successful_clears), (1, 1));

        test.with_reducer_tx(TestAuth::internal(), |ctx| raids::run_raid_job(ctx, finished_job)).unwrap();
        assert_eq!(material_quantity(before.fillets_slot), initial_fillets + completed.awarded_fillets);
        assert_eq!(material_quantity(before.scales_slot), initial_scales + completed.awarded_scales);
        let badge = test.db.raid_badge().key().find(badge_key).unwrap();
        assert_eq!((badge.cleared_grades, badge.successful_clears), (1, 1));
    }
}

#[test]
fn raid_views_are_empty_for_unlinked_or_unselected_callers() {
    let test = setup();
    let discord_id = 1901;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 19001, biome_id);
    let request_id = snowflake(&test, 701);
    submit_prepare(&test, discord_id, request_id, ordinary.species_id, 0);
    let unlinked = test.view_context(auth("unlinked"));
    assert!(raids::my_raid_attempts(&unlinked).is_empty());
    assert!(raids::my_raid_actions(&unlinked).is_empty());
    assert!(raids::my_rod_reinforcements(&unlinked).is_empty());

    link_browser(&test, player_id, "raid-browser");
    let browser = test.view_context(auth("raid-browser"));
    assert_eq!(raids::my_raid_attempts(&browser).len(), 1);
    assert_eq!(raids::my_raid_actions(&browser).len(), 1);
    assert!(raids::my_rod_reinforcements(&browser).is_empty());

    let wrong_service = test.view_context(auth("unselected-service"));
    assert!(raids::adapter_raid_attempts(&wrong_service).is_empty());
    assert!(raids::adapter_raid_actions(&wrong_service).is_empty());
    assert!(raids::adapter_rod_reinforcements(&wrong_service).is_empty());

    let selection_id = snowflake(&test, 702);
    select_discord_player(&test, discord_id, selection_id);
    let selected_adapter = test.view_context(auth("raid-bot"));
    let attempts = raids::adapter_raid_attempts(&selected_adapter);
    assert_eq!(attempts.len(), 1);
    assert_eq!(attempts[0].player_id, player_id);
    assert_eq!(raids::adapter_raid_actions(&selected_adapter).len(), 1);
}

#[test]
fn activate_raids_is_owner_only_additive_and_idempotent() {
    let test = setup();
    let player_id = player(&test, 2001);
    add_coins(&test, player_id, 4321);
    grant_item(&test, player_id, 900, "raid_supplies", 25);
    let rod = test.db.owned_rod().player_id().filter(player_id).next().unwrap();
    let initial_player = test.db.player().player_id().find(player_id).unwrap();
    let initial_owned_rods: Vec<_> = test
        .db
        .owned_rod()
        .player_id()
        .filter(player_id)
        .map(|row| (row.key, row.rod_id, row.upgrade_level))
        .collect();
    let initial_items: Vec<_> = test
        .db
        .item_stack()
        .player_id()
        .filter(player_id)
        .map(|row| (row.key, row.item, row.quantity))
        .collect();
    let initial_listings: Vec<_> = test
        .db
        .shop_listing()
        .iter()
        .filter(|row| row.listing_id != 150)
        .map(|row| (row.listing_id, row.kind, row.target_id, row.price_coins))
        .collect();
    let mut legacy_config = test.db.game_config().version().find(17).unwrap();
    legacy_config.version = 16;
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        for row in ctx.db.raid_grade_definition().iter().collect::<Vec<_>>() {
            ctx.db.raid_grade_definition().grade().delete(row.grade);
        }
        for row in ctx.db.community_raid_event().iter().collect::<Vec<_>>() {
            ctx.db.community_raid_event().event_id().delete(row.event_id);
        }
        ctx.db.shop_listing().listing_id().delete(150);
        ctx.db.game_config().version().delete(17);
        if ctx.db.game_config().version().find(16).is_some() {
            ctx.db
                .game_config()
                .version()
                .update(legacy_config.clone());
        } else {
            ctx.db.game_config().insert(legacy_config.clone());
        }
        Ok::<(), String>(())
    })
    .unwrap();
    assert_eq!(
        test.with_reducer_tx(auth("reader"), |ctx| raids::activate_raids(ctx)).unwrap_err(),
        "OWNER_REQUIRED"
    );
    assert_eq!(test.db.raid_grade_definition().count(), 0);
    assert!(test.db.shop_listing().listing_id().find(150).is_none());

    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::activate_raids(ctx)).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| raids::activate_raids(ctx)).unwrap();
    assert_eq!(test.db.raid_grade_definition().count(), 10);
    assert!(test.db.community_raid_event().count() >= 2);
    assert!(test.db.raid_catch_counter().singleton().find(1).is_none());
    assert_eq!(
        test.db.game_config().version().find(17).unwrap().version,
        17
    );
    let player_after = test.db.player().player_id().find(player_id).unwrap();
    assert_eq!(
        (player_after.coins, player_after.total_xp, player_after.fish_count, player_after.completed_casts),
        (initial_player.coins, initial_player.total_xp, initial_player.fish_count, initial_player.completed_casts)
    );
    assert_eq!(
        test.db
            .owned_rod()
            .player_id()
            .filter(player_id)
            .map(|row| (row.key, row.rod_id, row.upgrade_level))
            .collect::<Vec<_>>(),
        initial_owned_rods
    );
    assert_eq!(
        test.db
            .item_stack()
            .player_id()
            .filter(player_id)
            .map(|row| (row.key, row.item, row.quantity))
            .collect::<Vec<_>>(),
        initial_items
    );
    assert_eq!(
        test.db
            .shop_listing()
            .iter()
            .filter(|row| row.listing_id != 150)
            .map(|row| (row.listing_id, row.kind, row.target_id, row.price_coins))
            .collect::<Vec<_>>(),
        initial_listings
    );
    assert_eq!(test.db.earned_achievement().count(), 0);
    assert_eq!(test.db.achievement_delivery().count(), 0);
    assert!(test.db.owned_rod().key().find(rod.key).is_some());
    assert!(test.db.raid_statistics().player_id().find(player_id).is_none());
}

#[test]
fn terminal_raid_receipts_prune_without_pruning_durable_progress_or_active_attempts() {
    let test = setup();
    let discord_id = 2101;
    let player_id = player(&test, discord_id);
    let ordinary = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let biome_id = force_only_species_rank(&test, ordinary.species_id, 0);
    cast_one(&test, player_id, 21001, biome_id);
    let terminal = complete_personal(&test, discord_id, ordinary.species_id, 0, 900);
    let terminal_at = terminal.settled_at.unwrap();
    let badge_key = (u128::from(player_id) << 32) | u128::from(ordinary.species_id);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        ctx.db.raid_catch_counter().insert(RaidCatchCounter {
            singleton: 1,
            next_sequence: 42,
        });
        Ok::<(), String>(())
    })
    .unwrap();
    assert!(test.db.raid_action_receipt().player_id().filter(player_id).count() > 0);

    let active_discord = 2102;
    let active_player = player(&test, active_discord);
    let active_species = test
        .db
        .species_definition()
        .iter()
        .find(|row| row.counts_for_ordinary_collection_completion)
        .unwrap();
    let active_biome = force_only_species_rank(&test, active_species.species_id, 0);
    cast_one(&test, active_player, 21002, active_biome);
    let active_prepare = snowflake(&test, 901);
    submit_prepare(&test, active_discord, active_prepare, active_species.species_id, 0);
    let active_attempt = (u128::from(active_player) << 64) | u128::from(active_prepare);

    advance_to(
        &test,
        terminal_at + TimeDuration::from_micros(29 * 86_400_000_000),
    );
    let maintenance_job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        crate::maintenance::prune_history(ctx, maintenance_job)
    })
    .unwrap();
    assert!(test.db.raid_attempt().id().find(terminal.id).is_some());
    assert!(test.db.raid_attempt().id().find(active_attempt).is_some());

    advance_to(
        &test,
        terminal_at + TimeDuration::from_micros(31 * 86_400_000_000),
    );
    let maintenance_job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        crate::maintenance::prune_history(ctx, maintenance_job)
    })
    .unwrap();
    assert!(test.db.raid_attempt().id().find(terminal.id).is_none());
    assert!(test.db.raid_attempt().id().find(active_attempt).is_some());
    assert!(test.db.raid_action_receipt().player_id().filter(player_id).next().is_none());
    assert!(test.db.raid_badge().key().find(badge_key).is_some());
    assert!(test.db.raid_statistics().player_id().find(player_id).is_some());
    assert!(test.db.raid_catch_counter().singleton().find(1).is_some());
    assert!(test.db.raid_delivery().attempt_id().find(terminal.id).is_some());
}

#[test]
fn raid_delivery_leases_are_private_pinned_and_do_not_replay() {
    let test = setup();
    let discord_id = 2201;
    let player_id = player(&test, discord_id);
    let species = test.db.species_definition().iter()
        .find(|row| row.counts_for_ordinary_collection_completion).unwrap();
    seed_sold_natural_discovery(&test, player_id, species.species_id, 0);
    let attempt = complete_personal(&test, discord_id, species.species_id, 0, 940);
    test.with_reducer_tx(TestAuth::internal(), |ctx| {
        accounts::configure_service(ctx, Identity::from_claims("test", "raid-bot-two"), ServiceRole::DiscordAdapter, true)?;
        accounts::configure_service(ctx, Identity::from_claims("test", "raid-linker"), ServiceRole::AccountLinker, true)
    }).unwrap();
    link_browser(&test, player_id, "raid-delivery-browser");
    for subject in ["anonymous", "raid-delivery-browser", "raid-linker"] {
        assert!(raid_notifications::adapter_raid_deliveries(&test.view_context(auth(subject))).is_empty());
        assert!(test.with_reducer_tx(auth(subject), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 1)).is_err());
        assert!(test.with_reducer_tx(auth(subject), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 1)).is_err());
    }
    assert_eq!(raid_notifications::adapter_raid_deliveries(&test.view_context(auth("raid-bot"))).len(), 1);
    assert_eq!(test.with_reducer_tx(auth("raid-bot"), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 0)).unwrap_err(), "INVALID_LEASE");
    let before = test.db.player().player_id().find(player_id).unwrap();
    let stats = test.db.raid_statistics().player_id().find(player_id).unwrap();
    let stacks: Vec<_> = test.db.item_stack().player_id().filter(player_id).map(|r| (r.key, r.quantity)).collect();
    test.with_reducer_tx(auth("raid-bot"), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 1)).unwrap();
    let deferred = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap();
    assert_eq!((deferred.channel_id, deferred.lease_nonce, deferred.attempts), (0, 0, 0));
    test.with_reducer_tx(auth("raid-bot"), |ctx| crate::notifications::record_achievement_route(ctx, discord_id, 55)).unwrap();
    test.clock.advance(TimeDuration::from_micros(61_000_000));
    test.with_reducer_tx(auth("raid-bot"), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 11)).unwrap();
    let leased = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap();
    assert_eq!((leased.channel_id, leased.lease_nonce, leased.attempts), (55, 11, 1));
    assert_eq!(test.with_reducer_tx(auth("raid-bot"), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 0)).unwrap_err(), "DELIVERY_LEASE_CHANGED");
    test.with_reducer_tx(auth("raid-bot"), |ctx| crate::notifications::record_achievement_route(ctx, discord_id, 99)).unwrap();
    test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 22)).unwrap();
    assert_eq!(test.db.raid_delivery().attempt_id().find(attempt.id).unwrap().lease_nonce, 11);
    assert_eq!(test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 11)).unwrap_err(), "DELIVERY_LEASE_CHANGED");
    test.clock.advance(TimeDuration::from_micros(121_000_000));
    test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::claim_raid_delivery(ctx, attempt.id, 22)).unwrap();
    let retried = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap();
    assert_eq!((retried.channel_id, retried.lease_nonce, retried.attempts), (55, 22, 2));
    assert_eq!(test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 11)).unwrap_err(), "DELIVERY_LEASE_CHANGED");
    test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 22)).unwrap();
    test.with_reducer_tx(auth("raid-bot-two"), |ctx| raid_notifications::acknowledge_raid_delivery(ctx, attempt.id, 22)).unwrap();
    assert!(test.db.raid_delivery().attempt_id().find(attempt.id).is_none());
    let claim_id = snowflake(&test, 945);
    select_discord_player(&test, discord_id, claim_id);
    test.with_reducer_tx(auth("raid-bot"), |ctx| raids::claim_raid_from_discord(ctx, discord_id, claim_id, attempt.id)).unwrap();
    for _ in 0..2 {
        test.with_reducer_tx(TestAuth::internal(), |ctx| raids::activate_raids(ctx)).unwrap();
    }
    assert!(test.db.raid_delivery().attempt_id().find(attempt.id).is_none());
    let after = test.db.player().player_id().find(player_id).unwrap();
    assert_eq!((before.coins, before.total_xp, before.fish_count), (after.coins, after.total_xp, after.fish_count));
    assert_eq!(stats.personal_completions, test.db.raid_statistics().player_id().find(player_id).unwrap().personal_completions);
    assert_eq!(stacks, test.db.item_stack().player_id().filter(player_id).map(|r| (r.key, r.quantity)).collect::<Vec<_>>());
}

#[test]
fn raid_delivery_and_awards_roll_back_together() {
    let test = setup();
    let discord_id = 2301;
    let player_id = player(&test, discord_id);
    let species = test.db.species_definition().iter().find(|r| r.counts_for_ordinary_collection_completion).unwrap();
    seed_sold_natural_discovery(&test, player_id, species.species_id, 0);
    give_supply(&test, player_id, 1);
    let prepare = snowflake(&test, 950);
    submit_prepare(&test, discord_id, prepare, species.species_id, 0);
    let id = (u128::from(player_id) << 64) | u128::from(prepare);
    submit_deploy(&test, discord_id, snowflake(&test, 951), id);
    let attempt = test.db.raid_attempt().id().find(id).unwrap();
    advance_to(&test, attempt.starts_at);
    fire_job(&test, id, "start");
    advance_to(&test, attempt.ends_at);
    let job = test.db.raid_job().attempt_id().filter(id).find(|job| job.phase == "finish").unwrap();
    let stacks: Vec<_> = test.db.item_stack().player_id().filter(player_id).map(|r| (r.key, r.quantity)).collect();
    assert_eq!(test.with_reducer_tx(TestAuth::internal(), |ctx| {
        raids::run_raid_job(ctx, job)?;
        Err::<(), String>("ABORT_TEST".into())
    }).unwrap_err(), "ABORT_TEST");
    assert_eq!(test.db.raid_attempt().id().find(id).unwrap().status, "running");
    assert!(test.db.raid_delivery().attempt_id().find(id).is_none());
    assert!(test.db.raid_badge().player_id().filter(player_id).next().is_none());
    assert!(test.db.raid_statistics().player_id().find(player_id).is_none());
    assert_eq!(stacks, test.db.item_stack().player_id().filter(player_id).map(|r| (r.key, r.quantity)).collect::<Vec<_>>());
}

#[test]
fn special_delivery_keeps_frozen_catch_after_history_pruning() {
    let test = setup();
    let discord_id = 2401;
    let player_id = player(&test, discord_id);
    seed_sold_natural_discovery(&test, player_id, 165, 0);
    let attempt = complete_special(&test, discord_id, 165, 960, root_seed_for_special_roll(false));
    let before = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap().special_catch.unwrap();
    advance_to(&test, attempt.settled_at.unwrap() + TimeDuration::from_micros(31 * 86_400_000_000));
    let job = test.db.maintenance_job().scheduled_id().find(1).unwrap();
    test.with_reducer_tx(TestAuth::internal(), |ctx| crate::maintenance::prune_history(ctx, job)).unwrap();
    assert!(test.db.raid_attempt().id().find(attempt.id).is_none());
    assert!(test.db.command_receipt().interaction_id().find(before.interaction_id).is_none());
    let frozen = test.db.raid_delivery().attempt_id().find(attempt.id).unwrap().special_catch.unwrap();
    assert_eq!((frozen.catch_id, frozen.species_id, frozen.rarity, frozen.length_mm, frozen.weight_g),
        (before.catch_id, before.species_id, before.rarity, before.length_mm, before.weight_g));
}
