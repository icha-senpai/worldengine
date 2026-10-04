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

#[cfg(test)]
mod tests {
    use super::*;
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
