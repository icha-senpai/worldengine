//! Shared, stateless haul buttons. The clicker's current loadout starts the trip.
use game_client::module_bindings::HaulTrip;
use poise::serenity_prelude as serenity;

pub const PREFIX: &str = "haul_again:";
pub const BOAT_ID: &str = "haul_again:boat";
pub const NET_ID: &str = "haul_again:net";

pub fn request(
    custom_id: &str,
    clicking_user_id: u64,
) -> Result<(u64, &'static str), &'static str> {
    if clicking_user_id > 0 {
        match custom_id {
            BOAT_ID => return Ok((clicking_user_id, "boat")),
            NET_ID => return Ok((clicking_user_id, "net")),
            _ => {}
        }
    }
    Err("That haul button is invalid. Use /fishboat or /fishnet to start a trip.")
}

pub fn components(kind: &str) -> Vec<serenity::CreateActionRow> {
    let (id, label, emoji) = match kind {
        "boat" => (BOAT_ID, "Boat again", "🚣"),
        "net" => (NET_ID, "Net again", "🕸"),
        _ => return vec![],
    };
    vec![serenity::CreateActionRow::Buttons(vec![
        serenity::CreateButton::new(id)
            .label(label)
            .emoji(serenity::ReactionType::Unicode(emoji.into()))
            .style(serenity::ButtonStyle::Success),
    ])]
}

pub fn status(trip: &HaulTrip) -> Result<String, String> {
    let (_, count) = game_rules::hauls::settings(&trip.kind)?;
    Ok(if trip.status == "running" {
        let emoji = if trip.kind == "boat" { "🚣" } else { "🕸" };
        format!(
            "{emoji} **{}** is underway in **{}**!\n{} catch opportunities · ready <t:{}:R>.\nYour results will arrive in the channel where it started. You can keep using /fish. No bait required.",
            trip.equipment_name,
            trip.biome.name,
            count,
            trip.due_at.to_micros_since_unix_epoch() / 1_000_000
        )
    } else {
        format!(
            "Your {} haul is complete: {} catches, +{} XP and +{} coins. Results are saved in your Tackle box and Journal.",
            trip.kind,
            trip.results.len(),
            trip.total_xp,
            trip.total_coins
        )
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn buttons_start_the_matching_kind_for_the_clicking_angler() {
        for user in [123, 456] {
            assert_eq!(request(BOAT_ID, user), Ok((user, "boat")));
            assert_eq!(request(NET_ID, user), Ok((user, "net")));
        }
        assert!(request(BOAT_ID, 0).is_err());
        for invalid in [
            "haul_again:",
            "haul_again:rod",
            "haul_again:boat:123",
            "fish_again:cast",
        ] {
            assert!(request(invalid, 123).is_err());
        }
    }

    #[test]
    fn completed_cards_get_one_matching_green_persistent_button() {
        for (kind, id, label) in [
            ("boat", BOAT_ID, "Boat again"),
            ("net", NET_ID, "Net again"),
        ] {
            let rows = serde_json::to_value(components(kind)).unwrap();
            assert_eq!(rows.as_array().unwrap().len(), 1);
            let buttons = rows[0]["components"].as_array().unwrap();
            assert_eq!(buttons.len(), 1);
            assert_eq!(buttons[0]["custom_id"], id);
            assert_eq!(buttons[0]["label"], label);
            assert_eq!(buttons[0]["style"], 3);
            assert_ne!(buttons[0]["disabled"], true);
        }
    }
}
