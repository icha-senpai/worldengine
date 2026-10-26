//! Restart-safe delivery of already committed haul rewards.
use game_client::{Client, module_bindings::HaulTrip};
use poise::serenity_prelude as serenity;
use std::{sync::Arc, time::Duration};

pub fn start(game: Arc<Client>, http: Arc<serenity::Http>, website: String) {
    tokio::spawn(async move {
        let mut interval = tokio::time::interval(Duration::from_secs(5));
        loop {
            interval.tick().await;
            if !game.ready() {
                continue;
            }
            for pending in game.pending_hauls() {
                if pending.status == "running" {
                    if let Err(error) = game.finish_haul(pending.interaction_id).await {
                        tracing::warn!(%error, trip=pending.interaction_id, "Haul completion deferred; trip retained");
                    }
                    continue;
                }
                let nonce = std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap_or_default()
                    .as_nanos()
                    ^ (u128::from(std::process::id()) << 64);
                let trip = match game.claim_haul(pending.interaction_id, nonce).await {
                    Ok(Some(trip)) => trip,
                    Ok(None) => continue,
                    Err(error) => {
                        tracing::warn!(%error, "Haul delivery lease unavailable");
                        continue;
                    }
                };
                let lines: Vec<_> = trip
                    .results
                    .iter()
                    .map(|pull| match pull.outcome.as_str() {
                        "fish" => format!(
                            "🐟 **{}** · {} rank · {} · {} · +{} XP",
                            game.haul_species_name(pull.species_id.unwrap_or(0)),
                            pull.rarity,
                            crate::measurements::format_length(u64::from(pull.length_mm)),
                            crate::measurements::format_weight(pull.weight_g),
                            pull.xp_granted
                        ),
                        "junk" => format!(
                            "🥫 Rusted tin ×{} · +{} XP",
                            pull.item_quantity, pull.xp_granted
                        ),
                        _ => format!(
                            "🧰 Scrap ×{} · +{} coins · +{} XP",
                            pull.item_quantity, pull.coins_granted, pull.xp_granted
                        ),
                    })
                    .collect();
                let message = haul_message(&trip, &lines, &website);
                match serenity::ChannelId::new(trip.channel_id)
                    .send_message(&http, message)
                    .await
                {
                    Ok(_) => {
                        if let Err(error) = game.acknowledge_haul(trip.interaction_id, nonce).await
                        {
                            tracing::warn!(%error, trip=trip.interaction_id, "Haul posted; acknowledgement must recover");
                        }
                    }
                    Err(error) => {
                        tracing::warn!(%error, trip=trip.interaction_id, attempt=trip.attempts, "Haul delivery deferred; rewards and pending message retained")
                    }
                }
            }
        }
    });
}

pub fn haul_message(trip: &HaulTrip, lines: &[String], website: &str) -> serenity::CreateMessage {
    serenity::CreateMessage::new()
        .allowed_mentions(
            serenity::CreateAllowedMentions::new()
                .all_users(false)
                .all_roles(false)
                .everyone(false),
        )
        .nonce(serenity::Nonce::String(format!(
            "h{:x}",
            trip.interaction_id
        )))
        .enforce_nonce(true)
        .components(crate::haul_again::components(&trip.kind))
        .content(format!(
            "<@{}>, your {} haul is back!",
            trip.discord_user_id, trip.kind
        ))
        .embed(
            serenity::CreateEmbed::new()
                .title(format!(
                    "{} · {} catches",
                    trip.equipment_name,
                    trip.results.len()
                ))
                .description(lines.join("\n"))
                .field(
                    "Total earned",
                    format!("+{} XP · +{} coins", trip.total_xp, trip.total_coins),
                    false,
                )
                .footer(serenity::CreateEmbedFooter::new(
                    "Fish are saved in your Tackle box. Start another trip whenever you're ready.",
                ))
                .url(format!("{}/#inventory", website.trim_end_matches('/')))
                .color(0x408B99),
        )
}

#[cfg(test)]
mod tests {
    use super::*;
    use game_client::module_bindings::{BiomeDefinition, CommandReceipt, GameConfig};
    use spacetimedb_sdk::{Identity, Timestamp};

    #[test]
    fn completed_haul_message_has_all_results_and_stable_safe_retry_payload() {
        let receipt = CommandReceipt {
            interaction_id: 100,
            player_id: 1,
            discord_user_id: 99,
            guild_id: None,
            channel_id: 42,
            command: "fishboat".into(),
            caught_at: Timestamp::UNIX_EPOCH,
            outcome: "junk".into(),
            catch_id: None,
            species_id: None,
            rarity: String::new(),
            length_mm: 0,
            weight_g: 0,
            size_grade: 0,
            xp_granted: 2,
            coins_granted: 0,
            sale_value_coins: 0,
            rules_version: 10,
            content_version: 5,
            item_granted: "rusted_tin".into(),
            item_quantity: 1,
            biome_id: 1,
            rod_id: 101,
        };
        let mut trip = HaulTrip {
            interaction_id: u64::MAX,
            player_id: 1,
            discord_user_id: 99,
            guild_id: None,
            channel_id: 42,
            kind: "boat".into(),
            equipment_id: 101,
            equipment_name: "Twig Boat".into(),
            quality_level: 0,
            luck_bp: 0,
            xp_bonus_bp: 0,
            biome: BiomeDefinition {
                biome_id: 1,
                name: "Meadow Pond".into(),
                description: String::new(),
                minimum_level: 1,
                required_power: 0,
                fish_weight: 86,
                junk_weight: 12,
                treasure_weight: 2,
            },
            rules: GameConfig {
                version: 10,
                cast_cooldown_seconds: 60,
                inventory_capacity: 0,
                recent_limit: 50,
                level_cap: 500,
            },
            collection_focus_enabled: false,
            started_at: Timestamp::UNIX_EPOCH,
            due_at: Timestamp::UNIX_EPOCH,
            completed_at: Some(Timestamp::UNIX_EPOCH),
            status: "ready".into(),
            results: vec![receipt; 10],
            total_xp: 20,
            total_coins: 0,
            available_at: Timestamp::UNIX_EPOCH,
            lease_owner: Identity::ZERO,
            lease_nonce: 11,
            attempts: 1,
        };
        let lines = vec!["Rusted tin ×1 · +2 XP".into(); 10];
        let first =
            serde_json::to_value(haul_message(&trip, &lines, "https://fishbound.ichaa.dev/"))
                .unwrap();
        trip.lease_nonce = 22;
        trip.attempts = 2;
        let retry =
            serde_json::to_value(haul_message(&trip, &lines, "https://fishbound.ichaa.dev/"))
                .unwrap();
        assert_eq!(first, retry);
        assert_eq!(first["embeds"][0]["title"], "Twig Boat · 10 catches");
        assert_eq!(
            first["embeds"][0]["description"]
                .as_str()
                .unwrap()
                .lines()
                .count(),
            10
        );
        assert_eq!(
            first["embeds"][0]["fields"][0]["value"],
            "+20 XP · +0 coins"
        );
        assert_eq!(first["allowed_mentions"]["parse"], serde_json::json!([]));
        assert_eq!(first["enforce_nonce"], true);
        assert_eq!(
            first["components"][0]["components"][0]["custom_id"],
            crate::haul_again::BOAT_ID
        );
        trip.kind = "net".into();
        let net = serde_json::to_value(haul_message(&trip, &lines, "https://fishbound.ichaa.dev/"))
            .unwrap();
        assert_eq!(
            net["components"][0]["components"][0]["custom_id"],
            crate::haul_again::NET_ID
        );
        assert_eq!(first["nonce"], "hffffffffffffffff");
        trip.status = "running".into();
        let net_status = crate::haul_again::status(&trip).unwrap();
        assert!(net_status.contains("4 catch opportunities"));
        trip.kind = "boat".into();
        let boat_status = crate::haul_again::status(&trip).unwrap();
        assert!(boat_status.contains("10 catch opportunities"));
        assert!(boat_status.contains("You can keep using /fish"));
        assert_eq!(
            first["embeds"][0]["url"],
            "https://fishbound.ichaa.dev/#inventory"
        );
    }
}
