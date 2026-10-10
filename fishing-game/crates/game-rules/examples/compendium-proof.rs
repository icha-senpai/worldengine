//! Cross-check frontend display ranges against the actual authoritative sampler.
use game_rules::measurements::{SpeciesMeasurements, classify_rarity, generate_in_size_band};
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
