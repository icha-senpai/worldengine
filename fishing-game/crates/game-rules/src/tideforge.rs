//! Versioned, optional gear effects. No persistence or production randomness.

use serde::{Deserialize, Serialize};
use rand::Rng;
use std::collections::{BTreeMap, BTreeSet};
use std::sync::OnceLock;

pub const EFFECTS_VERSION: u32 = 1;
pub const MASTERY_CURVE_VERSION: u32 = 1;
pub const BASE_ACTIVITY_XP: u64 = 100;
pub const NORMAL_FILLETS_SLOT: u32 = 800;
pub const NORMAL_SCALES_SLOT: u32 = 801;
pub const CATALOG_JSON: &str = include_str!("../../../content/tideforge-effects.json");

#[derive(Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Catalog {
    version: u32,
    status: String,
    innate_primary_luck_bp: u32,
    mastery: Mastery,
    affixes: Vec<Affix>,
    signatures: Vec<Signature>,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Mastery {
    curve_version: u32,
    maximum_level: u8,
    base_activity_xp: u64,
    first_level_cost: u64,
    level_cost_step: u64,
    slot_levels: [u8; 4],
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Affix {
    id: String,
    name: String,
    kinds: Vec<String>,
    condition: Option<String>,
    stats: BTreeMap<String, Vec<u32>>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Signature {
    id: String,
    name: String,
    kind: String,
    #[serde(default)]
    stats: BTreeMap<String, u32>,
    #[serde(default)]
    parameters: BTreeMap<String, u32>,
}

fn catalog() -> &'static Catalog {
    static VALUE: OnceLock<Catalog> = OnceLock::new();
    VALUE.get_or_init(|| serde_json::from_str(CATALOG_JSON).expect("authored Tideforge catalog"))
}

fn valid_kind(kind: &str) -> bool {
    matches!(kind, "rod" | "boat" | "net")
}

pub fn valid_goal(goal: &str) -> bool {
    matches!(goal, "ranks" | "maxima" | "perfects")
}

const STATS: [&str; 22] = [
    "luckBp", "playerXpBp", "masteryXpBp", "saleBp", "salvageBp",
    "treasureBp", "filletBp", "scaleBp", "powerCenti", "baitAttemptBp",
    "weightFinishBp", "lengthFinishBp", "endpointPpm", "rankFocusBp",
    "baitPreserveBp", "extraPullBp", "fullAttemptCenti", "maximaFocusBp",
    "perfectFocusBp", "durationBp", "duplicateRerollBp", "haulAttemptCenti",
];

pub fn validate_catalog() -> Result<(), &'static str> {
    let c = catalog();
    if c.version != EFFECTS_VERSION || c.status != "candidate-local-only"
        || c.innate_primary_luck_bp != 100
        || c.mastery.curve_version != MASTERY_CURVE_VERSION
        || c.mastery.maximum_level != 100 || c.mastery.base_activity_xp != BASE_ACTIVITY_XP
        || c.mastery.first_level_cost != 100 || c.mastery.level_cost_step != 20
        || c.mastery.slot_levels != [1, 10, 25, 50]
        || c.affixes.len() != 39 || c.signatures.len() != 24
    {
        return Err("TIDEFORGE_CATALOG_INVALID");
    }
    let mut ids = BTreeSet::new();
    for row in &c.affixes {
        if row.id.is_empty() || row.name.is_empty() || !ids.insert(&row.id)
            || row.kinds.is_empty() || row.kinds.iter().any(|k| !valid_kind(k))
            || row.kinds.iter().collect::<BTreeSet<_>>().len() != row.kinds.len()
            || row.condition.as_deref().is_some_and(|v| !matches!(v, "primary" | "secondary"))
            || row.stats.is_empty()
        {
            return Err("TIDEFORGE_CATALOG_INVALID");
        }
        for (name, grid) in &row.stats {
            if !STATS.contains(&name.as_str()) || grid.len() != 10
                || grid.iter().any(|v| *v == 0) || grid.windows(2).any(|v| v[0] > v[1])
                || name == "extraPullBp" && grid.iter().any(|v| *v > 10_000)
            {
                return Err("TIDEFORGE_CATALOG_INVALID");
            }
        }
    }
    ids.clear();
    for row in &c.signatures {
        if row.id.is_empty() || row.name.is_empty() || !ids.insert(&row.id)
            || !(valid_kind(&row.kind) || row.kind == "waters")
            || row.stats.keys().any(|k| !STATS.contains(&k.as_str()))
            || row.stats.get("extraPullBp").is_some_and(|v| *v > 10_000)
        {
            return Err("TIDEFORGE_CATALOG_INVALID");
        }
        for (name, value) in &row.parameters {
            let valid = match name.as_str() {
                "pairedFinishBp" | "dailyBaseMasteryBp" | "firstTreasureDoubleBp"
                | "dailyNewRankPlayerXpBp" => *value <= 10_000,
                "firstJunkScrap" | "firstJunkTin" | "duplicateRerolls" | "dailyOrdinaryFish" => *value == 1,
                "firstJunkFillets" | "firstFishFillets" => *value == 6,
                "firstJunkScales" | "firstFishScales" => *value == 4,
                "firstFishAttemptCenti" => *value == 100,
                "outsidePrimaryLuckBp" => *value == 1000,
                _ => false,
            };
            if !valid {
                return Err("TIDEFORGE_CATALOG_INVALID");
            }
        }
    }
    // A conservative sum includes mutually exclusive affixes, so no legal
    // four-slot composition can exceed these probability/representation checks.
    for kind in ["rod", "boat", "net"] {
        let mut totals = BTreeMap::<&str, u64>::new();
        for row in c.affixes.iter().filter(|a| a.kinds.iter().any(|k| k == kind)) {
            for (name, grid) in &row.stats {
                *totals.entry(name).or_default() += u64::from(grid[9]);
            }
        }
        for row in c.signatures.iter().filter(|s| s.kind == kind || s.kind == "waters") {
            for (name, value) in &row.stats {
                *totals.entry(name).or_default() += u64::from(*value);
            }
        }
        let total = |key| totals.get(key).copied().unwrap_or(0);
        if totals.values().any(|v| *v > u64::from(u32::MAX))
            || total("rankFocusBp") + 5_000 > 10_000
            || ["weightFinishBp", "lengthFinishBp", "baitPreserveBp", "maximaFocusBp",
                "perfectFocusBp", "duplicateRerollBp"].iter().any(|k| total(k) > 10_000)
            || total("endpointPpm") + 200 > 1_000_000
            || total("durationBp") >= 10_000
            || kind == "rod" && 16 * 10_000 + 16 * total("baitAttemptBp")
                + total("fullAttemptCenti") * 100 > 50 * 10_000
        {
            return Err("TIDEFORGE_COMPOSITION_INVALID");
        }
    }
    Ok(())
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct AffixRoll {
    pub slot: u8,
    pub id: String,
    pub tier: u8,
    pub finish_bp: u16,
    pub secondary_biome_id: Option<u32>,
}

pub struct ComposeInput<'a> {
    pub kind: &'a str,
    pub biome_id: u32,
    pub primary_biome_id: u32,
    pub mastery_xp: u64,
    pub affixes: &'a [AffixRoll],
    pub native_signature: Option<&'a str>,
    pub kind_signature: Option<&'a str>,
    pub waters_signature: Option<&'a str>,
    pub goal: &'a str,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct Effects {
    pub version: u32,
    pub kind: String,
    pub goal: String,
    pub stats: BTreeMap<String, u32>,
    pub extra_pull_bp: Vec<u32>,
    pub signatures: Vec<String>,
    pub parameters: BTreeMap<String, BTreeMap<String, u32>>,
    pub unlocked_slots: u8,
    pub active_affixes: Vec<String>,
    pub dormant_affixes: Vec<String>,
}

impl Effects {
    pub fn stat(&self, key: &str) -> u32 {
        self.stats.get(key).copied().unwrap_or(0)
    }

    pub fn has(&self, signature: &str) -> bool {
        self.signatures.iter().any(|id| id == signature)
    }

    pub fn parameter(&self, signature: &str, key: &str) -> u32 {
        self.parameters.get(signature).and_then(|p| p.get(key)).copied().unwrap_or(0)
    }

    fn add(&mut self, key: &str, value: u32) -> Result<(), &'static str> {
        if key == "extraPullBp" {
            self.extra_pull_bp.push(value);
        } else {
            let combined = self.stat(key).checked_add(value).ok_or("TIDEFORGE_EFFECT_OVERFLOW")?;
            self.stats.insert(key.into(), combined);
        }
        Ok(())
    }
}

pub fn signature_kind(id: &str) -> Result<&'static str, &'static str> {
    catalog().signatures.iter().find(|s| s.id == id)
        .map(|s| s.kind.as_str()).ok_or("TIDEFORGE_SIGNATURE_INVALID")
}

pub fn rolled_value(maximum: u32, finish_bp: u16) -> Result<u32, &'static str> {
    if finish_bp > 10_000 {
        return Err("TIDEFORGE_FINISH_INVALID");
    }
    let minimum = (u64::from(maximum) * 4).div_ceil(5);
    Ok((minimum + (u64::from(maximum) - minimum) * u64::from(finish_bp) / 10_000) as u32)
}

pub fn mastery_threshold(level: u8) -> Result<u64, &'static str> {
    if !(1..=100).contains(&level) {
        return Err("TIDEFORGE_MASTERY_LEVEL_INVALID");
    }
    let n = u64::from(level - 1);
    Ok(100 * n + 10 * n * n.saturating_sub(1))
}

pub fn mastery_level(xp: u64) -> u8 {
    let mut level = 1;
    while level < 100 && xp >= mastery_threshold(level + 1).expect("bounded mastery level") {
        level += 1;
    }
    level
}

pub fn unlocked_slots(xp: u64) -> u8 {
    let level = mastery_level(xp);
    [1, 10, 25, 50].iter().filter(|&&threshold| level >= threshold).count() as u8
}

/// `rate_bp` is the entire multiplier, not an implicit bonus over 100%.
pub fn scale_credit(amount: u64, rate_bp: u32, remainder_bp: u16) -> Result<(u64, u16), &'static str> {
    if remainder_bp >= 10_000 {
        return Err("TIDEFORGE_REMAINDER_INVALID");
    }
    let scaled = u128::from(amount) * u128::from(rate_bp) + u128::from(remainder_bp);
    let whole = (scaled / 10_000).try_into().map_err(|_| "TIDEFORGE_CREDIT_OVERFLOW")?;
    Ok((whole, (scaled % 10_000) as u16))
}

pub fn compose(input: ComposeInput<'_>) -> Result<Effects, &'static str> {
    if !valid_kind(input.kind) || !valid_goal(input.goal)
        || input.biome_id == 0 || input.primary_biome_id == 0 || input.affixes.len() > 4
    {
        return Err("TIDEFORGE_LOADOUT_INVALID");
    }
    let mut effects = Effects {
        version: EFFECTS_VERSION, kind: input.kind.into(), goal: input.goal.into(),
        stats: BTreeMap::new(), extra_pull_bp: Vec::new(), signatures: Vec::new(),
        parameters: BTreeMap::new(), unlocked_slots: unlocked_slots(input.mastery_xp),
        active_affixes: Vec::new(), dormant_affixes: Vec::new(),
    };
    let mut slots = BTreeSet::new();
    let mut ids = BTreeSet::new();
    let mut biome_affixes = 0;
    let mut ordered: Vec<_> = input.affixes.iter().collect();
    ordered.sort_by_key(|a| a.slot);
    for rolled in ordered {
        let row = catalog().affixes.iter().find(|a| a.id == rolled.id)
            .ok_or("TIDEFORGE_AFFIX_INVALID")?;
        if rolled.slot >= 4 || !slots.insert(rolled.slot) || !ids.insert(rolled.id.as_str())
            || !row.kinds.iter().any(|k| k == input.kind) || !(1..=10).contains(&rolled.tier)
            || rolled.finish_bp > 10_000
            || (row.condition.as_deref() == Some("secondary")
                && rolled.secondary_biome_id.is_none_or(|b| b == 0))
            || (row.condition.as_deref() != Some("secondary")
                && rolled.secondary_biome_id.is_some())
        {
            return Err("TIDEFORGE_AFFIX_INVALID");
        }
        if row.condition.is_some() {
            biome_affixes += 1;
        }
        let matching = match row.condition.as_deref() {
            Some("primary") => input.biome_id == input.primary_biome_id,
            Some("secondary") => rolled.secondary_biome_id != Some(input.primary_biome_id)
                && rolled.secondary_biome_id == Some(input.biome_id),
            _ => true,
        };
        if rolled.slot >= effects.unlocked_slots || !matching {
            effects.dormant_affixes.push(rolled.id.clone());
            continue;
        }
        effects.active_affixes.push(rolled.id.clone());
        for (name, grid) in &row.stats {
            effects.add(name, rolled_value(grid[usize::from(rolled.tier - 1)], rolled.finish_bp)?)?;
        }
    }
    if biome_affixes > 2 {
        return Err("TIDEFORGE_BIOME_AFFIX_LIMIT");
    }
    for (slot, selected) in [
        ("native", input.native_signature),
        ("kind", input.kind_signature),
        ("waters", input.waters_signature),
    ] {
        let Some(id) = selected else { continue };
        let row = catalog().signatures.iter().find(|s| s.id == id)
            .ok_or("TIDEFORGE_SIGNATURE_INVALID")?;
        let compatible = match slot {
            "native" => row.kind == input.kind || row.kind == "waters",
            "kind" => row.kind == input.kind,
            _ => row.kind == "waters",
        };
        if !compatible {
            return Err("TIDEFORGE_SIGNATURE_INCOMPATIBLE");
        }
        if effects.has(id) {
            continue;
        }
        effects.signatures.push(id.into());
        effects.parameters.insert(id.into(), row.parameters.clone());
        for (name, value) in &row.stats {
            effects.add(name, *value)?;
        }
        if id == "wayfarers_compass" && input.biome_id != input.primary_biome_id {
            effects.add("luckBp", effects.parameter(id, "outsidePrimaryLuckBp"))?;
        }
    }
    if input.biome_id == input.primary_biome_id {
        effects.add("luckBp", catalog().innate_primary_luck_bp)?;
    }
    Ok(effects)
}

pub fn chance(numerator: u32, denominator: u32, rng: &mut impl Rng) -> Result<bool, &'static str> {
    if denominator == 0 || numerator > denominator {
        return Err("TIDEFORGE_PROBABILITY_INVALID");
    }
    Ok(numerator == denominator || numerator != 0 && rng.gen_range(0..denominator) < numerator)
}

/// The no-extra-Power branch deliberately retains the old 10,000-sided roll.
pub fn rod_pulls(power: u32, effects: &Effects, rng: &mut impl Rng) -> Result<usize, &'static str> {
    let extra = effects.stat("powerCenti");
    let mut count = if extra == 0 && power <= 200 {
        1 + usize::from(rng.gen_range(0..10_000u32)
            < crate::rods::bonus_pull_bp(power).map_err(|_| "INVALID_ROD_POWER")?)
    } else {
        let centi = u64::from(power).checked_mul(100).and_then(|v| v.checked_add(u64::from(extra)))
            .ok_or("TIDEFORGE_POWER_OVERFLOW")?;
        1 + (centi / 20_000) as usize
            + usize::from(chance((centi % 20_000) as u32, 20_000, rng)?)
    };
    for &probability in &effects.extra_pull_bp {
        count += usize::from(chance(probability, 10_000, rng)?);
    }
    Ok(count)
}

pub fn haul_extra_pulls(effects: &Effects, rng: &mut impl Rng) -> Result<usize, &'static str> {
    let mut extra = 0;
    for &probability in &effects.extra_pull_bp {
        extra += usize::from(chance(probability, 10_000, rng)?);
    }
    Ok(extra)
}

#[derive(Default)]
pub struct DuplicateQuota {
    affix_attempted: bool,
    signature_used: bool,
}

impl DuplicateQuota {
    /// Eligibility refers to the original candidate, before any replacement.
    pub fn reroll(
        &mut self,
        known_ordinary: bool,
        effects: &Effects,
        rng: &mut impl Rng,
    ) -> Result<bool, &'static str> {
        if !known_ordinary || !matches!(effects.kind.as_str(), "boat" | "net") {
            return Ok(false);
        }
        let gate = effects.stat("duplicateRerollBp");
        if !self.affix_attempted && gate > 0 {
            self.affix_attempted = true;
            if chance(gate, 10_000, rng)? {
                return Ok(true);
            }
        }
        let signature = if effects.kind == "boat" { "cartographers_wake" } else { "whispering_mesh" };
        if !self.signature_used && effects.has(signature) {
            self.signature_used = true;
            return Ok(true);
        }
        Ok(false)
    }
}

/// Keep the frozen Luck weighting while excluding both exceptional species.
pub fn sample_ordinary(
    weights: &[u128],
    ordinary: &[bool],
    rng: &mut impl Rng,
) -> Result<usize, &'static str> {
    if weights.len() != ordinary.len() {
        return Err("TIDEFORGE_POOL_INVALID");
    }
    let indices: Vec<_> = ordinary.iter().enumerate()
        .filter_map(|(i, allowed)| allowed.then_some(i)).collect();
    let filtered: Vec<_> = indices.iter().map(|i| weights[*i]).collect();
    Ok(indices[crate::sampling::sample_wide(&filtered, rng)?])
}

pub fn normal_haul_seconds(base: u32, reduction_bp: u32) -> Result<u32, &'static str> {
    if base == 0 || reduction_bp >= 10_000 {
        return Err("TIDEFORGE_DURATION_INVALID");
    }
    Ok((u64::from(base) * u64::from(10_000 - reduction_bp)).div_ceil(10_000) as u32)
}

pub fn specimen_attempts(
    base_rolls: u8,
    bait_rolls: u8,
    ordinary: bool,
    first_ordinary: bool,
    effects: &Effects,
    rng: &mut impl Rng,
) -> Result<u32, &'static str> {
    if !(1..=16).contains(&base_rolls) || bait_rolls > 16 {
        return Err("INVALID_SIZE_ROLLS");
    }
    let mut credit = u64::from(base_rolls) * 10_000;
    if ordinary && effects.kind == "rod" {
        credit += u64::from(bait_rolls) * u64::from(effects.stat("baitAttemptBp"))
            + u64::from(effects.stat("fullAttemptCenti")) * 100;
    } else if ordinary && first_ordinary {
        credit += u64::from(effects.stat("haulAttemptCenti")) * 100;
        if effects.kind == "net" {
            credit += u64::from(effects.parameter("moonthread", "firstFishAttemptCenti")) * 100;
        }
    }
    if credit > 50 * 10_000 {
        return Err("TIDEFORGE_ATTEMPTS_INVALID");
    }
    Ok((credit / 10_000) as u32 + u32::from(chance((credit % 10_000) as u32, 10_000, rng)?))
}

/// Targets are evaluated against the original candidate, never a replacement.
pub fn steer_rank<R: Rng>(
    initial: usize,
    weights: &[u128; 10],
    known_mask: u16,
    maxima_mask: u16,
    perfect_known: bool,
    eligible: bool,
    base_focus_bp: u32,
    effects: &Effects,
    rng: &mut R,
) -> Result<usize, &'static str> {
    if initial >= 10 || weights[initial] == 0 || known_mask & !1023 != 0
        || maxima_mask & !known_mask != 0
    {
        return Err("TIDEFORGE_RANK_STATE_INVALID");
    }
    if !eligible || effects.kind != "rod" {
        return Ok(initial);
    }
    let missing: Vec<_> = (0..10).filter(|r| weights[*r] > 0 && known_mask & (1 << r) == 0).collect();
    let unfinished: Vec<_> = (0..10).filter(|r| weights[*r] > 0 && maxima_mask & (1 << r) == 0).collect();
    let focus_bp = base_focus_bp.checked_add(effects.stat("rankFocusBp"))
        .filter(|v| *v <= 10_000).ok_or("TIDEFORGE_FOCUS_INVALID")?;
    let missing_check = |rng: &mut R| -> Result<Option<usize>, &'static str> {
        if known_mask & (1 << initial) == 0 || missing.is_empty()
            || !chance(focus_bp, 10_000, rng)?
        {
            return Ok(None);
        }
        let choices: Vec<_> = missing.iter().map(|r| weights[*r]).collect();
        Ok(Some(missing[crate::sampling::sample_wide(&choices, rng)?]))
    };
    let maxima_check = |rng: &mut R| -> Result<Option<usize>, &'static str> {
        if maxima_mask & (1 << initial) == 0 || unfinished.is_empty()
            || effects.stat("maximaFocusBp") == 0 && effects.stat("perfectFocusBp") == 0
        {
            return Ok(None);
        }
        let target = unfinished[rng.gen_range(0..unfinished.len())];
        let gate = if target == 9 { effects.stat("perfectFocusBp") } else { effects.stat("maximaFocusBp") };
        Ok(chance(gate, 10_000, rng)?.then_some(target))
    };
    let selected = match effects.goal.as_str() {
        "ranks" => match missing_check(rng)? {
            Some(rank) => Some(rank),
            None => maxima_check(rng)?,
        },
        "maxima" => match maxima_check(rng)? {
            Some(rank) => Some(rank),
            None => missing_check(rng)?,
        },
        "perfects" => {
            if initial == 9 {
                return Ok(initial);
            }
            if !perfect_known && weights[9] > 0
                && chance(effects.stat("perfectFocusBp"), 10_000, rng)?
            {
                Some(9)
            } else {
                missing_check(rng)?
            }
        }
        _ => return Err("TIDEFORGE_GOAL_INVALID"),
    };
    Ok(selected.unwrap_or(initial))
}

pub struct MeasurementPlan {
    pub species: crate::measurements::SpeciesMeasurements,
    pub band: u8,
    pub base_rolls: u8,
    pub bait_rolls: u8,
    pub ordinary: bool,
    pub first_ordinary: bool,
    pub focus_enabled: bool,
    pub all_ranks_known: bool,
    pub reinforcement_level: u8,
    pub rules_version: u32,
}

pub fn generate_measurements(
    plan: MeasurementPlan,
    effects: &Effects,
    rng: &mut impl Rng,
) -> Result<crate::measurements::Measurements, &'static str> {
    use crate::measurements::{
        Measurements, classify_size, maximum_in_size_band, size_band_bounds, weight_at_length,
    };
    let rod = plan.ordinary && effects.kind == "rod";
    let reinforced = rod && plan.focus_enabled && plan.all_ranks_known && plan.band == 9
        && plan.rules_version >= crate::raids::catalog().rules_version
        && plan.reinforcement_level > 0;
    let oath = rod && plan.focus_enabled && plan.all_ranks_known && plan.band == 9
        && effects.has("trophys_oath");
    let precision = rod && ["baitAttemptBp", "fullAttemptCenti", "lengthFinishBp",
        "weightFinishBp", "endpointPpm"].iter().any(|key| effects.stat(key) != 0)
        || plan.ordinary && plan.first_ordinary && effects.kind != "rod"
            && (effects.stat("haulAttemptCenti") != 0
                || effects.parameter("moonthread", "firstFishAttemptCenti") != 0)
        || oath;
    if !precision {
        return if reinforced {
            crate::raids::reinforced_measurements(
                plan.species, plan.band, plan.base_rolls, plan.rules_version,
                crate::raids::perfect_bonus_rolls(plan.reinforcement_level)?, rng,
            )
        } else {
            crate::trophy::generate(plan.species, plan.band, plan.base_rolls, plan.rules_version, rng)
        };
    }
    let count = specimen_attempts(
        plan.base_rolls, plan.bait_rolls, plan.ordinary, plan.first_ordinary, effects, rng,
    )?;
    let (min_length, max_length, rank_weight_floor) = size_band_bounds(plan.species, plan.band)?;
    let baseline_endpoint = if crate::trophy::endpoint_roll_enabled(plan.band, count as u8, plan.rules_version) {
        200u32
    } else {
        0
    };
    let endpoint = baseline_endpoint.checked_add(if rod { effects.stat("endpointPpm") } else { 0 })
        .filter(|v| *v <= 1_000_000).ok_or("TIDEFORGE_ENDPOINT_INVALID")?;
    let length_bp = if rod { effects.stat("lengthFinishBp") } else { 0 };
    let weight_bp = if rod { effects.stat("weightFinishBp") } else { 0 };
    let mut best = None::<Measurements>;
    for _ in 0..count {
        let candidate = if chance(endpoint, 1_000_000, rng)? {
            maximum_in_size_band(plan.species, plan.band)?
        } else {
            let length_mm = if chance(length_bp, 10_000, rng)? {
                max_length as u32
            } else {
                rng.gen_range(min_length..=max_length) as u32
            };
            let min_weight = weight_at_length(plan.species, length_mm, 880_000)?
                .max(rank_weight_floor).max(plan.species.min_weight_g);
            let max_weight = weight_at_length(plan.species, length_mm, 1_120_000)?
                .max(min_weight).min(plan.species.max_weight_g);
            if min_weight > max_weight {
                return Err("SIZE_BAND_UNAVAILABLE");
            }
            let weight_g = if chance(weight_bp, 10_000, rng)? {
                max_weight
            } else {
                rng.gen_range(min_weight..=max_weight)
            };
            Measurements {
                length_mm, weight_g,
                size_grade: classify_size(
                    u64::from(length_mm) * 1_000_000 / u64::from(plan.species.typical_length_mm),
                ),
            }
        };
        if best.is_none_or(|old| (candidate.weight_g, candidate.length_mm) > (old.weight_g, old.length_mm)) {
            best = Some(candidate);
        }
    }
    let mut best = best.ok_or("TIDEFORGE_ATTEMPTS_INVALID")?;
    if reinforced {
        for _ in 0..crate::raids::perfect_bonus_rolls(plan.reinforcement_level)? {
            if chance(1, crate::trophy::PERFECT_ROLL_ONE_IN, rng)? {
                best = maximum_in_size_band(plan.species, plan.band)?;
                break;
            }
        }
    }
    if oath && chance(effects.parameter("trophys_oath", "pairedFinishBp"), 10_000, rng)? {
        best = maximum_in_size_band(plan.species, plan.band)?;
    }
    Ok(best)
}

#[cfg(test)]
#[path = "tideforge_tests.rs"]
mod tests;
