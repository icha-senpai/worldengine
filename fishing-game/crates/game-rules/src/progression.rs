/// Rules 10 retain the first ten level costs, then grow by 35 XP per level.
pub fn xp_to_next(level: u32) -> u64 {
    xp_to_next_for_version(level, 10)
}
pub fn xp_to_next_for_version(level: u32, rules_version: u32) -> u64 {
    let level = u64::from(level);
    if rules_version < 10 || level <= 10 {
        80 + 25 * level + 5 * level * level
    } else {
        830 + 35 * (level - 10)
    }
}
pub fn level_for_xp(total_xp: u64, max_level: u32) -> u32 {
    level_for_xp_for_version(total_xp, max_level, 10)
}
pub fn level_for_xp_for_version(mut total_xp: u64, max_level: u32, rules_version: u32) -> u32 {
    let mut level = 1;
    while level < max_level && total_xp >= xp_to_next_for_version(level, rules_version) {
        total_xp -= xp_to_next_for_version(level, rules_version);
        level += 1;
    }
    level
}
pub fn record_wins(
    value: u64,
    caught_micros: i64,
    catch_id: u64,
    previous: (u64, i64, u64),
) -> bool {
    value > previous.0
        || (value == previous.0 && (caught_micros, catch_id) < (previous.1, previous.2))
}
pub fn discord_created_micros(interaction_id: u64) -> Result<i64, &'static str> {
    let milliseconds = (interaction_id >> 22)
        .checked_add(1_420_070_400_000)
        .ok_or("INVALID_INTERACTION")?;
    i64::try_from(
        milliseconds
            .checked_mul(1000)
            .ok_or("INVALID_INTERACTION")?,
    )
    .map_err(|_| "INVALID_INTERACTION")
}
pub fn check_interaction_freshness(
    interaction_id: u64,
    now_micros: i64,
) -> Result<(), &'static str> {
    let created = discord_created_micros(interaction_id)?;
    let age = now_micros
        .checked_sub(created)
        .ok_or("INVALID_INTERACTION")?;
    if !(-5_000_000..=300_000_000).contains(&age) {
        return Err("INTERACTION_EXPIRED");
    }
    Ok(())
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn xp_thresholds_are_monotonic_and_allow_multiple_level_crossings() {
        assert_eq!(xp_to_next(1), 110);
        assert_eq!(xp_to_next(10), 830);
        assert_eq!(xp_to_next(11), 865);
        assert_eq!(xp_to_next(25), 1355);
        assert_eq!((1..120).map(xp_to_next).sum::<u64>(), 304395);
        let mut total = 0;
        for level in 1..120 {
            total += xp_to_next(level);
            assert_eq!(level_for_xp(total - 1, 120), level);
            assert_eq!(level_for_xp(total, 120), level + 1);
            assert!(xp_to_next(level + 1) > xp_to_next(level));
        }
        assert_eq!(level_for_xp(u64::MAX, 120), 120);
    }
    #[test]
    fn expanded_cap_credits_banked_xp_and_keeps_existing_thresholds() {
        let cap = 10_000;
        let previous_cap_xp: u64 = (1..120).map(xp_to_next).sum();
        assert_eq!(previous_cap_xp, 304395);
        assert_eq!(level_for_xp(previous_cap_xp, cap), 120);
        let banked_xp = 7_662_839;
        assert_eq!(level_for_xp(banked_xp, 120), 120);
        assert_eq!(level_for_xp(banked_xp, cap), 648);
        for level in [121, 250, 500, 1000, cap] {
            let threshold: u64 = (1..level).map(xp_to_next).sum();
            assert_eq!(level_for_xp(threshold - 1, cap), level - 1);
            assert_eq!(level_for_xp(threshold, cap), level);
        }
        assert_eq!((1..cap).map(xp_to_next).sum::<u64>(), 1_754_621_895);
        assert_eq!(level_for_xp(u64::MAX, cap), cap);
    }
    #[test]
    fn display_cap_500_preserves_the_curve_and_accepts_unbounded_lifetime_xp() {
        let cap = 500;
        let threshold: u64 = (1..cap).map(xp_to_next).sum();
        assert_eq!(threshold, 4_603_145);
        assert_eq!(level_for_xp(threshold - 1, cap), 499);
        assert_eq!(level_for_xp(threshold, cap), cap);
        for xp in [threshold + 1, 1_754_621_895, u64::MAX] {
            assert_eq!(level_for_xp(xp, cap), cap);
        }
        for level in 1..=500 {
            assert_eq!(
                xp_to_next_for_version(level, 16),
                xp_to_next_for_version(level, 15)
            );
        }
    }
    #[test]
    fn curve_activation_retains_early_levels_and_never_lowers_existing_levels() {
        let mut legacy_total = 0;
        for level in 1..120 {
            let old = xp_to_next_for_version(level, 9);
            if level <= 10 {
                assert_eq!(old, xp_to_next(level));
            }
            assert!(xp_to_next(level) <= old);
            assert!(
                level_for_xp(legacy_total, 120) >= level_for_xp_for_version(legacy_total, 120, 9)
            );
            assert_eq!(level_for_xp_for_version(legacy_total, 120, 9), level);
            assert_eq!(
                level_for_xp_for_version(legacy_total + old - 1, 120, 9),
                level
            );
            legacy_total += old;
        }
        assert_eq!(legacy_total, 3032120);
    }
    #[test]
    fn record_ties_use_earliest_time_then_smallest_id() {
        assert!(record_wins(11, 300, 50, (10, 100, 1)));
        assert!(record_wins(10, 99, 99, (10, 100, 1)));
        assert!(record_wins(10, 100, 1, (10, 100, 2)));
        assert!(!record_wins(10, 101, 1, (10, 100, 2)));
        assert!(!record_wins(9, 1, 1, (10, 100, 2)));
    }
    #[test]
    fn old_and_future_discord_ids_cannot_create_new_operations() {
        let id = ((1_790_000_000_000u64 - 1_420_070_400_000) << 22) | 17;
        let now = discord_created_micros(id).unwrap();
        assert_eq!(check_interaction_freshness(id, now + 300_000_000), Ok(()));
        assert_eq!(
            check_interaction_freshness(id, now + 300_000_001),
            Err("INTERACTION_EXPIRED")
        );
        assert_eq!(
            check_interaction_freshness(id, now - 5_000_001),
            Err("INTERACTION_EXPIRED")
        );
    }
}
