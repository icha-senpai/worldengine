use super::*;
use crate::measurements::{SpeciesMeasurements, classify_rarity, maximum_in_size_band};
use rand::{SeedableRng, rngs::StdRng};

fn input<'a>(kind: &'a str, affixes: &'a [AffixRoll]) -> ComposeInput<'a> {
    ComposeInput {
        kind, biome_id: 2, primary_biome_id: 1, mastery_xp: u64::MAX,
        affixes, native_signature: None, kind_signature: None, waters_signature: None, goal: "ranks",
    }
}

fn roll(slot: u8, id: &str) -> AffixRoll {
    AffixRoll { slot, id: id.into(), tier: 10, finish_bp: 10_000, secondary_biome_id: None }
}

fn carp() -> SpeciesMeasurements {
    SpeciesMeasurements {
        typical_length_mm: 450, min_length_mm: 247, max_length_mm: 855,
        typical_weight_g: 1600, min_weight_g: 200, max_weight_g: 12800,
    }
}

fn measurement_plan(band: u8, rolls: u8) -> MeasurementPlan {
    MeasurementPlan {
        species: carp(), band, base_rolls: rolls, bait_rolls: rolls,
        ordinary: true, first_ordinary: true, focus_enabled: true,
        all_ranks_known: true, reinforcement_level: 10, rules_version: 17,
    }
}

#[test]
fn catalogue_and_mastery_thresholds_are_explicit_and_keep_lifetime_xp() {
    validate_catalog().unwrap();
    for (level, threshold, slots) in [(1, 0, 1), (10, 1620, 2), (25, 7920, 3),
        (50, 28420, 4), (100, 106920, 4)]
    {
        assert_eq!(mastery_threshold(level), Ok(threshold));
        assert_eq!(mastery_level(threshold), level);
        assert_eq!(unlocked_slots(threshold), slots);
        if threshold > 0 {
            assert_eq!(mastery_level(threshold - 1), level - 1);
        }
    }
    assert_eq!(mastery_level(u64::MAX), 100);
    assert!(mastery_threshold(0).is_err());
    assert!(mastery_threshold(101).is_err());
    assert_eq!(rolled_value(2500, 0), Ok(2000));
    assert_eq!(rolled_value(2500, 5000), Ok(2250));
    assert_eq!(rolled_value(2500, 10000), Ok(2500));
    assert_eq!(rolled_value(1, 0), Ok(1));
    assert!(rolled_value(2500, 10001).is_err());
}

#[test]
fn fractional_credit_is_conserved_and_overflow_is_not_clipped() {
    let mut remainder = 0;
    let mut total = 0;
    for _ in 0..4 {
        let (credit, next) = scale_credit(1, 12500, remainder).unwrap();
        total += credit;
        remainder = next;
    }
    assert_eq!((total, remainder), (5, 0));
    assert_eq!(scale_credit(100, 10160, 0), Ok((101, 6000)));
    assert_eq!(scale_credit(0, 0, 9999), Ok((0, 9999)));
    assert!(scale_credit(u64::MAX, 10001, 0).is_err());
    assert!(scale_credit(1, 1, 10000).is_err());
}

#[test]
fn dormant_affixes_validate_and_secondary_affinity_never_activates_primary() {
    let rows = [roll(0, "fortune"), roll(1, "scholar"), roll(2, "old_salt"), roll(3, "coinwright")];
    let mut request = input("rod", &rows);
    request.mastery_xp = 0;
    let effects = compose(request).unwrap();
    assert_eq!(effects.stat("luckBp"), 2500);
    assert_eq!(effects.stat("playerXpBp"), 0);
    assert_eq!(effects.dormant_affixes.len(), 3);
    let mut malformed = rows.clone();
    malformed[3].tier = 11;
    let mut request = input("rod", &malformed);
    request.mastery_xp = 0;
    assert!(compose(request).is_err());
    let mut secondary = roll(0, "secondary_affinity");
    secondary.secondary_biome_id = Some(2);
    let rows = [secondary, roll(1, "attuned_focus")];
    let effects = compose(input("rod", &rows)).unwrap();
    assert_eq!(effects.stat("luckBp"), 4000);
    assert_eq!(effects.stat("rankFocusBp"), 0);
    let mut request = input("rod", &rows);
    request.primary_biome_id = 2;
    let effects = compose(request).unwrap();
    assert_eq!(effects.stat("luckBp"), 100);
    assert_eq!(effects.stat("rankFocusBp"), 1500);
    assert!(effects.dormant_affixes.contains(&"secondary_affinity".into()));
}

#[test]
fn signature_ids_deduplicate_without_merging_distinct_pull_gates() {
    let rows = [roll(0, "aftercast")];
    let mut request = input("rod", &rows);
    request.native_signature = Some("echoed_line");
    request.kind_signature = Some("echoed_line");
    request.waters_signature = Some("forgotten_cache");
    let effects = compose(request).unwrap();
    assert_eq!(effects.extra_pull_bp, vec![5000, 5000]);
    assert_eq!(effects.signatures.len(), 2);
    assert_eq!(effects.parameter("forgotten_cache", "firstFishFillets"), 6);
    let mut request = input("boat", &[]);
    request.native_signature = Some("trophys_oath");
    assert!(compose(request).is_err());
}

#[test]
fn zero_effect_pulls_and_measurements_preserve_the_legacy_rng_stream() {
    let effects = compose(input("rod", &[])).unwrap();
    assert!(effects.stats.is_empty());
    for seed in 0..100 {
        let mut actual = StdRng::seed_from_u64(seed);
        let mut legacy = actual.clone();
        let expected = 1 + usize::from(legacy.gen_range(0..10_000u32) < crate::rods::bonus_pull_bp(185).unwrap());
        assert_eq!(rod_pulls(185, &effects, &mut actual), Ok(expected));
        assert_eq!(actual, legacy);
        for band in 0..10 {
            let mut actual = StdRng::seed_from_u64(seed);
            let mut legacy = actual.clone();
            let expected = if band == 9 {
                crate::raids::reinforced_measurements(carp(), band, 16, 17, 10, &mut legacy).unwrap()
            } else {
                crate::trophy::generate(carp(), band, 16, 17, &mut legacy).unwrap()
            };
            assert_eq!(generate_measurements(measurement_plan(band, 16), &effects, &mut actual), Ok(expected));
            assert_eq!(actual, legacy);
        }
    }
}

#[test]
fn fifty_attempts_and_heaviest_complete_specimens_stay_in_their_legal_rank() {
    let rows = [roll(0, "chum_catalyst"), roll(1, "measured_cast"), roll(2, "weight_weaver"), roll(3, "trophy_lens")];
    let mut request = input("rod", &rows);
    request.kind_signature = Some("baitkeepers_promise");
    let effects = compose(request).unwrap();
    for seed in 0..40 {
        let mut rng = StdRng::seed_from_u64(seed);
        assert_eq!(specimen_attempts(16, 16, true, true, &effects, &mut rng), Ok(50));
        for band in 0..10 {
            let specimen = generate_measurements(measurement_plan(band, 16), &effects, &mut rng).unwrap();
            assert_eq!(classify_rarity(carp(), specimen), Ok(band));
            let maximum = maximum_in_size_band(carp(), band).unwrap();
            assert!(specimen.length_mm <= maximum.length_mm && specimen.weight_g <= maximum.weight_g);
        }
    }
}

#[test]
fn rank_priority_makes_one_replacement_and_perfects_preserves_an_initial_uur() {
    let rows = [roll(0, "maxima_priority")];
    let mut effects = compose(input("rod", &rows)).unwrap();
    effects.stats.insert("maximaFocusBp".into(), 10000);
    effects.stats.insert("perfectFocusBp".into(), 10000);
    let known = 1023 ^ (1 << 8);
    for seed in 0..100 {
        let mut rng = StdRng::seed_from_u64(seed);
        assert_eq!(steer_rank(0, &[1; 10], known, known ^ (1 << 9), false, true, 10000, &effects, &mut rng), Ok(8));
        effects.goal = "perfects".into();
        let before = rng.clone();
        assert_eq!(steer_rank(9, &[1; 10], known, known ^ (1 << 9), false, true, 10000, &effects, &mut rng), Ok(9));
        assert_eq!(before, rng);
        effects.goal = "ranks".into();
    }
}

#[test]
fn precision_stays_legal_for_every_ordinary_catalog_rank_and_excludes_exceptionals() {
    let data: serde_json::Value = serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
    let rows = [roll(0, "chum_catalyst"), roll(1, "trophy_lens"), roll(2, "weight_weaver"), roll(3, "maxima_priority")];
    let mut request = input("rod", &rows);
    request.native_signature = Some("trophys_oath");
    request.kind_signature = Some("baitkeepers_promise");
    let effects = compose(request).unwrap();
    let mut ordinary_species = 0;
    let mut ordinary_ranks = 0;
    for fish in data["species"].as_array().unwrap() {
        let ordinary = fish["countsForOrdinaryCollectionCompletion"].as_bool().unwrap();
        let length = fish["typicalLengthMm"].as_u64().unwrap();
        let weight = fish["typicalWeightG"].as_u64().unwrap();
        let model = SpeciesMeasurements {
            typical_length_mm: length as u32, min_length_mm: (length * 55 / 100) as u32,
            max_length_mm: (length * 19 / 10) as u32,
            typical_weight_g: weight, min_weight_g: (weight / 8).max(1), max_weight_g: weight * 8,
        };
        if ordinary {
            ordinary_species += 1;
        }
        for rarity in fish["allowedRarities"].as_array().unwrap() {
            let band = crate::RARITY_TIERS.iter().position(|r| Some(*r) == rarity.as_str()).unwrap() as u8;
            if ordinary {
                ordinary_ranks += 1;
            }
            for seed in [0, 17] {
                let mut actual = StdRng::seed_from_u64(seed);
                let mut legacy = actual.clone();
                let mut plan = measurement_plan(band, 16);
                plan.species = model;
                plan.ordinary = ordinary;
                let specimen = generate_measurements(plan, &effects, &mut actual).unwrap();
                assert_eq!(classify_rarity(model, specimen), Ok(band));
                let (min_length, max_length, floor) = crate::measurements::size_band_bounds(model, band).unwrap();
                let min_weight = crate::measurements::weight_at_length(model, specimen.length_mm, 880_000)
                    .unwrap().max(floor).max(model.min_weight_g);
                let max_weight = crate::measurements::weight_at_length(model, specimen.length_mm, 1_120_000)
                    .unwrap().max(min_weight).min(model.max_weight_g);
                assert!((min_length..=max_length).contains(&u64::from(specimen.length_mm)));
                assert!((min_weight..=max_weight).contains(&specimen.weight_g));
                if !ordinary {
                    assert_eq!(specimen, crate::trophy::generate(model, band, 16, 17, &mut legacy).unwrap());
                    assert_eq!(actual, legacy);
                }
            }
        }
    }
    assert_eq!((ordinary_species, ordinary_ranks), (249, 2490));
}

#[test]
fn wide_power_and_haul_quotas_are_nonrecursive_and_ordinary_only() {
    let rows = [roll(0, "line_tension"), roll(1, "aftercast")];
    let mut request = input("rod", &rows);
    request.native_signature = Some("echoed_line");
    let effects = compose(request).unwrap();
    for base in [185, 200, 201, u32::MAX] {
        for seed in 0..10 {
            let mut rng = StdRng::seed_from_u64(seed);
            let count = rod_pulls(base, &effects, &mut rng).unwrap();
            let guaranteed = 1 + ((u64::from(base) * 100 + 4000) / 20_000) as usize;
            assert!((guaranteed..=guaranteed + 3).contains(&count));
        }
    }
    let rows = [roll(0, "schoolfinder")];
    let mut request = input("boat", &rows);
    request.native_signature = Some("cartographers_wake");
    let mut effects = compose(request).unwrap();
    let mut quota = DuplicateQuota::default();
    let mut rng = StdRng::seed_from_u64(0);
    assert!(!quota.reroll(false, &effects, &mut rng).unwrap());
    assert!(quota.reroll(true, &effects, &mut rng).unwrap());
    assert!(quota.reroll(true, &effects, &mut rng).unwrap());
    assert!(!quota.reroll(true, &effects, &mut rng).unwrap());
    effects.stats.insert("duplicateRerollBp".into(), 1);
    let mut found_failed_gate = false;
    for seed in 0..100 {
        let mut probe = StdRng::seed_from_u64(seed);
        if chance(1, 10_000, &mut probe).unwrap() { continue; }
        let mut actual = StdRng::seed_from_u64(seed);
        let mut quota = DuplicateQuota::default();
        assert!(quota.reroll(true, &effects, &mut actual).unwrap());
        assert!(!quota.reroll(true, &effects, &mut actual).unwrap());
        found_failed_gate = true;
        break;
    }
    assert!(found_failed_gate);
    for _ in 0..100 {
        assert_eq!(sample_ordinary(&[u128::MAX - 5, 5], &[false, true], &mut rng), Ok(1));
    }
    assert!(sample_ordinary(&[1, 1], &[false, false], &mut rng).is_err());
}
