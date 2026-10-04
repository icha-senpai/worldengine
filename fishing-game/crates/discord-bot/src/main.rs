use game_client::{Client, Error, Snapshot, module_bindings::ServiceRole};
use poise::serenity_prelude as serenity;
use std::sync::Arc;

struct Data {
    game: Arc<Client>,
    website: String,
    pending: tokio::sync::Semaphore,
    assets: std::path::PathBuf,
}
type Context<'a> = poise::Context<'a, Data, Error>;
fn interaction_id(ctx: Context<'_>) -> Result<u64, Error> {
    match ctx {
        poise::Context::Application(ctx) => Ok(ctx.interaction.id.get()),
        _ => Err("Use a slash command".into()),
    }
}

async fn snapshot(ctx: Context<'_>, cast: bool) -> Result<Snapshot, Error> {
    let _permit = ctx
        .data()
        .pending
        .try_acquire()
        .map_err(|_| "The pond is busy; try again shortly")?;
    ctx.data()
        .game
        .player(
            ctx.author().id.get(),
            ctx.author().name.clone(),
            interaction_id(ctx)?,
            ctx.guild_id().map(|id| id.get()),
            ctx.channel_id().get(),
            cast,
        )
        .await
}
async fn reply(ctx: Context<'_>, message: impl Into<String>) -> Result<(), Error> {
    ctx.send(
        poise::CreateReply::default()
            .content(message)
            .allowed_mentions(serenity::CreateAllowedMentions::new()),
    )
    .await?;
    Ok(())
}
fn rejection(error: &Error) -> String {
    let message = error.to_string();
    if let Some(value) = message
        .split("COOLDOWN_ACTIVE:")
        .nth(1)
        .and_then(|s| s.split(|c: char| !c.is_ascii_digit()).next())
        .and_then(|s| s.parse::<i64>().ok())
    {
        return format!("Your next cast is ready <t:{}:R>.", value / 1_000_000);
    }
    if message.contains("INVENTORY_FULL") {
        return "Your inventory is full. Sell some unfavorited catches on the website first."
            .into();
    }
    if message.contains("INTERACTION_EXPIRED") {
        return "That request expired. Use /fish for a new cast.".into();
    }
    if message.contains("BIOME_LEVEL_REQUIRED") || message.contains("ROD_LOCKED") {
        return "That biome or rod needs a higher angler level. Use /biome or /gear to see unlock levels.".into();
    }
    if message.contains("BIOME_POWER_REQUIRED") {
        return "Equip the biome's required rod with /gear first. Return to an earlier biome before equipping a weaker rod.".into();
    }
    if message.contains("BIOME_UNAVAILABLE") || message.contains("ROD_UNAVAILABLE") {
        return "That ID is unavailable. Use /biome or /gear to see valid IDs.".into();
    }
    "The game could not confirm this request. Please try again shortly.".into()
}

/// Cast in your selected biome. One accepted cast every sixty seconds.
#[poise::command(slash_command)]
async fn fish(ctx: Context<'_>) -> Result<(), Error> {
    ctx.defer().await?;
    let result = match snapshot(ctx, true).await {
        Ok(result) => result,
        Err(error) => {
            tracing::warn!(%error, "Cast rejected or unavailable");
            return reply(ctx, rejection(&error)).await;
        }
    };
    let receipt = result.receipt.ok_or("Missing committed cast receipt")?;
    let message = match receipt.outcome.as_str() {
        "fish" => {
            let species = result
                .species
                .iter()
                .find(|row| Some(row.species_id) == receipt.species_id)
                .ok_or("Missing species")?;
            let grade = game_rules::measurements::SIZE_GRADE_NAMES[receipt.size_grade as usize];
            format!(
                "🎣 **{}** · {} rank · {}\n{:.1} cm · {:.3} kg · +{} XP\n{:.2}× typical length · {:.2}× typical weight\nCatch #{} saved. Next cast <t:{}:R>.",
                species.name,
                receipt.rarity,
                grade,
                f64::from(receipt.length_mm) / 10.0,
                receipt.weight_g as f64 / 1000.0,
                receipt.xp_granted,
                f64::from(receipt.length_mm) / f64::from(species.typical_length_mm),
                receipt.weight_g as f64 / species.typical_weight_g as f64,
                receipt.catch_id.unwrap_or_default(),
                (receipt.caught_at.to_micros_since_unix_epoch() + 60_000_000) / 1_000_000
            )
        }
        "junk" => format!(
            "🥫 A rusted tin! +{} XP. Saved to your items.",
            receipt.xp_granted
        ),
        "treasure" => format!(
            "🪙 A treasure cache! +{} coins, +{} scrap, +{} XP.",
            receipt.coins_granted, receipt.item_quantity, receipt.xp_granted
        ),
        _ => return Err("Unknown committed category".into()),
    };
    let biome = result
        .biomes
        .iter()
        .find(|row| row.biome_id == receipt.biome_id)
        .ok_or("Missing biome")?;
    let content = format!(
        "{message}\n{} · [Your collection]({})",
        biome.name,
        ctx.data().website
    );
    if let Some(species) = result
        .species
        .iter()
        .find(|row| Some(row.species_id) == receipt.species_id)
    {
        let sprite = ctx
            .data()
            .assets
            .join("fish")
            .join(format!("{}.png", species.key));
        let rank = ctx
            .data()
            .assets
            .join("rank-cards")
            .join(format!("{}.png", receipt.rarity));
        if let (Ok(sprite), Ok(rank)) = (
            serenity::CreateAttachment::path(sprite).await,
            serenity::CreateAttachment::path(rank).await,
        ) {
            let embed = serenity::CreateEmbed::new()
                .title(&species.name)
                .description(content)
                .image(format!("attachment://{}.png", species.key))
                .thumbnail(format!("attachment://{}.png", receipt.rarity));
            ctx.send(
                poise::CreateReply::default()
                    .embed(embed)
                    .attachment(sprite)
                    .attachment(rank)
                    .allowed_mentions(serenity::CreateAllowedMentions::new()),
            )
            .await?;
            return Ok(());
        }
        tracing::warn!("Catch artwork unavailable; delivering the saved text result");
    }
    reply(ctx, content).await
}

/// Show your fishing progression and website link.
#[poise::command(slash_command)]
async fn profile(ctx: Context<'_>) -> Result<(), Error> {
    ctx.defer_ephemeral().await?;
    let result = snapshot(ctx, false).await?;
    let ordinary = result
        .species
        .iter()
        .filter(|row| row.counts_for_ordinary_collection_completion)
        .count();
    let discoveries = result
        .collection
        .iter()
        .filter(|row| {
            result.species.iter().any(|s| {
                s.species_id == row.species_id && s.counts_for_ordinary_collection_completion
            })
        })
        .count();
    reply(ctx, format!("**Your Fishbound profile**\nLevel {} · {} XP · {} coins\n{} casts · {} fish · {}/{} ordinary discoveries\nInventory: {}/{}\n[Open Fishbound]({})",
        game_rules::progression::level_for_xp(result.player.total_xp, result.config.level_cap), result.player.total_xp, result.player.coins,
        result.player.completed_casts, result.player.fish_count, discoveries, ordinary, result.player.kept_count, result.config.inventory_capacity, ctx.data().website)).await
}

/// See your owned fish and manage favorites or sales on the website.
#[poise::command(slash_command)]
async fn inventory(ctx: Context<'_>) -> Result<(), Error> {
    ctx.defer_ephemeral().await?;
    let result = snapshot(ctx, false).await?;
    let mut inventory = result.inventory;
    inventory.sort_by_key(|row| std::cmp::Reverse(row.catch_id));
    let rows: Vec<_> = inventory
        .iter()
        .take(12)
        .map(|fish| {
            let name = result
                .species
                .iter()
                .find(|row| row.species_id == fish.species_id)
                .map(|row| row.name.as_str())
                .unwrap_or("Fish");
            format!(
                "#{} {}{} · {} rank · {:.1} cm · {} coins",
                fish.catch_id,
                if fish.favorite { "★ " } else { "" },
                name,
                fish.rarity,
                f64::from(fish.length_mm) / 10.0,
                fish.sale_value_coins
            )
        })
        .collect();
    reply(
        ctx,
        format!(
            "**Inventory · {}/100**\n{}\n[Manage catches]({})",
            inventory.len(),
            if rows.is_empty() {
                "No catches yet. Try /fish.".into()
            } else {
                rows.join("\n")
            },
            ctx.data().website
        ),
    )
    .await
}

/// See collection progress by biome and your companion book.
#[poise::command(slash_command)]
async fn collection(ctx: Context<'_>) -> Result<(), Error> {
    ctx.defer_ephemeral().await?;
    let result = snapshot(ctx, false).await?;
    let rows: Vec<_> = result
        .biomes
        .iter()
        .map(|biome| {
            let pool: Vec<_> = result
                .species
                .iter()
                .filter(|s| {
                    s.biome_id == biome.biome_id && s.counts_for_ordinary_collection_completion
                })
                .collect();
            let discovered = pool
                .iter()
                .filter(|s| {
                    result
                        .collection
                        .iter()
                        .any(|p| p.species_id == s.species_id)
                })
                .count();
            format!(
                "{} · {}/{} ordinary discoveries",
                biome.name,
                discovered,
                pool.len()
            )
        })
        .collect();
    reply(
        ctx,
        format!(
            "**Collection · 251 species**\n{}\nOrdinary ranks F through UUR come from species-relative length and weight; both must meet the minimum. Fihs is UUR-only; the Sock F-only. Both are bonus discoveries.\n[Open the full book]({})",
            rows.join("\n"), ctx.data().website
        ),
    )
    .await
}

/// List biomes or travel using a biome ID. Equip the required rod first.
#[poise::command(slash_command)]
async fn biome(
    ctx: Context<'_>,
    #[description = "Biome ID from the list"] biome_id: Option<u32>,
) -> Result<(), Error> {
    ctx.defer_ephemeral().await?;
    let result = if let Some(id) = biome_id {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The game is busy")?;
        ctx.data()
            .game
            .change_loadout(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                Some(id),
                None,
            )
            .await?
    } else {
        snapshot(ctx, false).await?
    };
    let rows: Vec<_> = result
        .biomes
        .iter()
        .map(|b| {
            format!(
                "{} {} · ID {} · level {} · power {}",
                if b.biome_id == result.player.selected_biome_id {
                    "→"
                } else {
                    "·"
                },
                b.name,
                b.biome_id,
                b.minimum_level,
                b.required_power
            )
        })
        .collect();
    reply(ctx, format!("**Biomes**\n{}\nUse /gear to equip a level-earned rod. Travel keeps your cast cooldown.", rows.join("\n"))).await
}

/// List level-earned rods or claim and equip one using its ID.
#[poise::command(slash_command)]
async fn gear(
    ctx: Context<'_>,
    #[description = "Rod ID from the list"] rod_id: Option<u32>,
) -> Result<(), Error> {
    ctx.defer_ephemeral().await?;
    let result = if let Some(id) = rod_id {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The game is busy")?;
        ctx.data()
            .game
            .change_loadout(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                None,
                Some(id),
            )
            .await?
    } else {
        snapshot(ctx, false).await?
    };
    let rows: Vec<_> = result
        .rods
        .iter()
        .map(|r| {
            format!(
                "{} {} · ID {} · level {} · power {}",
                if r.rod_id == result.player.equipped_rod_id {
                    "→"
                } else {
                    "·"
                },
                r.name,
                r.rod_id,
                r.minimum_level,
                r.power
            )
        })
        .collect();
    reply(ctx, format!("**Rods earned through angler levels**\n{}\nClaims are free and granted once. These rods unlock access; they do not change catch odds.", rows.join("\n"))).await
}

/// Learn the commands and link your companion website.
#[poise::command(slash_command)]
async fn help(ctx: Context<'_>) -> Result<(), Error> {
    reply(ctx, format!("**Fishbound**\n/fish — cast every 60 seconds\n/profile — progression and wallet\n/inventory — catches and management link\n/collection — discoveries by biome\n/biome [biome_id] — destinations and travel\n/gear [rod_id] — earned rods and equipment\n/help — this guide\n\n251 species across seven biomes. Link Discord on [the website]({}) to see your catches live, travel, equip rods, favorite catches, and confirm sales. Selling keeps discoveries and records. Ordinary ranks F through UUR come from species-relative length and weight; both must meet the minimum. Fihs is UUR-only; the Sock F-only. Both are bonus discoveries.", ctx.data().website)).await
}

fn required(name: &str) -> Result<String, Error> {
    std::env::var(name)
        .ok()
        .filter(|v| !v.trim().is_empty())
        .ok_or_else(|| format!("Set {name} in the service environment").into())
}

#[tokio::main]
async fn main() -> Result<(), Error> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();
    let token = required("DISCORD_BOT_TOKEN")?;
    let game = Arc::new(
        Client::connect(
            &required("SPACETIMEDB_URI")?,
            &required("SPACETIMEDB_DATABASE")?,
            game_client::service_token("SPACETIMEDB_BOT_TOKEN")?,
            ServiceRole::DiscordAdapter,
        )
        .await?,
    );
    let website = required("WEBSITE_URL")?;
    let _reconnect_watch = game.start_reconnect_watch();
    let assets = std::env::var("ASSET_ROOT").unwrap_or_else(|_| "assets".into());
    let register = std::env::var("DISCORD_REGISTER_COMMANDS").as_deref() == Ok("true");
    let guild = std::env::var("DISCORD_GUILD_ID")
        .ok()
        .filter(|s| !s.is_empty())
        .map(|id| id.parse::<u64>())
        .transpose()?;
    let framework = poise::Framework::builder()
        .options(poise::FrameworkOptions {
            commands: vec![
                fish(),
                profile(),
                inventory(),
                collection(),
                biome(),
                gear(),
                help(),
            ],
            on_error: |error| {
                Box::pin(async move {
                    if let poise::FrameworkError::Command { ctx, error, .. } = error {
                        tracing::warn!(%error, "Command unavailable");
                        let _ = reply(ctx, rejection(&error)).await;
                    } else {
                        tracing::warn!("Discord framework error");
                    }
                })
            },
            ..Default::default()
        })
        .setup(move |ctx, _, framework| {
            Box::pin(async move {
                if register {
                    if let Some(guild) = guild {
                        poise::builtins::register_in_guild(
                            ctx,
                            &framework.options().commands,
                            serenity::GuildId::new(guild),
                        )
                        .await?;
                    } else {
                        poise::builtins::register_globally(ctx, &framework.options().commands)
                            .await?;
                    }
                }
                tracing::info!("Discord adapter ready");
                Ok(Data {
                    game,
                    website,
                    pending: tokio::sync::Semaphore::new(32),
                    assets: assets.into(),
                })
            })
        })
        .build();
    let mut client = serenity::ClientBuilder::new(token, serenity::GatewayIntents::GUILDS)
        .framework(framework)
        .await?;
    let shard_manager = client.shard_manager.clone();
    tokio::spawn(async move {
        tokio::signal::ctrl_c().await.ok();
        shard_manager.shutdown_all().await;
    });
    client.start().await?;
    Ok(())
}
