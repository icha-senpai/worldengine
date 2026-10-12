use crate::measurements::{
    Measurements, SpeciesMeasurements, generate_in_size_band, maximum_in_size_band,
};
use rand::Rng;

pub const PERFECT_ROLL_ONE_IN: u32 = 5_000;

/// UUR endpoint rolls started in rules 11. Rules 12 extends them to every
/// selected rank for multi-attempt luck bait.
pub fn endpoint_roll_enabled(band: u8, rolls: u8, rules_version: u32) -> bool {
    rules_version >= 11 && (band == 9 || rules_version >= 12 && rolls > 1)
}

fn attempt(
    species: SpeciesMeasurements,
    band: u8,
    perfect_roll: bool,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if perfect_roll && rng.gen_range(0..PERFECT_ROLL_ONE_IN) == 0 {
        maximum_in_size_band(species, band)
    } else {
        generate_in_size_band(species, band, rng)
    }
}

/// Species and rank are selected before this function. Keep the heaviest
/// specimen; length breaks weight ties. Rules before 11 retain natural rolls;
/// rules 11 retain UUR-only endpoint rolls, and rules 12 extends multi-attempt bait.
pub fn generate(
    species: SpeciesMeasurements,
    band: u8,
    rolls: u8,
    rules_version: u32,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if !(1..=if rules_version >= 14 { 16 } else { 8 }).contains(&rolls) {
        return Err("INVALID_SIZE_ROLLS");
    }
    let perfect_roll = endpoint_roll_enabled(band, rolls, rules_version);
    let mut best = attempt(species, band, perfect_roll, rng)?;
    for _ in 1..rolls {
        let candidate = attempt(species, band, perfect_roll, rng)?;
        if (candidate.weight_g, candidate.length_mm) > (best.weight_g, best.length_mm) {
            best = candidate;
        }
    }
    Ok(best)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::measurements::classify_rarity;
    use rand::{SeedableRng, rngs::StdRng};
    fn carp() -> SpeciesMeasurements {
        SpeciesMeasurements {
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12800,
        }
    }

    #[test]
    fn rules_14_sixteen_attempts_keep_the_band_and_never_lose_the_eight_attempt_result() {
        for band in 0..10 {
            for seed in 0..200 {
                let mut eight_rng = StdRng::seed_from_u64(seed);
                let mut sixteen_rng = eight_rng.clone();
                let eight = generate(carp(), band, 8, 14, &mut eight_rng).unwrap();
                let sixteen = generate(carp(), band, 16, 14, &mut sixteen_rng).unwrap();
                assert_eq!(classify_rarity(carp(), sixteen), Ok(band));
                assert!((sixteen.weight_g, sixteen.length_mm) >= (eight.weight_g, eight.length_mm));
            }
        }
        assert_eq!(
            generate(carp(), 9, 16, 13, &mut StdRng::seed_from_u64(1)),
            Err("INVALID_SIZE_ROLLS")
        );
        assert_eq!(
            generate(carp(), 9, 17, 14, &mut StdRng::seed_from_u64(1)),
            Err("INVALID_SIZE_ROLLS")
        );
    }

    #[test]
    fn one_natural_roll_preserves_legacy_rng_and_all_ranks_keep_their_caps() {
        let fish = carp();
        for band in 0..10 {
            for seed in 0..100 {
                let mut rng = StdRng::seed_from_u64(seed);
                let mut baseline = rng.clone();
                let first = generate_in_size_band(fish, band, &mut baseline).unwrap();
                assert_eq!(generate(fish, band, 1, 10, &mut rng).unwrap(), first);
                let mut rng = StdRng::seed_from_u64(seed);
                let best = generate(fish, band, 8, 11, &mut rng).unwrap();
                assert!(best.length_mm <= fish.max_length_mm && best.weight_g <= fish.max_weight_g);
                assert_eq!(classify_rarity(fish, best), Ok(band));
                if band < 9 {
                    assert!((best.weight_g, best.length_mm) >= (first.weight_g, first.length_mm));
                }
            }
        }
        let mut rng = StdRng::seed_from_u64(0);
        assert_eq!(
            generate(fish, 9, 0, 11, &mut rng),
            Err("INVALID_SIZE_ROLLS")
        );
        assert_eq!(
            generate(fish, 9, 9, 11, &mut rng),
            Err("INVALID_SIZE_ROLLS")
        );
    }

    #[test]
    fn endpoint_hit_uses_both_exact_maxima_and_only_uur_is_eligible() {
        let fish = carp();
        let seed = (0..100000)
            .find(|seed| StdRng::seed_from_u64(*seed).gen_range(0..PERFECT_ROLL_ONE_IN) == 0)
            .unwrap();
        let maximum = maximum_in_size_band(fish, 9).unwrap();
        assert_eq!((maximum.length_mm, maximum.weight_g), (855, 12291));
        assert_eq!(
            generate(fish, 9, 1, 11, &mut StdRng::seed_from_u64(seed)).unwrap(),
            maximum
        );
        for band in 0..9 {
            let mut rng = StdRng::seed_from_u64(seed);
            let mut baseline = rng.clone();
            assert_eq!(
                generate(fish, band, 1, 11, &mut rng).unwrap(),
                generate_in_size_band(fish, band, &mut baseline).unwrap()
            );
        }
    }

    #[test]
    fn rules_12_multi_attempt_bait_hits_every_species_rank_without_promoting_the_catch() {
        let catalog: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
        let seed = (0..100000)
            .find(|seed| StdRng::seed_from_u64(*seed).gen_range(0..PERFECT_ROLL_ONE_IN) == 0)
            .unwrap();
        let mut checked = 0;
        for fish in catalog["species"].as_array().unwrap() {
            let length = fish["typicalLengthMm"].as_u64().unwrap() as u32;
            let weight = fish["typicalWeightG"].as_u64().unwrap();
            let model = SpeciesMeasurements {
                typical_length_mm: length,
                min_length_mm: length * 55 / 100,
                max_length_mm: length * 19 / 10,
                typical_weight_g: weight,
                min_weight_g: (weight / 8).max(1),
                max_weight_g: weight * 8,
            };
            for rank in fish["allowedRarities"].as_array().unwrap() {
                let band = crate::RARITY_TIERS
                    .iter()
                    .position(|&r| r == rank.as_str().unwrap())
                    .unwrap() as u8;
                for rolls in [2, 3, 4, 8] {
                    let measured =
                        generate(model, band, rolls, 12, &mut StdRng::seed_from_u64(seed)).unwrap();
                    assert_eq!(measured, maximum_in_size_band(model, band).unwrap());
                    assert_eq!(classify_rarity(model, measured), Ok(band));
                    checked += 1;
                }
            }
        }
        assert_eq!(checked, 2492 * 4);
    }

    #[test]
    fn rules_12_preserve_legacy_trophy_and_other_bait_streams() {
        let fish = carp();
        for seed in 0..100 {
            for band in 0..10 {
                if band < 9 {
                    let mut current = StdRng::seed_from_u64(seed);
                    let mut legacy = current.clone();
                    assert_eq!(
                        generate(fish, band, 1, 12, &mut current),
                        generate(fish, band, 1, 10, &mut legacy)
                    );
                    assert_eq!(current, legacy);
                    let mut old_trophy = StdRng::seed_from_u64(seed);
                    let mut natural = old_trophy.clone();
                    assert_eq!(
                        generate(fish, band, 8, 11, &mut old_trophy),
                        generate(fish, band, 8, 10, &mut natural)
                    );
                    assert_eq!(old_trophy, natural);
                } else {
                    for rolls in [1, 8] {
                        let mut current = StdRng::seed_from_u64(seed);
                        let mut legacy = current.clone();
                        assert_eq!(
                            generate(fish, band, rolls, 12, &mut current),
                            generate(fish, band, rolls, 11, &mut legacy)
                        );
                        assert_eq!(current, legacy);
                    }
                }
            }
        }
    }

    #[test]
    fn later_endpoint_hit_survives_keep_heaviest_selection() {
        let fish = carp();
        let seed = (0..100000)
            .find(|seed| {
                let mut rng = StdRng::seed_from_u64(*seed);
                if rng.gen_range(0..PERFECT_ROLL_ONE_IN) == 0 {
                    return false;
                }
                generate_in_size_band(fish, 9, &mut rng).unwrap();
                rng.gen_range(0..PERFECT_ROLL_ONE_IN) == 0
            })
            .unwrap();
        assert_eq!(
            generate(fish, 9, 8, 11, &mut StdRng::seed_from_u64(seed)).unwrap(),
            maximum_in_size_band(fish, 9).unwrap()
        );
    }

    #[test]
    fn every_real_species_including_fihs_can_use_the_endpoint_roll() {
        let catalog: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
        let seed = (0..100000)
            .find(|seed| StdRng::seed_from_u64(*seed).gen_range(0..PERFECT_ROLL_ONE_IN) == 0)
            .unwrap();
        let mut checked = 0;
        for fish in catalog["species"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|fish| fish["key"] != "nidalees-lost-sock")
        {
            let length = fish["typicalLengthMm"].as_u64().unwrap() as u32;
            let weight = fish["typicalWeightG"].as_u64().unwrap();
            let model = SpeciesMeasurements {
                typical_length_mm: length,
                min_length_mm: length * 55 / 100,
                max_length_mm: length * 19 / 10,
                typical_weight_g: weight,
                min_weight_g: (weight / 8).max(1),
                max_weight_g: weight * 8,
            };
            let measured = generate(model, 9, 1, 11, &mut StdRng::seed_from_u64(seed)).unwrap();
            assert_eq!(measured, maximum_in_size_band(model, 9).unwrap());
            assert_eq!(classify_rarity(model, measured), Ok(9));
            checked += 1;
        }
        assert_eq!(checked, 250);
    }

    #[test]
    fn seeded_eight_attempt_frequency_matches_one_minus_miss_probability() {
        let fish = SpeciesMeasurements {
            typical_length_mm: 5500,
            min_length_mm: 3025,
            max_length_mm: 10450,
            typical_weight_g: 1100000,
            min_weight_g: 137500,
            max_weight_g: 8800000,
        };
        let maximum = maximum_in_size_band(fish, 9).unwrap();
        let mut rng = StdRng::seed_from_u64(8841729);
        let samples = 200000;
        let hits = (0..samples)
            .filter(|_| generate(fish, 9, 8, 11, &mut rng).unwrap() == maximum)
            .count() as f64;
        let p = 1.0 - (1.0 - 1.0 / f64::from(PERFECT_ROLL_ONE_IN)).powi(8);
        let expected = f64::from(samples) * p;
        assert!(
            (hits - expected).abs() < 5.0 * (expected * (1.0 - p)).sqrt(),
            "{hits} vs {expected}"
        );
    }

    #[test]
    fn lower_rank_trophy_endpoint_frequency_matches_eight_attempts() {
        let fish = SpeciesMeasurements {
            typical_length_mm: 5500,
            min_length_mm: 3025,
            max_length_mm: 10450,
            typical_weight_g: 1100000,
            min_weight_g: 137500,
            max_weight_g: 8800000,
        };
        for band in [0, 8] {
            let maximum = maximum_in_size_band(fish, band).unwrap();
            let mut rng = StdRng::seed_from_u64(8841729);
            let samples = 200000;
            let hits = (0..samples)
                .filter(|_| generate(fish, band, 8, 12, &mut rng).unwrap() == maximum)
                .count() as f64;
            let p = 1.0 - (1.0 - 1.0 / f64::from(PERFECT_ROLL_ONE_IN)).powi(8);
            let expected = f64::from(samples) * p;
            assert!(
                (hits - expected).abs() < 5.0 * (expected * (1.0 - p)).sqrt(),
                "band {band}: {hits} vs {expected}"
            );
        }
    }
}
