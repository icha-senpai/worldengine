//! Lead-authored live transport assertions for the identity-bound raid fixture.
//! Run after the browser actor has earned raid progress; never connects to Discord.
use game_client::{Client, Error, RaidAction, Snapshot, module_bindings::ServiceRole};
use std::{
    process::Command,
    sync::atomic::{AtomicU64, Ordering},
    time::{Duration, SystemTime, UNIX_EPOCH},
};

const ACTOR: u64 = 99003;
static SEQUENCE: AtomicU64 = AtomicU64::new(1);

fn millis() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_millis() as u64
}

fn snowflake() -> u64 {
    ((millis() - 1_420_070_400_000) << 22) | SEQUENCE.fetch_add(1, Ordering::Relaxed)
}

fn item(snapshot: &Snapshot, name: &str) -> u64 {
    snapshot
        .items
        .iter()
        .find(|row| row.item == name)
        .map_or(0, |row| row.quantity)
}

fn assert_scope(snapshot: &Snapshot) {
    let player = snapshot.player.player_id;
    assert!(
        snapshot
            .raid_attempts
            .iter()
            .all(|row| row.player_id == player)
    );
    assert!(
        snapshot
            .raid_actions
            .iter()
            .all(|row| row.player_id == player)
    );
    assert!(
        snapshot
            .raid_badges
            .iter()
            .all(|row| row.player_id == player)
    );
    assert!(
        snapshot
            .rod_reinforcements
            .iter()
            .all(|row| row.player_id == player)
    );
    assert!(
        snapshot
            .raid_statistics
            .iter()
            .all(|row| row.player_id == player)
    );
}

fn owner_call(uri: &str, database: &str, reducer: &str, args: &[String]) -> Result<(), Error> {
    let result = Command::new(std::env::var("TEST_SPACETIME_CLI")?)
        .args([
            "call",
            "--server",
            uri,
            "--no-config",
            "--yes",
            database,
            reducer,
        ])
        .args(args)
        .output()?;
    if !result.status.success() {
        return Err(String::from_utf8_lossy(&result.stderr).into_owned().into());
    }
    Ok(())
}

async fn action(client: &Client, request_id: u64, intent: RaidAction) -> Result<Snapshot, Error> {
    let snapshot = client
        .raid_action(ACTOR, "Other Raid Test Angler".into(), request_id, intent)
        .await?;
    assert_scope(&snapshot);
    Ok(snapshot)
}

#[tokio::main]
async fn main() -> Result<(), Error> {
    let uri = std::env::var("TEST_SPACETIMEDB_URI")?;
    let database = std::env::var("TEST_SPACETIMEDB_DATABASE")?;
    if uri != "http://127.0.0.1:3127" || !database.starts_with("fishbound-proof-raids-") {
        return Err("The raid proof requires its isolated loopback database".into());
    }
    let client = Client::connect(
        &uri,
        &database,
        std::env::var("TEST_BOT_TOKEN")?,
        ServiceRole::DiscordAdapter,
    )
    .await?;
    let baseline = client
        .player(
            ACTOR,
            "Other Raid Test Angler".into(),
            snowflake(),
            None,
            0,
            false,
        )
        .await?;
    assert_scope(&baseline);
    assert_eq!(baseline.raid_grades.len(), 10);
    assert!(baseline.raid_attempts.is_empty());
    assert!(baseline.raid_badges.is_empty());
    let species = baseline
        .species
        .iter()
        .find(|species| {
            species.biome_id == 1
                && species.counts_for_ordinary_collection_completion
                && baseline
                    .collection
                    .iter()
                    .any(|row| row.species_id == species.species_id && row.count > 0)
        })
        .expect("the fixture supplies a discovered ordinary species")
        .species_id;
    let supply_before = item(&baseline, "raid_supplies");
    let until_boundary = 600_000 - millis() % 600_000;
    if until_boundary < 10_000 {
        tokio::time::sleep(Duration::from_millis(until_boundary + 100)).await;
    }
    let prepare = snowflake();
    let snapshot = action(
        &client,
        prepare,
        RaidAction::Prepare {
            species_id: species,
            grade: 0,
        },
    )
    .await?;
    let cancelled = (u128::from(snapshot.player.player_id) << 64) | u128::from(prepare);
    assert_eq!(
        snapshot
            .raid_attempts
            .iter()
            .find(|row| row.id == cancelled)
            .unwrap()
            .status,
        "prepared"
    );
    let snapshot = action(
        &client,
        snowflake(),
        RaidAction::Cancel {
            attempt_id: cancelled,
        },
    )
    .await?;
    assert_eq!(item(&snapshot, "raid_supplies"), supply_before);
    assert!(snapshot.raid_badges.is_empty());

    let mut latest = snapshot;
    for grade in 0..=1 {
        let request = snowflake();
        let snapshot = action(
            &client,
            request,
            RaidAction::Prepare {
                species_id: species,
                grade,
            },
        )
        .await?;
        let attempt_id = (u128::from(snapshot.player.player_id) << 64) | u128::from(request);
        let snapshot = action(&client, snowflake(), RaidAction::Deploy { attempt_id }).await?;
        assert_eq!(
            snapshot
                .raid_attempts
                .iter()
                .find(|row| row.id == attempt_id)
                .unwrap()
                .status,
            "deployed"
        );
        owner_call(
            &uri,
            &database,
            "proof_raid_finish",
            &[attempt_id.to_string()],
        )?;
        let claim = snowflake();
        let snapshot = action(&client, claim, RaidAction::Claim { attempt_id }).await?;
        let badge = snapshot
            .raid_badges
            .iter()
            .find(|row| row.species_id == species)
            .unwrap();
        assert_eq!(badge.cleared_grades, grade + 1);
        assert_eq!(badge.successful_clears, u64::from(grade + 1));
        let replay = action(&client, claim, RaidAction::Claim { attempt_id }).await?;
        assert_eq!(
            replay.raid_statistics[0].personal_completions,
            u64::from(grade + 1)
        );
        assert_eq!(
            item(&replay, "raid_supplies"),
            supply_before - u64::from(grade + 1)
        );
        latest = replay;
    }
    let reinforce = snowflake();
    let coins = latest.player.coins;
    let reinforced = action(
        &client,
        reinforce,
        RaidAction::Reinforce {
            rod_id: 1,
            expected_level: 0,
        },
    )
    .await?;
    assert_eq!(
        reinforced
            .rod_reinforcements
            .iter()
            .find(|row| row.rod_id == 1)
            .unwrap()
            .level,
        1
    );
    assert!(
        reinforced
            .rod_reinforcements
            .iter()
            .all(|row| row.rod_id != 2 || row.level == 0)
    );
    assert_eq!(
        reinforced
            .owned_rods
            .iter()
            .find(|row| row.rod_id == 1)
            .unwrap()
            .upgrade_level,
        baseline
            .owned_rods
            .iter()
            .find(|row| row.rod_id == 1)
            .unwrap()
            .upgrade_level
    );
    let f_cost = latest
        .raid_grades
        .iter()
        .find(|row| row.grade == 0)
        .unwrap()
        .reinforce_coins;
    assert_eq!(reinforced.player.coins, coins - f_cost);
    let replay = action(
        &client,
        reinforce,
        RaidAction::Reinforce {
            rod_id: 1,
            expected_level: 0,
        },
    )
    .await?;
    assert_eq!(replay.player.coins, reinforced.player.coins);
    let before_f = item(&replay, "raid_fillets_f");
    let before_d = item(&replay, "raid_fillets_d");
    let before_coins = replay.player.coins;
    let refine = snowflake();
    let (input_quantity, output_quantity, coins_per_batch) = (60, 50, 5);
    let refined = action(
        &client,
        refine,
        RaidAction::Refine {
            material_id: 0,
            from_grade: 0,
            batches: 1,
        },
    )
    .await?;
    assert_eq!(item(&refined, "raid_fillets_f"), before_f - input_quantity);
    assert_eq!(item(&refined, "raid_fillets_d"), before_d + output_quantity);
    assert_eq!(refined.player.coins, before_coins - coins_per_batch);
    let replay = action(
        &client,
        refine,
        RaidAction::Refine {
            material_id: 0,
            from_grade: 0,
            batches: 1,
        },
    )
    .await?;
    assert_eq!(
        item(&replay, "raid_fillets_d"),
        item(&refined, "raid_fillets_d")
    );
    assert_eq!(replay.player.coins, refined.player.coins);

    const EVENT: u64 = 90990102;
    owner_call(
        &uri,
        &database,
        "proof_raid_open_event",
        &[EVENT.to_string()],
    )?;
    let prepare = snowflake();
    let community = action(
        &client,
        prepare,
        RaidAction::PrepareCommunity { event_id: EVENT },
    )
    .await?;
    let attempt_id = (u128::from(community.player.player_id) << 64) | u128::from(prepare);
    action(&client, snowflake(), RaidAction::Deploy { attempt_id }).await?;
    owner_call(
        &uri,
        &database,
        "proof_raid_finish",
        &[attempt_id.to_string()],
    )?;
    let completed = action(&client, snowflake(), RaidAction::Claim { attempt_id }).await?;
    assert_eq!(completed.raid_statistics[0].community_completions, 1);
    assert_eq!(completed.player.coins, refined.player.coins);
    assert_eq!(
        completed
            .raid_badges
            .iter()
            .find(|row| row.species_id == species)
            .unwrap()
            .cleared_grades,
        2
    );
    let fish_before = completed.player.fish_count;
    let cooldown = completed.player.next_cast_at;
    let prepare = snowflake();
    let special = action(
        &client,
        prepare,
        RaidAction::Prepare {
            species_id: 74,
            grade: 0,
        },
    )
    .await?;
    let attempt_id = (u128::from(special.player.player_id) << 64) | u128::from(prepare);
    action(&client, snowflake(), RaidAction::Deploy { attempt_id }).await?;
    owner_call(
        &uri,
        &database,
        "proof_raid_finish",
        &[attempt_id.to_string()],
    )?;
    let special = action(&client, snowflake(), RaidAction::Claim { attempt_id }).await?;
    let catch_id = special
        .raid_attempts
        .iter()
        .find(|row| row.id == attempt_id)
        .unwrap()
        .catch_id
        .unwrap();
    let fish = special
        .inventory
        .iter()
        .find(|row| row.catch_id == catch_id)
        .unwrap();
    assert_eq!(fish.species_id, 74);
    assert_eq!(fish.rarity, "UUR");
    assert_eq!(special.player.fish_count, fish_before + 1);
    assert_eq!(special.player.next_cast_at, cooldown);
    assert_eq!(special.raid_statistics[0].special_completions, 1);
    client.disconnect();
    let recovered = client
        .player(
            ACTOR,
            "Other Raid Test Angler".into(),
            snowflake(),
            None,
            0,
            false,
        )
        .await?;
    assert_scope(&recovered);
    assert_eq!(recovered.raid_statistics[0].personal_completions, 2);
    assert_eq!(recovered.raid_statistics[0].community_completions, 1);
    assert_eq!(recovered.raid_statistics[0].special_completions, 1);
    client.disconnect();
    println!(
        "PASS native raid transport: all seven actions, scoped snapshots, replay-safe spending, per-rod state, community statistics, exceptional catch and reconnect."
    );
    Ok(())
}
