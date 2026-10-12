//! Recognition and presentation use saved integer dimensions, never rounded text.
use crate::measurements::{format_length, format_weight};
use game_client::module_bindings::{CommandReceipt, SpeciesDefinition};
use game_rules::measurements::{SpeciesMeasurements, is_perfect};
use serenity::builder::CreateEmbed;

pub fn card(
    species: &SpeciesDefinition,
    receipt: &CommandReceipt,
    angler: &str,
) -> Option<CreateEmbed> {
    if receipt.outcome != "fish"
        || receipt.species_id != Some(species.species_id)
        || species.key == "nidalees-lost-sock"
    {
        return None;
    }
    let band = game_rules::RARITY_TIERS.iter().rposition(|rank| {
        species
            .allowed_rarities
            .iter()
            .any(|allowed| allowed == rank)
    })? as u8;
    let perfect = is_perfect(
        SpeciesMeasurements {
            typical_length_mm: species.typical_length_mm,
            min_length_mm: species.min_length_mm,
            max_length_mm: species.max_length_mm,
            typical_weight_g: species.typical_weight_g,
            min_weight_g: species.min_weight_g,
            max_weight_g: species.max_weight_g,
        },
        band,
        receipt.length_mm,
        receipt.weight_g,
    )
    .ok()?;
    if !perfect {
        return None;
    }
    Some(
        CreateEmbed::new()
            .title(format!("✦ PERFECT CATCH · {}", species.name))
            .description("Both exact maximum measurements. A catch for the history books.")
            .field("Angler", angler, false)
            .field(
                "Maximum length",
                format!(
                    "{} · {} mm",
                    format_length(u64::from(receipt.length_mm)),
                    receipt.length_mm
                ),
                true,
            )
            .field(
                "Maximum weight",
                format!(
                    "{} · {} g",
                    format_weight(receipt.weight_g),
                    receipt.weight_g
                ),
                true,
            )
            .field(
                "Catch",
                format!("{} rank · +{} XP", receipt.rarity, receipt.xp_granted),
                false,
            )
            .color(0xffd34f),
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn perfect_card_names_angler_fish_and_exact_maxima_and_rejects_near_misses() {
        let species = SpeciesDefinition {
            species_id: 1,
            key: "carp".into(),
            name: "Carp".into(),
            sprite_asset: "/fish/carp.png".into(),
            allowed_rarities: game_rules::RARITY_TIERS
                .iter()
                .map(|s| s.to_string())
                .collect(),
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12800,
            base_value: 1,
            encounter_weight: 1,
            biome_id: 1,
            discovery_xp: 0,
            counts_for_ordinary_collection_completion: true,
        };
        let mut receipt = CommandReceipt {
            interaction_id: 1,
            player_id: 1,
            discord_user_id: 1,
            guild_id: None,
            channel_id: 1,
            command: "fish".into(),
            caught_at: spacetimedb_sdk::Timestamp::from_micros_since_unix_epoch(0),
            outcome: "fish".into(),
            catch_id: Some(1),
            species_id: Some(1),
            rarity: "UUR".into(),
            length_mm: 855,
            weight_g: 12291,
            size_grade: 5,
            xp_granted: 25,
            coins_granted: 0,
            sale_value_coins: 1,
            rules_version: 10,
            content_version: 2,
            item_granted: String::new(),
            item_quantity: 0,
            biome_id: 1,
            rod_id: 1,
        };
        let payload = serde_json::to_string(&card(&species, &receipt, "Boss").unwrap()).unwrap();
        for expected in ["PERFECT CATCH", "Carp", "Boss", "855 mm", "12291 g"] {
            assert!(payload.contains(expected));
        }
        receipt.length_mm -= 1;
        assert!(card(&species, &receipt, "Boss").is_none());
        receipt.length_mm += 1;
        receipt.weight_g -= 1;
        assert!(card(&species, &receipt, "Boss").is_none());
        receipt.weight_g += 1;
        let mut fake = species.clone();
        fake.key = "nidalees-lost-sock".into();
        assert!(card(&fake, &receipt, "Boss").is_none());
    }
}
