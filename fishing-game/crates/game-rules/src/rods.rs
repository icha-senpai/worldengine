//! Shared equipment math for independent mixed pulls and graded rank luck.
pub fn luck_weights(tickets: &[(u64, u8)], luck_bp: u32) -> Result<Vec<u64>, String> {
    if luck_bp > 8_500 || tickets.iter().any(|(_, score)| *score > 9) {
        return Err("INVALID_ROD_LUCK".into());
    }
    let total = tickets
        .iter()
        .try_fold(0i128, |sum, (w, _)| sum.checked_add(i128::from(*w)))
        .ok_or("WEIGHT_OVERFLOW")?;
    let moment = tickets
        .iter()
        .try_fold(0i128, |sum, (w, r)| {
            sum.checked_add(i128::from(*w) * i128::from(*r))
        })
        .ok_or("WEIGHT_OVERFLOW")?;
    let distance = total
        .checked_mul(9)
        .and_then(|v| v.checked_sub(moment))
        .ok_or("WEIGHT_OVERFLOW")?;
    if total == 0 || distance <= 0 {
        return Err("INVALID_ROD_POOL".into());
    }
    tickets
        .iter()
        .map(|(weight, score)| {
            let adjustment = i128::from(luck_bp)
                .checked_mul(i128::from(*score) * total - moment)
                .ok_or("WEIGHT_OVERFLOW")?;
            let factor = distance
                .checked_mul(10000)
                .and_then(|v| v.checked_add(adjustment))
                .ok_or("WEIGHT_OVERFLOW")?;
            if factor <= 0 {
                return Err("INVALID_ROD_POOL".into());
            }
            u64::try_from(
                i128::from(*weight)
                    .checked_mul(factor)
                    .ok_or("WEIGHT_OVERFLOW")?,
            )
            .map_err(|_| "WEIGHT_OVERFLOW".into())
        })
        .collect()
}
pub fn bonus_pull_bp(power: u32) -> Result<u32, String> {
    if power > 200 {
        return Err("INVALID_ROD_POWER".into());
    }
    Ok(power * 50)
}
/// Apply once to the complete cast reward, including discoveries from either pull.
pub fn fishing_xp(base: u64, bonus_bp: u32) -> Result<u64, String> {
    if bonus_bp > 10000 {
        return Err("INVALID_ROD_XP".into());
    }
    base.checked_mul(10000 + u64::from(bonus_bp))
        .map(|xp| xp / 10000)
        .ok_or_else(|| "XP_OVERFLOW".into())
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn xp_and_power_bounds_are_explicit() {
        assert_eq!(fishing_xp(120, 10000), Ok(240));
        assert_eq!(fishing_xp(3, 5000), Ok(4));
        assert!(fishing_xp(u64::MAX, 10000).is_err());
        assert!(fishing_xp(1, 10001).is_err());
        assert_eq!(bonus_pull_bp(185), Ok(9250));
        assert_eq!(bonus_pull_bp(1), Ok(50));
        assert!(bonus_pull_bp(201).is_err());
    }
    #[test]
    fn all_loadouts_preserve_normalization_and_exact_exceptional_odds() {
        let catalog: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
        let equipment: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/crafting.json")).unwrap();
        let world: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/world.json")).unwrap();
        for biome in 1..=7 {
            let mut entries = Vec::new();
            for fish in catalog["species"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|fish| fish["biomeId"] == biome)
            {
                for rank in fish["ranks"].as_array().unwrap() {
                    let rarity = rank["rarity"].as_str().unwrap();
                    let score = if fish["key"] == "nidalees-lost-sock" {
                        9
                    } else {
                        crate::RARITY_TIERS
                            .iter()
                            .position(|r| *r == rarity)
                            .unwrap() as u8
                    };
                    entries.push((
                        fish["key"].as_str().unwrap(),
                        rarity,
                        rank["encounterWeight"].as_u64().unwrap(),
                        score,
                    ));
                }
            }
            let tickets: Vec<_> = entries.iter().map(|(_, _, w, r)| (*w, *r)).collect();
            let baseline = luck_weights(&tickets, 0).unwrap();
            let total: u64 = baseline.iter().sum();
            for rod in world["rods"].as_array().unwrap() {
                for quality in equipment["qualities"].as_array().unwrap() {
                    for bait_luck in [0, 500, 1000, 1500] {
                        let luck = (rod["luckBp"].as_u64().unwrap()
                            + quality["luckBp"].as_u64().unwrap()
                            + bait_luck) as u32;
                        let weights = luck_weights(&tickets, luck).unwrap();
                        assert_eq!(weights.iter().sum::<u64>(), total);
                        assert!(weights.iter().all(|w| *w > 0));
                        for (i, (_, _, _, score)) in
                            entries.iter().enumerate().filter(|(_, row)| row.3 == 9)
                        {
                            assert_eq!(
                                u128::from(weights[i]) * 10000,
                                u128::from(baseline[i]) * u128::from(10000 + luck)
                            );
                            assert_eq!(*score, 9);
                        }
                        if biome == 7 {
                            let fihs = entries.iter().position(|row| row.0 == "fihs").unwrap();
                            let sock = entries
                                .iter()
                                .position(|row| row.0 == "nidalees-lost-sock")
                                .unwrap();
                            let rarest = entries
                                .iter()
                                .enumerate()
                                .filter(|(_, row)| row.0 != "fihs" && row.1 == "UUR")
                                .map(|(i, _)| weights[i])
                                .min()
                                .unwrap();
                            assert_eq!(weights[fihs] * 2, rarest);
                            assert_eq!(weights[sock] * 2, weights[fihs]);
                        }
                    }
                }
            }
        }
        assert!(luck_weights(&[(1, 9)], 8500).is_err());
        assert!(luck_weights(&[(1, 0), (1, 9)], 8501).is_err());
        assert!(luck_weights(&[(1, 10)], 0).is_err());
    }
}
