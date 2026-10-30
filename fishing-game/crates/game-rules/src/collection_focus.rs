//! Rank collection help never changes the already selected species.
use rand::Rng;

pub const CHANCE_BP: u32 = 2_000;
pub const MIN_RULES_VERSION: u32 = 13;

/// Only reroll a collected rank, and weight missing ranks by their normal tickets.
/// Returning early consumes no RNG, preserving unfocused and completed streams.
pub fn reroll(
    selected: u8,
    ranks: &[(u8, u64)],
    known: &[bool],
    rng: &mut impl Rng,
) -> Result<u8, &'static str> {
    reroll_with_chance(selected, ranks, known, CHANCE_BP, rng)
}

pub fn reroll_with_chance(
    selected: u8,
    ranks: &[(u8, u64)],
    known: &[bool],
    chance_bp: u32,
    rng: &mut impl Rng,
) -> Result<u8, &'static str> {
    if chance_bp > 10_000 {
        return Err("INVALID_COLLECTION_FOCUS");
    }
    if usize::from(selected) >= known.len()
        || ranks
            .iter()
            .any(|(rank, _)| usize::from(*rank) >= known.len())
    {
        return Err("INVALID_COLLECTION_RANK");
    }
    if !known[usize::from(selected)] {
        return Ok(selected);
    }
    let missing: Vec<_> = ranks
        .iter()
        .filter(|(rank, weight)| *weight > 0 && !known[usize::from(*rank)])
        .collect();
    if missing.is_empty() || rng.gen_range(0..10_000u32) >= chance_bp {
        return Ok(selected);
    }
    let weights: Vec<_> = missing.iter().map(|(_, weight)| *weight).collect();
    Ok(missing[crate::sampling::sample(&weights, rng)?].0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use rand::{SeedableRng, rngs::StdRng};

    #[test]
    fn unknown_and_complete_ranks_preserve_rng() {
        for known in [[false, false], [true, true]] {
            let mut rng = StdRng::seed_from_u64(3);
            let mut unchanged = rng.clone();
            assert_eq!(reroll(0, &[(0, 900), (1, 100)], &known, &mut rng), Ok(0));
            assert_eq!(rng.r#gen::<u64>(), unchanged.r#gen::<u64>());
        }
    }

    #[test]
    fn only_missing_nonzero_ranks_receive_the_weighted_reroll() {
        let mut rng = StdRng::seed_from_u64(20261012);
        let mut counts = [0u64; 4];
        for _ in 0..200_000 {
            counts[reroll(
                0,
                &[(0, 500), (1, 300), (2, 100), (3, 0)],
                &[true, false, false, false],
                &mut rng,
            )
            .unwrap() as usize] += 1;
        }
        assert!((counts[0] as f64 / 200_000.0 - 0.8).abs() < 0.004);
        assert!((counts[1] as f64 / 200_000.0 - 0.15).abs() < 0.004);
        assert!((counts[2] as f64 / 200_000.0 - 0.05).abs() < 0.004);
        assert_eq!(counts[3], 0);
        assert_eq!(
            reroll(4, &[(0, 1)], &[false], &mut rng),
            Err("INVALID_COLLECTION_RANK")
        );
    }
}
