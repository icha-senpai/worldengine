use rand::Rng;

pub fn total_weight(weights: &[u64]) -> Result<u64, &'static str> {
    let total = weights
        .iter()
        .try_fold(0u64, |sum, weight| sum.checked_add(*weight))
        .ok_or("WEIGHT_OVERFLOW")?;
    if total == 0 {
        return Err("EMPTY_POOL");
    }
    Ok(total)
}

/// The caller supplies an unbiased draw in [0, total_weight).
pub fn select_from_roll(weights: &[u64], mut roll: u64) -> Result<usize, &'static str> {
    if roll >= total_weight(weights)? {
        return Err("ROLL_OUT_OF_RANGE");
    }
    for (index, weight) in weights.iter().enumerate() {
        if roll < *weight {
            return Ok(index);
        }
        roll -= weight;
    }
    Err("ROLL_OUT_OF_RANGE")
}

pub fn sample(weights: &[u64], rng: &mut impl Rng) -> Result<usize, &'static str> {
    let total = total_weight(weights)?;
    select_from_roll(weights, rng.gen_range(0..total))
}

pub fn total_weight_wide(weights: &[u128]) -> Result<u128, &'static str> {
    let total = weights
        .iter()
        .try_fold(0u128, |sum, weight| sum.checked_add(*weight))
        .ok_or("WEIGHT_OVERFLOW")?;
    if total == 0 {
        return Err("EMPTY_POOL");
    }
    Ok(total)
}

/// The caller supplies an unbiased draw in [0, total_weight_wide).
pub fn select_from_roll_wide(weights: &[u128], mut roll: u128) -> Result<usize, &'static str> {
    if roll >= total_weight_wide(weights)? {
        return Err("ROLL_OUT_OF_RANGE");
    }
    for (index, weight) in weights.iter().enumerate() {
        if roll < *weight {
            return Ok(index);
        }
        roll -= weight;
    }
    Err("ROLL_OUT_OF_RANGE")
}

pub fn sample_wide(weights: &[u128], rng: &mut impl Rng) -> Result<usize, &'static str> {
    let total = total_weight_wide(weights)?;
    // Keep the existing u64 RNG draw and stream for every legacy-sized pool.
    let roll = if let Ok(total) = u64::try_from(total) {
        u128::from(rng.gen_range(0..total))
    } else {
        rng.gen_range(0..total)
    };
    select_from_roll_wide(weights, roll)
}

#[cfg(test)]
mod tests {
    use super::*;
    use rand::{RngCore, SeedableRng, rngs::StdRng};

    #[test]
    fn wide_intervals_and_checked_totals_are_exact() {
        let weights = [0, 2, 0, u128::from(u64::MAX), 1, 0];
        assert_eq!(select_from_roll_wide(&weights, 0), Ok(1));
        assert_eq!(select_from_roll_wide(&weights, 1), Ok(1));
        assert_eq!(select_from_roll_wide(&weights, 2), Ok(3));
        let total = total_weight_wide(&weights).unwrap();
        assert_eq!(select_from_roll_wide(&weights, total - 1), Ok(4));
        assert_eq!(select_from_roll_wide(&weights, total), Err("ROLL_OUT_OF_RANGE"));
        assert_eq!(select_from_roll_wide(&[u128::MAX], u128::MAX - 1), Ok(0));
        assert_eq!(total_weight_wide(&[]), Err("EMPTY_POOL"));
        assert_eq!(total_weight_wide(&[0, 0]), Err("EMPTY_POOL"));
        assert_eq!(total_weight_wide(&[u128::MAX, 1]), Err("WEIGHT_OVERFLOW"));
        let mut rng = StdRng::seed_from_u64(81);
        for _ in 0..64 {
            assert!(sample_wide(&weights, &mut rng).is_ok());
            assert_eq!(sample_wide(&[u128::MAX], &mut rng), Ok(0));
        }
    }

    #[test]
    fn wide_sampler_preserves_the_u64_rng_stream() {
        let weights = [0, 2, 0, 3, 1, 0];
        let wide: Vec<_> = weights.iter().map(|weight| u128::from(*weight)).collect();
        for seed in 0..64 {
            let mut old = StdRng::seed_from_u64(seed);
            let mut new = old.clone();
            for _ in 0..32 {
                assert_eq!(sample(&weights, &mut old), sample_wide(&wide, &mut new));
            }
            assert_eq!(old.next_u64(), new.next_u64());
        }
        let mut old = StdRng::seed_from_u64(81);
        let mut new = old.clone();
        assert_eq!(sample(&[u64::MAX], &mut old), sample_wide(&[u128::from(u64::MAX)], &mut new));
        assert_eq!(old.next_u64(), new.next_u64());
    }
    #[test]
    fn every_interval_including_zero_weights_and_boundaries_is_correct() {
        let weights = [0, 2, 0, 3, 1, 0];
        let actual: Vec<_> = (0..6)
            .map(|roll| select_from_roll(&weights, roll).unwrap())
            .collect();
        assert_eq!(actual, vec![1, 1, 3, 3, 3, 4]);
        assert_eq!(select_from_roll(&weights, 6), Err("ROLL_OUT_OF_RANGE"));
        assert_eq!(select_from_roll(&[u64::MAX], u64::MAX - 1), Ok(0));
    }
    #[test]
    fn rejects_empty_pools_and_overflow() {
        assert_eq!(total_weight(&[]), Err("EMPTY_POOL"));
        assert_eq!(total_weight(&[0, 0]), Err("EMPTY_POOL"));
        assert_eq!(total_weight(&[u64::MAX, 1]), Err("WEIGHT_OVERFLOW"));
    }
}
