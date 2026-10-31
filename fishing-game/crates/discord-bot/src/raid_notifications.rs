use crate::{
    achievement_notifications::AchievementNotifications, catch_art::rank_color, measurements,
};
use game_client::{Client, module_bindings::RaidDelivery};
use poise::serenity_prelude as serenity;
use std::{sync::Arc, time::Duration};

pub fn start(
    game: Arc<Client>,
    http: Arc<serenity::Http>,
    website: String,
    routes: Arc<AchievementNotifications>,
) {
    tokio::spawn(async move {
        let mut interval = tokio::time::interval(Duration::from_secs(5));
        loop {
            interval.tick().await;
            if !game.ready() {
                continue;
            }
            for pending in game.pending_raid_deliveries() {
                if pending.channel_id == 0 {
                    if let Some(channel) =
                        routes.channel_for(pending.discord_user_id, pending.player_id)
                    {
                        if let Err(error) = game
                            .remember_achievement_route(pending.discord_user_id, channel)
                            .await
                        {
                            tracing::warn!(%error, "Raid channel could not be synchronized");
                            continue;
                        }
                    }
                }
                let nonce = (std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap_or_default()
                    .as_nanos()
                    ^ (u128::from(std::process::id()) << 64)
                    ^ pending.attempt_id)
                    .max(1);
                let delivery = match game.claim_raid_delivery(pending.attempt_id, nonce).await {
                    Ok(Some(delivery)) => delivery,
                    Ok(None) => continue,
                    Err(error) => {
                        tracing::warn!(%error, "Raid delivery lease unavailable");
                        continue;
                    }
                };
                match serenity::ChannelId::new(delivery.channel_id)
                    .send_message(&http, raid_message(&delivery, &website))
                    .await
                {
                    Ok(_) => {
                        if let Err(error) = game
                            .acknowledge_raid_delivery(delivery.attempt_id, nonce)
                            .await
                        {
                            tracing::warn!(%error, attempt=delivery.attempt_id, "Raid posted; acknowledgement must recover");
                        }
                    }
                    Err(error) => {
                        tracing::warn!(%error, attempt=delivery.attempt_id, retry=delivery.attempts, "Raid delivery deferred; pending message retained");
                    }
                }
            }
        }
    });
}

fn delivery_nonce(id: u128) -> String {
    let alphabet = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
    let mut result = String::with_capacity(23);
    result.push('r');
    for shift in (0..22).rev() {
        result.push(alphabet[((id >> (shift * 6)) & 63) as usize] as char);
    }
    result
}

fn fish_asset(asset: &str) -> Option<&str> {
    let slug = asset.strip_prefix("/fish/")?.strip_suffix(".png")?;
    (!slug.is_empty()
        && slug
            .bytes()
            .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-'))
    .then_some(asset)
}

pub fn raid_message(delivery: &RaidDelivery, website: &str) -> serenity::CreateMessage {
    let website = website.trim_end_matches('/');
    let target: String = delivery.target_name.chars().take(120).collect();
    let grades = ["F", "D", "C", "B", "A", "S", "SS", "SSS", "UR", "UUR"];
    let mut embed = match delivery.kind.as_str() {
        "personal" => {
            let grade = delivery
                .grade
                .and_then(|grade| grades.get(usize::from(grade)))
                .copied()
                .unwrap_or("?");
            let mut embed = serenity::CreateEmbed::new()
                .title(format!("{target} - {grade} raid"))
                .description("Your expedition has returned.")
                .field(
                    "Raid rewards",
                    format!(
                        "+{} fillets\n+{} scales",
                        delivery.awarded_fillets, delivery.awarded_scales
                    ),
                    false,
                )
                .color(rank_color(grade));
            if delivery.first_clear && grade != "?" {
                embed = embed
                    .field("Seal earned", format!("{grade} seal"), false)
                    .image(format!(
                        "{website}/raid-seals/{}.png",
                        grade.to_ascii_lowercase()
                    ));
            }
            embed
        }
        "special" => {
            let mut embed = serenity::CreateEmbed::new()
                .title(format!("{target} - special raid"))
                .description("Your expedition has returned.");
            if let Some(catch) = &delivery.special_catch {
                embed = embed
                    .field(
                        "Catch",
                        format!(
                            "{} rank - {} - {}",
                            catch.rarity,
                            measurements::format_length(u64::from(catch.length_mm)),
                            measurements::format_weight(catch.weight_g)
                        ),
                        false,
                    )
                    .color(rank_color(&catch.rarity));
            }
            embed
        }
        "community" => serenity::CreateEmbed::new()
            .title(target)
            .description("+1 community completion credit - bragging rights earned.")
            .color(0x408B99),
        _ => serenity::CreateEmbed::new()
            .title("Raid complete")
            .description("Your expedition has returned."),
    };
    if delivery.kind != "community" {
        if let Some(asset) = fish_asset(&delivery.species_asset) {
            embed = embed.thumbnail(format!("{website}{asset}"));
        }
    }
    serenity::CreateMessage::new()
        .allowed_mentions(
            serenity::CreateAllowedMentions::new()
                .all_users(false)
                .all_roles(false)
                .everyone(false),
        )
        .nonce(serenity::Nonce::String(delivery_nonce(delivery.attempt_id)))
        .enforce_nonce(true)
        .content(format!(
            "<@{}>, your raid is complete!",
            delivery.discord_user_id
        ))
        .embed(embed.url(format!("{website}/#quests")))
}

#[cfg(test)]
mod tests {
    use super::*;
    use game_client::module_bindings::CommandReceipt;
    use spacetimedb_sdk::{Identity, Timestamp};

    fn delivery() -> RaidDelivery {
        RaidDelivery {
            attempt_id: 1,
            player_id: 7,
            discord_user_id: 99,
            display_name: "Angler".into(),
            kind: "personal".into(),
            target_name: "Anchovy".into(),
            species_asset: "/fish/anchovy.png".into(),
            grade: Some(0),
            awarded_fillets: 62,
            awarded_scales: 45,
            first_clear: true,
            special_catch: None,
            completed_at: Timestamp::UNIX_EPOCH,
            available_at: Timestamp::UNIX_EPOCH,
            channel_id: 42,
            lease_owner: Identity::ZERO,
            lease_nonce: 1,
            attempts: 1,
        }
    }

    fn catch(rarity: &str) -> CommandReceipt {
        CommandReceipt {
            interaction_id: 7,
            player_id: 7,
            discord_user_id: 99,
            guild_id: None,
            channel_id: 0,
            command: "fishraid".into(),
            caught_at: Timestamp::UNIX_EPOCH,
            outcome: "fish".into(),
            catch_id: Some(8),
            species_id: Some(74),
            rarity: rarity.into(),
            length_mm: 127,
            weight_g: 454,
            size_grade: 1,
            xp_granted: 0,
            coins_granted: 0,
            sale_value_coins: 0,
            rules_version: 17,
            content_version: 5,
            item_granted: String::new(),
            item_quantity: 0,
            biome_id: 1,
            rod_id: 1,
        }
    }

    fn payload(row: &RaidDelivery) -> serde_json::Value {
        serde_json::to_value(raid_message(row, "https://fishbound.ichaa.dev/")).unwrap()
    }

    #[test]
    fn personal_and_repeat_show_only_earned_seals_and_safe_assets() {
        let mut row = delivery();
        let first = payload(&row);
        assert_eq!(first["content"], "<@99>, your raid is complete!");
        assert_eq!(first["allowed_mentions"]["parse"], serde_json::json!([]));
        assert_eq!(first["enforce_nonce"], true);
        assert_eq!(first["embeds"][0]["title"], "Anchovy - F raid");
        assert_eq!(
            first["embeds"][0]["fields"][0]["value"],
            "+62 fillets\n+45 scales"
        );
        assert_eq!(first["embeds"][0]["fields"][1]["name"], "Seal earned");
        assert_eq!(
            first["embeds"][0]["thumbnail"]["url"],
            "https://fishbound.ichaa.dev/fish/anchovy.png"
        );
        assert_eq!(
            first["embeds"][0]["image"]["url"],
            "https://fishbound.ichaa.dev/raid-seals/f.png"
        );
        assert_eq!(
            first["embeds"][0]["url"],
            "https://fishbound.ichaa.dev/#quests"
        );
        row.attempts = 9;
        row.lease_nonce = 77;
        assert_eq!(first, payload(&row));
        row.first_clear = false;
        row.species_asset = "/fish/../secret.png".into();
        let repeat = payload(&row);
        assert_eq!(repeat["embeds"][0]["fields"].as_array().unwrap().len(), 1);
        assert!(repeat["embeds"][0].get("image").is_none());
        assert!(repeat["embeds"][0].get("thumbnail").is_none());
        assert_eq!(first["nonce"], repeat["nonce"]);
        for invalid in [
            "https://evil.test/fish/a.png",
            "/fish/a.png?x",
            "/fish/a/b.png",
            "/fish/.png",
        ] {
            assert!(fish_asset(invalid).is_none());
        }
    }

    #[test]
    fn special_fish_and_community_do_not_invent_materials_or_seals() {
        let mut row = delivery();
        row.kind = "special".into();
        row.grade = None;
        row.first_clear = false;
        for (name, rarity, asset) in [
            ("Fihs", "UUR", "/fish/fihs.png"),
            ("Nidalees Lost Sock", "F", "/fish/nidalees-lost-sock.png"),
        ] {
            row.target_name = name.into();
            row.species_asset = asset.into();
            row.special_catch = Some(catch(rarity));
            let message = payload(&row);
            let embed = &message["embeds"][0];
            assert_eq!(
                embed["fields"][0]["value"],
                format!("{rarity} rank - 5.0 in - 1 lb 0.01 oz")
            );
            assert_eq!(embed["color"], rank_color(rarity));
            assert_eq!(embed["fields"].as_array().unwrap().len(), 1);
            assert!(embed.get("image").is_none());
            assert!(!message.to_string().contains("fillets"));
            assert!(!message.to_string().contains("scales"));
        }
        row.kind = "community".into();
        row.target_name = "Community expedition".into();
        row.special_catch = None;
        row.species_asset.clear();
        let community = payload(&row);
        assert!(
            community["embeds"][0]["description"]
                .as_str()
                .unwrap()
                .contains("+1 community")
        );
        assert!(community["embeds"][0].get("fields").is_none());
        assert!(community["embeds"][0].get("thumbnail").is_none());
    }

    #[test]
    fn nonce_encodes_all_bits_and_embeds_fit_limits() {
        let keys = [0, 1, 1u128 << 127, u128::MAX];
        let nonces: Vec<_> = keys.into_iter().map(delivery_nonce).collect();
        assert!(nonces.iter().all(|nonce| nonce.len() == 23));
        assert_eq!(
            nonces
                .iter()
                .collect::<std::collections::HashSet<_>>()
                .len(),
            keys.len()
        );
        let mut row = delivery();
        row.target_name = "a".repeat(500);
        let message = payload(&row);
        assert!(message["embeds"][0]["title"].as_str().unwrap().len() <= 256);
        assert!(message["content"].as_str().unwrap().len() <= 2000);
        assert!(message["nonce"].as_str().unwrap().len() <= 25);
    }
}
