//! Cosmetic announcements follow committed awards; they never grant achievements.
use game_client::{AchievementUnlock, Client, Error};
use poise::serenity_prelude as serenity;
use serde::{Deserialize, Serialize};
use std::{
    collections::HashMap,
    path::PathBuf,
    sync::{Arc, Mutex},
    time::Duration,
};

#[derive(Clone, Debug, Serialize, Deserialize)]
struct Route {
    player_id: Option<u64>,
    channel_id: u64,
}

#[derive(Default, Serialize, Deserialize)]
struct Routes {
    database: String,
    users: HashMap<u64, Route>,
}

pub struct AchievementNotifications {
    routes: Mutex<Routes>,
    path: PathBuf,
}

impl AchievementNotifications {
    pub fn load(database: String, path: PathBuf) -> Self {
        let saved = std::fs::read(&path).ok().and_then(|bytes| {
            match serde_json::from_slice::<Routes>(&bytes) {
                Ok(routes) if routes.database == database => Some(routes),
                Ok(_) => None,
                Err(error) => {
                    tracing::warn!(%error, "Achievement channel history could not be read");
                    None
                }
            }
        });
        Self {
            routes: Mutex::new(saved.unwrap_or(Routes {
                database,
                users: HashMap::new(),
            })),
            path,
        }
    }

    /// Save context before a command can cause an award, without delaying its reply on disk I/O.
    pub fn record_channel(&self, discord_user_id: u64, channel_id: u64, player_id: Option<u64>) {
        if discord_user_id == 0 || channel_id == 0 {
            return;
        }
        let mut routes = self.routes.lock().expect("achievement routes mutex");
        let player_id = player_id.or_else(|| {
            routes
                .users
                .get(&discord_user_id)
                .and_then(|route| route.player_id)
        });
        routes.users.insert(
            discord_user_id,
            Route {
                player_id,
                channel_id,
            },
        );
    }

    pub async fn remember_player(self: &Arc<Self>, discord_user_id: u64, player_id: Option<u64>) {
        let Some(player_id) = player_id else {
            return;
        };
        {
            let mut routes = self.routes.lock().expect("achievement routes mutex");
            let Some(route) = routes.users.get_mut(&discord_user_id) else {
                return;
            };
            route.player_id = Some(player_id);
        }
        let this = self.clone();
        let outcome = tokio::task::spawn_blocking(move || -> Result<(), Error> {
            // Serialize writers too, so a slower write cannot replace a newer channel.
            let routes = this.routes.lock().expect("achievement routes mutex");
            if let Some(parent) = this.path.parent() {
                std::fs::create_dir_all(parent)?;
            }
            let temporary = this.path.with_extension("tmp");
            std::fs::write(&temporary, serde_json::to_vec(&*routes)?)?;
            std::fs::rename(temporary, &this.path)?;
            Ok(())
        })
        .await;
        if !matches!(outcome, Ok(Ok(()))) {
            tracing::warn!(?outcome, "Achievement channel history could not be saved");
        }
    }

    fn route(&self, unlock: &AchievementUnlock) -> Option<(u64, Route)> {
        let routes = self.routes.lock().expect("achievement routes mutex");
        if let Some(user) = unlock.discord_user_id {
            if let Some(route) = routes.users.get(&user) {
                return Some((user, route.clone()));
            }
        }
        routes.users.iter().find_map(|(user, route)| {
            (route.player_id == Some(unlock.player_id)).then(|| (*user, route.clone()))
        })
    }

    pub fn start(self: &Arc<Self>, game: Arc<Client>, http: Arc<serenity::Http>, website: String) {
        let notifications = self.clone();
        let mut unlocks = game.subscribe_achievement_unlocks();
        tokio::spawn(async move {
            loop {
                let unlock = match unlocks.recv().await {
                    Ok(unlock) => unlock,
                    Err(tokio::sync::broadcast::error::RecvError::Lagged(count)) => {
                        tracing::warn!(count, "Achievement announcement queue lagged");
                        continue;
                    }
                    Err(tokio::sync::broadcast::error::RecvError::Closed) => break,
                };
                if !game.ready() {
                    continue;
                }
                let Some((user, route)) = notifications.route(&unlock) else {
                    tracing::info!(
                        player_id = unlock.player_id,
                        achievement_id = unlock.achievement_id,
                        "Achievement earned before Discord channel is known"
                    );
                    continue;
                };
                notifications
                    .remember_player(user, Some(unlock.player_id))
                    .await;
                // Discord's enforced nonce prevents duplicate posts during short delivery retries.
                for attempt in 0..3 {
                    match serenity::ChannelId::new(route.channel_id)
                        .send_message(&http, achievement_message(&unlock, &website))
                        .await
                    {
                        Ok(_) => {
                            tracing::info!(
                                player_id = unlock.player_id,
                                achievement_id = unlock.achievement_id,
                                channel_id = route.channel_id,
                                "Achievement badge announced"
                            );
                            break;
                        }
                        Err(error) if attempt < 2 => {
                            tracing::warn!(%error, attempt, achievement_id = unlock.achievement_id, "Retrying achievement announcement");
                            tokio::time::sleep(Duration::from_secs(attempt + 1)).await;
                        }
                        Err(error) => {
                            tracing::warn!(%error, player_id = unlock.player_id, achievement_id = unlock.achievement_id, "Achievement badge delivery failed; award is retained")
                        }
                    }
                }
            }
        });
    }
}

pub fn achievement_message(unlock: &AchievementUnlock, website: &str) -> serenity::CreateMessage {
    let website = website.trim_end_matches('/');
    serenity::CreateMessage::new()
        .allowed_mentions(
            serenity::CreateAllowedMentions::new()
                .all_users(false)
                .all_roles(false)
                .everyone(false),
        )
        .nonce(serenity::Nonce::String(format!("a{:x}", unlock.key)))
        .enforce_nonce(true)
        .embed(
            serenity::CreateEmbed::new()
                .author(serenity::CreateEmbedAuthor::new(format!(
                    "{} unlocked an achievement!",
                    unlock.display_name
                )))
                .title(&unlock.name)
                .description(&unlock.description)
                .url(format!("{website}/#achievements"))
                .image(format!(
                    "{website}/achievements/badge-{}.png",
                    unlock.achievement_id
                ))
                .field("Title earned", &unlock.title, false)
                .color(if unlock.bonus { 0x9B75BE } else { 0xD9AA55 }),
        )
}

#[cfg(test)]
mod tests {
    use super::*;
    fn award() -> AchievementUnlock {
        AchievementUnlock {
            key: (7u128 << 32) | 17,
            player_id: 7,
            discord_user_id: Some(99),
            display_name: "Test Angler".into(),
            achievement_id: 17,
            name: "A fish called Fihs".into(),
            description: "Discover Fihs.".into(),
            title: "Myth Hunter".into(),
            bonus: true,
        }
    }

    #[test]
    fn embed_has_real_art_and_safe_retry_identity() {
        let value =
            serde_json::to_value(achievement_message(&award(), "https://fish.ichaa.dev/")).unwrap();
        assert_eq!(value["embeds"][0]["title"], "A fish called Fihs");
        assert_eq!(value["embeds"][0]["description"], "Discover Fihs.");
        assert_eq!(
            value["embeds"][0]["image"]["url"],
            "https://fish.ichaa.dev/achievements/badge-17.png"
        );
        assert_eq!(value["allowed_mentions"]["parse"], serde_json::json!([]));
        assert_eq!(value["enforce_nonce"], true);
        assert_eq!(value["nonce"], "a700000011");
    }

    #[tokio::test]
    async fn website_awards_keep_the_latest_channel_across_restart() {
        let path = std::env::temp_dir().join(format!(
            "fishbound-achievement-routes-{}.json",
            std::process::id()
        ));
        let notifications = Arc::new(AchievementNotifications::load("proof".into(), path.clone()));
        notifications.record_channel(99, 42, None);
        assert_eq!(notifications.route(&award()).unwrap().1.channel_id, 42);
        notifications.remember_player(99, Some(7)).await;
        notifications.record_channel(99, 84, None);
        notifications.remember_player(99, Some(7)).await;
        let mut website_award = award();
        website_award.discord_user_id = None;
        let restored = AchievementNotifications::load("proof".into(), path.clone());
        assert_eq!(restored.route(&website_award).unwrap().1.channel_id, 84);
        let other_database = AchievementNotifications::load("different-proof".into(), path.clone());
        assert!(other_database.route(&website_award).is_none());
        let mut other_player = website_award;
        other_player.player_id = 8;
        assert!(restored.route(&other_player).is_none());
        std::fs::remove_file(path).unwrap();
    }
}
