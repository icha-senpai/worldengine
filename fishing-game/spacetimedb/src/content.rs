use crate::tables::*;
use serde::Deserialize;
use spacetimedb::{ReducerContext, Table};

pub const CONTENT_VERSION: u32 = 4;
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Rules {
    version: u32,
    cast_cooldown_seconds: u32,
}
#[derive(Deserialize)]
struct Catalog {
    version: u32,
    species: Vec<Species>,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Species {
    species_id: u32,
    key: String,
    name: String,
    sprite_key: String,
    allowed_rarities: Vec<String>,
    ranks: Vec<Rank>,
    typical_length_mm: u32,
    typical_weight_g: u64,
    base_value: u64,
    encounter_weight: u64,
    biome_id: u32,
    discovery_xp: u64,
    counts_for_ordinary_collection_completion: bool,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Rank {
    rarity: String,
    encounter_weight: u64,
    base_xp: u64,
    base_value: u64,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct World {
    version: u32,
    level_cap: u32,
    biomes: Vec<Biome>,
    rods: Vec<Rod>,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Biome {
    biome_id: u32,
    name: String,
    description: String,
    minimum_level: u32,
    required_power: u32,
    fish_weight: u64,
    junk_weight: u64,
    treasure_weight: u64,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Rod {
    rod_id: u32,
    name: String,
    power: u32,
    minimum_level: u32,
}

pub fn seed(ctx: &ReducerContext) {
    let rules: Rules = serde_json::from_str(include_str!("../../content/game-rules.json"))
        .expect("validated rules");
    let world: World =
        serde_json::from_str(include_str!("../../content/world.json")).expect("validated world");
    let catalog: Catalog = serde_json::from_str(include_str!("../../content/species.json"))
        .expect("validated catalog");
    assert_eq!(world.version, CONTENT_VERSION);
    assert_eq!(catalog.version, CONTENT_VERSION);
    ctx.db.game_config().insert(GameConfig {
        version: rules.version,
        cast_cooldown_seconds: rules.cast_cooldown_seconds,
        inventory_capacity: 0,
        recent_limit: 100,
        level_cap: world.level_cap,
    });
    for (ordinal, tier) in game_rules::RARITY_TIERS.iter().enumerate() {
        ctx.db.rarity_definition().insert(RarityDefinition {
            ordinal: ordinal as u8,
            tier: (*tier).into(),
            reference_weight: game_rules::REFERENCE_TIER_WEIGHTS[ordinal],
            card_asset: format!("/rank-cards/{tier}.png"),
            minimum_length_millionths: game_rules::measurements::RANK_LENGTH_MIN[ordinal],
            minimum_weight_millionths: game_rules::measurements::RANK_WEIGHT_MIN[ordinal],
        });
    }
    for biome in world.biomes {
        ctx.db.biome_definition().insert(BiomeDefinition {
            biome_id: biome.biome_id,
            name: biome.name,
            description: biome.description,
            minimum_level: biome.minimum_level,
            required_power: biome.required_power,
            fish_weight: biome.fish_weight,
            junk_weight: biome.junk_weight,
            treasure_weight: biome.treasure_weight,
        });
    }
    for rod in world.rods {
        ctx.db.rod_definition().insert(RodDefinition {
            rod_id: rod.rod_id,
            name: rod.name,
            power: rod.power,
            minimum_level: rod.minimum_level,
        });
    }
    for fish in catalog.species {
        for rank in fish.ranks {
            let ordinal = game_rules::RARITY_TIERS
                .iter()
                .position(|tier| *tier == rank.rarity)
                .expect("valid tier");
            ctx.db
                .species_rank_definition()
                .insert(SpeciesRankDefinition {
                    key: u64::from(fish.species_id) * 10 + ordinal as u64,
                    species_id: fish.species_id,
                    biome_id: fish.biome_id,
                    ordinal: ordinal as u8,
                    rarity: rank.rarity,
                    encounter_weight: rank.encounter_weight,
                    base_xp: rank.base_xp,
                    base_value: rank.base_value,
                });
        }
        let typical = fish.typical_length_mm;
        let weight = fish.typical_weight_g;
        ctx.db.species_definition().insert(SpeciesDefinition {
            species_id: fish.species_id,
            key: fish.key,
            name: fish.name,
            sprite_asset: format!("/fish/{}.png", fish.sprite_key),
            allowed_rarities: fish.allowed_rarities,
            typical_length_mm: typical,
            min_length_mm: typical * 55 / 100,
            max_length_mm: typical * 19 / 10,
            typical_weight_g: weight,
            min_weight_g: (weight / 8).max(1),
            max_weight_g: weight * 8,
            base_value: fish.base_value,
            encounter_weight: fish.encounter_weight,
            biome_id: fish.biome_id,
            discovery_xp: fish.discovery_xp,
            counts_for_ordinary_collection_completion: fish
                .counts_for_ordinary_collection_completion,
        });
    }
}
