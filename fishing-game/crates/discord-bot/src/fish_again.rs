use poise::serenity_prelude as serenity;

pub const PREFIX: &str = "fish_again:";
pub const CUSTOM_ID: &str = "fish_again:cast";

pub fn caster(custom_id: &str, clicking_user_id: u64) -> Result<u64, &'static str> {
    if custom_id == CUSTOM_ID && clicking_user_id > 0 {
        Ok(clicking_user_id)
    } else {
        Err("That fishing button is invalid. Use /fish for a new cast.")
    }
}

pub fn components() -> Vec<serenity::CreateActionRow> {
    vec![serenity::CreateActionRow::Buttons(vec![
        serenity::CreateButton::new(CUSTOM_ID)
            .label("Fish again")
            .emoji(serenity::ReactionType::Unicode("🎣".into()))
            .style(serenity::ButtonStyle::Success),
    ])]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn shared_button_casts_for_each_clicking_angler() {
        assert_eq!(caster(CUSTOM_ID, 123), Ok(123));
        assert_eq!(caster(CUSTOM_ID, 456), Ok(456));
        assert!(caster(CUSTOM_ID, 0).is_err());
        for invalid in [
            "fish_again:0",
            "fish_again:",
            "fish_again:123:456",
            "fish_again:no",
            "sell:123",
        ] {
            assert!(caster(invalid, 123).is_err());
        }
    }

    #[test]
    fn button_is_static_green_enabled_and_shared() {
        let rows = serde_json::to_value(components()).unwrap();
        let button = &rows[0]["components"][0];
        assert_eq!(button["custom_id"], CUSTOM_ID);
        assert_eq!(button["label"], "Fish again");
        assert_eq!(button["style"], 3);
        assert_eq!(button["emoji"]["name"], "🎣");
        assert_ne!(button["disabled"], true);
    }
}
