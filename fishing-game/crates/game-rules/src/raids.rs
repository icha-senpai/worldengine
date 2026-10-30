//! Shared raid economy, UTC scheduling and rod-mastery rules.
use crate::measurements::{Measurements, SpeciesMeasurements, generate_in_size_band, maximum_in_size_band};
use rand::Rng;
use serde::Deserialize;

pub const MICROS_PER_SECOND: i64 = 1_000_000;
pub const SECONDS_PER_DAY: i64 = 86_400;
pub const MAX_REINFORCEMENT_LEVEL: u8 = 10;

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Catalog {
    pub version: u32,
    pub rules_version: u32,
    pub deployment_window_seconds: u32,
    pub request_lifetime_seconds: u32,
    pub history_retention_days: u32,
    pub supply: Supply,
    pub materials: Vec<Material>,
    pub refining: Refining,
    pub grades: Vec<Grade>,
    pub reward_bonus_divisor: u64,
    pub community: Community,
    pub special: Special,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Supply {
    pub listing_id: u32,
    pub target_id: u32,
    pub item: String,
    pub slot: u32,
    pub name: String,
    pub description: String,
    pub price_coins: u64,
    pub units_per_purchase: u32,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Material {
    pub id: u8,
    pub key: String,
    pub name: String,
    pub slot_base: u32,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Refining {
    pub input_quantity: u64,
    pub output_quantity: u64,
    pub coins_per_batch: u64,
    pub maximum_batches: u32,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Grade {
    pub grade: u8,
    pub name: String,
    pub duration_seconds: u32,
    pub supply_units: u32,
    pub fillets: u64,
    pub scales: u64,
    pub reinforce_coins: u64,
    pub focus_chance_bp: u32,
    pub perfect_bonus_rolls: u8,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Community {
    pub start_days_utc: Vec<u8>,
    pub duration_seconds: u32,
    pub signup_seconds: u32,
    pub supply_units: u32,
    pub names: Vec<String>,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Special {
    pub duration_seconds: u32,
    pub supply_units: u32,
    pub maximum_fish: u8,
    pub maximum_roll_one_in: u32,
    pub requires_species_discovery: bool,
    pub species_keys: Vec<String>,
}

pub fn catalog() -> Catalog {
    serde_json::from_str(include_str!("../../../content/raids.json"))
        .expect("validated raid catalog")
}

pub fn grade(index: u8) -> Result<Grade, &'static str> {
    catalog().grades.into_iter().find(|row| row.grade == index).ok_or("INVALID_RAID_GRADE")
}

pub fn material(grade: u8, material_id: u8) -> Result<(u32, String), &'static str> {
    let tier = self::grade(grade)?;
    let row = catalog().materials.into_iter().find(|row| row.id == material_id)
        .ok_or("INVALID_RAID_MATERIAL")?;
    Ok((row.slot_base + u32::from(grade), format!("raid_{}_{}", row.key, tier.name.to_lowercase())))
}

pub fn next_personal_start(now_micros: i64) -> Result<i64, &'static str> {
    if now_micros < 0 {
        return Err("INVALID_RAID_TIME");
    }
    let window = i64::from(catalog().deployment_window_seconds) * MICROS_PER_SECOND;
    (now_micros / window + 1).checked_mul(window).ok_or("RAID_TIME_OVERFLOW")
}

/// Browser request IDs encode UTC milliseconds in the high 48 bits and a
/// random 16-bit suffix. Expiry makes pruning old action receipts replay-safe.
pub fn validate_browser_request(now_micros: i64, request_id: u64) -> Result<(), &'static str> {
    let millis = i64::try_from(request_id >> 16).map_err(|_| "INVALID_RAID_REQUEST")?;
    let sent = millis.checked_mul(1000).ok_or("INVALID_RAID_REQUEST")?;
    let age = now_micros.checked_sub(sent).ok_or("INVALID_RAID_REQUEST")?;
    let lifetime = i64::from(catalog().request_lifetime_seconds) * MICROS_PER_SECOND;
    if request_id == 0 || !(-300 * MICROS_PER_SECOND..=lifetime).contains(&age) {
        return Err("RAID_REQUEST_EXPIRED");
    }
    Ok(())
}

pub fn reward(base: u64, elapsed_seconds: u64, duration_seconds: u32, rng: &mut impl Rng) -> Result<u64, &'static str> {
    reward_with_divisor(
        base,
        elapsed_seconds,
        duration_seconds,
        catalog().reward_bonus_divisor,
        rng,
    )
}

/// Resolve a raid's authored payout divisor pinned when the attempt was prepared.
pub fn reward_with_divisor(
    base: u64,
    elapsed_seconds: u64,
    duration_seconds: u32,
    bonus_divisor: u64,
    rng: &mut impl Rng,
) -> Result<u64, &'static str> {
    if duration_seconds == 0 {
        return Err("INVALID_RAID_DURATION");
    }
    if bonus_divisor == 0 {
        return Err("INVALID_RAID_REWARD_DIVISOR");
    }
    let bonus = rng.gen_range(0..=base / bonus_divisor);
    let maximum = base.checked_add(bonus).ok_or("RAID_REWARD_OVERFLOW")?;
    let elapsed = elapsed_seconds.min(u64::from(duration_seconds));
    u64::try_from(u128::from(maximum) * u128::from(elapsed) / u128::from(duration_seconds))
        .map_err(|_| "RAID_REWARD_OVERFLOW")
}

pub fn focus_chance_bp(reinforcement_level: u8) -> Result<u32, &'static str> {
    if reinforcement_level == 0 {
        Ok(crate::collection_focus::CHANCE_BP)
    } else {
        Ok(grade(reinforcement_level - 1)?.focus_chance_bp)
    }
}

pub fn perfect_bonus_rolls(reinforcement_level: u8) -> Result<u8, &'static str> {
    if reinforcement_level == 0 {
        Ok(0)
    } else {
        Ok(grade(reinforcement_level - 1)?.perfect_bonus_rolls)
    }
}

/// Ordinary, rank-mastered species receive additional endpoint-only chances.
/// Existing size/bait sampling is unchanged when mastery is not eligible.
pub fn reinforced_measurements(
    model: SpeciesMeasurements,
    band: u8,
    size_rolls: u8,
    rules_version: u32,
    bonus_rolls: u8,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if bonus_rolls > MAX_REINFORCEMENT_LEVEL {
        return Err("INVALID_REINFORCEMENT");
    }
    let result = crate::trophy::generate(model, band, size_rolls, rules_version, rng)?;
    if band == 9 {
        for _ in 0..bonus_rolls {
            if rng.gen_range(0..crate::trophy::PERFECT_ROLL_ONE_IN) == 0 {
                return maximum_in_size_band(model, band);
            }
        }
    }
    Ok(result)
}

/// Exceptional raid rewards preserve their species' sole legal rank. A
/// maximum F Sock is a rank maximum, never an official UUR perfect.
pub fn special_measurements(
    model: SpeciesMeasurements,
    band: u8,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    special_measurements_with_probability(
        model,
        band,
        catalog().special.maximum_roll_one_in,
        rng,
    )
}

/// Use the attempt's pinned catalog probability without changing the
/// exceptional-fish sampler or its authored measurement behavior.
pub fn special_measurements_with_probability(
    model: SpeciesMeasurements,
    band: u8,
    maximum_roll_one_in: u32,
    rng: &mut impl Rng,
) -> Result<Measurements, &'static str> {
    if !matches!(band, 0 | 9) {
        return Err("INVALID_SPECIAL_RAID");
    }
    if maximum_roll_one_in == 0 {
        return Err("INVALID_SPECIAL_RAID");
    }
    if rng.gen_range(0..maximum_roll_one_in) == 0 {
        maximum_in_size_band(model, band)
    } else {
        generate_in_size_band(model, band, rng)
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct CommunityWindow {
    pub event_id: u64,
    pub name: String,
    pub signup_at: i64,
    pub starts_at: i64,
    pub ends_at: i64,
}

fn civil_from_days(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let day_of_era = z - era * 146_097;
    let year_of_era = (day_of_era - day_of_era / 1460 + day_of_era / 36_524
        - day_of_era / 146_096) / 365;
    let mut year = year_of_era + era * 400;
    let day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    let month_prime = (5 * day_of_year + 2) / 153;
    let day = day_of_year - (153 * month_prime + 2) / 5 + 1;
    let month = month_prime + if month_prime < 10 { 3 } else { -9 };
    year += i64::from(month <= 2);
    (year, month, day)
}

fn days_from_civil(year: i64, month: i64, day: i64) -> i64 {
    let year = year - i64::from(month <= 2);
    let era = year.div_euclid(400);
    let year_of_era = year - era * 400;
    let month_prime = month + if month > 2 { -3 } else { 9 };
    let day_of_year = (153 * month_prime + 2) / 5 + day - 1;
    era * 146_097 + year_of_era * 365 + year_of_era / 4
        - year_of_era / 100 + day_of_year - 719_468
}

/// Fixed UTC calendar slots, not drifting thirty-day intervals. Return the
/// unfinished events in this month and next month for the Quest calendar.
pub fn community_windows(now_micros: i64) -> Result<Vec<CommunityWindow>, &'static str> {
    let config = catalog().community;
    let (year, month, _) = civil_from_days(now_micros.div_euclid(SECONDS_PER_DAY * MICROS_PER_SECOND));
    if !(1970..=9998).contains(&year) {
        return Err("INVALID_RAID_TIME");
    }
    let mut windows = Vec::new();
    for offset in 0..=1 {
        let next_month = month + offset;
        let next_year = year + (next_month - 1) / 12;
        let next_month = (next_month - 1) % 12 + 1;
        for (index, &day) in config.start_days_utc.iter().enumerate() {
            let starts_at = days_from_civil(next_year, next_month, i64::from(day))
                * SECONDS_PER_DAY * MICROS_PER_SECOND;
            let ends_at = starts_at + i64::from(config.duration_seconds) * MICROS_PER_SECOND;
            if ends_at <= now_micros {
                continue;
            }
            windows.push(CommunityWindow {
                event_id: (next_year * 10_000 + next_month * 100 + i64::from(day)) as u64,
                name: config.names[index % config.names.len()].clone(),
                signup_at: starts_at - i64::from(config.signup_seconds) * MICROS_PER_SECOND,
                starts_at,
                ends_at,
            });
        }
    }
    Ok(windows)
}

#[cfg(test)]
mod tests {
    use super::*;
    use rand::{SeedableRng, rngs::StdRng};

    fn utc_micros(year: i64, month: i64, day: i64) -> i64 {
        days_from_civil(year, month, day) * SECONDS_PER_DAY * MICROS_PER_SECOND
    }

    fn browser_request(sent_millis: i64, suffix: u16) -> u64 {
        ((sent_millis as u64) << 16) | u64::from(suffix)
    }

    #[test]
    fn catalog_grades_materials_and_personal_schedule_match_contract() {
        let catalog = catalog();
        assert_eq!(
            catalog.grades.iter().map(|grade| grade.name.as_str()).collect::<Vec<_>>(),
            vec!["F", "D", "C", "B", "A", "S", "SS", "SSS", "UR", "UUR"]
        );
        assert_eq!(
            catalog
                .grades
                .iter()
                .map(|grade| u64::from(grade.duration_seconds))
                .sum::<u64>(),
            108 * 60 * 60
        );
        for grade in 0..10 {
            let (fillet_slot, fillet_item) = material(grade, 0).unwrap();
            let (scale_slot, scale_item) = material(grade, 1).unwrap();
            assert_eq!(fillet_slot, 1000 + u32::from(grade));
            assert_eq!(scale_slot, 1100 + u32::from(grade));
            assert_ne!(fillet_slot, scale_slot);
            assert_eq!(fillet_item, format!("raid_fillets_{}", catalog.grades[grade as usize].name.to_lowercase()));
            assert_eq!(scale_item, format!("raid_scales_{}", catalog.grades[grade as usize].name.to_lowercase()));
        }
        assert!(matches!(grade(10), Err("INVALID_RAID_GRADE")));
        assert_eq!(material(0, 2), Err("INVALID_RAID_MATERIAL"));
    }

    #[test]
    fn pinned_reward_divisor_preserves_the_authored_bonus_distribution() {
        let divisor = catalog().reward_bonus_divisor;
        for seed in [0, 1, 7, 19, 74, 165, 5000] {
            let mut standard = StdRng::seed_from_u64(seed);
            let mut pinned = StdRng::seed_from_u64(seed);
            assert_eq!(
                reward(720, 108_000, 108_000, &mut standard),
                reward_with_divisor(720, 108_000, 108_000, divisor, &mut pinned)
            );
            assert_eq!(standard.r#gen::<u64>(), pinned.r#gen::<u64>());
        }
        let mut rng = StdRng::seed_from_u64(1);
        assert_eq!(
            reward_with_divisor(10, 1, 10, 0, &mut rng),
            Err("INVALID_RAID_REWARD_DIVISOR")
        );
    }

    #[test]
    fn personal_start_and_browser_request_windows_are_exact() {
        assert_eq!(next_personal_start(599_999_999), Ok(600_000_000));
        assert_eq!(next_personal_start(600_000_000), Ok(1_200_000_000));
        assert_eq!(next_personal_start(-1), Err("INVALID_RAID_TIME"));

        let now = 1_800_000_000_000_000i64;
        let now_millis = now / 1000;
        assert_eq!(validate_browser_request(now, browser_request(now_millis - 60_000, 9)), Ok(()));
        assert_eq!(validate_browser_request(now, browser_request(now_millis - 86_400_000, 10)), Ok(()));
        assert_eq!(validate_browser_request(now, browser_request(now_millis - 86_400_001, 11)), Err("RAID_REQUEST_EXPIRED"));
        assert_eq!(validate_browser_request(now, browser_request(now_millis + 300_000, 12)), Ok(()));
        assert_eq!(validate_browser_request(now, browser_request(now_millis + 300_001, 13)), Err("RAID_REQUEST_EXPIRED"));
    }

    #[test]
    fn community_calendar_rolls_through_december_and_leap_february_in_utc() {
        let december = utc_micros(2026, 12, 15) + 12 * 60 * 60 * MICROS_PER_SECOND;
        let windows = community_windows(december).unwrap();
        let dec16 = windows.iter().find(|window| window.event_id == 20261216).unwrap();
        let jan1 = windows.iter().find(|window| window.event_id == 20270101).unwrap();
        assert_eq!(dec16.name, "The Midnight Undertow");
        assert_eq!(jan1.name, "The Deepwater Convergence");
        assert_eq!(dec16.signup_at, dec16.starts_at - 24 * 60 * 60 * MICROS_PER_SECOND);
        assert_eq!(dec16.ends_at - dec16.starts_at, 96 * 60 * 60 * MICROS_PER_SECOND);

        let leap_february = utc_micros(2028, 2, 28);
        let windows = community_windows(leap_february).unwrap();
        let march1 = windows.iter().find(|window| window.event_id == 20280301).unwrap();
        assert_eq!(march1.starts_at, utc_micros(2028, 3, 1));
        assert_eq!(march1.ends_at, utc_micros(2028, 3, 5));
        assert_eq!(march1.name, "The Deepwater Convergence");
    }

    #[test]
    fn pinned_special_probability_preserves_the_authored_sampler() {
        let model = SpeciesMeasurements {
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12_800,
        };
        for seed in [1, 7, 19, 74, 165, 5000] {
            let mut catalog_rng = StdRng::seed_from_u64(seed);
            let mut pinned_rng = StdRng::seed_from_u64(seed);
            assert_eq!(
                special_measurements(model, 9, &mut catalog_rng),
                special_measurements_with_probability(model, 9, 5000, &mut pinned_rng)
            );
        }
        let mut rng = StdRng::seed_from_u64(1);
        assert_eq!(
            special_measurements_with_probability(model, 1, 5000, &mut rng),
            Err("INVALID_SPECIAL_RAID")
        );
    }

    #[test]
    fn authored_focus_chances_and_endpoint_roll_counts_map_each_grade() {
        assert_eq!(focus_chance_bp(0), Ok(crate::collection_focus::CHANCE_BP));
        for level in 1..=10 {
            let grade = grade(level - 1).unwrap();
            assert_eq!(focus_chance_bp(level), Ok(grade.focus_chance_bp));
            assert_eq!(perfect_bonus_rolls(level), Ok(grade.perfect_bonus_rolls));
        }
        assert_eq!(focus_chance_bp(11), Err("INVALID_RAID_GRADE"));
        assert_eq!(perfect_bonus_rolls(11), Err("INVALID_RAID_GRADE"));
    }

    #[test]
    fn reinforced_focus_proc_uses_the_authored_grade_chance_and_only_missing_ranks() {
        let seed = (0..100_000u64)
            .find(|seed| {
                let mut rng = StdRng::seed_from_u64(*seed);
                let roll = rng.gen_range(0..10_000u32);
                (crate::collection_focus::CHANCE_BP..focus_chance_bp(1).unwrap())
                    .contains(&roll)
            })
            .expect("a seed falls between the authored 20% and 23% proc thresholds");
        let mut ordinary_rng = StdRng::seed_from_u64(seed);
        let mut reinforced_rng = StdRng::seed_from_u64(seed);
        let ranks = [(0, 100), (1, 100)];
        let known = [true, false];
        assert_eq!(
            crate::collection_focus::reroll(0, &ranks, &known, &mut ordinary_rng),
            Ok(0)
        );
        assert_eq!(
            crate::collection_focus::reroll_with_chance(
                0,
                &ranks,
                &known,
                focus_chance_bp(1).unwrap(),
                &mut reinforced_rng
            ),
            Ok(1)
        );
    }

    #[test]
    fn unreinforced_focus_preserves_the_legacy_reroll_stream() {
        let ranks = [(0, 900), (1, 50), (2, 50)];
        let known = [true, false, false];
        for seed in [0, 1, 7, 19, 74, 165, 5000] {
            let mut legacy = StdRng::seed_from_u64(seed);
            let mut explicit = StdRng::seed_from_u64(seed);
            assert_eq!(
                crate::collection_focus::reroll(0, &ranks, &known, &mut legacy),
                crate::collection_focus::reroll_with_chance(
                    0,
                    &ranks,
                    &known,
                    focus_chance_bp(0).unwrap(),
                    &mut explicit
                )
            );
            assert_eq!(legacy.r#gen::<u64>(), explicit.r#gen::<u64>());
        }
    }

    #[test]
    fn zero_reinforcement_rolls_preserve_trophy_measurements_and_rng() {
        let model = SpeciesMeasurements {
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12_800,
        };
        for band in 0..10 {
            let mut old_stream = StdRng::seed_from_u64(91 + u64::from(band));
            let mut new_stream = old_stream.clone();
            let old = crate::trophy::generate(model, band, 2, 17, &mut old_stream);
            let new = reinforced_measurements(model, band, 2, 17, 0, &mut new_stream);
            assert_eq!(old, new);
            assert_eq!(
                old_stream.r#gen::<u64>(),
                new_stream.r#gen::<u64>(),
                "band {band} must preserve the unreinforced RNG stream"
            );
        }
    }

    #[test]
    fn endpoint_bonus_rolls_are_only_used_for_uur_measurements() {
        let model = SpeciesMeasurements {
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12_800,
        };
        let mut trophy_rng = StdRng::seed_from_u64(740);
        let mut reinforced_rng = trophy_rng.clone();
        assert_eq!(
            crate::trophy::generate(model, 8, 1, 17, &mut trophy_rng),
            reinforced_measurements(model, 8, 1, 17, 10, &mut reinforced_rng)
        );
        assert_eq!(
            trophy_rng.r#gen::<u64>(),
            reinforced_rng.r#gen::<u64>(),
            "non-UUR bands consume no endpoint-only draws"
        );

        let maximum = maximum_in_size_band(model, 9).unwrap();
        let seed = (0..100_000u64)
            .find(|seed| {
                let mut baseline_rng = StdRng::seed_from_u64(*seed);
                let baseline = crate::trophy::generate(model, 9, 1, 17, &mut baseline_rng).unwrap();
                let mut reinforced_rng = StdRng::seed_from_u64(*seed);
                let reinforced =
                    reinforced_measurements(model, 9, 1, 17, 10, &mut reinforced_rng).unwrap();
                baseline != maximum && reinforced == maximum
            })
            .expect("a seeded UUR cast reaches an authored endpoint");
        let mut rng = StdRng::seed_from_u64(seed);
        assert_eq!(
            reinforced_measurements(model, 9, 1, 17, 10, &mut rng).unwrap(),
            maximum
        );
        assert_eq!(
            reinforced_measurements(model, 9, 1, 17, 11, &mut StdRng::seed_from_u64(seed)),
            Err("INVALID_REINFORCEMENT")
        );
    }

    #[test]
    fn special_sampler_has_seeded_joint_maximum_and_normal_outcomes() {
        let model = SpeciesMeasurements {
            typical_length_mm: 450,
            min_length_mm: 247,
            max_length_mm: 855,
            typical_weight_g: 1600,
            min_weight_g: 200,
            max_weight_g: 12_800,
        };
        let find = |band, want_maximum| {
            (0..100_000u64)
                .find(|seed| {
                    let mut rng = StdRng::seed_from_u64(*seed);
                    let result = special_measurements_with_probability(model, band, 5000, &mut rng)
                        .unwrap();
                    let maximum = maximum_in_size_band(model, band).unwrap();
                    (result == maximum) == want_maximum
                })
                .expect("seed exists for expected sampler outcome")
        };
        for band in [0, 9] {
            let maximum_seed = find(band, true);
            let ordinary_seed = find(band, false);
            let mut maximum_rng = StdRng::seed_from_u64(maximum_seed);
            let mut ordinary_rng = StdRng::seed_from_u64(ordinary_seed);
            assert_eq!(
                special_measurements_with_probability(model, band, 5000, &mut maximum_rng).unwrap(),
                maximum_in_size_band(model, band).unwrap()
            );
            assert_ne!(
                special_measurements_with_probability(model, band, 5000, &mut ordinary_rng).unwrap(),
                maximum_in_size_band(model, band).unwrap()
            );
        }
    }
}
