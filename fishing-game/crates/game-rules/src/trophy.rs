use crate::measurements::{
    Measurements, SpeciesMeasurements, generate_in_size_band, maximum_in_size_band,
};
use rand::Rng;

pub const PERFECT_ROLL_ONE_IN: u32 = 5_000;

fn attempt(
    species: SpeciesMeasurements,
    band: u8,
    perfect_roll: bool,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if perfect_roll && band == 9 && rng.gen_range(0..PERFECT_ROLL_ONE_IN) == 0 {
        maximum_in_size_band(species, band)
    } else {
        generate_in_size_band(species, band, rng)
    }
}

/// Species and rank are selected before this function. Keep the heaviest
/// specimen; length breaks weight ties. Rules before 11 retain natural rolls.
pub fn generate(
    species: SpeciesMeasurements,
    band: u8,
    rolls: u8,
    perfect_roll: bool,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if !(1..=8).contains(&rolls) {
        return Err("INVALID_SIZE_ROLLS");
    }
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
    fn one_natural_roll_preserves_legacy_rng_and_all_ranks_keep_their_caps() {
        let fish = carp();
        for band in 0..10 {
            for seed in 0..100 {
                let mut rng = StdRng::seed_from_u64(seed);
                let mut baseline = rng.clone();
                let first = generate_in_size_band(fish, band, &mut baseline).unwrap();
                assert_eq!(generate(fish, band, 1, false, &mut rng).unwrap(), first);
                let mut rng = StdRng::seed_from_u64(seed);
                let best = generate(fish, band, 8, true, &mut rng).unwrap();
                assert!(best.length_mm <= fish.max_length_mm && best.weight_g <= fish.max_weight_g);
                assert_eq!(classify_rarity(fish, best), Ok(band));
                if band < 9 {
                    assert!((best.weight_g, best.length_mm) >= (first.weight_g, first.length_mm));
                }
            }
        }
        let mut rng = StdRng::seed_from_u64(0);
        assert_eq!(
            generate(fish, 9, 0, true, &mut rng),
            Err("INVALID_SIZE_ROLLS")
        );
        assert_eq!(
            generate(fish, 9, 9, true, &mut rng),
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
            generate(fish, 9, 1, true, &mut StdRng::seed_from_u64(seed)).unwrap(),
            maximum
        );
        for band in 0..9 {
            let mut rng = StdRng::seed_from_u64(seed);
            let mut baseline = rng.clone();
            assert_eq!(
                generate(fish, band, 1, true, &mut rng).unwrap(),
                generate_in_size_band(fish, band, &mut baseline).unwrap()
            );
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
            generate(fish, 9, 8, true, &mut StdRng::seed_from_u64(seed)).unwrap(),
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
            let measured = generate(model, 9, 1, true, &mut StdRng::seed_from_u64(seed)).unwrap();
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
            .filter(|_| generate(fish, 9, 8, true, &mut rng).unwrap() == maximum)
            .count() as f64;
        let p = 1.0 - (1.0 - 1.0 / f64::from(PERFECT_ROLL_ONE_IN)).powi(8);
        let expected = f64::from(samples) * p;
        assert!(
            (hits - expected).abs() < 5.0 * (expected * (1.0 - p)).sqrt(),
            "{hits} vs {expected}"
        );
    }
}
