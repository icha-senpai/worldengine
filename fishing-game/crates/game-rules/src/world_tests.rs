use crate::{
    measurements::{
        RANK_LENGTH_MIN, RANK_WEIGHT_MIN, SpeciesMeasurements, classify_rarity,
        generate_in_size_band,
    },
    progression::{level_for_xp, xp_to_next},
    sampling::{sample, select_from_roll},
};
use rand::{SeedableRng, rngs::StdRng};
use serde_json::Value;

fn content() -> (Value, Value) {
    (
        serde_json::from_str(include_str!("../../../content/world.json")).unwrap(),
        serde_json::from_str(include_str!("../../../content/species.json")).unwrap(),
    )
}
fn n(row: &Value, field: &str) -> u64 {
    row[field].as_u64().unwrap()
}

#[test]
fn every_species_has_selectable_ticket_boundaries_and_safe_measurements() {
    let (world, catalog) = content();
    let mut rng = StdRng::seed_from_u64(251);
    let mut count = 0;
    for biome in world["biomes"].as_array().unwrap() {
        let pool: Vec<_> = catalog["species"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|f| f["biomeId"] == biome["biomeId"])
            .collect();
        let ranks: Vec<_> = pool
            .iter()
            .flat_map(|f| f["ranks"].as_array().unwrap())
            .collect();
        let weights: Vec<_> = ranks
            .iter()
            .map(|rank| n(rank, "encounterWeight"))
            .collect();
        assert_eq!(weights.iter().sum::<u64>(), 1_000_000);
        let mut start = 0;
        for (index, _) in ranks.iter().enumerate() {
            assert_eq!(select_from_roll(&weights, start), Ok(index));
            assert_eq!(
                select_from_roll(&weights, start + weights[index] - 1),
                Ok(index)
            );
            start += weights[index];
        }
        for fish in pool {
            let expected = match fish["key"].as_str().unwrap() {
                "fihs" => vec!["UUR"],
                "nidalees-lost-sock" => vec!["F"],
                _ => crate::RARITY_TIERS.to_vec(),
            };
            let actual: Vec<_> = fish["ranks"]
                .as_array()
                .unwrap()
                .iter()
                .map(|rank| rank["rarity"].as_str().unwrap())
                .collect();
            assert_eq!(actual, expected);
            let length = n(fish, "typicalLengthMm") as u32;
            let weight = n(fish, "typicalWeightG");
            let model = SpeciesMeasurements {
                typical_length_mm: length,
                min_length_mm: length * 55 / 100,
                max_length_mm: length * 19 / 10,
                typical_weight_g: weight,
                min_weight_g: (weight / 8).max(1),
                max_weight_g: weight * 8,
            };
            for rank in fish["ranks"].as_array().unwrap() {
                let ordinal = crate::RARITY_TIERS
                    .iter()
                    .position(|tier| *tier == rank["rarity"].as_str().unwrap())
                    .unwrap() as u8;
                for _ in 0..100 {
                    let result = generate_in_size_band(model, ordinal, &mut rng).unwrap();
                    assert_eq!(classify_rarity(model, result), Ok(ordinal));
                    assert!(
                        (model.min_length_mm..=model.max_length_mm).contains(&result.length_mm)
                    );
                    assert!((model.min_weight_g..=model.max_weight_g).contains(&result.weight_g));
                }
            }
            count += 1;
        }
    }
    assert_eq!(count, 251);
}

#[test]
fn all_seven_pools_match_category_and_species_distribution() {
    let (world, catalog) = content();
    let mut rng = StdRng::seed_from_u64(7);
    const CASTS: u64 = 200_000;
    for biome in world["biomes"].as_array().unwrap() {
        let categories = [
            n(biome, "fishWeight"),
            n(biome, "junkWeight"),
            n(biome, "treasureWeight"),
        ];
        let pool: Vec<_> = catalog["species"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|f| f["biomeId"] == biome["biomeId"])
            .collect();
        let species_weights: Vec<_> = pool.iter().map(|f| n(f, "encounterWeight")).collect();
        let weights: Vec<_> = pool
            .iter()
            .flat_map(|f| f["ranks"].as_array().unwrap())
            .map(|r| n(r, "encounterWeight"))
            .collect();
        let band_weights: Vec<Vec<_>> = pool
            .iter()
            .map(|f| {
                f["ranks"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|r| n(r, "encounterWeight"))
                    .collect()
            })
            .collect();
        let ordinals: Vec<Vec<_>> = pool
            .iter()
            .map(|f| {
                f["ranks"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|r| {
                        crate::RARITY_TIERS
                            .iter()
                            .position(|tier| *tier == r["rarity"].as_str().unwrap())
                            .unwrap() as u8
                    })
                    .collect()
            })
            .collect();
        let models: Vec<_> = pool
            .iter()
            .map(|f| {
                let length = n(f, "typicalLengthMm") as u32;
                let weight = n(f, "typicalWeightG");
                SpeciesMeasurements {
                    typical_length_mm: length,
                    min_length_mm: length * 55 / 100,
                    max_length_mm: length * 19 / 10,
                    typical_weight_g: weight,
                    min_weight_g: (weight / 8).max(1),
                    max_weight_g: weight * 8,
                }
            })
            .collect();
        let offsets: Vec<usize> = band_weights
            .iter()
            .scan(0, |offset, bands| {
                let old = *offset;
                *offset += bands.len();
                Some(old)
            })
            .collect();
        let mut outcomes = [0u64; 3];
        let mut fish_counts = vec![0u64; weights.len()];
        for _ in 0..CASTS {
            let category = sample(&categories, &mut rng).unwrap();
            outcomes[category] += 1;
            if category == 0 {
                let fish = sample(&species_weights, &mut rng).unwrap();
                let band = sample(&band_weights[fish], &mut rng).unwrap();
                let measured =
                    generate_in_size_band(models[fish], ordinals[fish][band], &mut rng).unwrap();
                let actual_rank = classify_rarity(models[fish], measured).unwrap();
                assert_eq!(actual_rank, ordinals[fish][band]);
                let actual_band = ordinals[fish]
                    .iter()
                    .position(|&ordinal| ordinal == actual_rank)
                    .unwrap();
                fish_counts[offsets[fish] + actual_band] += 1;
            }
        }
        let assert_frequency = |actual: u64, p: f64| {
            let mean = CASTS as f64 * p;
            let deviation = (CASTS as f64 * p * (1.0 - p)).sqrt();
            assert!(
                (actual as f64 - mean).abs() <= 6.0 * deviation + 2.0,
                "frequency {actual} outside six standard deviations of {mean}"
            );
        };
        for (i, weight) in categories.iter().enumerate() {
            assert_frequency(outcomes[i], *weight as f64 / 100.0);
        }
        for (i, weight) in weights.iter().enumerate() {
            assert_frequency(
                fish_counts[i],
                categories[0] as f64 / 100.0 * *weight as f64 / 1_000_000.0,
            );
        }
    }
}

#[test]
fn level_curve_and_earned_rods_reach_every_biome_within_cap() {
    let (world, _) = content();
    let cap = n(&world, "levelCap") as u32;
    for biome in world["biomes"].as_array().unwrap() {
        let required = n(biome, "minimumLevel") as u32;
        let xp: u64 = (1..required).map(xp_to_next).sum();
        assert!(required <= cap);
        assert_eq!(level_for_xp(xp, cap), required);
        if required > 1 {
            assert_eq!(level_for_xp(xp - 1, cap), required - 1);
        }
        assert!(
            world["rods"]
                .as_array()
                .unwrap()
                .iter()
                .any(|rod| n(rod, "minimumLevel") <= u64::from(required)
                    && n(rod, "power") >= n(biome, "requiredPower"))
        );
    }
}

#[test]
fn published_size_thresholds_match_the_authoritative_classifier() {
    let sizes: Value =
        serde_json::from_str(include_str!("../../../content/size-rules.json")).unwrap();
    for (i, tier) in sizes["tiers"].as_array().unwrap().iter().enumerate() {
        assert_eq!(tier["rarity"], crate::RARITY_TIERS[i]);
        assert_eq!(n(tier, "minimumLengthMillionths"), RANK_LENGTH_MIN[i]);
        assert_eq!(n(tier, "minimumWeightMillionths"), RANK_WEIGHT_MIN[i]);
    }
}
