//! Exact legacy odds followed by a positive, diminishing-return rank bias.

pub const LEGACY_LUCK_BOUNDARY_BP: u32 = 26_600;
pub const TAIL_SCALE_BP: u128 = 100_000;
pub const TAIL_BLEND_BP: u128 = 50_000;
pub const TARGET_UUR_MULTIPLIER: u128 = 10;
pub const TARGET_TICK_SCALE: u128 = 1_000_000;

fn gcd(mut a: u128, mut b: u128) -> u128 {
    while b != 0 {
        (a, b) = (b, a % b);
    }
    a
}

fn add(a: u128, b: u128) -> Result<u128, String> {
    a.checked_add(b).ok_or("WEIGHT_OVERFLOW".into())
}

fn mul(a: u128, b: u128) -> Result<u128, String> {
    a.checked_mul(b).ok_or("WEIGHT_OVERFLOW".into())
}

fn sum(values: &[u128]) -> Result<u128, String> {
    values.iter().try_fold(0, |total, value| add(total, *value))
}

fn reduce(values: Vec<u128>) -> Vec<u128> {
    let divisor = values.iter().copied().fold(0, gcd).max(1);
    values.into_iter().map(|value| value / divisor).collect()
}

fn legacy(tickets: &[(u64, u8)], total: i128, moment: i128, base: i128, luck: i128) -> Result<Vec<u128>, String> {
    tickets.iter().map(|(weight, score)| {
        if *weight == 0 {
            return Ok(0);
        }
        let adjustment = luck.checked_mul(i128::from(*score) * total - moment).ok_or("WEIGHT_OVERFLOW")?;
        let factor = base.checked_add(adjustment).ok_or("WEIGHT_OVERFLOW")?;
        if factor <= 0 {
            return Err("INVALID_ROD_POOL".into());
        }
        mul(u128::from(*weight), factor as u128)
    }).collect()
}

/// Proportional allocation with a minimum and stable largest-remainder rounding.
fn allocate(basis: &[u128], mut budget: u128, minimum: u128) -> Result<Vec<u128>, String> {
    let mut result = vec![0; basis.len()];
    let mut free: Vec<_> = basis.iter().enumerate().filter(|(_, value)| **value > 0).map(|(index, _)| index).collect();
    if budget < mul(minimum, free.len() as u128)? {
        return Err("INVALID_ROD_POOL".into());
    }
    while !free.is_empty() {
        let total = free.iter().try_fold(0, |total, index| add(total, basis[*index]))?;
        let threshold = mul(minimum, total)?;
        let small = free.iter().copied().filter_map(|index| match mul(basis[index], budget) {
            Ok(value) if value < threshold => Some(Ok(index)),
            Ok(_) => None,
            Err(error) => Some(Err(error)),
        }).collect::<Result<Vec<_>, String>>()?;
        if !small.is_empty() {
            for index in &small {
                result[*index] = minimum;
            }
            budget -= mul(minimum, small.len() as u128)?;
            free.retain(|index| !small.contains(index));
            continue;
        }
        let mut remainders = Vec::new();
        let mut used = 0;
        for index in &free {
            let product = mul(basis[*index], budget)?;
            result[*index] = product / total;
            used = add(used, result[*index])?;
            remainders.push((*index, product % total));
        }
        remainders.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
        let remainder = usize::try_from(budget - used).map_err(|_| "WEIGHT_OVERFLOW")?;
        if remainder > remainders.len() {
            return Err("INVALID_ROD_POOL".into());
        }
        for (index, _) in remainders.into_iter().take(remainder) {
            result[index] = add(result[index], 1)?;
        }
        return Ok(result);
    }
    if budget != 0 {
        return Err("INVALID_ROD_POOL".into());
    }
    Ok(result)
}

fn target(tickets: &[(u64, u8)], anchor: &[u128], total: u128) -> Result<Vec<u128>, String> {
    let mut top = 0;
    let mut largest_top = 0;
    let mut others = 0;
    let mut rank_totals = vec![0; 10];
    for ((weight, score), value) in tickets.iter().zip(anchor) {
        rank_totals[usize::from(*score)] = add(rank_totals[usize::from(*score)], *value)?;
        if *score == 9 {
            top = add(top, u128::from(*weight))?;
            largest_top = largest_top.max(u128::from(*weight));
        } else if *weight > 0 {
            others += 1;
        }
    }
    let bound_denominator = add(mul(2, top)?, mul(others, largest_top)?)?;
    let (numerator, denominator) = if mul(TARGET_UUR_MULTIPLIER, bound_denominator)? <= mul(2, total)? {
        (TARGET_UUR_MULTIPLIER, 1)
    } else {
        let numerator = mul(2, total)?;
        let divisor = gcd(numerator, bound_denominator);
        (numerator / divisor, bound_denominator / divisor)
    };
    let ticks = mul(mul(total, denominator)?, TARGET_TICK_SCALE)?;
    let top_scale = mul(numerator, TARGET_TICK_SCALE)?;
    let top_budget = mul(top, top_scale)?;
    let remaining = ticks.checked_sub(top_budget).ok_or("INVALID_ROD_POOL")?;
    let middle_total = sum(&rank_totals[1..9])?;
    let anchor_total = sum(anchor)?;
    // Preserve F's total share; ordinary F specimens must not become extinct.
    let f_budget = if middle_total == 0 { remaining } else {
        mul(rank_totals[0], ticks)? / anchor_total
    };
    let middle_budget = remaining.checked_sub(f_budget).ok_or("INVALID_ROD_POOL")?;
    let middle = allocate(&rank_totals[1..9], middle_budget, 0)?;
    let mut budgets = vec![f_budget];
    budgets.extend(middle);
    let floor = mul(largest_top, top_scale)? / 2;
    let mut result = vec![0; tickets.len()];
    for score in 0..9 {
        let basis: Vec<_> = tickets.iter().zip(anchor).map(|((_, rank), value)| if usize::from(*rank) == score { *value } else { 0 }).collect();
        let allocated = allocate(&basis, budgets[score], floor)?;
        for (value, amount) in result.iter_mut().zip(allocated) {
            *value = add(*value, amount)?;
        }
    }
    for (index, (weight, score)) in tickets.iter().enumerate() {
        if *score == 9 {
            result[index] = mul(u128::from(*weight), top_scale)?;
        }
    }
    if sum(&result)? != ticks {
        return Err("INVALID_ROD_POOL".into());
    }
    Ok(reduce(result))
}

fn dominates(tickets: &[(u64, u8)], anchor: &[u128], target: &[u128]) -> Result<bool, String> {
    let a = sum(anchor)?;
    let b = sum(target)?;
    for score in 1..=9 {
        let mut upper_a = 0;
        let mut upper_b = 0;
        for (((_, rank), x), y) in tickets.iter().zip(anchor).zip(target) {
            if *rank >= score {
                upper_a = add(upper_a, *x)?;
                upper_b = add(upper_b, *y)?;
            }
        }
        if mul(upper_b, a)? < mul(upper_a, b)? {
            return Ok(false);
        }
    }
    Ok(true)
}

fn positive_tail(tickets: &[(u64, u8)], total: i128, moment: i128, base: i128, anchor: i128, luck: u32) -> Result<Vec<u128>, String> {
    let ordinary = legacy(tickets, total, moment, base, i128::from(luck).min(anchor))?;
    let excess = u128::from(luck).saturating_sub(anchor as u128);
    let adjusted = ordinary.into_iter().zip(tickets).map(|(value, (_, score))| {
        if excess == 0 { Ok(value) } else {
            mul(value, add(TAIL_SCALE_BP, mul(excess, u128::from(*score) + 1)?)?)
        }
    }).collect::<Result<Vec<_>, String>>()?;
    sum(&adjusted)?;
    Ok(adjusted)
}

pub fn weights(tickets: &[(u64, u8)], luck_bp: u32) -> Result<Vec<u128>, String> {
    if tickets.iter().any(|(_, score)| *score > 9) {
        return Err("INVALID_ROD_LUCK".into());
    }
    let total = tickets
        .iter()
        .try_fold(0i128, |sum, (weight, _)| sum.checked_add(i128::from(*weight)))
        .ok_or("WEIGHT_OVERFLOW")?;
    let moment = tickets
        .iter()
        .try_fold(0i128, |sum, (weight, score)| {
            sum.checked_add(i128::from(*weight) * i128::from(*score))
        })
        .ok_or("WEIGHT_OVERFLOW")?;
    let distance = total
        .checked_mul(9)
        .and_then(|value| value.checked_sub(moment))
        .ok_or("WEIGHT_OVERFLOW")?;
    let minimum = tickets
        .iter()
        .filter(|(weight, _)| *weight > 0)
        .map(|(_, score)| i128::from(*score))
        .min()
        .ok_or("INVALID_ROD_POOL")?;
    if total == 0 || distance <= 0 {
        return Err("INVALID_ROD_POOL".into());
    }
    let base = distance.checked_mul(10_000).ok_or("WEIGHT_OVERFLOW")?;
    let minimum_slope = minimum * total - moment;
    let mut anchor_luck = if minimum_slope < 0 {
        i128::from(LEGACY_LUCK_BOUNDARY_BP).min((base - 1) / -minimum_slope)
    } else {
        i128::from(LEGACY_LUCK_BOUNDARY_BP)
    };
    let compatibility_luck = anchor_luck;
    if !tickets.iter().any(|(weight, score)| *weight > 0 && *score == 9) {
        return positive_tail(tickets, total, moment, base, anchor_luck, luck_bp);
    }
    let mut anchor = reduce(legacy(tickets, total, moment, base, anchor_luck)?);
    let initial = target(tickets, &anchor, total as u128);
    let mut destination = match initial {
        Ok(values) if dominates(tickets, &anchor, &values)? => Some(values),
        Ok(_) => None,
        Err(error) if error == "INVALID_ROD_POOL" => None,
        Err(error) => return Err(error),
    };
    if destination.is_none() {
        // Unusual future pools must not jump backwards after a legacy near-pole.
        anchor_luck = 0;
        anchor = reduce(legacy(tickets, total, moment, base, 0)?);
        let values = match target(tickets, &anchor, total as u128) {
            Ok(values) => values,
            Err(error) if error == "INVALID_ROD_POOL" => {
                return positive_tail(tickets, total, moment, base, compatibility_luck, luck_bp);
            }
            Err(error) => return Err(error),
        };
        if !dominates(tickets, &anchor, &values)? {
            return positive_tail(tickets, total, moment, base, compatibility_luck, luck_bp);
        }
        destination = Some(values);
    }
    if i128::from(luck_bp) <= anchor_luck {
        let adjusted = legacy(tickets, total, moment, base, i128::from(luck_bp))?;
        sum(&adjusted)?;
        return Ok(adjusted);
    }
    let destination = destination.ok_or("INVALID_ROD_POOL")?;
    let anchor_total = sum(&anchor)?;
    let target_total = sum(&destination)?;
    let divisor = gcd(anchor_total, target_total);
    let excess = u128::from(luck_bp) - anchor_luck as u128;
    let adjusted = anchor.iter().zip(destination).map(|(a, b)| {
        add(mul(mul(TAIL_BLEND_BP, *a)?, target_total / divisor)?,
            mul(mul(excess, b)?, anchor_total / divisor)?)
    }).collect::<Result<Vec<_>, String>>()?;
    sum(&adjusted)?;
    Ok(adjusted)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn legacy(tickets: &[(u64, u8)], luck: u32) -> Vec<u128> {
        let total: i128 = tickets.iter().map(|row| i128::from(row.0)).sum();
        let moment: i128 = tickets.iter().map(|row| i128::from(row.0) * i128::from(row.1)).sum();
        let base = (9 * total - moment) * 10_000;
        tickets.iter().map(|(weight, score)| {
            let factor = base + i128::from(luck) * (i128::from(*score) * total - moment);
            assert!(factor > 0);
            u128::from(*weight) * factor as u128
        }).collect()
    }

    #[test]
    fn early_pole_and_zero_weights_are_safe_without_clipping() {
        let pool = [(1, 0), (1, 9)];
        assert_eq!(weights(&pool, 9_999).unwrap(), vec![169_998, 189_996]);
        assert_eq!(weights(&pool, 10_000).unwrap(), vec![170_000, 190_000]);
        assert!(weights(&pool, u32::MAX).unwrap().iter().all(|weight| *weight > 0));
        let same_score = [(0, 0), (2, 4), (1, 4)];
        assert_eq!(weights(&same_score, 26_601).unwrap(), vec![0, 30_001_500_000, 15_000_750_000]);
        let maximum = weights(&same_score, u32::MAX).unwrap();
        assert_eq!(maximum[0], 0);
        assert_eq!(maximum[1], maximum[2] * 2);
    }

    #[test]
    fn invalid_data_still_fails_explicitly() {
        assert!(weights(&[], 0).is_err());
        assert!(weights(&[(0, 0)], 0).is_err());
        assert!(weights(&[(1, 9)], u32::MAX).is_err());
        assert!(weights(&[(1, 10)], 0).is_err());
        assert!(weights(&[(u64::MAX, 4)], 0).is_err());
    }

    #[test]
    fn forced_rank_fixture_remains_valid_at_zero_and_high_luck() {
        let tickets = [(1_000, 9), (1, 0)];
        assert_eq!(weights(&tickets, 0).unwrap(), legacy(&tickets, 0));
        for luck in [1, 9, 10, 26_600, u32::MAX] {
            let actual = weights(&tickets, luck).unwrap();
            assert!(actual.iter().all(|weight| *weight > 0));
            assert!(crate::sampling::total_weight_wide(&actual).is_ok());
        }
    }

    #[test]
    fn catalog_keeps_legacy_vectors_and_valid_high_luck_distributions() {
        let catalog: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
        for biome in 1..=7 {
            let mut entries = Vec::new();
            for fish in catalog["species"].as_array().unwrap().iter()
                .filter(|fish| fish["biomeId"] == biome)
            {
                for rank in fish["ranks"].as_array().unwrap() {
                    let score = if fish["key"] == "nidalees-lost-sock" {
                        9
                    } else {
                        crate::RARITY_TIERS.iter().position(|name| Some(*name) == rank["rarity"].as_str()).unwrap() as u8
                    };
                    entries.push((fish["key"].as_str().unwrap(), rank["rarity"].as_str().unwrap(),
                        rank["encounterWeight"].as_u64().unwrap(), score));
                }
            }
            let tickets: Vec<_> = entries.iter().map(|row| (row.2, row.3)).collect();
            for luck in [0, 1, 7_000, 12_000, 20_000, 26_599, 26_600] {
                assert_eq!(weights(&tickets, luck).unwrap(), legacy(&tickets, luck));
            }
            let mut previous = [0f64; 10];
            for luck in [0, 12_000, 26_600, 26_601, 33_200, 35_000, 40_000, 100_000, 1_000_000, u32::MAX] {
                let actual = weights(&tickets, luck).unwrap();
                let total = crate::sampling::total_weight_wide(&actual).unwrap();
                assert!(actual.iter().all(|weight| *weight > 0));
                for rank in 0..=9 {
                    let upper: u128 = tickets.iter().zip(&actual).filter(|(row, _)| row.1 >= rank).map(|(_, weight)| *weight).sum();
                    let chance = upper as f64 / total as f64;
                    assert!(chance.is_finite() && chance > 0.0 && chance <= 1.0);
                    assert!(chance + 1e-14 >= previous[usize::from(rank)]);
                    previous[usize::from(rank)] = chance;
                }
                if luck == u32::MAX {
                    assert!(total > u128::from(u64::MAX));
                }
                if biome == 7 {
                    let fihs = entries.iter().position(|row| row.0 == "fihs").unwrap();
                    let sock = entries.iter().position(|row| row.0 == "nidalees-lost-sock").unwrap();
                    let rarest = entries.iter().enumerate()
                        .filter(|(_, row)| row.0 != "fihs" && row.1 == "UUR")
                        .map(|(index, _)| actual[index]).min().unwrap();
                    assert_eq!(actual[fihs] * 2, rarest);
                    assert_eq!(actual[sock] * 2, actual[fihs]);
                }
            }
        }
    }
}
