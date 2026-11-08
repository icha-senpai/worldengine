//! Candidate-local Tideforge economy rules.
use crate::tideforge::{self, AffixRoll};
use rand::Rng;
use serde::Deserialize;
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

pub const CATALOG_JSON: &str = include_str!("../../../content/tideforge-economy.json");
pub const VERSION: u32 = 1;

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Catalog {
    pub version: u32,
    pub status: String,
    pub required_effects_version: u32,
    pub quote_lifetime_seconds: u64,
    pub quote_cooldown_seconds: u64,
    pub maximum_fish_batch: usize,
    pub maximum_conversion_batches: u64,
    pub receipt_retention_days: u64,
    pub maximum_prune_rows: usize,
    pub families: Vec<Family>,
    pub acquisition: Acquisition,
    pub forge: Forge,
    pub recovery: Recovery,
    pub processing: Processing,
    pub marks: Marks,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Family {
    pub family_id: u32,
    pub coins: u64,
    pub marks: u64,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Acquisition {
    pub affix_selection: String,
    pub maximum_conditional_affixes: usize,
    pub secondary_biome_selection: String,
    pub requires_owned_original: bool,
    pub quality_level: u8,
    pub reinforcement_level: u8,
    pub finish_minimum_bp: u16,
    pub finish_maximum_bp: u16,
    pub camp: AcquisitionOffer,
    pub wandering: AcquisitionOffer,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct AcquisitionOffer {
    pub affix_count: usize,
    pub tiers: Vec<TierWeight>,
    pub signature_chance_bp: u32,
    pub utc_start_hours: Option<Vec<u8>>,
    pub duration_hours: Option<u8>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TierWeight {
    pub tier: u8,
    pub weight: u32,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Forge {
    pub shards_per_addition: u64,
    pub shards_per_change: u64,
    pub new_affix_finish_bp: u16,
    pub refinement_step_bp: u16,
    pub refinement_full_cost_coins: u64,
    pub requires_discovered_type: bool,
    pub requires_destination_grade_clear: bool,
    pub tiers: Vec<ForgeTier>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ForgeTier {
    pub tier: u8,
    pub grade: u8,
    pub fillets: u64,
    pub scales: u64,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Recovery {
    pub shards_by_tier: [u64; 10],
    pub refund_coins: bool,
    pub refund_materials: bool,
    pub reject_known_signature_extraction: bool,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Processing {
    pub fillets_slot: u32,
    pub scales_slot: u32,
    pub fillets_by_rank: [u64; 10],
    pub scales_by_rank: [u64; 10],
    pub normal_to_f_input: u64,
    pub normal_to_f_output: u64,
    pub normal_to_f_coin_cost: u64,
    pub refining_input: u64,
    pub refining_output: u64,
    pub refining_coin_cost: u64,
    pub protect_trophies_by_default: bool,
    pub exclude_bonus_species: bool,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Marks {
    pub ordinary_raid_by_grade: [u64; 10],
    pub community_raid: u64,
    pub special_raid: u64,
    pub new_achievement: u64,
    pub backfill: bool,
    pub require_raid_booking_after_activation: bool,
}

pub fn catalog() -> Result<&'static Catalog, &'static str> {
    static VALUE: OnceLock<Result<Catalog, &'static str>> = OnceLock::new();
    VALUE.get_or_init(|| {
        let data = serde_json::from_str(CATALOG_JSON).map_err(|_| "TIDEFORGE_ECONOMY_CATALOG_INVALID")?;
        validate(&data)?;
        Ok(data)
    }).as_ref().map_err(|e| *e)
}

pub fn validate(c: &Catalog) -> Result<(), &'static str> {
    let invalid = || "TIDEFORGE_ECONOMY_CATALOG_INVALID";
    if c.version != VERSION || c.status != "candidate-local-only"
        || c.required_effects_version != tideforge::EFFECTS_VERSION
        || c.quote_lifetime_seconds != 120 || c.quote_cooldown_seconds != 1
        || c.maximum_fish_batch != 50 || c.maximum_conversion_batches != 1000
        || c.receipt_retention_days != 8 || c.maximum_prune_rows != 1000
        || c.acquisition.affix_selection != "uniform-remaining-legal"
        || c.acquisition.maximum_conditional_affixes != 2
        || c.acquisition.secondary_biome_selection != "player-selected-non-primary-defined-biome"
        || !c.acquisition.requires_owned_original || c.acquisition.quality_level != 0
        || c.acquisition.reinforcement_level != 0
        || c.acquisition.finish_minimum_bp != 0 || c.acquisition.finish_maximum_bp != 10_000
        || c.acquisition.camp.affix_count != 1 || c.acquisition.wandering.affix_count != 4
        || c.acquisition.wandering.utc_start_hours.as_deref() != Some(&[0, 6, 12, 18][..])
        || c.acquisition.wandering.duration_hours != Some(2)
        || c.acquisition.camp.utc_start_hours.is_some()
        || c.acquisition.camp.duration_hours.is_some()
        || !c.forge.requires_discovered_type || !c.forge.requires_destination_grade_clear
        || c.forge.shards_per_addition != 1 || c.forge.shards_per_change != 1
        || c.forge.new_affix_finish_bp != 0 || c.forge.refinement_step_bp == 0
        || 10_000 % c.forge.refinement_step_bp != 0
        || c.recovery.shards_by_tier != [1, 1, 1, 2, 2, 2, 2, 3, 3, 3] || c.recovery.refund_coins
        || c.recovery.refund_materials || !c.recovery.reject_known_signature_extraction
        || c.processing.fillets_slot != tideforge::NORMAL_FILLETS_SLOT
        || c.processing.scales_slot != tideforge::NORMAL_SCALES_SLOT
        || c.processing.normal_to_f_input == 0 || c.processing.normal_to_f_output == 0
        || c.processing.normal_to_f_coin_cost != 0 || c.processing.refining_input == 0
        || c.processing.refining_output == 0 || c.processing.refining_output >= c.processing.refining_input
        || !c.processing.protect_trophies_by_default || !c.processing.exclude_bonus_species
        || c.marks.community_raid != 0 || c.marks.special_raid != 0 || c.marks.new_achievement != 1
        || c.marks.ordinary_raid_by_grade.iter().any(|value| *value == 0)
        || c.marks.backfill || !c.marks.require_raid_booking_after_activation
    {
        return Err(invalid());
    }
    let families: BTreeSet<_> = c.families.iter().map(|row| row.family_id).collect();
    if families != (2..=7).collect() || c.families.len() != families.len()
        || c.families.iter().any(|row| row.coins == 0 || row.marks == 0)
        || c.forge.tiers.len() != 10
        || c.forge.tiers.iter().enumerate().any(|(i, row)| usize::from(row.tier) != i + 1
            || usize::from(row.grade) != i || row.fillets == 0 || row.scales == 0)
        || c.processing.fillets_by_rank.iter().any(|v| *v == 0)
        || c.processing.scales_by_rank.iter().any(|v| *v == 0)
    {
        return Err(invalid());
    }
    for (offer, expected) in [
        (&c.acquisition.camp, BTreeSet::from([1, 2, 3])),
        (&c.acquisition.wandering, BTreeSet::from([8, 9, 10])),
    ] {
        let tiers: BTreeSet<_> = offer.tiers.iter().map(|row| row.tier).collect();
        if offer.signature_chance_bp > 10_000 || offer.tiers.is_empty()
            || tiers.len() != offer.tiers.len() || tiers != expected
            || offer.tiers.iter().any(|row| !(1..=10).contains(&row.tier) || row.weight == 0)
            || offer.tiers.iter().try_fold(0u32, |n, row| n.checked_add(row.weight)) != Some(100)
        {
            return Err(invalid());
        }
    }
    tideforge::validate_catalog()?;
    for kind in ["rod", "boat", "net"] {
        let ids = tideforge::affix_ids(kind);
        let unconditional = ids.iter().filter(|id| tideforge::affix_condition(id) == Ok(None)).count();
        if ids.len() < c.acquisition.wandering.affix_count
            || unconditional < c.acquisition.wandering.affix_count - c.acquisition.maximum_conditional_affixes
            || tideforge::signature_ids(kind).is_empty()
        {
            return Err(invalid());
        }
    }
    Ok(())
}

pub fn dismantle_shards(tier: u8) -> Result<u64, &'static str> {
    if !(1..=10).contains(&tier) {
        return Err("TIDEFORGE_TIER_INVALID");
    }
    Ok(catalog()?.recovery.shards_by_tier[usize::from(tier - 1)])
}

pub fn family_price(family_id: u32) -> Result<&'static Family, &'static str> {
    catalog()?.families.iter().find(|row| row.family_id == family_id)
        .ok_or("TIDEFORGE_ECONOMY_FAMILY_UNAVAILABLE")
}

pub fn validate_secondary(
    affix_id: &str,
    secondary: Option<u32>,
    primary: u32,
    defined_biomes: &[u32],
) -> Result<(), &'static str> {
    match (tideforge::affix_condition(affix_id)?, secondary) {
        (Some("secondary"), Some(biome))
            if biome != primary && defined_biomes.contains(&biome) && biome != 0 => Ok(()),
        (Some("secondary"), _) | (_, Some(_)) => Err("TIDEFORGE_SECONDARY_BIOME_INVALID"),
        _ => Ok(()),
    }
}

pub fn validate_affix_set(kind: &str, affixes: &[AffixRoll]) -> Result<(), &'static str> {
    if affixes.len() > 4 {
        return Err("TIDEFORGE_AFFIX_INVALID");
    }
    let allowed = tideforge::affix_ids(kind);
    let mut slots = BTreeSet::new();
    let mut ids = BTreeSet::new();
    let mut conditional = 0;
    for roll in affixes {
        if roll.slot >= 4 || !(1..=10).contains(&roll.tier) || roll.finish_bp > 10_000
            || !slots.insert(roll.slot) || !ids.insert(roll.id.as_str())
            || !allowed.contains(&roll.id.as_str())
        {
            return Err("TIDEFORGE_AFFIX_INVALID");
        }
        conditional += usize::from(tideforge::affix_condition(&roll.id)?.is_some());
        if tideforge::affix_condition(&roll.id)? == Some("secondary") {
            if roll.secondary_biome_id.is_none_or(|v| v == 0) {
                return Err("TIDEFORGE_SECONDARY_BIOME_INVALID");
            }
        } else if roll.secondary_biome_id.is_some() {
            return Err("TIDEFORGE_SECONDARY_BIOME_INVALID");
        }
    }
    if conditional > catalog()?.acquisition.maximum_conditional_affixes {
        return Err("TIDEFORGE_BIOME_AFFIX_LIMIT");
    }
    Ok(())
}

pub struct NaturalGear {
    pub affixes: Vec<AffixRoll>,
    pub signature: Option<String>,
}

pub fn roll_acquisition(
    kind: &str,
    primary: u32,
    secondary_biome_id: u32,
    defined_biomes: &[u32],
    wandering: bool,
    rng: &mut impl Rng,
) -> Result<NaturalGear, &'static str> {
    let c = catalog()?;
    let offer = if wandering { &c.acquisition.wandering } else { &c.acquisition.camp };
    let mut choices = tideforge::affix_ids(kind);
    let mut waters = defined_biomes.to_vec();
    waters.sort_unstable();
    if primary == 0 || !waters.contains(&primary) || waters.iter().any(|v| *v == 0)
        || waters.windows(2).any(|v| v[0] == v[1])
        || choices.len() < offer.affix_count
    {
        return Err("TIDEFORGE_ECONOMY_CATALOG_INVALID");
    }
    validate_secondary("secondary_affinity", Some(secondary_biome_id), primary, &waters)?;
    let mut affixes = Vec::with_capacity(offer.affix_count);
    let mut conditional = 0;
    let total = offer.tiers.iter().map(|v| v.weight).sum::<u32>();
    for slot in 0..offer.affix_count {
        let legal: Vec<usize> = choices.iter().enumerate()
            .filter_map(|(i, id)| (conditional < c.acquisition.maximum_conditional_affixes
                || tideforge::affix_condition(id) == Ok(None)).then_some(i)).collect();
        if legal.is_empty() {
            return Err("TIDEFORGE_ECONOMY_CATALOG_INVALID");
        }
        let index = legal[rng.gen_range(0..legal.len())];
        let id = choices.remove(index);
        let condition = tideforge::affix_condition(id)?;
        conditional += usize::from(condition.is_some());
        let mut pick = rng.gen_range(0..total);
        let mut tier = None;
        for row in &offer.tiers {
            if pick < row.weight {
                tier = Some(row.tier);
                break;
            }
            pick -= row.weight;
        }
        let finish_bp = rng.gen_range(c.acquisition.finish_minimum_bp..=c.acquisition.finish_maximum_bp);
        let secondary_biome_id = (condition == Some("secondary")).then_some(secondary_biome_id);
        affixes.push(AffixRoll {
            slot: slot as u8, id: id.into(), tier: tier.ok_or("TIDEFORGE_ECONOMY_CATALOG_INVALID")?,
            finish_bp, secondary_biome_id,
        });
    }
    let signatures = tideforge::signature_ids(kind);
    let signature = if tideforge::chance(offer.signature_chance_bp, 10_000, rng)? {
        Some(signatures[rng.gen_range(0..signatures.len())].to_owned())
    } else {
        None
    };
    validate_affix_set(kind, &affixes)?;
    Ok(NaturalGear { affixes, signature })
}

pub fn upgrade_cost(from_tier: u8, target_tier: u8) -> Result<BTreeMap<u8, (u64, u64)>, &'static str> {
    if from_tier > 10 || target_tier == 0 || target_tier > 10 || from_tier >= target_tier {
        return Err("TIDEFORGE_TIER_INVALID");
    }
    let mut costs = BTreeMap::new();
    for row in catalog()?.forge.tiers.iter().filter(|row| row.tier > from_tier && row.tier <= target_tier) {
        costs.insert(row.grade, (row.fillets, row.scales));
    }
    Ok(costs)
}

fn next_visible_finish(
    finish: u16,
    step: u16,
    mut values: impl FnMut(u16) -> Result<BTreeMap<String, u32>, &'static str>,
) -> Result<u16, &'static str> {
    let before = values(finish)?;
    if step == 0 || finish >= 10_000 || before == values(10_000)? {
        return Err("TIDEFORGE_REFINEMENT_UNAVAILABLE");
    }
    let mut next = (u32::from(finish) / u32::from(step) + 1) * u32::from(step);
    while next <= 10_000 && values(next as u16)? == before {
        next += u32::from(step);
    }
    if next > 10_000 {
        return Err("TIDEFORGE_REFINEMENT_UNAVAILABLE");
    }
    Ok(next as u16)
}

pub fn refine_affix(id: &str, tier: u8, finish: u16) -> Result<(u16, u64), &'static str> {
    let forge = &catalog()?.forge;
    let next = next_visible_finish(finish, forge.refinement_step_bp, |value| {
        tideforge::affix_values(id, tier, value)
    })?;
    let cumulative = |value: u16| -> Result<u64, &'static str> {
        let scaled = u128::from(forge.refinement_full_cost_coins) * u128::from(value);
        (scaled.div_ceil(10_000)).try_into().map_err(|_| "TIDEFORGE_COIN_OVERFLOW")
    };
    Ok((next, cumulative(next)? - cumulative(finish)?))
}

#[derive(Debug, PartialEq, Eq)]
pub struct RefinePlan {
    pub input: u64,
    pub coins: u64,
    pub outputs: BTreeMap<u8, u64>,
}

pub fn refine_material(from: u8, to: u8, batches: u64) -> Result<RefinePlan, &'static str> {
    let c = catalog()?;
    if from >= to || to > 9 || batches == 0 || batches > c.maximum_conversion_batches {
        return Err("TIDEFORGE_REFINING_INVALID");
    }
    let p = &c.processing;
    let input = batches.checked_mul(p.refining_input).ok_or("TIDEFORGE_MATERIAL_OVERFLOW")?;
    let mut plan = RefinePlan { input, coins: 0, outputs: BTreeMap::new() };
    let mut current_batches = batches;
    for grade in from + 1..=to {
        plan.coins = plan.coins.checked_add(current_batches.checked_mul(p.refining_coin_cost)
            .ok_or("TIDEFORGE_COIN_OVERFLOW")?).ok_or("TIDEFORGE_COIN_OVERFLOW")?;
        let produced = current_batches.checked_mul(p.refining_output).ok_or("TIDEFORGE_MATERIAL_OVERFLOW")?;
        if grade == to {
            plan.outputs.insert(grade, produced);
        } else {
            let next = produced / p.refining_input;
            if next == 0 {
                return Err("TIDEFORGE_REFINING_TARGET_UNREACHABLE");
            }
            let remainder = produced % p.refining_input;
            if remainder != 0 {
                plan.outputs.insert(grade, remainder);
            }
            current_batches = next;
        }
    }
    Ok(plan)
}

pub fn wandering_close(micros: i64) -> Result<Option<i64>, &'static str> {
    if micros < 0 {
        return Err("TIDEFORGE_TIME_INVALID");
    }
    let c = &catalog()?.acquisition.wandering;
    let starts = c.utc_start_hours.as_deref().ok_or("TIDEFORGE_ECONOMY_CATALOG_INVALID")?;
    let hour_micros = 3_600_000_000i64;
    let duration = i64::from(c.duration_hours.ok_or("TIDEFORGE_ECONOMY_CATALOG_INVALID")?) * hour_micros;
    let day_start = micros - micros % crate::daily::DAY_MICROS;
    for &hour in starts {
        let offset = i64::from(hour) * hour_micros;
        let elapsed = micros - day_start;
        if elapsed >= offset && elapsed < offset + duration {
            let close = day_start.checked_add(offset).and_then(|start| start.checked_add(duration))
                .ok_or("TIDEFORGE_TIME_INVALID")?;
            return Ok(Some(close));
        }
    }
    Ok(None)
}

#[cfg(test)]
mod tests {
    use super::*;
    use rand::{rngs::StdRng, SeedableRng};

    #[test]
    fn dismantle_shards_follow_current_tier() {
        for (tier, expected) in (1..=10).zip([1, 1, 1, 2, 2, 2, 2, 3, 3, 3]) {
            assert_eq!(dismantle_shards(tier), Ok(expected));
        }
        assert_eq!(dismantle_shards(0), Err("TIDEFORGE_TIER_INVALID"));
        assert_eq!(dismantle_shards(11), Err("TIDEFORGE_TIER_INVALID"));
    }

    #[test]
    fn authored_catalog_validates_and_family_prices_are_shared() {
        let c = catalog().unwrap();
        assert_eq!((c.version, c.status.as_str()), (1, "candidate-local-only"));
        assert_eq!((family_price(2).unwrap().coins, family_price(2).unwrap().marks), (500, 1));
        assert_eq!((family_price(7).unwrap().coins, family_price(7).unwrap().marks), (130_000, 130));
        assert!(family_price(1).is_err());
        assert!(serde_json::from_str::<Catalog>(&CATALOG_JSON.replace(
            "\"version\": 1", "\"version\": 1, \"unknown\": 0"
        )).is_err());
        assert_eq!(c.marks.ordinary_raid_by_grade.iter().sum::<u64>() * 2, 130);
        for (section, field, value) in [
            ("acquisition", "affixSelection", serde_json::json!("uniform-unconstrained")),
            ("forge", "shardsPerAddition", serde_json::json!(0)),
            ("recovery", "shardsByTier", serde_json::json!([1, 1, 1, 2, 2, 2, 2, 3, 3, 4])),
            ("processing", "filletsSlot", serde_json::json!(801)),
        ] {
            let mut json: serde_json::Value = serde_json::from_str(CATALOG_JSON).unwrap();
            json[section][field] = value;
            let invalid: Catalog = serde_json::from_value(json).unwrap();
            assert!(validate(&invalid).is_err());
        }
    }

    #[test]
    fn wandering_visits_four_times_every_utc_day_with_exclusive_closing_boundaries() {
        let hour = 3_600_000_000i64;
        for day in [0, 1, 6, 30, 31, 365, 20_764, 20_818] {
            let midnight = day * crate::daily::DAY_MICROS;
            for start_hour in [0, 6, 12, 18] {
                let start = midnight + start_hour * hour;
                let close = start + 2 * hour;
                if start > 0 {
                    assert_eq!(wandering_close(start - 1).unwrap(), None);
                }
                assert_eq!(wandering_close(start).unwrap(), Some(close));
                assert_eq!(wandering_close(start + hour).unwrap(), Some(close));
                assert_eq!(wandering_close(close - 1).unwrap(), Some(close));
                assert_eq!(wandering_close(close).unwrap(), None);
                assert_eq!(wandering_close(close + hour).unwrap(), None);
            }
            assert_eq!(wandering_close(midnight + crate::daily::DAY_MICROS - 1).unwrap(), None);
        }
        assert!(wandering_close(-1).is_err());
    }

    #[test]
    fn wandering_schedule_validation_rejects_other_windows_and_legacy_monthly_fields() {
        for (field, value) in [
            ("utcStartHours", serde_json::json!([0, 6, 12])),
            ("utcStartHours", serde_json::json!([0, 6, 12, 24])),
            ("utcStartHours", serde_json::json!([0, 0, 12, 18])),
            ("durationHours", serde_json::json!(3)),
        ] {
            let mut json: serde_json::Value = serde_json::from_str(CATALOG_JSON).unwrap();
            json["acquisition"]["wandering"][field] = value;
            assert!(validate(&serde_json::from_value(json).unwrap()).is_err());
        }
        let mut json: serde_json::Value = serde_json::from_str(CATALOG_JSON).unwrap();
        json["acquisition"]["wandering"]["utcStartDayOfMonth"] = serde_json::json!(1);
        assert!(serde_json::from_value::<Catalog>(json).is_err());
    }

    #[test]
    fn seeded_wandering_rolls_are_distinct_compatible_and_legal() {
        for kind in ["rod", "boat", "net"] {
            let mut saw_secondary = false;
            for seed in 0..=64 {
                let mut rng = StdRng::seed_from_u64(seed);
                let gear = roll_acquisition(kind, 2, 7, &[1, 2, 3, 4, 5, 6, 7], true, &mut rng).unwrap();
                assert_eq!(gear.affixes.len(), 4);
                validate_affix_set(kind, &gear.affixes).unwrap();
                assert!(gear.affixes.iter().filter(|a| tideforge::affix_condition(&a.id).unwrap().is_some()).count() <= 2);
                for roll in &gear.affixes {
                    assert!((8..=10).contains(&roll.tier));
                    assert!(roll.finish_bp <= 10_000);
                    validate_secondary(&roll.id, roll.secondary_biome_id, 2, &[1, 2, 3, 4, 5, 6, 7]).unwrap();
                    if roll.id == "secondary_affinity" {
                        saw_secondary = true;
                        assert_eq!(roll.secondary_biome_id, Some(7));
                    }
                }
                if let Some(id) = gear.signature {
                    assert!(tideforge::signature_ids(kind).contains(&id.as_str()));
                }
            }
            assert!(saw_secondary, "secondary affinity not exercised for {kind}");
        }
    }

    #[test]
    fn secondary_affinity_requires_a_defined_non_primary_biome() {
        let waters = [1, 2, 3];
        assert!(validate_secondary("secondary_affinity", None, 2, &waters).is_err());
        assert!(validate_secondary("secondary_affinity", Some(2), 2, &waters).is_err());
        assert!(validate_secondary("secondary_affinity", Some(4), 2, &waters).is_err());
        assert!(validate_secondary("resonance", Some(1), 2, &waters).is_err());
        assert_eq!(validate_secondary("secondary_affinity", Some(1), 2, &waters), Ok(()));
    }

    #[test]
    fn refinement_is_visible_and_cumulative_and_upgrade_includes_every_tier() {
        let cost = upgrade_cost(1, 3).unwrap();
        assert_eq!(cost.get(&1), Some(&(27, 18)));
        assert_eq!(cost.get(&2), Some(&(36, 24)));
        assert_eq!(refine_affix("secondary_affinity", 1, 0).unwrap(), (2000, 250));
        assert_eq!(refine_affix("secondary_affinity", 1, 9_999).unwrap(), (10_000, 0));
        assert!(refine_affix("secondary_affinity", 1, 10_000).is_err());
        let fixed = |_: u16| Ok(BTreeMap::from([("value".to_owned(), 1)]));
        assert!(next_visible_finish(4000, 2000, fixed).is_err());
        let delayed = |finish: u16| Ok(BTreeMap::from([("value".to_owned(), if finish >= 6000 { 1 } else { 0 })]));
        assert_eq!(next_visible_finish(0, 2000, delayed), Ok(6000));
    }

    #[test]
    fn multi_hop_refining_returns_only_new_intermediate_remainders() {
        let plan = refine_material(0, 2, 11).unwrap();
        assert_eq!(plan.input, 220);
        assert_eq!(plan.coins, 20);
        assert_eq!(plan.outputs, BTreeMap::from([(1, 18), (2, 162)]));
        assert!(refine_material(0, 2, 1).is_err());
        assert!(refine_material(0, 10, 100).is_err());
    }
}
