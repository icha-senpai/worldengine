use game_rules::tideforge::{self, AffixRoll, ComposeInput};
use serde::Deserialize;
use serde_json::{Value, json};
use std::io::{self, Read};

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "camelCase", rename_all_fields = "camelCase")]
enum Case {
    Compose {
        kind: String,
        biome_id: u32,
        primary_biome_id: u32,
        mastery_xp: String,
        affixes: Vec<AffixRoll>,
        native_signature: Option<String>,
        kind_signature: Option<String>,
        waters_signature: Option<String>,
        goal: String,
    },
    Mastery { xp: String },
    Credit { amount: String, rate_bp: u32, remainder_bp: u16 },
    Roll { maximum: u32, finish_bp: u16 },
}

fn run(case: Case) -> Result<Value, &'static str> {
    match case {
        Case::Compose {
            kind, biome_id, primary_biome_id, mastery_xp, affixes,
            native_signature, kind_signature, waters_signature, goal,
        } => {
            let xp = mastery_xp.parse().map_err(|_| "TIDEFORGE_MASTERY_XP_INVALID")?;
            let effects = tideforge::compose(ComposeInput {
                kind: &kind, biome_id, primary_biome_id, mastery_xp: xp, affixes: &affixes,
                native_signature: native_signature.as_deref(),
                kind_signature: kind_signature.as_deref(),
                waters_signature: waters_signature.as_deref(), goal: &goal,
            })?;
            Ok(serde_json::to_value(effects).expect("integer effect snapshot"))
        }
        Case::Mastery { xp } => {
            let xp = xp.parse().map_err(|_| "TIDEFORGE_MASTERY_XP_INVALID")?;
            Ok(json!({ "level": tideforge::mastery_level(xp), "slots": tideforge::unlocked_slots(xp) }))
        }
        Case::Credit { amount, rate_bp, remainder_bp } => {
            let amount = amount.parse().map_err(|_| "TIDEFORGE_AMOUNT_INVALID")?;
            let (whole, remainder) = tideforge::scale_credit(amount, rate_bp, remainder_bp)?;
            Ok(json!({ "whole": whole.to_string(), "remainder": remainder }))
        }
        Case::Roll { maximum, finish_bp } => Ok(json!(tideforge::rolled_value(maximum, finish_bp)?)),
    }
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    tideforge::validate_catalog()?;
    let mut input = String::new();
    io::stdin().read_to_string(&mut input)?;
    let cases: Vec<Value> = serde_json::from_str(&input)?;
    let results: Vec<Value> = cases.into_iter().map(|value| {
        match serde_json::from_value::<Case>(value) {
            Ok(case) => match run(case) {
                Ok(result) => json!({ "ok": result }),
                Err(error) => json!({ "error": error }),
            },
            Err(_) => json!({ "error": "TIDEFORGE_PARITY_INPUT_INVALID" }),
        }
    }).collect();
    println!("{}", serde_json::to_string(&results)?);
    Ok(())
}
