use crate::sampling::sample;
use rand::Rng;

#[derive(Clone, Copy, Debug)]
pub struct SpeciesMeasurements {
    pub typical_length_mm: u32,
    pub min_length_mm: u32,
    pub max_length_mm: u32,
    pub typical_weight_g: u64,
    pub min_weight_g: u64,
    pub max_weight_g: u64,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Measurements {
    pub length_mm: u32,
    pub weight_g: u64,
    pub size_grade: u8,
}

pub fn classify_size(ratio_millionths: u64) -> u8 {
    match ratio_millionths {
        0..750_000 => 0,
        750_000..900_000 => 1,
        900_000..1_150_000 => 2,
        1_150_000..1_350_000 => 3,
        1_350_000..1_600_000 => 4,
        _ => 5,
    }
}
pub const SIZE_GRADE_NAMES: [&str; 6] = ["Tiny", "Small", "Typical", "Large", "Trophy", "Colossal"];

// Weight scales with volume. Both measured dimensions must meet the cutoff;
// these are comparisons to this species, never to other species' raw sizes.
pub const RANK_LENGTH_MIN: [u64; 10] = [
    0, 750_000, 900_000, 1_000_000, 1_150_000, 1_300_000, 1_450_000, 1_600_000, 1_750_000,
    1_850_000,
];
pub const RANK_WEIGHT_MIN: [u64; 10] = [
    0, 421_875, 729_000, 1_000_000, 1_520_875, 2_197_000, 3_048_625, 4_096_000, 5_359_375,
    6_331_625,
];

pub fn classify_rarity(
    species: SpeciesMeasurements,
    measured: Measurements,
) -> Result<u8, &'static str> {
    if species.typical_length_mm == 0
        || species.typical_weight_g == 0
        || measured.length_mm == 0
        || measured.weight_g == 0
    {
        return Err("INVALID_MEASUREMENTS");
    }
    let ordinal = (0..RANK_LENGTH_MIN.len())
        .rev()
        .find(|&i| {
            u128::from(measured.length_mm) * 1_000_000
                >= u128::from(species.typical_length_mm) * u128::from(RANK_LENGTH_MIN[i])
                && u128::from(measured.weight_g) * 1_000_000
                    >= u128::from(species.typical_weight_g) * u128::from(RANK_WEIGHT_MIN[i])
        })
        .expect("F has zero thresholds");
    Ok(ordinal as u8)
}

fn weight_at_length(
    species: SpeciesMeasurements,
    length_mm: u32,
    condition: u64,
) -> Result<u64, &'static str> {
    let numerator = u128::from(species.typical_weight_g)
        .checked_mul(u128::from(length_mm).pow(3))
        .and_then(|value| value.checked_mul(u128::from(condition)))
        .ok_or("MEASUREMENT_OVERFLOW")?;
    let denominator = u128::from(species.typical_length_mm)
        .pow(3)
        .checked_mul(1_000_000)
        .ok_or("MEASUREMENT_OVERFLOW")?;
    let weight = numerator
        .checked_add(denominator / 2)
        .ok_or("MEASUREMENT_OVERFLOW")?
        / denominator;
    Ok(weight.clamp(
        u128::from(species.min_weight_g),
        u128::from(species.max_weight_g),
    ) as u64)
}

/// Sample physical dimensions in a weighted size band. The caller classifies
/// the resulting integer measurements afterward to obtain the saved rarity.
pub fn generate_in_size_band(
    species: SpeciesMeasurements,
    band: u8,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    let i = usize::from(band);
    if i >= RANK_LENGTH_MIN.len()
        || species.typical_length_mm == 0
        || species.typical_weight_g == 0
        || species.min_length_mm == 0
        || species.min_weight_g == 0
        || species.min_length_mm > species.typical_length_mm
        || species.max_length_mm < species.typical_length_mm
        || species.min_weight_g > species.typical_weight_g
        || species.max_weight_g < species.typical_weight_g
    {
        return Err("INVALID_MEASUREMENTS");
    }
    let ceil_ratio = |typical: u64, factor: u64| -> Result<u64, &'static str> {
        (u128::from(typical) * u128::from(factor))
            .div_ceil(1_000_000)
            .try_into()
            .map_err(|_| "MEASUREMENT_OVERFLOW")
    };
    let min_length = u64::from(species.min_length_mm).max(ceil_ratio(
        u64::from(species.typical_length_mm),
        RANK_LENGTH_MIN[i],
    )?);
    let max_length = if i + 1 < RANK_LENGTH_MIN.len() {
        u64::from(species.max_length_mm).min(
            ceil_ratio(u64::from(species.typical_length_mm), RANK_LENGTH_MIN[i + 1])?
                .saturating_sub(1),
        )
    } else {
        u64::from(species.max_length_mm)
    };
    let rank_weight_floor = ceil_ratio(species.typical_weight_g, RANK_WEIGHT_MIN[i])?;
    if min_length > max_length || rank_weight_floor > species.max_weight_g {
        return Err("SIZE_BAND_UNAVAILABLE");
    }
    let length_mm = rng.gen_range(min_length..=max_length) as u32;
    let min_weight = weight_at_length(species, length_mm, 880_000)?
        .max(rank_weight_floor)
        .max(species.min_weight_g);
    // At gram precision, a tiny fish may need its next whole gram to meet the
    // cutoff. Allow that rounding step without dropping it into a different band.
    let max_weight = weight_at_length(species, length_mm, 1_120_000)?
        .max(min_weight)
        .min(species.max_weight_g);
    if min_weight > max_weight {
        return Err("SIZE_BAND_UNAVAILABLE");
    }
    let weight_g = rng.gen_range(min_weight..=max_weight);
    Ok(Measurements {
        length_mm,
        weight_g,
        size_grade: classify_size(
            u64::from(length_mm) * 1_000_000 / u64::from(species.typical_length_mm),
        ),
    })
}

pub fn from_factors(
    species: SpeciesMeasurements,
    length_factor: u64,
    condition_factor: u64,
) -> Result<Measurements, &'static str> {
    if species.typical_length_mm == 0
        || species.typical_weight_g == 0
        || species.min_length_mm > species.typical_length_mm
        || species.max_length_mm < species.typical_length_mm
        || species.min_weight_g > species.typical_weight_g
        || species.max_weight_g < species.typical_weight_g
        || !(880_000..=1_120_000).contains(&condition_factor)
    {
        return Err("INVALID_MEASUREMENTS");
    }
    let length = u64::from(species.typical_length_mm)
        .checked_mul(length_factor)
        .and_then(|value| value.checked_add(500_000))
        .ok_or("MEASUREMENT_OVERFLOW")?
        / 1_000_000;
    let length_mm = length.clamp(
        u64::from(species.min_length_mm),
        u64::from(species.max_length_mm),
    ) as u32;
    let weight_g = weight_at_length(species, length_mm, condition_factor)?;
    let ratio = u64::from(length_mm) * 1_000_000 / u64::from(species.typical_length_mm);
    Ok(Measurements {
        length_mm,
        weight_g,
        size_grade: classify_size(ratio),
    })
}

pub fn generate(
    species: SpeciesMeasurements,
    size_shift: u32,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if size_shift > 50_000 {
        return Err("SIZE_MODIFIER_OUT_OF_RANGE");
    }
    let band = sample(&[800, 2200, 5000, 1600, 380, 20], rng)?;
    let bounds = [
        (550_000u64, 749_999),
        (750_000, 899_999),
        (900_000, 1_149_999),
        (1_150_000, 1_349_999),
        (1_350_000, 1_599_999),
        (1_600_000, 1_850_000),
    ];
    let factor = rng.gen_range(bounds[band].0..=bounds[band].1) + u64::from(size_shift);
    from_factors(species, factor, rng.gen_range(880_000..=1_120_000))
}

#[cfg(test)]
mod tests {
    use super::*;
    use rand::{SeedableRng, rngs::StdRng};
    fn carp() -> SpeciesMeasurements {
        SpeciesMeasurements {
            typical_length_mm: 430,
            min_length_mm: 230,
            max_length_mm: 820,
            typical_weight_g: 1700,
            min_weight_g: 200,
            max_weight_g: 14000,
        }
    }
    #[test]
    fn every_rank_requires_both_dimensions_and_uses_exact_integer_boundaries() {
        let model = SpeciesMeasurements {
            typical_length_mm: 1_000_000,
            min_length_mm: 1,
            max_length_mm: 2_000_000,
            typical_weight_g: 1_000_000,
            min_weight_g: 1,
            max_weight_g: 8_000_000,
        };
        for i in 1..10 {
            let measured = Measurements {
                length_mm: RANK_LENGTH_MIN[i] as u32,
                weight_g: RANK_WEIGHT_MIN[i],
                size_grade: 0,
            };
            assert_eq!(classify_rarity(model, measured), Ok(i as u8));
            assert_eq!(
                classify_rarity(
                    model,
                    Measurements {
                        length_mm: measured.length_mm - 1,
                        ..measured
                    }
                ),
                Ok(i as u8 - 1)
            );
            assert_eq!(
                classify_rarity(
                    model,
                    Measurements {
                        weight_g: measured.weight_g - 1,
                        ..measured
                    }
                ),
                Ok(i as u8 - 1)
            );
        }
    }
    #[test]
    fn a_trophy_minnow_outranks_a_larger_shark_and_weight_can_raise_rank() {
        let minnow = SpeciesMeasurements {
            typical_length_mm: 90,
            min_length_mm: 49,
            max_length_mm: 171,
            typical_weight_g: 14,
            min_weight_g: 1,
            max_weight_g: 112,
        };
        let shark = SpeciesMeasurements {
            typical_length_mm: 2400,
            min_length_mm: 1320,
            max_length_mm: 4560,
            typical_weight_g: 90000,
            min_weight_g: 11250,
            max_weight_g: 720000,
        };
        assert_eq!(
            classify_rarity(
                minnow,
                Measurements {
                    length_mm: 167,
                    weight_g: 89,
                    size_grade: 5
                }
            ),
            Ok(9)
        );
        assert_eq!(
            classify_rarity(
                shark,
                Measurements {
                    length_mm: 2400,
                    weight_g: 90000,
                    size_grade: 2
                }
            ),
            Ok(3)
        );
        let long_lean = Measurements {
            length_mm: 800,
            weight_g: 2000,
            size_grade: 5,
        };
        assert_eq!(classify_rarity(carp(), long_lean), Ok(3));
        assert_eq!(
            classify_rarity(
                carp(),
                Measurements {
                    weight_g: 12000,
                    ..long_lean
                }
            ),
            Ok(9)
        );
    }
    #[test]
    fn raising_either_dimension_never_lowers_rank_and_invalid_models_fail() {
        let model = carp();
        let mut previous = 0;
        for length_mm in 230..=820 {
            let rank = classify_rarity(
                model,
                Measurements {
                    length_mm,
                    weight_g: 12000,
                    size_grade: 0,
                },
            )
            .unwrap();
            assert!(rank >= previous);
            previous = rank;
        }
        previous = 0;
        for weight_g in 200..=14000 {
            let rank = classify_rarity(
                model,
                Measurements {
                    length_mm: 810,
                    weight_g,
                    size_grade: 0,
                },
            )
            .unwrap();
            assert!(rank >= previous);
            previous = rank;
        }
        let mut rng = StdRng::seed_from_u64(99);
        assert_eq!(
            generate_in_size_band(model, 10, &mut rng),
            Err("INVALID_MEASUREMENTS")
        );
        let enormous = SpeciesMeasurements {
            typical_weight_g: u64::MAX,
            max_weight_g: u64::MAX,
            ..model
        };
        assert_eq!(
            generate_in_size_band(enormous, 9, &mut rng),
            Err("MEASUREMENT_OVERFLOW")
        );
        assert_eq!(
            classify_rarity(
                model,
                Measurements {
                    length_mm: 0,
                    weight_g: 1,
                    size_grade: 0
                }
            ),
            Err("INVALID_MEASUREMENTS")
        );
    }
    #[test]
    fn fixed_point_matches_documented_carp_and_condition_changes_weight_only() {
        let lean = from_factors(carp(), 1_400_000, 880_000).unwrap();
        let full = from_factors(carp(), 1_400_000, 1_060_000).unwrap();
        assert_eq!(full.length_mm, 602);
        assert_eq!(full.weight_g, 4945);
        assert_eq!(full.size_grade, 4);
        assert_eq!(lean.length_mm, full.length_mm);
        assert!(lean.weight_g < full.weight_g);
    }
    #[test]
    fn all_generated_measurements_and_shifted_grades_obey_final_limits() {
        let mut rng = StdRng::seed_from_u64(17);
        let species = carp();
        for _ in 0..50_000 {
            let result = generate(species, 50_000, &mut rng).unwrap();
            assert!((species.min_length_mm..=species.max_length_mm).contains(&result.length_mm));
            assert!((species.min_weight_g..=species.max_weight_g).contains(&result.weight_g));
            assert_eq!(
                result.size_grade,
                classify_size(u64::from(result.length_mm) * 1_000_000 / 430)
            );
        }
    }
    #[test]
    fn invalid_limits_and_wide_overflow_are_rejected() {
        let mut species = carp();
        species.typical_length_mm = 0;
        assert_eq!(
            from_factors(species, 1_000_000, 1_000_000),
            Err("INVALID_MEASUREMENTS")
        );
        let species = SpeciesMeasurements {
            typical_length_mm: u32::MAX,
            min_length_mm: 1,
            max_length_mm: u32::MAX,
            typical_weight_g: u64::MAX,
            min_weight_g: 1,
            max_weight_g: u64::MAX,
        };
        assert_eq!(
            from_factors(species, 1_000_000, 1_000_000),
            Err("MEASUREMENT_OVERFLOW")
        );
    }
}
