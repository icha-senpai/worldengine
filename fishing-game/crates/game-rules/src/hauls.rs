//! Fixed-size, bait-free hauls use graded rank luck with their own gear limits.
pub fn settings(kind: &str) -> Result<(i64, usize), String> {
    match kind {
        "boat" => Ok((600, 10)),
        "net" => Ok((240, 4)),
        _ => Err("INVALID_HAUL_KIND".into()),
    }
}

pub fn luck_weights(tickets: &[(u64, u8)], luck_bp: u32) -> Result<Vec<u64>, String> {
    if luck_bp > 26_600 {
        return Err("INVALID_HAUL_LUCK".into());
    }
    crate::rods::graded_luck_weights(tickets, luck_bp)
}

pub fn fishing_xp(base: u64, bonus_bp: u32) -> Result<u64, String> {
    if bonus_bp > 37_400 {
        return Err("INVALID_HAUL_XP".into());
    }
    base.checked_mul(10_000 + u64::from(bonus_bp))
        .map(|value| value / 10_000)
        .ok_or_else(|| "XP_OVERFLOW".into())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn fixed_timing_and_maximum_xp() {
        assert_eq!(settings("boat"), Ok((600, 10)));
        assert_eq!(settings("net"), Ok((240, 4)));
        assert!(settings("rod").is_err());
        assert_eq!(fishing_xp(100, 37_400), Ok(474));
        assert!(fishing_xp(u64::MAX, 37_400).is_err());
        assert!(fishing_xp(1, 37_401).is_err());
        assert!(crate::rods::fishing_xp(100, 37_400).is_err());
    }
    #[test]
    fn every_haul_quality_preserves_positive_weights_and_exceptional_hierarchy() {
        let species: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
        let gear: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/haul-equipment.json")).unwrap();
        let crafting: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/crafting.json")).unwrap();
        for biome in 1..=7 {
            let mut entries = Vec::new();
            for fish in species["species"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|f| f["biomeId"] == biome)
            {
                for rank in fish["ranks"].as_array().unwrap() {
                    let name = fish["key"].as_str().unwrap();
                    let score = if name == "nidalees-lost-sock" {
                        9
                    } else {
                        crate::RARITY_TIERS
                            .iter()
                            .position(|r| Some(*r) == rank["rarity"].as_str())
                            .unwrap() as u8
                    };
                    entries.push((
                        name,
                        rank["rarity"].as_str().unwrap(),
                        rank["encounterWeight"].as_u64().unwrap(),
                        score,
                    ));
                }
            }
            let tickets: Vec<_> = entries.iter().map(|e| (e.2, e.3)).collect();
            let baseline = luck_weights(&tickets, 0).unwrap();
            for item in gear["equipment"].as_array().unwrap() {
                for quality in crafting["qualities"].as_array().unwrap() {
                    let luck = (item["luckBp"].as_u64().unwrap()
                        + quality["luckBp"].as_u64().unwrap()
                            * item["qualityBonusMultiplier"].as_u64().unwrap())
                        as u32;
                    let weights = luck_weights(&tickets, luck).unwrap();
                    assert!(weights.iter().all(|w| *w > 0));
                    assert_eq!(weights.iter().sum::<u64>(), baseline.iter().sum::<u64>());
                    for (i, entry) in entries.iter().enumerate().filter(|(_, e)| e.3 == 9) {
                        assert_eq!(
                            u128::from(weights[i]) * 10_000,
                            u128::from(baseline[i]) * u128::from(10_000 + luck)
                        );
                        assert_eq!(entry.3, 9);
                    }
                    if biome == 7 {
                        let fihs = entries.iter().position(|e| e.0 == "fihs").unwrap();
                        let sock = entries
                            .iter()
                            .position(|e| e.0 == "nidalees-lost-sock")
                            .unwrap();
                        let rarest = entries
                            .iter()
                            .enumerate()
                            .filter(|(_, e)| e.0 != "fihs" && e.1 == "UUR")
                            .map(|(i, _)| weights[i])
                            .min()
                            .unwrap();
                        assert_eq!(weights[fihs] * 2, rarest);
                        assert_eq!(weights[sock] * 2, weights[fihs]);
                    }
                }
            }
        }
        assert!(luck_weights(&[(1, 0), (1, 9)], 26_601).is_err());
    }
}
