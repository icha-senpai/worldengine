//! Runs only against a fresh loopback proof database; never connects to Discord.
use game_client::{Client, Error, module_bindings::ServiceRole};
use std::time::{SystemTime, UNIX_EPOCH};

fn snowflake(sequence: u64) -> u64 {
    let ms = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_millis() as u64;
    ((ms - 1_420_070_400_000) << 22) | sequence
}
#[tokio::main]
async fn main() -> Result<(), Error> {
    let uri = std::env::var("TEST_SPACETIMEDB_URI")?;
    let database = std::env::var("TEST_SPACETIMEDB_DATABASE")?;
    if uri != "http://127.0.0.1:3127" || !database.starts_with("fishbound-proof-") {
        return Err("Proof adapter requires isolated local database".into());
    }
    let client = Client::connect(
        &uri,
        &database,
        std::env::var("TEST_BOT_TOKEN")?,
        ServiceRole::DiscordAdapter,
    )
    .await?;
    let id = snowflake(1);
    let result = client
        .player(9001, "Native proof".into(), id, Some(5), 42, true)
        .await?;
    assert_eq!(result.player.completed_casts, 1);
    let receipt = result.receipt.expect("committed receipt");
    let replay = client
        .player(9001, "Native proof".into(), id, Some(5), 42, true)
        .await?;
    assert_eq!(replay.receipt.expect("replayed receipt"), receipt);
    assert_eq!(replay.player.completed_casts, 1);
    assert_eq!(replay.species.len(), 251);
    assert_eq!(replay.biomes.len(), 7);
    assert_eq!(replay.rods.len(), 7);
    let unchanged = client
        .change_loadout(9001, "Native proof".into(), snowflake(4), Some(1), Some(1))
        .await?;
    assert_eq!(unchanged.player.next_cast_at, replay.player.next_cast_at);
    let locked = client
        .change_loadout(9001, "Native proof".into(), snowflake(5), Some(7), None)
        .await;
    assert!(locked.is_err_and(|error| error.to_string().contains("BIOME_LEVEL_REQUIRED")));
    let daily_id = snowflake(6);
    let delivery = client
        .daily(9001, "Native proof".into(), daily_id, Some(5), 42)
        .await?;
    assert!(delivery.claimed);
    assert_eq!(delivery.coins_granted, 100);
    assert_eq!(delivery.stamps, 1);
    client.disconnect();
    assert_eq!(
        client
            .daily(9001, "Native proof".into(), daily_id, Some(5), 42)
            .await?,
        delivery
    );
    let duplicate = client
        .daily(9001, "Native proof".into(), snowflake(7), Some(6), 42)
        .await?;
    assert!(!duplicate.claimed);
    assert_eq!(duplicate.coins_granted, 0);
    let after_delivery = client
        .player(
            9001,
            "Native proof".into(),
            snowflake(8),
            Some(5),
            42,
            false,
        )
        .await?;
    assert_eq!(after_delivery.player.coins, replay.player.coins + 100);
    assert_eq!(after_delivery.player.total_xp, replay.player.total_xp);
    assert_eq!(
        after_delivery.player.next_cast_at,
        replay.player.next_cast_at
    );
    client.disconnect();
    let recovered = client
        .player(9001, "Native proof".into(), id, Some(5), 42, true)
        .await?;
    assert_eq!(
        recovered.receipt.expect("automatically recovered receipt"),
        receipt
    );
    let (left, right) = tokio::join!(
        client.player(9002, "Left".into(), snowflake(2), None, 42, true),
        client.player(9003, "Right".into(), snowflake(3), None, 42, true)
    );
    assert_eq!(left?.player.discord_user_id, 9002);
    assert_eq!(right?.player.discord_user_id, 9003);
    drop(client);
    let resumed = Client::connect(
        &uri,
        &database,
        std::env::var("TEST_BOT_TOKEN")?,
        ServiceRole::DiscordAdapter,
    )
    .await?;
    assert_eq!(
        resumed
            .player(9001, "Native proof".into(), id, Some(5), 42, true)
            .await?
            .receipt
            .expect("reconnect receipt"),
        receipt
    );
    println!(
        "PASS native Rust adapter: commit, replay, serialized selection, and reconnect recovery"
    );
    Ok(())
}
