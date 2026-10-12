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
        if band == 9 {
            assert_eq!(high, maximum_in_size_band(model, band).unwrap());
            let natural = 1.0
                / ((u64::from(high.length_mm - low.length_mm) + 1)
                    * (high.weight_g - lightest_at_max_length.weight_g + 1))
                    as f64;
            let endpoint = 1.0 / f64::from(game_rules::trophy::PERFECT_ROLL_ONE_IN);
            let size_chance = endpoint + (1.0 - endpoint) * natural;
            assert!((row["naturalPerfectSizeChance"].as_f64().unwrap() - natural).abs() < 1e-15);
            assert!((row["perfectSizeChance"].as_f64().unwrap() - size_chance).abs() < 1e-15);
            let biome = world["biomes"]
                .as_array()
                .unwrap()
                .iter()
                .find(|biome| biome["biomeId"] == fish["biomeId"])
                .unwrap();
            let pool: u64 = catalog["species"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|candidate| candidate["biomeId"] == fish["biomeId"])
                .map(|candidate| n(candidate, "encounterWeight"))
                .sum();
            let rank = fish["ranks"]
                .as_array()
                .unwrap()
                .iter()
                .find(|rank| rank["rarity"] == "UUR")
                .unwrap();
            let category = n(biome, "fishWeight") as f64
                / (n(biome, "fishWeight") + n(biome, "junkWeight") + n(biome, "treasureWeight"))
                    as f64;
            let p = n(rank, "encounterWeight") as f64 / pool as f64 * category * size_chance;
            let q = n(&world["rods"][0], "power") as f64 / 200.0;
            let per_cast = (1.0 + q) * p - q * p * p;
            assert!(
                (row["perfectCastChance"].as_f64().unwrap() - per_cast).abs() < per_cast * 1e-12
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
