//! Isolated loopback load driver: real game transport and artwork, no Discord API.
use discord_bot::{
    MAX_PENDING_GAME_REQUESTS,
    catch_art::{CatchArt, Material},
};
use game_client::{Client, Error, module_bindings::ServiceRole};
use serde_json::json;
use std::{
    sync::Arc,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};
use tokio::{
    sync::{Barrier, Semaphore},
    task::JoinSet,
};

fn emit(value: serde_json::Value) {
    println!("{value}");
}
fn snowflake(sequence: u64) -> u64 {
    let ms = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_millis() as u64;
    ((ms - 1_420_070_400_000) << 22) | sequence
}

#[tokio::main]
async fn main() -> Result<(), Error> {
    let database = std::env::var("TEST_SPACETIMEDB_DATABASE")?;
    let uri = std::env::var("TEST_SPACETIMEDB_URI")?;
    if uri != "http://127.0.0.1:3127" || !database.starts_with("fishbound-proof-load-") {
        return Err("Only a fresh loopback load-proof database is allowed".into());
    }
    let count: usize = std::env::var("LOAD_USERS")?.parse()?;
    let first_user: u64 = std::env::var("LOAD_FIRST_USER")?.parse()?;
    let spacing_ms: u64 = std::env::var("LOAD_SPACING_MS")?.parse()?;
    if ![25, 100, 250].contains(&count) || ![0, 100].contains(&spacing_ms) {
        return Err("Only the bounded 25/100/250-user scenarios are allowed".into());
    }
    let game = Arc::new(
        Client::connect(
            &uri,
            &database,
            std::env::var("TEST_BOT_TOKEN")?,
            ServiceRole::DiscordAdapter,
        )
        .await?,
    );
    let art = Arc::new(CatchArt::new(
        std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets"),
    ));
    let pending = Arc::new(Semaphore::new(MAX_PENDING_GAME_REQUESTS));
    let start = Arc::new(Barrier::new(count + 1));
    let mut tasks = JoinSet::new();
    for index in 0..count {
        let (game, art, pending, start) =
            (game.clone(), art.clone(), pending.clone(), start.clone());
        tasks.spawn(async move {
            start.wait().await;
            tokio::time::sleep(Duration::from_millis(spacing_ms * index as u64)).await;
            let user = first_user + index as u64;
            let began = Instant::now();
            // Deliberately synthetic: this is not a Discord acknowledgement measurement.
            tokio::time::sleep(Duration::from_millis(50)).await;
            let ack_ms = began.elapsed().as_secs_f64() * 1000.0;
            let Ok(permit) = pending.try_acquire() else {
                emit(json!({"type":"result","user":user,"status":"busy","ack_ms":ack_ms,"total_ms":began.elapsed().as_secs_f64()*1000.0}));
                return;
            };
            emit(json!({"type":"cast-start","user":user,"at_ms":SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis() as u64}));
            let game_start = Instant::now();
            let interaction = snowflake(index as u64 + 1);
            let snapshot = game.player(user, format!("Load {user}"), interaction, Some(1), 1, true).await;
            if snapshot.is_ok() {
                if let Err(error) = game.sync_portrait(user, None, 0).await {
                    emit(json!({"type":"portrait-error","user":user,"error":error.to_string()}));
                }
            }
            let game_ms = game_start.elapsed().as_secs_f64() * 1000.0;
            drop(permit); // The real bot releases its game permit before rendering/delivery.
            let snapshot = match snapshot {
                Ok(snapshot) => snapshot,
                Err(error) => {
                    emit(json!({"type":"result","user":user,"status":"game-error","error":error.to_string(),"game_ms":game_ms,"ack_ms":ack_ms,"total_ms":began.elapsed().as_secs_f64()*1000.0}));
                    return;
                }
            };
            let render_start = Instant::now();
            let mut bytes = 0;
            let mut art_errors = 0;
            for pull in &snapshot.cast_pulls {
                let receipt = &pull.receipt;
                let rendered = match receipt.outcome.as_str() {
                    "fish" => {
                        let species = snapshot.species.iter().find(|s| Some(s.species_id) == receipt.species_id).expect("catalog species");
                        let maximum = discord_bot::perfect_catch::card(species, receipt, &snapshot.player.display_name).is_some();
                        if maximum && receipt.rarity == "UUR" && species.key != "nidalees-lost-sock" {
                            art.render_perfect(&species.key, &receipt.rarity).await
                        } else if maximum {
                            art.render_rank_maximum(&species.key, &receipt.rarity).await
                        } else {
                            art.render(&species.key, &receipt.rarity).await
                        }
                    }
                    "junk" => art.render_material(Material::RustedTin).await,
                    "treasure" => art.render_material(Material::Scrap).await,
                    _ => unreachable!("known category"),
                };
                match rendered { Ok(png) => bytes += png.len(), Err(_) => art_errors += 1 }
            }
            let art_ms = render_start.elapsed().as_secs_f64() * 1000.0;
            // Substitute for external HTTP delivery; no real messages or Discord tokens.
            tokio::time::sleep(Duration::from_millis(250)).await;
            emit(json!({"type":"result","user":user,"status":"ok","interaction":interaction.to_string(),"player":snapshot.player.player_id,"pulls":snapshot.cast_pulls.len(),"ack_ms":ack_ms,"game_ms":game_ms,"art_ms":art_ms,"art_errors":art_errors,"attachment_bytes":bytes,"total_ms":began.elapsed().as_secs_f64()*1000.0}));
        });
    }
    emit(json!({"type":"ready","users":count,"spacing_ms":spacing_ms}));
    start.wait().await;
    while let Some(result) = tasks.join_next().await {
        result?;
    }
    game.disconnect();
    Ok(())
}
