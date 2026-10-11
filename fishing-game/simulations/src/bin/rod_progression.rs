//! Offline progression audit. Never connects to or changes the player database.
use game_rules::{
    measurements::{SpeciesMeasurements, classify_rarity, generate_in_size_band},
    progression::xp_to_next,
    rods::{fishing_xp, luck_weights},
};
use rand::{Rng, SeedableRng, rngs::StdRng};
use serde_json::{Value, json};
use std::{
    fs,
    path::{Path, PathBuf},
};

fn n(v: &Value, key: &str) -> u64 {
    v[key].as_u64().unwrap()
}
fn rows(v: &Value, key: &str) -> Vec<Value> {
    v[key].as_array().unwrap().clone()
}
#[derive(Clone, Copy, Debug, PartialEq)]
struct Stats {
    power: u64,
    luck: u64,
    xp: u64,
}
impl Stats {
    fn at_least(self, old: Self) -> bool {
        self.power >= old.power && self.luck >= old.luck && self.xp >= old.xp
    }
}
struct Fish {
    model: SpeciesMeasurements,
    discovery: u64,
    ranks: Vec<Value>,
}
struct Pool {
    entries: Vec<(usize, u8)>,
    cumulative: Vec<u64>,
    total: u64,
}
struct Content {
    world: Value,
    catalog: Value,
    crafting: Value,
    trader: Value,
    rods: Vec<Value>,
    qualities: Vec<Value>,
    baits: Vec<Value>,
    biomes: Vec<Value>,
    fish: Vec<Fish>,
    pools: Vec<Vec<Vec<Pool>>>,
    thresholds: Vec<u64>,
}
impl Content {
    fn load() -> Self {
        let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../content");
        Self::load_from(&root)
    }
    fn load_from(root: &Path) -> Self {
        let read = |name: &str| {
            serde_json::from_str::<Value>(&fs::read_to_string(root.join(name)).unwrap()).unwrap()
        };
        let world = read("world.json");
        let catalog = read("species.json");
        let crafting = read("crafting.json");
        let trader = read("trader.json");
        let rods = rows(&world, "rods");
        let qualities = rows(&crafting, "qualities");
        let baits = rows(&crafting, "baits");
        let biomes = rows(&world, "biomes");
        let species = rows(&catalog, "species");
        let fish = species
            .iter()
            .map(|f| {
                let length = n(f, "typicalLengthMm") as u32;
                let weight = n(f, "typicalWeightG");
                Fish {
                    model: SpeciesMeasurements {
                        typical_length_mm: length,
                        min_length_mm: length * 55 / 100,
                        max_length_mm: length * 19 / 10,
                        typical_weight_g: weight,
                        min_weight_g: (weight / 8).max(1),
                        max_weight_g: weight * 8,
                    },
                    discovery: n(f, "discoveryXp"),
                    ranks: rows(f, "ranks"),
                }
            })
            .collect();
        let pools = biomes
            .iter()
            .map(|biome| {
                rods.iter()
                    .map(|rod| {
                        qualities
                            .iter()
                            .map(|quality| {
                                let mut entries = Vec::new();
                                let mut tickets = Vec::new();
                                for (i, f) in species
                                    .iter()
                                    .enumerate()
                                    .filter(|(_, f)| f["biomeId"] == biome["biomeId"])
                                {
                                    for rank in f["ranks"].as_array().unwrap() {
                                        let ordinal = game_rules::RARITY_TIERS
                                            .iter()
                                            .position(|r| *r == rank["rarity"].as_str().unwrap())
                                            .unwrap()
                                            as u8;
                                        entries.push((i, ordinal));
                                        tickets.push((
                                            n(rank, "encounterWeight"),
                                            if f["key"] == "nidalees-lost-sock" {
                                                9
                                            } else {
                                                ordinal
                                            },
                                        ));
                                    }
                                }
                                assert_eq!(tickets.iter().map(|t| t.0).sum::<u64>(), 1_000_000);
                                let weights = luck_weights(
                                    &tickets,
                                    (n(rod, "luckBp") + n(quality, "luckBp")) as u32,
                                )
                                .unwrap();
                                let mut total = 0;
                                let cumulative = weights
                                    .into_iter()
                                    .map(|w| {
                                        total += w;
                                        total
                                    })
                                    .collect();
                                Pool {
                                    entries,
                                    cumulative,
                                    total,
                                }
                            })
                            .collect()
                    })
                    .collect()
            })
            .collect();
        let mut thresholds = vec![0; 121];
        for level in 2..=120 {
            thresholds[level] = thresholds[level - 1] + xp_to_next((level - 1) as u32);
        }
        Self {
            world,
            catalog,
            crafting,
            trader,
            rods,
            qualities,
            baits,
            biomes,
            fish,
            pools,
            thresholds,
        }
    }
    fn stats(&self, rod: usize, q: usize) -> Stats {
        Stats {
            power: n(&self.rods[rod], "power") + n(&self.qualities[q], "powerBonus"),
            luck: n(&self.rods[rod], "luckBp") + n(&self.qualities[q], "luckBp"),
            xp: n(&self.rods[rod], "xpBonusBp") + n(&self.qualities[q], "xpBonusBp"),
        }
    }
    fn recipe(&self, rod: usize, quality: usize) -> &Value {
        self.crafting["rodRecipes"]
            .as_array()
            .and_then(|rows| {
                rows.iter().find(|r| {
                    n(r, "rodId") == (rod + 1) as u64 && n(r, "qualityLevel") == quality as u64
                })
            })
            .unwrap_or(&self.qualities[quality])
    }
    fn price(&self, kind: &str, target: usize) -> u64 {
        let row = self.trader["listings"]
            .as_array()
            .unwrap()
            .iter()
            .find(|r| r["kind"] == kind && n(r, "targetId") == (target + 1) as u64)
            .unwrap();
        n(row, "priceCoins")
    }
}
#[derive(Clone)]
struct Player {
    xp: u64,
    coins: u64,
    tin: u64,
    scrap: u64,
    casts: u64,
    rod: usize,
    quality: usize,
    biome: usize,
    bait_uses: [u64; 9],
    seen: Vec<bool>,
}
impl Player {
    fn new(c: &Content) -> Self {
        Self {
            xp: 0,
            coins: 0,
            tin: 0,
            scrap: 0,
            casts: 0,
            rod: 0,
            quality: 0,
            biome: 0,
            bait_uses: [0; 9],
            seen: vec![false; c.fish.len()],
        }
    }
    fn upgrade(&mut self, c: &Content, cap: usize) {
        while self.quality < cap {
            let q = c.recipe(self.rod, self.quality + 1);
            let tin = n(q, "tinCost");
            let scrap = n(q, "scrapCost");
            if self.tin < tin || self.scrap < scrap {
                break;
            }
            self.tin -= tin;
            self.scrap -= scrap;
            self.quality += 1;
        }
    }
    fn daily(&mut self, casts_per_day: u64) {
        if casts_per_day > 0 && self.casts.is_multiple_of(casts_per_day) {
            let claims = self.casts / casts_per_day;
            self.coins += game_rules::daily::award(claims, None, claims as i64)
                .unwrap()
                .unwrap()
                .1;
        }
    }
    fn bait(&mut self, c: &Content, policy: Policy, cap: usize) -> usize {
        let id = match policy {
            Policy::Natural => 0,
            Policy::Pink => 8,
            Policy::Materials if self.quality < cap => {
                let q = c.recipe(self.rod, self.quality + 1);
                if n(q, "tinCost").saturating_sub(self.tin)
                    >= n(q, "scrapCost").saturating_sub(self.scrap)
                {
                    1
                } else {
                    2
                }
            }
            Policy::Materials => 0,
        };
        if id == 0 {
            return 0;
        }
        if self.bait_uses[id] == 0 {
            let b = &c.baits[id - 1];
            let price = n(b, "priceCoins");
            if self.coins < price {
                return 0;
            }
            self.coins -= price;
            self.bait_uses[id] += n(b, "usesPerPurchase");
        }
        self.bait_uses[id] -= 1;
        id
    }
    fn cast(&mut self, c: &Content, bait: usize, rng: &mut StdRng) {
        let stats = c.stats(self.rod, self.quality);
        let biome = &c.biomes[self.biome];
        let pool = &c.pools[self.biome][self.rod][self.quality];
        let pulls = 1 + u64::from(
            rng.gen_range(0..10000u32)
                < game_rules::rods::bonus_pull_bp(stats.power as u32).unwrap(),
        );
        let mut xp = 0;
        for _ in 0..pulls {
            let roll = rng.gen_range(
                0..n(biome, "fishWeight") + n(biome, "junkWeight") + n(biome, "treasureWeight"),
            );
            if roll < n(biome, "fishWeight") {
                let ticket = rng.gen_range(0..pool.total);
                let (i, band) = pool.entries[pool.cumulative.partition_point(|end| *end <= ticket)];
                let fish = &c.fish[i];
                let measured = generate_in_size_band(fish.model, band, rng).unwrap();
                let ordinal = classify_rarity(fish.model, measured).unwrap();
                let rank = fish
                    .ranks
                    .iter()
                    .find(|r| r["rarity"] == game_rules::RARITY_TIERS[ordinal as usize])
                    .unwrap();
                xp += n(rank, "baseXp")
                    * (10000 + [0, 0, 0, 1000, 2000, 2500][measured.size_grade as usize])
                    / 10000;
                if !self.seen[i] {
                    self.seen[i] = true;
                    xp += fish.discovery;
                }
                let ratio = (u64::from(measured.length_mm) * 1_000_000
                    / u64::from(fish.model.typical_length_mm))
                .clamp(600_000, 2_000_000);
                self.coins += n(rank, "baseValue") * ratio / 1_000_000;
            } else if roll < n(biome, "fishWeight") + n(biome, "junkWeight") {
                self.tin += 1;
                xp += 2;
            } else {
                xp += 8;
                self.coins += rng.gen_range(25..=60);
                self.scrap += rng.gen_range(1..=3);
            }
        }
        let bonus = stats.xp
            + if bait == 0 {
                0
            } else {
                n(&c.baits[bait - 1], "xpBonusBp")
            };
        self.xp += fishing_xp(xp, bonus as u32).unwrap();
        if bait == 1 {
            self.tin += 1;
        } else if bait == 2 {
            self.scrap += 1;
        }
        self.casts += 1;
    }
}
#[derive(Clone, Copy)]
enum Policy {
    Natural,
    Materials,
    Pink,
}
#[derive(Default)]
struct Stage {
    gate_q: Vec<usize>,
    purchase_q: Vec<usize>,
    immediate_q: Vec<usize>,
    gate_casts: Vec<u64>,
    gate_stage_casts: Vec<u64>,
    coin_delay: Vec<u64>,
    recovery: Vec<u64>,
    recovery_using_old: Vec<u64>,
    common_dominated: u64,
    instant_regression: u64,
    gate_expected_rod: u64,
    purchase_stage_casts: Vec<u64>,
    purchase_casts: Vec<u64>,
    purchase_levels: Vec<u64>,
    prismatic_ready_at_purchase: u64,
    sampled_casts: u64,
    earned_coins: u64,
    earned_tin: u64,
    earned_scrap: u64,
}
fn recovery(
    c: &Content,
    mut p: Player,
    old: Stats,
    seed: u64,
    casts_per_day: u64,
    farm_with: Option<(usize, usize)>,
) -> u64 {
    let mut rng = StdRng::seed_from_u64(seed);
    let start = p.casts;
    let cap = (p.quality..=6)
        .find(|q| c.stats(p.rod, *q).at_least(old))
        .unwrap();
    loop {
        p.upgrade(c, cap);
        if c.stats(p.rod, p.quality).at_least(old) {
            return p.casts - start;
        }
        if p.casts > start {
            p.daily(casts_per_day);
        }
        let bait = p.bait(c, Policy::Materials, cap);
        let new_gear = (p.rod, p.quality);
        if let Some((rod, quality)) = farm_with {
            p.rod = rod;
            p.quality = quality;
        }
        p.cast(c, bait, &mut rng);
        (p.rod, p.quality) = new_gear;
        assert!(p.casts - start < 20000, "Recovery did not converge");
    }
}
fn summary(values: &[u64]) -> Value {
    let mut v = values.to_vec();
    v.sort_unstable();
    if v.is_empty() {
        return Value::Null;
    }
    let at = |q: f64| v[((v.len() as f64 * q).ceil() as usize).saturating_sub(1)];
    json!({"mean":values.iter().sum::<u64>() as f64/values.len() as f64,"p10":at(0.1),"median":at(0.5),"p90":at(0.9),"p95":at(0.95),"min":v[0],"max":v[v.len()-1]})
}
// Fishing-day equivalents assume the stated cast budget on days played.
// Missed days add elapsed calendar time; neither XP nor materials decay.
fn fishing_days(values: &[u64], casts_per_day: u64) -> Value {
    if casts_per_day == 0 {
        return Value::Null;
    }
    let mut stats = summary(values);
    if let Some(fields) = stats.as_object_mut() {
        for value in fields.values_mut() {
            *value = json!(value.as_f64().unwrap() / casts_per_day as f64);
        }
    }
    stats
}
fn histogram(values: &[usize]) -> Vec<usize> {
    (0..=6)
        .map(|q| values.iter().filter(|v| **v == q).count())
        .collect()
}
// Alternative pacing target: hold each rod at Mythic, bank its Prismatic
// recipe, and only then buy the next family. This measures the material chase
// rather than assuming players always replace rods at the level gate.
fn simulate_material_choice(c: &Content, policy: Policy, trials: u64, casts_per_day: u64) -> Value {
    let mut mythic_levels: Vec<Vec<u64>> = vec![vec![]; 6];
    let mut ready_levels: Vec<Vec<u64>> = vec![vec![]; 6];
    let mut ready_casts: Vec<Vec<u64>> = vec![vec![]; 6];
    let mut gate_ready = [0u64; 6];
    for trial in 0..trials {
        let mut rng = StdRng::seed_from_u64(10112026 + trial);
        let mut p = Player::new(c);
        let mut stage_start = 0;
        let mut reached_mythic = false;
        let mut reached_ready = false;
        let mut reached_gate = false;
        while p.rod < 6 {
            p.daily(casts_per_day);
            p.upgrade(c, 5);
            let recipe = c.recipe(p.rod, 6);
            let ready = p.quality == 5
                && p.tin >= n(recipe, "tinCost")
                && p.scrap >= n(recipe, "scrapCost");
            let level = u64::from(game_rules::progression::level_for_xp(p.xp, 120));
            if p.quality == 5 && !reached_mythic {
                mythic_levels[p.rod].push(level);
                reached_mythic = true;
            }
            if ready && !reached_ready {
                ready_levels[p.rod].push(level);
                ready_casts[p.rod].push(p.casts - stage_start);
                reached_ready = true;
            }
            let target = p.rod + 1;
            let at_gate = p.xp >= c.thresholds[n(&c.rods[target], "minimumLevel") as usize];
            if at_gate && !reached_gate {
                gate_ready[p.rod] += u64::from(ready);
                reached_gate = true;
            }
            while p.biome < 6
                && p.xp >= c.thresholds[n(&c.biomes[p.biome + 1], "minimumLevel") as usize]
                && p.coins >= c.price("licence", p.biome + 1)
            {
                p.coins -= c.price("licence", p.biome + 1);
                p.biome += 1;
            }
            if (ready || p.rod == 0)
                && at_gate
                && p.biome >= target - 1
                && p.coins >= c.price("rod", target)
            {
                p.coins -= c.price("rod", target);
                p.rod = target;
                p.quality = 0;
                stage_start = p.casts;
                reached_mythic = false;
                reached_ready = false;
                reached_gate = false;
                if p.rod == 6 {
                    break;
                }
            }
            // Continue material bait through the final recipe, but bank it.
            let banked_recipe = p.quality == 5
                && p.tin >= n(c.recipe(p.rod, 6), "tinCost")
                && p.scrap >= n(c.recipe(p.rod, 6), "scrapCost");
            let bait = p.bait(c, policy, if banked_recipe { 5 } else { 6 });
            p.cast(c, bait, &mut rng);
            assert!(p.casts < 50000, "Material-choice career did not converge");
        }
    }
    let transitions: Vec<_> = (1..6).map(|i| json!({
        "from":c.rods[i]["name"],"to":c.rods[i+1]["name"],
        "next_rod_unlock_level":c.rods[i+1]["minimumLevel"],
        "mythic_level":summary(&mythic_levels[i]),
        "prismatic_materials_ready_level":summary(&ready_levels[i]),
        "stage_casts_to_prismatic_materials":summary(&ready_casts[i]),
        "prismatic_materials_ready_at_first_gate_check_pct":100.0*gate_ready[i] as f64/trials as f64
    })).collect();
    json!({"trials":trials,"transitions":transitions})
}
fn simulate(
    c: &Content,
    policy: Policy,
    cap: usize,
    trials: u64,
    seed: u64,
    casts_per_day: u64,
) -> Value {
    let mut stages: Vec<Stage> = (0..6).map(|_| Stage::default()).collect();
    for trial in 0..trials {
        let mut rng = StdRng::seed_from_u64(seed + trial);
        let mut p = Player::new(c);
        let mut gates = [None; 6];
        let mut previous_purchase_cast = 0;
        while p.rod < 6 {
            p.daily(casts_per_day);
            p.upgrade(c, cap);
            for target in 1..=6 {
                if gates[target - 1].is_none()
                    && p.xp >= c.thresholds[n(&c.rods[target], "minimumLevel") as usize]
                {
                    gates[target - 1] = Some(p.casts);
                    let s = &mut stages[target - 1];
                    s.gate_q.push(p.quality);
                    s.gate_casts.push(p.casts);
                    s.gate_stage_casts.push(p.casts - previous_purchase_cast);
                    s.gate_expected_rod += u64::from(p.rod == target - 1);
                }
            }
            // Buy sequential licences first, then each rod, as soon as eligible and affordable.
            while p.biome < 6
                && p.xp >= c.thresholds[n(&c.biomes[p.biome + 1], "minimumLevel") as usize]
                && p.coins >= c.price("licence", p.biome + 1)
            {
                p.coins -= c.price("licence", p.biome + 1);
                p.biome += 1;
            }
            let target = p.rod + 1;
            if p.xp >= c.thresholds[n(&c.rods[target], "minimumLevel") as usize]
                && p.biome >= target - 1
                && p.coins >= c.price("rod", target)
            {
                let old = c.stats(p.rod, p.quality);
                let old_gear = (p.rod, p.quality);
                let s = &mut stages[target - 1];
                s.purchase_q.push(p.quality);
                s.purchase_casts.push(p.casts);
                s.purchase_stage_casts
                    .push(p.casts - previous_purchase_cast);
                s.purchase_levels
                    .push(u64::from(game_rules::progression::level_for_xp(p.xp, 120)));
                s.prismatic_ready_at_purchase += u64::from(
                    p.quality == 6
                        || (p.quality == 5
                            && p.tin >= n(c.recipe(p.rod, 6), "tinCost")
                            && p.scrap >= n(c.recipe(p.rod, 6), "scrapCost")),
                );
                s.coin_delay.push(p.casts - gates[target - 1].unwrap());
                let common = c.stats(target, 0);
                s.common_dominated += u64::from(old.at_least(common) && old != common);
                p.coins -= c.price("rod", target);
                p.rod = target;
                p.quality = 0;
                p.upgrade(c, cap);
                s.immediate_q.push(p.quality);
                let regresses = !c.stats(target, p.quality).at_least(old);
                s.instant_regression += u64::from(regresses);
                if regresses {
                    s.recovery.push(recovery(
                        c,
                        p.clone(),
                        old,
                        seed + trial + 1_000_000 * (target as u64),
                        casts_per_day,
                        None,
                    ));
                    s.recovery_using_old.push(recovery(
                        c,
                        p.clone(),
                        old,
                        seed + trial + 1_000_000 * (target as u64),
                        casts_per_day,
                        Some(old_gear),
                    ));
                }
                previous_purchase_cast = p.casts;
                if p.rod == 6 {
                    break;
                }
            }
            let farm_cap = if cap == 5
                && !(p.quality == 5
                    && p.tin >= n(c.recipe(p.rod, 6), "tinCost")
                    && p.scrap >= n(c.recipe(p.rod, 6), "scrapCost"))
            {
                6
            } else {
                cap
            };
            let bait = p.bait(c, policy, farm_cap);
            let before_rewards = (p.coins, p.tin, p.scrap);
            p.cast(c, bait, &mut rng);
            let stage = &mut stages[p.rod];
            stage.sampled_casts += 1;
            stage.earned_coins += p.coins - before_rewards.0;
            stage.earned_tin += p.tin - before_rewards.1;
            stage.earned_scrap += p.scrap - before_rewards.2;
            assert!(p.casts < 50000, "Career did not converge");
        }
    }
    let transitions: Vec<_> = stages.iter().enumerate().map(|(i,s)|json!({
        "from":c.rods[i]["name"],"to":c.rods[i+1]["name"],
        "sampled_casts":s.sampled_casts,
        "mean_cast_rewards":{"gross_coins":s.earned_coins as f64/s.sampled_casts.max(1) as f64,"tin":s.earned_tin as f64/s.sampled_casts.max(1) as f64,"scrap":s.earned_scrap as f64/s.sampled_casts.max(1) as f64},
        "level":c.rods[i+1]["minimumLevel"],
        "gate_quality_counts":histogram(&s.gate_q),"purchase_quality_counts":histogram(&s.purchase_q),
        "immediate_new_quality_counts":histogram(&s.immediate_q),
        "mythic_or_better_at_purchase_pct":100.0*s.purchase_q.iter().filter(|q|**q>=5).count() as f64/trials as f64,
        "prismatic_ready_at_purchase_pct":100.0*s.prismatic_ready_at_purchase as f64/trials as f64,
        "casts_since_previous_purchase":summary(&s.purchase_stage_casts),
        "cumulative_purchase_casts":summary(&s.purchase_casts),
        "fishing_days_since_previous_purchase":fishing_days(&s.purchase_stage_casts,casts_per_day),
        "cumulative_purchase_fishing_days":fishing_days(&s.purchase_casts,casts_per_day),"purchase_level":summary(&s.purchase_levels),
        "epic_or_better_at_level_gate_pct":100.0*s.gate_q.iter().filter(|q|**q>=3).count() as f64/trials as f64,
        "old_dominates_new_common_at_purchase_pct":100.0*s.common_dominated as f64/trials as f64,
        "any_stat_regresses_after_immediate_upgrades_pct":100.0*s.instant_regression as f64/trials as f64,
        "expected_previous_rod_at_gate_pct":100.0*s.gate_expected_rod as f64/trials as f64,
        "casts_to_level_gate":summary(&s.gate_casts),"casts_since_previous_purchase_to_gate":summary(&s.gate_stage_casts),
        "purchase_delay_casts":summary(&s.coin_delay),"material_bait_recovery_casts_conditional_on_regression":summary(&s.recovery),
        "material_bait_recovery_keeping_old_equipped_conditional_on_regression":summary(&s.recovery_using_old)
    })).collect();
    json!({"trials":trials,"seed":seed,"quality_cap":cap,"transitions":transitions})
}
fn calibrate_gold_prices(
    c: &mut Content,
    policy: Policy,
    trials: u64,
    casts_per_day: u64,
) -> Value {
    let mut players: Vec<_> = (0..trials)
        .map(|_| {
            let mut p = Player::new(c);
            p.daily(casts_per_day);
            p
        })
        .collect();
    let mut rngs: Vec<_> = (0..trials)
        .map(|i| StdRng::seed_from_u64(20112026 + i))
        .collect();
    let mut milestones = vec![];
    for target in 1..=6 {
        let mut wallets = vec![];
        for (p, rng) in players.iter_mut().zip(rngs.iter_mut()) {
            loop {
                p.upgrade(c, 5);
                while p.biome < 6
                    && p.xp >= c.thresholds[n(&c.biomes[p.biome + 1], "minimumLevel") as usize]
                    && p.coins >= c.price("licence", p.biome + 1)
                {
                    p.coins -= c.price("licence", p.biome + 1);
                    p.biome += 1;
                }
                let ready = p.quality == 5
                    && p.tin >= n(c.recipe(p.rod, 6), "tinCost")
                    && p.scrap >= n(c.recipe(p.rod, 6), "scrapCost");
                if p.biome >= target - 1
                    && ((target < 2 && p.coins >= c.price("rod", target)) || (target >= 2 && ready))
                {
                    wallets.push(p.coins);
                    break;
                }
                let bait = p.bait(c, policy, if ready { 5 } else { 6 });
                p.cast(c, bait, rng);
                p.daily(casts_per_day);
                assert!(p.casts < 100000, "Calibration did not converge");
            }
        }
        if target >= 2 {
            let price = (summary(&wallets)["median"].as_u64().unwrap() / 250).max(1) * 250;
            let listing = c.trader["listings"]
                .as_array_mut()
                .unwrap()
                .iter_mut()
                .find(|row| row["kind"] == "rod" && n(row, "targetId") == (target + 1) as u64)
                .unwrap();
            listing["priceCoins"] = json!(price);
            milestones.push(json!({"rod":c.rods[target]["name"],"price":price,"wallet_at_previous_prismatic_readiness":summary(&wallets)}));
        }
        for (p, rng) in players.iter_mut().zip(rngs.iter_mut()) {
            while p.coins < c.price("rod", target) {
                let bait = p.bait(c, policy, 5);
                p.cast(c, bait, rng);
                p.daily(casts_per_day);
            }
            p.coins -= c.price("rod", target);
            p.rod = target;
            p.quality = 0;
        }
    }
    json!({"trials":trials,"calibration":"Median wallet when the previous rod is Mythic with the Prismatic recipe banked; River remains a short starter purchase; Marsh and later prices follow the previous family. Validation uses separate career seeds and buys immediately when eligible/affordable.","milestones":milestones,"trader":c.trader})
}
fn main() {
    let args: Vec<String> = std::env::args().collect();
    let trials = args.get(1).map(|v| v.parse().unwrap()).unwrap_or(5000u64);
    assert!(trials > 0);
    let output = args
        .get(2)
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("output/balance/rod-progression-2026-10-11.json"));
    let casts_per_day = args.get(3).map(|v| v.parse().unwrap()).unwrap_or(50u64);
    // Optional fourth positional argument reads a hypothetical content snapshot.
    let mut c = args
        .get(4)
        .map(|root| Content::load_from(Path::new(root)))
        .unwrap_or_else(Content::load);
    if args.get(5).is_some_and(|v| v.starts_with("calibrate-")) {
        let policy = if args[5] == "calibrate-natural" {
            Policy::Natural
        } else {
            Policy::Materials
        };
        let report = calibrate_gold_prices(&mut c, policy, trials, casts_per_day);
        fs::create_dir_all(output.parent().unwrap()).unwrap();
        fs::write(&output, serde_json::to_string_pretty(&report).unwrap()).unwrap();
        eprintln!("Saved calibration {}", output.display());
        return;
    }
    let gold_only = args.get(5).is_some_and(|value| value == "gold");
    let scenarios = if gold_only {
        vec![
            ("natural_bank_at_mythic", Policy::Natural, 5),
            ("natural_spend_prismatic", Policy::Natural, 6),
            ("resource_bait_bank_at_mythic", Policy::Materials, 5),
            ("pink_bank_at_mythic", Policy::Pink, 5),
            ("resource_bait_spend_prismatic", Policy::Materials, 6),
        ]
    } else {
        vec![
            ("natural_materials", Policy::Natural, 6),
            ("resource_bait_to_epic", Policy::Materials, 3),
            ("resource_bait_unlimited_upgrades", Policy::Materials, 6),
            ("pink_xp_slime_natural_materials", Policy::Pink, 6),
        ]
    };
    let mut results = serde_json::Map::new();
    for (name, policy, cap) in scenarios {
        eprintln!("Simulating {name}: {trials} careers");
        results.insert(
            name.into(),
            simulate(&c, policy, cap, trials, 10112026, casts_per_day),
        );
    }
    let mut material_choice = serde_json::Map::new();
    for (name, policy) in [
        ("natural_materials", Policy::Natural),
        ("resource_bait", Policy::Materials),
        ("pink_xp_slime", Policy::Pink),
    ] {
        if gold_only {
            break;
        }
        eprintln!("Simulating Mythic/Prismatic material choice {name}: {trials} careers");
        material_choice.insert(
            name.into(),
            simulate_material_choice(&c, policy, trials, casts_per_day),
        );
    }
    let report = json!({"date":"2026-10-11","world_version":c.world["version"],"catalog_version":c.catalog["version"],
        "crafting_version":c.crafting["version"],"trader_version":c.trader["version"],"casts_per_day":casts_per_day,
        "rod_stats":c.rods,"qualities":c.qualities,"rod_recipes":c.crafting["rodRecipes"],"shop_listings":c.trader["listings"],
        "quality_labels":c.qualities.iter().map(|q|q["name"].clone()).collect::<Vec<_>>(),
        "assumptions":["Fresh level-1 Common Twig, zero coins and materials; free starter rod/licence.",
            "Sell every fish immediately at its authoritative saved value; keep all tin/scrap for rods.",
            "Claim daily before the first cast and every casts_per_day casts; 0 disables daily.",
            "Buy sequential licences first and each next rod as soon as its gate, previous licence and coins allow; equip immediately.",
            "Upgrade equipped rod to the scenario quality cap whenever materials allow, including immediately before shop purchases.",
            "Material bait switches between deficits for the next recipe; actual ten-use purchases, charges carried per bait.",
            "Epic-cap policy banks after Epic; Mythic-cap policy banks the Prismatic recipe and continues resource bait until it is funded; unlimited policies spend on Prismatic.",
            "Recovery is a separate branch: keep the new rod equipped and farm material bait until all three stats match/exceed the old rod; do not purchase further rods.",
            "Alternative recovery branch keeps the old rod equipped while spending gathered materials on the new owned rod; paired recovery seed, same starting state.",
            "Catch odds, physical measurements, size XP, first discoveries, integer cast XP, mixed pulls, treasure coins and scrap follow current source.",
            "Fishing-day equivalents assume the specified cast budget every day played; missed days extend calendar time without losing XP, quality or materials.",
            "No other income, gifts, material spending, favorites, achievements payouts or skipped rods; simulated strategies are not observed player frequencies."],
        "material_choice_assumptions":["Twig is replaced as soon as eligible and affordable; the material-choice target starts with River.","Upgrade to Mythic, then bank that family's actual Prismatic recipe instead of spending it.","From River onward buy the next rod only when its gate, price and Prismatic material readiness all permit; carry saved materials into the new rod.","Resource bait continues through the Prismatic recipe; licences and waters advance as soon as affordable."],
        "material_choice":material_choice,"scenarios":results});
    if let Some(parent) = output.parent() {
        fs::create_dir_all(parent).unwrap();
    }
    fs::write(&output, serde_json::to_string_pretty(&report).unwrap()).unwrap();
    eprintln!("Saved {}", output.display());
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn new_common_beats_previous_prismatic_and_recipes_spend_individual_steps() {
        let c = Content::load();
        for i in 0..6 {
            assert!(c.stats(i + 1, 0).at_least(c.stats(i, 6)));
            assert!(!c.stats(i, 6).at_least(c.stats(i + 1, 0)));
        }
        let mut p = Player::new(&c);
        p.tin = 95;
        p.scrap = 60;
        p.upgrade(&c, 3);
        assert_eq!((p.quality, p.tin, p.scrap), (3, 0, 0));
        p.rod = 1;
        p.quality = 0;
        p.upgrade(&c, 3);
        assert_eq!(p.quality, 0);
    }
    #[test]
    fn threshold_crossings_and_material_bait_are_consistent() {
        let c = Content::load();
        assert_eq!(c.thresholds[5], 720);
        let mut p = Player::new(&c);
        p.coins = 20;
        assert_eq!(p.bait(&c, Policy::Materials, 3), 1);
        assert_eq!((p.coins, p.bait_uses[1]), (0, 9));
        p.tin = 10;
        assert_eq!(p.bait(&c, Policy::Materials, 3), 0);
        p.coins = 50;
        assert_eq!(p.bait(&c, Policy::Materials, 3), 2);
        let mut rng = StdRng::seed_from_u64(7);
        let before = p.scrap;
        p.cast(&c, 2, &mut rng);
        assert!(p.scrap >= before + 1);
    }
}
