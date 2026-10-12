//! Two independent bait slots share one cast and add their bonuses.
#[derive(Clone, Copy, Default, Debug, PartialEq, Eq)]
pub struct BaitBonuses {
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub size_rolls: u8,
}

pub fn combine(baits: &[BaitBonuses]) -> Result<BaitBonuses, &'static str> {
    if baits.len() > 2 {
        return Err("INVALID_BAIT_SLOTS");
    }
    let mut result = BaitBonuses {
        size_rolls: 1,
        ..Default::default()
    };
    let mut luck_rolls = 0;
    for bait in baits {
        if bait.luck_bp > 2500 || bait.xp_bonus_bp > 10000 || !(1..=8).contains(&bait.size_rolls) {
            return Err("INVALID_BAIT_BONUSES");
        }
        result.luck_bp += bait.luck_bp;
        result.xp_bonus_bp += bait.xp_bonus_bp;
        if bait.luck_bp > 0 {
            luck_rolls += bait.size_rolls;
        }
    }
    result.size_rolls = luck_rolls.max(1);
    Ok(result)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn all_catalog_pairs_stack_without_extra_size_attempts_from_slime_or_materials() {
        let catalog: serde_json::Value =
            serde_json::from_str(include_str!("../../../content/crafting.json")).unwrap();
        let baits: Vec<_> = catalog["baits"]
            .as_array()
            .unwrap()
            .iter()
            .map(|bait| BaitBonuses {
                luck_bp: bait["luckBp"].as_u64().unwrap() as u32,
                xp_bonus_bp: bait["xpBonusBp"].as_u64().unwrap() as u32,
                size_rolls: bait["sizeRolls"].as_u64().unwrap() as u8,
            })
            .collect();
        assert_eq!(combine(&[]).unwrap().size_rolls, 1);
        for first in &baits {
            assert_eq!(combine(&[*first]), Ok(*first));
            for second in &baits {
                let combined = combine(&[*first, *second]).unwrap();
                assert_eq!(combined.luck_bp, first.luck_bp + second.luck_bp);
                assert_eq!(combined.xp_bonus_bp, first.xp_bonus_bp + second.xp_bonus_bp);
                assert_eq!(
                    combined.size_rolls,
                    (if first.luck_bp > 0 {
                        first.size_rolls
                    } else {
                        0
                    } + if second.luck_bp > 0 {
                        second.size_rolls
                    } else {
                        0
                    })
                    .max(1)
                );
            }
        }
        assert_eq!(
            combine(&[baits[12], baits[12]]).unwrap(),
            BaitBonuses {
                luck_bp: 5000,
                xp_bonus_bp: 0,
                size_rolls: 16
            }
        );
        assert_eq!(combine(&[baits[7], baits[7]]).unwrap().xp_bonus_bp, 20000);
    }
}
