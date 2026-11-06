use game_rules::{luck, sampling};
use rand::{SeedableRng, rngs::StdRng};
use serde_json::Value;

fn main() {
    let path = std::env::args().nth(1).expect("fixture path");
    let cases: Vec<Value> = serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap();
    for case in &cases {
        let tickets: Vec<_> = case["tickets"].as_array().unwrap().iter().map(|row| {
            (row[0].as_str().unwrap().parse::<u64>().unwrap(), row[1].as_u64().unwrap() as u8)
        }).collect();
        let luck_bp = u32::try_from(case["luckBp"].as_u64().unwrap()).unwrap();
        let expected: Vec<_> = case["weights"].as_array().unwrap().iter()
            .map(|value| value.as_str().unwrap().parse::<u128>().unwrap()).collect();
        let actual = luck::weights(&tickets, luck_bp).unwrap();
        assert_eq!(actual, expected, "{}", case["name"]);
        let total = sampling::total_weight_wide(&actual).unwrap();
        assert_eq!(sampling::select_from_roll_wide(&actual, total), Err("ROLL_OUT_OF_RANGE"));
        let mut start = 0;
        for (index, weight) in actual.iter().enumerate() {
            if *weight > 0 {
                assert_eq!(sampling::select_from_roll_wide(&actual, start), Ok(index));
                assert_eq!(sampling::select_from_roll_wide(&actual, start + weight - 1), Ok(index));
            }
            start += weight;
        }
        assert_eq!(start, total);
        let mut rng = StdRng::seed_from_u64(58);
        for _ in 0..32 {
            let selected = sampling::sample_wide(&actual, &mut rng).unwrap();
            assert!(actual[selected] > 0);
        }
    }
    println!("PASS {} exact Rust/TypeScript Luck vectors and all sampler interval endpoints.", cases.len());
}
