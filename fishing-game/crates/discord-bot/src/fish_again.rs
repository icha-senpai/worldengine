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

pub fn attributed_content(clicking_user_id: u64, content: &str) -> String {
    format!("🎣 <@{clicking_user_id}> went fishing.\n{content}")
}

/// A normal channel post has no component-reply link to the previous bot catch.
/// The same nonce also prevents a lost delivery acknowledgement duplicating it.
pub fn public_message(
    reply: poise::CreateReply,
    source: serenity::MessageReference,
    interaction_id: u64,
) -> serenity::CreateMessage {
    reply
        .reply(false)
        .to_prefix(source)
        .allowed_mentions(serenity::CreateAllowedMentions::new())
        .nonce(serenity::Nonce::String(format!("f{interaction_id}")))
        .enforce_nonce(true)
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

    #[test]
    fn fresh_catch_names_the_clicker_without_replying_to_the_previous_bot_message() {
        let source = serenity::MessageReference::from((
            serenity::ChannelId::new(42),
            serenity::MessageId::new(777),
        ));
        for user in [123, 456] {
            let message = public_message(
                poise::CreateReply::new()
                    .content(attributed_content(user, "Carp · +25 XP"))
                    .embed(serenity::CreateEmbed::new().title("Carp"))
                    .components(components())
                    .reply(true),
                source.clone(),
                991,
            );
            let payload = serde_json::to_value(message).unwrap();
            assert_eq!(
                payload["content"],
                format!("🎣 <@{user}> went fishing.\nCarp · +25 XP")
            );
            assert!(
                payload
                    .get("message_reference")
                    .is_none_or(|reference| reference.is_null())
            );
            assert_eq!(payload["allowed_mentions"]["parse"], serde_json::json!([]));
            assert_eq!(payload["embeds"][0]["title"], "Carp");
            assert_eq!(
                payload["components"][0]["components"][0]["custom_id"],
                CUSTOM_ID
            );
            assert_eq!(payload["nonce"], "f991");
            assert_eq!(payload["enforce_nonce"], true);
        }
    }
}
