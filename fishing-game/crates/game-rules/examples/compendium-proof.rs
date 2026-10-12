//! Cross-check frontend display ranges against the actual authoritative sampler.
use game_rules::measurements::{
    SpeciesMeasurements, classify_rarity, generate_in_size_band, maximum_in_size_band,
};
use rand::{RngCore, SeedableRng, rngs::StdRng};
use serde_json::Value;

// Choose the first accepted random value for the last slot in an inclusive
// integer range. This exercises the real sampler at the displayed endpoints.
struct EndpointRng(Vec<u64>, usize);
impl RngCore for EndpointRng {
    fn next_u64(&mut self) -> u64 {
        let v = self.0[self.1];
        self.1 += 1;
        v
    }
    fn next_u32(&mut self) -> u32 {
        self.next_u64() as u32
    }
    fn fill_bytes(&mut self, _: &mut [u8]) {
        panic!("not used");
    }
    fn try_fill_bytes(&mut self, _: &mut [u8]) -> Result<(), rand::Error> {
        panic!("not used");
    }
}
fn last_roll(range: u64) -> u64 {
    ((u128::from(range - 1) << 64).div_ceil(u128::from(range))) as u64
}
fn n(row: &Value, key: &str) -> u64 {
    row[key].as_u64().unwrap()
}

fn main() {
    let catalog: Value =
        serde_json::from_str(include_str!("../../../content/species.json")).unwrap();
    let world: Value = serde_json::from_str(include_str!("../../../content/world.json")).unwrap();
    let rules: Value =
        serde_json::from_str(include_str!("../../../content/game-rules.json")).unwrap();
    assert_eq!(
        n(&rules, "perfectRollOneIn"),
        u64::from(game_rules::trophy::PERFECT_ROLL_ONE_IN)
    );
    let ranges: Vec<Value> =
        serde_json::from_str(&std::fs::read_to_string(std::env::args().nth(1).unwrap()).unwrap())
            .unwrap();
    let mut rng = StdRng::seed_from_u64(24);
    for row in &ranges {
        let fish = catalog["species"]
            .as_array()
            .unwrap()
            .iter()
            .find(|fish| fish["speciesId"] == row["speciesId"])
            .unwrap();
        let length = n(fish, "typicalLengthMm") as u32;
        let weight = n(fish, "typicalWeightG");
        let model = SpeciesMeasurements {
            typical_length_mm: length,
            min_length_mm: length * 55 / 100,
            max_length_mm: length * 19 / 10,
            typical_weight_g: weight,
            min_weight_g: (weight / 8).max(1),
            max_weight_g: weight * 8,
        };
        let band = game_rules::RARITY_TIERS
            .iter()
            .position(|&r| r == row["rarity"].as_str().unwrap())
            .unwrap() as u8;
        let loadout = row.get("loadout");
        let luck = loadout.map_or(0, |v| n(v, "luckBp") as u32);
        let power = loadout.map_or(n(&world["rods"][0], "power"), |v| n(v, "power"));
        let rolls = loadout.map_or(1, |v| n(v, "sizeRolls")) as i32;
        let version = loadout.map_or(n(&rules, "version"), |v| n(v, "rulesVersion"));
        let biome = world["biomes"]
            .as_array()
            .unwrap()
            .iter()
            .find(|biome| biome["biomeId"] == fish["biomeId"])
            .unwrap();
        let candidates: Vec<_> = catalog["species"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|candidate| candidate["biomeId"] == fish["biomeId"])
            .flat_map(|candidate| {
                candidate["ranks"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(move |rank| (candidate, rank))
            })
            .collect();
        let tickets: Vec<_> = candidates
            .iter()
            .map(|(candidate, rank)| {
                let score = if candidate["key"] == "nidalees-lost-sock" {
                    9
                } else {
                    game_rules::RARITY_TIERS
                        .iter()
                        .position(|&r| r == rank["rarity"].as_str().unwrap())
                        .unwrap() as u8
                };
                (n(rank, "encounterWeight"), score)
            })
            .collect();
        let weights = game_rules::rods::luck_weights(&tickets, luck).unwrap();
        let pool: u128 = weights.iter().map(|&weight| u128::from(weight)).sum();
        let species_tickets: u128 = candidates
            .iter()
            .zip(&weights)
            .filter(|((candidate, _), _)| candidate["speciesId"] == fish["speciesId"])
            .map(|(_, &weight)| u128::from(weight))
            .sum();
        let index = candidates
            .iter()
            .position(|(candidate, rank)| {
                candidate["speciesId"] == fish["speciesId"] && rank["rarity"] == row["rarity"]
            })
            .unwrap();
        let category = n(biome, "fishWeight") as f64
            / (n(biome, "fishWeight") + n(biome, "junkWeight") + n(biome, "treasureWeight")) as f64;
        let rank_pull = weights[index] as f64 / pool as f64 * category;
        let q = f64::from(game_rules::rods::bonus_pull_bp(power as u32).unwrap()) / 10000.0;
        let rank_cast = (1.0 + q) * rank_pull - q * rank_pull * rank_pull;
        assert!((row["castChance"].as_f64().unwrap() - rank_cast).abs() < rank_cast * 1e-12);
        let share = weights[index] as f64 / species_tickets as f64 * 100.0;
        assert!((row["sharePercent"].as_f64().unwrap() - share).abs() < 1e-10);
        let low = generate_in_size_band(model, band, &mut EndpointRng(vec![0, 0], 0)).unwrap();
        assert_eq!(u64::from(low.length_mm), n(row, "minLengthMm"));
        assert_eq!(low.weight_g, n(row, "minWeightG"));
        let high = generate_in_size_band(
            model,
            band,
            &mut EndpointRng(
                vec![
                    last_roll(n(row, "maxLengthMm") - n(row, "minLengthMm") + 1),
                    last_roll(n(row, "maxWeightG") - n(row, "minWeightAtMaxLength") + 1),
                ],
                0,
            ),
        )
        .unwrap();
        assert_eq!(u64::from(high.length_mm), n(row, "maxLengthMm"));
        assert_eq!(high.weight_g, n(row, "maxWeightG"));
        let lightest_at_max_length = generate_in_size_band(
            model,
            band,
            &mut EndpointRng(
                vec![
                    last_roll(n(row, "maxLengthMm") - n(row, "minLengthMm") + 1),
                    0,
                ],
                0,
            ),
        )
        .unwrap();
        assert_eq!(
            lightest_at_max_length.weight_g,
            n(row, "minWeightAtMaxLength")
        );
        if band == 9 || row.get("maximumSizeChance").is_some() {
            assert_eq!(high, maximum_in_size_band(model, band).unwrap());
            let natural = 1.0
                / ((u64::from(high.length_mm - low.length_mm) + 1)
                    * (high.weight_g - lightest_at_max_length.weight_g + 1))
                    as f64;
            let endpoint =
                if game_rules::trophy::endpoint_roll_enabled(band, rolls as u8, version as u32) {
                    1.0 / f64::from(game_rules::trophy::PERFECT_ROLL_ONE_IN)
                } else {
                    0.0
                };
            let attempt_chance = endpoint + (1.0 - endpoint) * natural;
            let size_chance = -(f64::from(rolls) * (-attempt_chance).ln_1p()).exp_m1();
            let natural_expected = row
                .get("naturalMaximumSizeChance")
                .unwrap_or(&row["naturalPerfectSizeChance"]);
            let size_expected = row
                .get("maximumSizeChance")
                .unwrap_or(&row["perfectSizeChance"]);
            assert!((natural_expected.as_f64().unwrap() - natural).abs() < 1e-15);
            assert!((size_expected.as_f64().unwrap() - size_chance).abs() < 1e-15);
            let p = rank_pull * size_chance;
            let per_cast = (1.0 + q) * p - q * p * p;
            assert!(
                (row.get("maximumCastChance")
                    .unwrap_or(&row["perfectCastChance"])
                    .as_f64()
                    .unwrap()
                    - per_cast)
                    .abs()
                    < per_cast * 1e-12
            );
        }
        for _ in 0..200 {
            let draw = generate_in_size_band(model, band, &mut rng).unwrap();
            assert!(
                (n(row, "minLengthMm")..=n(row, "maxLengthMm"))
                    .contains(&u64::from(draw.length_mm))
            );
            assert!((n(row, "minWeightG")..=n(row, "maxWeightG")).contains(&draw.weight_g));
            assert_eq!(classify_rarity(model, draw), Ok(band));
        }
    }
    println!(
        "PASS {} displayed rank ranges match Rust minimum/maximum endpoints and {} generated catches.",
        ranges.len(),
        ranges.len() * 200
    );
}
