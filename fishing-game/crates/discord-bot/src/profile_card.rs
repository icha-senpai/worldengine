//! Public profile cards share the website's progression, selected title and badge.
use game_client::{
    Snapshot,
    module_bindings::{AchievementDefinition, AnglerTitle, EarnedAchievement},
};
use poise::serenity_prelude as serenity;

fn selected_title<'a>(
    player: u64,
    titles: &[AnglerTitle],
    earned: &[EarnedAchievement],
    definitions: &'a [AchievementDefinition],
) -> Option<&'a AchievementDefinition> {
    let selected = titles.iter().find(|row| row.player_id == player)?;
    earned
        .iter()
        .find(|row| row.player_id == player && row.achievement_id == selected.achievement_id)?;
    definitions
        .iter()
        .find(|row| row.achievement_id == selected.achievement_id)
}

pub fn profile_embed(
    snapshot: &Snapshot,
    target: u64,
    website: &str,
    portrait: Option<&str>,
) -> Option<serenity::CreateEmbed> {
    let profile = snapshot
        .public_profiles
        .iter()
        .find(|row| row.player_id == target)?;
    let standing = snapshot
        .standings
        .iter()
        .find(|row| row.player_id == target);
    let ordinary = snapshot
        .species
        .iter()
        .filter(|row| row.counts_for_ordinary_collection_completion)
        .count();
    let badges = snapshot
        .earned_achievements
        .iter()
        .filter(|row| row.player_id == target)
        .count();
    let perfected = snapshot
        .perfect_species
        .iter()
        .filter(|row| row.player_id == target)
        .count();
    let title = selected_title(
        target,
        &snapshot.titles,
        &snapshot.earned_achievements,
        &snapshot.achievements,
    );
    Some(card(
        &PublicCard {
            player_id: target,
            display_name: &profile.display_name,
            level: profile.level,
            fish: profile.fish_count,
            discoveries: profile.discoveries,
            ordinary,
            uur: standing.map_or(0, |row| row.uur_count),
            records: standing.map_or(0, |row| row.records_held),
            badges,
            perfected,
            title,
            portrait,
        },
        website,
    ))
}

struct PublicCard<'a> {
    player_id: u64,
    display_name: &'a str,
    level: u32,
    fish: u64,
    discoveries: u32,
    ordinary: usize,
    uur: u64,
    records: u32,
    badges: usize,
    perfected: usize,
    title: Option<&'a AchievementDefinition>,
    portrait: Option<&'a str>,
}

fn card(profile: &PublicCard<'_>, website: &str) -> serenity::CreateEmbed {
    let website = website.trim_end_matches('/');
    let link = format!("{website}/?angler={}#anglers", profile.player_id);
    let title = profile.title.map_or("Angler", |row| row.title.as_str());
    let mut embed = serenity::CreateEmbed::new()
        .author(serenity::CreateEmbedAuthor::new(profile.display_name).url(&link))
        .title(title)
        .url(&link)
        .description(format!("[View angler profile]({link})"))
        .color(if profile.title.is_some_and(|row| row.bonus) {
            0x9B75BE
        } else {
            0xD9AA55
        })
        .field("Angler level", profile.level.to_string(), true)
        .field("Lifetime fish caught", profile.fish.to_string(), true)
        .field(
            "Species discovered",
            format!("{} / {}", profile.discoveries, profile.ordinary),
            true,
        )
        .field("UUR catches", profile.uur.to_string(), true)
        .field("Records held", profile.records.to_string(), true)
        .field("Badges earned", profile.badges.to_string(), true)
        .field("UUR perfected species", profile.perfected.to_string(), true)
        .footer(serenity::CreateEmbedFooter::new(
            "Fishbound · Manage your fishing adventure on the website",
        ));
    if let Some(portrait) = profile.portrait.filter(|url| !url.is_empty()) {
        embed = embed.thumbnail(portrait);
    }
    if let Some(badge) = profile.title {
        embed = embed.image(format!(
            "{website}/achievements/badge-{}.png",
            badge.achievement_id
        ));
    }
    embed
}

#[cfg(test)]
mod tests {
    use super::*;

    fn badge() -> AchievementDefinition {
        AchievementDefinition {
            achievement_id: 17,
            name: "A fish called Fihs".into(),
            description: "Discover Fihs.".into(),
            title: "Myth Hunter".into(),
            target: 1,
            bonus: true,
        }
    }

    #[test]
    fn profile_has_public_stats_portrait_and_selected_badge() {
        let badge = badge();
        let value = serde_json::to_value(card(
            &PublicCard {
                player_id: 7,
                display_name: "Test Angler",
                level: 42,
                fish: 1234,
                discoveries: 81,
                ordinary: 249,
                uur: 8,
                records: 2,
                badges: 12,
                perfected: 1,
                title: Some(&badge),
                portrait: Some("https://cdn.discordapp.com/avatars/1/example.png"),
            },
            "https://fish.ichaa.dev/",
        ))
        .unwrap();
        assert_eq!(value["author"]["name"], "Test Angler");
        assert_eq!(value["title"], "Myth Hunter");
        assert_eq!(value["url"], "https://fish.ichaa.dev/?angler=7#anglers");
        assert_eq!(
            value["image"]["url"],
            "https://fish.ichaa.dev/achievements/badge-17.png"
        );
        assert_eq!(
            value["thumbnail"]["url"],
            "https://cdn.discordapp.com/avatars/1/example.png"
        );
        assert_eq!(value["fields"][0]["value"], "42");
        assert_eq!(value["fields"][1]["value"], "1234");
        assert_eq!(value["fields"][2]["value"], "81 / 249");
        let text = value.to_string();
        for private in [
            "coins",
            "wallet",
            "inventory",
            "discord_user_id",
            "total_xp",
        ] {
            assert!(!text.contains(private), "Private field: {private}");
        }
    }

    #[test]
    fn missing_title_or_portrait_has_a_clean_fallback() {
        let value = serde_json::to_value(card(
            &PublicCard {
                player_id: 7,
                display_name: "New Angler",
                level: 1,
                fish: 0,
                discoveries: 0,
                ordinary: 249,
                uur: 0,
                records: 0,
                badges: 0,
                perfected: 0,
                title: None,
                portrait: None,
            },
            "https://fish.ichaa.dev",
        ))
        .unwrap();
        assert_eq!(value["title"], "Angler");
        assert!(value.get("image").is_none());
        assert!(value.get("thumbnail").is_none());
    }

    #[test]
    fn badge_must_be_earned_by_the_profile_owner() {
        let definitions = [badge()];
        let titles = [AnglerTitle {
            player_id: 7,
            achievement_id: 17,
        }];
        let mut awards = [EarnedAchievement {
            key: 1,
            player_id: 8,
            achievement_id: 17,
            completed_at: spacetimedb_sdk::Timestamp::UNIX_EPOCH,
        }];
        assert!(selected_title(7, &titles, &awards, &definitions).is_none());
        awards[0].player_id = 7;
        assert_eq!(
            selected_title(7, &titles, &awards, &definitions)
                .unwrap()
                .achievement_id,
            17
        );
        assert!(selected_title(8, &titles, &awards, &definitions).is_none());
    }
}
