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
    let locked_offer = client
        .shop_action(9001, "Native proof".into(), snowflake(9), Some(20), None)
        .await;
    assert!(locked_offer.is_err_and(|error| error.to_string().contains("SHOP_LEVEL_REQUIRED")));
    if std::env::var("TEST_SOCIAL_PROOF").as_deref() == Ok("true") {
        let before = client
            .player(
                9408,
                "Native social proof".into(),
                snowflake(40),
                None,
                1,
                false,
            )
            .await?;
        assert_eq!(before.achievements.len(), 18);
        assert!(
            before
                .public_profiles
                .iter()
                .any(|row| row.player_id == before.player.player_id)
        );
        let ids = before
            .inventory
            .iter()
            .map(|row| row.catch_id)
            .collect::<Vec<_>>();
        assert!(!ids.is_empty());
        let quote = client
            .sale_action(
                9408,
                "Native social proof".into(),
                snowflake(41),
                Some(ids.clone()),
                None,
            )
            .await?;
        client.disconnect();
        let committed = client
            .sale_action(
                9408,
                "Native social proof".into(),
                snowflake(42),
                None,
                Some(quote.nonce),
            )
            .await?;
        assert!(committed.consumed);
        client.disconnect();
        assert_eq!(
            client
                .sale_action(
                    9408,
                    "Native social proof".into(),
                    snowflake(43),
                    None,
                    Some(quote.nonce)
                )
                .await?,
            committed
        );
        let after = client
            .player(
                9408,
                "Native social proof".into(),
                snowflake(44),
                None,
                1,
                false,
            )
            .await?;
        assert_eq!(after.player.coins, before.player.coins + quote.quoted_coins);
        assert_eq!(
            after.player.kept_count,
            before.player.kept_count - ids.len() as u32
        );
        assert_eq!(after.collection, before.collection);
        assert_eq!(after.earned_achievements, before.earned_achievements);
    }
    if std::env::var("TEST_SHOP_PROOF").as_deref() == Ok("true") {
        let quote = client
            .shop_action(
                9208,
                "Native trader proof".into(),
                snowflake(10),
                Some(20),
                None,
            )
            .await?;
        assert_eq!(quote.quoted_coins, 150);
        let committed = client
            .shop_action(
                9208,
                "Native trader proof".into(),
                snowflake(11),
                None,
                Some(quote.nonce),
            )
            .await?;
        assert!(committed.consumed);
        client.disconnect();
        assert_eq!(
            client
                .shop_action(
                    9208,
                    "Native trader proof".into(),
                    snowflake(12),
                    None,
                    Some(quote.nonce)
                )
                .await?,
            committed
        );
        let state = client
            .player(
                9208,
                "Native trader proof".into(),
                snowflake(13),
                None,
                1,
                false,
            )
            .await?;
        assert_eq!(state.player.coins, 199850);
        assert_eq!(state.licences.len(), 1);
        assert_eq!(state.owned_rods.len(), 1);
    }
    if std::env::var("TEST_CRAFTING_PROOF").as_deref() == Ok("true") {
        let quote = client
            .shop_action(
                9303,
                "Native crafting proof".into(),
                snowflake(20),
                Some(103),
                None,
            )
            .await?;
        client
            .shop_action(
                9303,
                "Native crafting proof".into(),
                snowflake(21),
                None,
                Some(quote.nonce),
            )
            .await?;
        let equipped = client
            .equip_bait(9303, "Native crafting proof".into(), snowflake(22), 3)
            .await?;
        assert_eq!(equipped.bait_loadout.expect("bait loadout").bait_id, 3);
        assert_eq!(equipped.owned_baits[0].uses_left, 10);
        let upgrade = client
            .upgrade_action(
                9303,
                "Native crafting proof".into(),
                snowflake(23),
                Some(1),
                None,
            )
            .await?;
        assert_eq!((upgrade.tin_cost, upgrade.scrap_cost), (10, 5));
        let committed = client
            .upgrade_action(
                9303,
                "Native crafting proof".into(),
                snowflake(24),
                None,
                Some(upgrade.nonce),
            )
            .await?;
        assert!(committed.consumed);
        client.disconnect();
        assert_eq!(
            client
                .upgrade_action(
                    9303,
                    "Native crafting proof".into(),
                    snowflake(25),
                    None,
                    Some(upgrade.nonce)
                )
                .await?,
            committed
        );
        let id = snowflake(26);
        let caught = client
            .player(9303, "Native crafting proof".into(), id, Some(5), 42, true)
            .await?;
        assert_eq!(caught.owned_rods[0].upgrade_level, 1);
        assert_eq!(caught.owned_baits[0].uses_left, 9);
        assert!((1..=2).contains(&caught.cast_pulls.len()));
        assert_eq!(
            caught
                .cast_equipment
                .as_ref()
                .expect("equipment snapshot")
                .luck_bp,
            700
        );
        let replay = client
            .player(9303, "Native crafting proof".into(), id, Some(5), 42, true)
            .await?;
        assert_eq!(replay.cast_pulls, caught.cast_pulls);
        assert_eq!(replay.cast_equipment, caught.cast_equipment);
        assert_eq!(replay.owned_baits[0].uses_left, 9);
        println!(
            "PASS native crafting client: bait purchase/equip, exact upgrade, reconnect replay, saved pulls and one bait charge"
        );
    }
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
