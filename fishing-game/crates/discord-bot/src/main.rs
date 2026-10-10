use discord_bot::achievement_notifications::AchievementNotifications;
use discord_bot::catch_art::{CatchArt, Material, ROD_QUALITY_COLORS};
use discord_bot::measurements::{format_length, format_weight};
use game_client::{Client, Error, Snapshot, module_bindings::ServiceRole};
use poise::serenity_prelude as serenity;
use std::{sync::Arc, time::Instant};

struct Data {
    game: Arc<Client>,
    website: String,
    pending: tokio::sync::Semaphore,
    catch_art: CatchArt,
    achievement_notifications: Arc<AchievementNotifications>,
}
type Context<'a> = poise::Context<'a, Data, Error>;
fn interaction_id(ctx: Context<'_>) -> Result<u64, Error> {
    match ctx {
        poise::Context::Application(ctx) => Ok(ctx.interaction.id.get()),
        _ => Err("Use a slash command".into()),
    }
}

async fn snapshot(ctx: Context<'_>, cast: bool) -> Result<Snapshot, Error> {
    let started = Instant::now();
    let _permit = ctx
        .data()
        .pending
        .try_acquire()
        .map_err(|_| "The pond is busy; try again shortly")?;
    let result = ctx
        .data()
        .game
        .player(
            ctx.author().id.get(),
            ctx.author().name.clone(),
            interaction_id(ctx)?,
            ctx.guild_id().map(|id| id.get()),
            ctx.channel_id().get(),
            cast,
        )
        .await;
    log_timing(ctx, "game", started, result.is_ok()).await;
    result
}

async fn log_timing(ctx: Context<'_>, stage: &str, started: Instant, success: bool) {
    let command_ms = ctx
        .invocation_data::<Instant>()
        .await
        .map(|started| started.elapsed().as_millis() as u64);
    tracing::info!(command = %ctx.command().name, interaction_id = interaction_id(ctx).ok(), stage, elapsed_ms = started.elapsed().as_millis() as u64, command_ms, success, "Discord command timing");
}

async fn acknowledge(ctx: Context<'_>, ephemeral: bool) -> Result<(), Error> {
    let started = Instant::now();
    let result = if ephemeral {
        ctx.defer_ephemeral().await
    } else {
        ctx.defer().await
    };
    log_timing(ctx, "acknowledge", started, result.is_ok()).await;
    result.map_err(Into::into)
}

async fn send_reply<'a>(
    ctx: Context<'a>,
    response: poise::CreateReply,
) -> Result<poise::ReplyHandle<'a>, Error> {
    let started = Instant::now();
    let result = ctx.send(response).await;
    log_timing(ctx, "reply", started, result.is_ok()).await;
    result.map_err(Into::into)
}

async fn reply(ctx: Context<'_>, message: impl Into<String>) -> Result<(), Error> {
    send_reply(
        ctx,
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
    if message.contains("INTERACTION_EXPIRED") {
        return "That request expired. Use /fish for a new cast.".into();
    }
    if message.contains("BIOME_LICENCE_REQUIRED") || message.contains("PREVIOUS_LICENCE_REQUIRED") {
        return "Visit /shop for biome licences. Buy each water's licence before moving on.".into();
    }
    if message.contains("ROD_NOT_OWNED") {
        return "Buy that rod at /shop before equipping it with /gear.".into();
    }
    if message.contains("INSUFFICIENT_COINS") {
        return "You need more coins for this offer. Sell catches on the website or collect /daily.".into();
    }
    if message.contains("SHOP_ITEM_UNAVAILABLE") {
        return "That item ID is unavailable. Use /shop to see the trader list.".into();
    }
    if message.contains("ALREADY_OWNED") {
        return "You already own this item.".into();
    }
    if message.contains("SHOP_LEVEL_REQUIRED") {
        return "Reach the item's required angler level before buying.".into();
    }
    if message.contains("ACTION_EXPIRED") || message.contains("QUOTE_CHANGED") {
        return "That offer expired or changed. Use /shop, /sell or /upgrade to review a fresh offer."
            .into();
    }
    if message.contains("BIOME_LEVEL_REQUIRED")
        || message.contains("BIOME_LOCKED")
        || message.contains("ROD_LOCKED")
    {
        return "That biome or rod needs a higher angler level. Use /biome or /gear to see unlock levels.".into();
    }
    if message.contains("INSUFFICIENT_MATERIALS") {
        return "You need more rusted tin and scrap. Resource bait at /shop helps; your next recipe is in the website Tackle Box.".into();
    }
    if message.contains("MAX_QUALITY") {
        return "That rod is already Prismatic.".into();
    }
    if message.contains("BAIT_NOT_OWNED") {
        return "Buy a 10-use pack at /shop before equipping bait.".into();
    }
    if message.contains("BIOME_UNAVAILABLE") || message.contains("ROD_UNAVAILABLE") {
        return "That ID is unavailable. Use /biome or /gear to see valid IDs.".into();
    }
    if message.contains("FAVORITE_PROTECTED") {
        return "Favorites are protected. Unfavorite that catch on the website before selling."
            .into();
    }
    if message.contains("CATCH_UNAVAILABLE") || message.contains("CATCH_NOT_OWNED") {
        return "A selected catch is unavailable or belongs to another angler. Check /inventory and prepare a new /sell preview.".into();
    }
    if message.contains("DUPLICATE_CATCH") || message.contains("BATCH_INVALID") {
        return "Choose 1–50 different catch IDs from /inventory.".into();
    }
    if message.contains("ACTION_CONFLICT") || message.contains("ACTION_NOT_FOUND") {
        return "That preview was replaced or is unavailable. Use /sell, /shop or /upgrade for a fresh preview.".into();
    }
    if message.contains("ACTION_RATE_LIMITED") {
        return "Give the last preview a moment, then try again.".into();
    }
    "The game could not confirm this request. Please try again shortly.".into()
}

/// Cast in your selected biome. One accepted cast every sixty seconds.
#[poise::command(slash_command)]
async fn fish(ctx: Context<'_>) -> Result<(), Error> {
    acknowledge(ctx, false).await?;
    let result = match snapshot(ctx, true).await {
        Ok(result) => result,
        Err(error) => {
            tracing::warn!(%error, "Cast rejected or unavailable");
            return reply(ctx, rejection(&error)).await;
        }
    };
    let receipt = result
        .receipt
        .as_ref()
        .ok_or("Missing committed cast receipt")?;
    let mut pulls: Vec<_> = result.cast_pulls.iter().collect();
    pulls.sort_by_key(|pull| pull.key);
    let receipts: Vec<_> = if pulls.is_empty() {
        vec![receipt]
    } else {
        pulls.iter().map(|pull| &pull.receipt).collect()
    };
    let mut response =
        poise::CreateReply::default().allowed_mentions(serenity::CreateAllowedMentions::new());
    let mut lines = Vec::new();
    for (index, pull) in receipts.iter().enumerate() {
        match pull.outcome.as_str() {
            "fish" => {
                let species = result
                    .species
                    .iter()
                    .find(|row| Some(row.species_id) == pull.species_id)
                    .ok_or("Missing species")?;
                let grade = game_rules::measurements::SIZE_GRADE_NAMES[pull.size_grade as usize];
                let description = format!(
                    "{} rank · {}\n{} · {} · +{} XP",
                    pull.rarity,
                    grade,
                    format_length(u64::from(pull.length_mm)),
                    format_weight(pull.weight_g),
                    pull.xp_granted
                );
                lines.push(format!("🎣 **{}** · {}", species.name, description));
                match ctx
                    .data()
                    .catch_art
                    .render(&species.key, &pull.rarity)
                    .await
                {
                    Ok(png) => {
                        let filename =
                            format!("catch-{}-{}-{}.png", index + 1, species.key, pull.rarity);
                        response = response
                            .embed(
                                serenity::CreateEmbed::new()
                                    .title(&species.name)
                                    .description(description)
                                    .image(format!("attachment://{filename}")),
                            )
                            .attachment(serenity::CreateAttachment::bytes(png, filename));
                    }
                    Err(error) => {
                        tracing::warn!(%error, "Catch artwork unavailable");
                        response = response.embed(
                            serenity::CreateEmbed::new()
                                .title(&species.name)
                                .description(description),
                        );
                    }
                }
            }
            "junk" => {
                lines.push(format!("Rusted tin · +{} XP", pull.xp_granted));
                response = material_card(
                    ctx,
                    response,
                    serenity::CreateEmbed::new()
                        .title("Rusted tin")
                        .description(format!("+1 rusted tin · +{} XP", pull.xp_granted)),
                    Material::RustedTin,
                    format!("pull-{}-rusted-tin.png", index + 1),
                )
                .await;
            }
            "treasure" => {
                lines.push(format!(
                    "Treasure · +{} coins · +{} scrap · +{} XP",
                    pull.coins_granted, pull.item_quantity, pull.xp_granted
                ));
                response = material_card(
                    ctx,
                    response,
                    serenity::CreateEmbed::new()
                        .title("Treasure cache")
                        .description(format!(
                            "+{} coins · +{} scrap · +{} XP",
                            pull.coins_granted, pull.item_quantity, pull.xp_granted
                        )),
                    Material::Scrap,
                    format!("pull-{}-scrap.png", index + 1),
                )
                .await;
            }
            _ => return Err("Unknown committed category".into()),
        }
    }
    let biome = result
        .biomes
        .iter()
        .find(|row| row.biome_id == receipt.biome_id)
        .ok_or("Missing biome")?;
    let mut summary = format!(
        "{} · {} pull{} · +{} XP total",
        biome.name,
        receipts.len(),
        if receipts.len() == 1 { "" } else { "s" },
        receipt.xp_granted
    );
    let rod_name = result
        .rods
        .iter()
        .find(|rod| rod.rod_id == receipt.rod_id)
        .map_or("Rod", |rod| rod.name.as_str());
    summary.push_str(&format!("\n**{}**", rod_name));
    if let Some(equipment) = &result.cast_equipment {
        let quality_name = result
            .qualities
            .iter()
            .find(|quality| quality.quality_level == equipment.quality_level)
            .map_or("Unknown quality", |quality| quality.name.as_str());
        summary.push_str(&format!(" · **{}**", quality_name));
        if equipment.bait_id != 0 {
            let name = result
                .baits
                .iter()
                .find(|bait| bait.bait_id == equipment.bait_id)
                .map_or("Bait", |bait| bait.name.as_str());
            summary.push_str(&format!(
                "\n{} · {} uses left",
                name, equipment.bait_uses_left
            ));
            if !equipment.bait_item.is_empty() {
                summary.push_str(&format!(" · +1 {}", equipment.bait_item.replace('_', " ")));
            }
        }
    }
    let level =
        game_rules::progression::level_for_xp(result.player.total_xp, result.config.level_cap);
    if level >= result.config.level_cap {
        summary.push_str(&format!("\nAngler level {} · Max level reached", level));
    } else {
        let level_start_xp: u64 = (1..level).map(game_rules::progression::xp_to_next).sum();
        let remaining_xp =
            game_rules::progression::xp_to_next(level) - (result.player.total_xp - level_start_xp);
        summary.push_str(&format!(
            "\nAngler level {} · {} XP to level {}",
            level,
            remaining_xp,
            level + 1
        ));
    }
    summary.push_str(&format!(
        "\nNext cast <t:{}:R> · [Your collection]({})",
        (receipt.caught_at.to_micros_since_unix_epoch() + 60_000_000) / 1_000_000,
        ctx.data().website
    ));
    match send_reply(ctx, response.content(&summary)).await {
        Ok(_) => Ok(()),
        Err(error) => {
            tracing::warn!(%error, "Catch card delivery failed");
            reply(ctx, format!("{}\n{}", lines.join("\n"), summary)).await
        }
    }
}

async fn material_card(
    ctx: Context<'_>,
    response: poise::CreateReply,
    embed: serenity::CreateEmbed,
    material: Material,
    filename: String,
) -> poise::CreateReply {
    match ctx.data().catch_art.render_material(material).await {
        Ok(png) => response
            .embed(embed.image(format!("attachment://{filename}")))
            .attachment(serenity::CreateAttachment::bytes(png, filename)),
        Err(error) => {
            tracing::warn!(%error, material = material.key(), "Material artwork unavailable");
            response.embed(embed)
        }
    }
}

/// Collect your Dockside Delivery. Every seventh delivery includes a coin bonus.
#[poise::command(slash_command)]
async fn daily(ctx: Context<'_>) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let _permit = ctx
        .data()
        .pending
        .try_acquire()
        .map_err(|_| "The dock is busy; try again shortly")?;
    let started = Instant::now();
    let outcome = ctx
        .data()
        .game
        .daily(
            ctx.author().id.get(),
            ctx.author().name.clone(),
            interaction_id(ctx)?,
            ctx.guild_id().map(|id| id.get()),
            ctx.channel_id().get(),
        )
        .await;
    log_timing(ctx, "game", started, outcome.is_ok()).await;
    let receipt = match outcome {
        Ok(receipt) => receipt,
        Err(error) => {
            tracing::warn!(%error, "Delivery rejected or unavailable");
            let message = if error.to_string().contains("INTERACTION_EXPIRED") {
                "That request expired. Use /daily for a new delivery.".into()
            } else {
                rejection(&error)
            };
            return reply(ctx, message).await;
        }
    };
    let reward = if receipt.claimed {
        format!(
            "📦 **Dockside Delivery**\n**+{} coins**{}",
            receipt.coins_granted,
            if receipt.stamps == 7 {
                " · includes your 250-coin stamp bonus!"
            } else {
                ""
            }
        )
    } else {
        "📦 **Dockside Delivery**\nYou've already collected today's delivery.".into()
    };
    let progress = if receipt.stamps == 7 {
        "Stamp card: **7/7 complete**. Your next delivery starts a new card.".into()
    } else {
        format!(
            "Stamp card: **{}/7** · +250 bonus coins on stamp 7.",
            receipt.stamps
        )
    };
    reply(ctx, format!("{reward}\n{progress}\nNext delivery <t:{}:R> · resets at midnight UTC.\nMissed days keep your stamps.",
        receipt.next_delivery_at.to_micros_since_unix_epoch() / 1_000_000)).await
}

/// Show your fishing progression and website link.
#[poise::command(slash_command)]
async fn profile(
    ctx: Context<'_>,
    #[description = "Public angler ID from /leaderboard; omit for your own profile"]
    player_id: Option<u64>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let result = snapshot(ctx, false).await?;
    let target = player_id.unwrap_or(result.player.player_id);
    let badge_count = result
        .earned_achievements
        .iter()
        .filter(|row| row.player_id == target)
        .count();
    let title_id = result
        .titles
        .iter()
        .find(|row| row.player_id == target)
        .map_or(0, |row| row.achievement_id);
    let title = result
        .achievements
        .iter()
        .find(|row| row.achievement_id == title_id)
        .map_or("Angler", |row| row.title.as_str());
    let public_link = format!(
        "{}/?angler={target}#anglers",
        ctx.data().website.trim_end_matches('/')
    );
    if target != result.player.player_id {
        let Some(other) = result
            .public_profiles
            .iter()
            .find(|row| row.player_id == target)
        else {
            return reply(
                ctx,
                "That angler ID is not in the public book. Use /leaderboard to find one.",
            )
            .await;
        };
        let standing = result.standings.iter().find(|row| row.player_id == target);
        return reply(ctx, format!("**{} · {}**\n[View profile]({}) · level {}\n{} lifetime fish · {}/249 ordinary discoveries\n{} UUR catches · {} current records · {} badges", plain_name(&other.display_name), title, public_link, other.level, other.fish_count, other.discoveries, standing.map_or(0, |row| row.uur_count), standing.map_or(0, |row| row.records_held), badge_count)).await;
    }
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
    reply(ctx, format!("**Your Fishbound profile · {title}**\nLevel {} · {} XP · {} coins\n{} casts · {} fish · {}/{} ordinary discoveries\nInventory: {} fish kept\n{badge_count} badges earned · [Your profile]({public_link})\n[Achievements and titles]({}/#achievements)",
        game_rules::progression::level_for_xp(result.player.total_xp, result.config.level_cap), result.player.total_xp, result.player.coins,
        result.player.completed_casts, result.player.fish_count, discoveries, ordinary, result.player.kept_count, ctx.data().website.trim_end_matches('/'))).await
}

fn plain_name(name: &str) -> String {
    name.chars()
        .filter(|c| {
            !matches!(c, '*' | '_' | '`' | '~' | '[' | ']' | '<' | '>' | '\\') && !c.is_control()
        })
        .collect()
}

#[derive(Debug, Clone, Copy, poise::ChoiceParameter)]
enum Board {
    #[name = "Discoveries"]
    Discoveries,
    #[name = "Fish caught"]
    Fish,
    #[name = "UUR catches"]
    Uur,
    #[name = "Records held"]
    Records,
}

/// Public fishing standings, or the longest and heaviest catches for a species.
#[poise::command(slash_command)]
async fn leaderboard(
    ctx: Context<'_>,
    #[description = "Standings category"] category: Option<Board>,
    #[description = "Page number, starting at 1"] page: Option<u32>,
    #[description = "Optional species ID for length and weight records"] species_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let result = snapshot(ctx, false).await?;
    if let Some(id) = species_id {
        let Some(species) = result.species.iter().find(|row| row.species_id == id) else {
            return reply(
                ctx,
                "Unknown species ID. Find species and their records in the website Records book.",
            )
            .await;
        };
        let rows: Vec<_> = result
            .records
            .iter()
            .filter(|row| row.species_id == id)
            .map(|row| {
                let size = if row.metric == "length" {
                    format_length(row.measurement)
                } else {
                    format_weight(row.measurement)
                };
                format!(
                    "{}: **{}** · {} rank · {} (Angler #{})",
                    row.metric,
                    size,
                    row.rarity,
                    plain_name(&row.display_name),
                    row.player_id
                )
            })
            .collect();
        return reply(
            ctx,
            format!(
                "**{} records · species #{}**\n{}\n[Records book]({}/#records)",
                species.name,
                id,
                if rows.is_empty() {
                    "No records yet.".into()
                } else {
                    rows.join("\n")
                },
                ctx.data().website.trim_end_matches('/')
            ),
        )
        .await;
    }
    let category = category.unwrap_or(Board::Discoveries);
    let value = |row: &game_client::module_bindings::AnglerStanding| match category {
        Board::Discoveries => u64::from(row.discoveries),
        Board::Fish => row.fish_count,
        Board::Uur => row.uur_count,
        Board::Records => u64::from(row.records_held),
    };
    let mut standings: Vec<_> = result
        .standings
        .iter()
        .filter(|row| value(row) > 0)
        .collect();
    standings.sort_by_key(|row| (std::cmp::Reverse(value(row)), row.player_id));
    let pages = standings.len().div_ceil(10).max(1);
    let page = usize::try_from(page.unwrap_or(1))
        .unwrap_or(1)
        .clamp(1, pages);
    let mut rank = 0;
    let mut previous = None;
    let rows: Vec<_> = standings
        .iter()
        .enumerate()
        .filter_map(|(index, row)| {
            let score = value(row);
            if previous != Some(score) {
                rank = index + 1;
                previous = Some(score);
            }
            if index / 10 + 1 != page {
                return None;
            }
            Some(format!(
                "{}. **{}** · {} · Angler #{}",
                rank,
                plain_name(&row.display_name),
                score,
                row.player_id
            ))
        })
        .collect();
    let label = match category {
        Board::Discoveries => "Ordinary discoveries",
        Board::Fish => "Lifetime fish caught",
        Board::Uur => "UUR catches",
        Board::Records => "Current records held",
    };
    reply(ctx, format!("**{} · page {}/{}**\n{}\nTied scores share a place. /profile player_id opens an angler's public profile.\n[Records book]({}/#records)", label, page, pages, if rows.is_empty() { "The first place is waiting for an angler.".into() } else { rows.join("\n") }, ctx.data().website.trim_end_matches('/'))).await
}

/// Preview and confirm a sale of up to 50 catch IDs from /inventory.
#[poise::command(slash_command)]
async fn sell(
    ctx: Context<'_>,
    #[description = "Catch IDs separated by spaces or commas, e.g. 12, 13"] catch_ids: Option<
        String,
    >,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let Some(text) = catch_ids else {
        return reply(ctx, "Use /inventory to find catch IDs, then /sell catch_ids:12,13 to preview their exact value. Up to 50 fish per sale; favorites are protected. Nothing is sold until you confirm.").await;
    };
    let ids = match discord_bot::sale_ids::parse(&text) {
        Ok(ids) => ids,
        Err(message) => return reply(ctx, message).await,
    };
    let quote = {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The trader is busy")?;
        ctx.data()
            .game
            .sale_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                Some(ids),
                None,
            )
            .await?
    };
    let result = snapshot(ctx, false).await?;
    let rows: Vec<_> = quote
        .catch_ids
        .iter()
        .take(5)
        .map(|id| {
            let fish = result.inventory.iter().find(|row| row.catch_id == *id);
            let name = fish
                .and_then(|fish| {
                    result
                        .species
                        .iter()
                        .find(|row| row.species_id == fish.species_id)
                })
                .map_or("Catch changed", |row| row.name.as_str());
            format!(
                "#{id} {}{}",
                name.chars().take(32).collect::<String>(),
                fish.map_or(String::new(), |row| format!(
                    " · {} rank · {} coins",
                    row.rarity, row.sale_value_coins
                ))
            )
        })
        .collect();
    let confirm_id = format!("sell:{}:confirm", interaction_id(ctx)?);
    let cancel_id = format!("sell:{}:cancel", interaction_id(ctx)?);
    let handle = send_reply(ctx, poise::CreateReply::default().content(format!("**Sell {} fish for {} coins?**\n{}{}\nCatch IDs: {}\nDiscoveries, records and badges remain. Offer expires <t:{}:R>.", quote.catch_ids.len(), quote.quoted_coins, rows.join("\n"), if quote.catch_ids.len() > 5 { "\nAdditional catches listed by ID below." } else { "" }, quote.catch_ids.iter().map(u64::to_string).collect::<Vec<_>>().join(", "), quote.expires_at.to_micros_since_unix_epoch() / 1_000_000))
        .allowed_mentions(serenity::CreateAllowedMentions::new()).components(vec![serenity::CreateActionRow::Buttons(vec![serenity::CreateButton::new(&confirm_id).label(format!("Sell · {} coins", quote.quoted_coins)).style(serenity::ButtonStyle::Success), serenity::CreateButton::new(&cancel_id).label("Cancel").style(serenity::ButtonStyle::Secondary)])])).await?;
    let interaction = handle
        .message()
        .await?
        .await_component_interaction(ctx)
        .author_id(ctx.author().id)
        .timeout(std::time::Duration::from_secs(110))
        .filter(move |event| {
            event.data.custom_id == confirm_id || event.data.custom_id == cancel_id
        })
        .await;
    let Some(interaction) = interaction else {
        handle
            .edit(
                ctx,
                poise::CreateReply::default()
                    .content("Sale preview closed. Use /sell for a fresh preview.")
                    .components(vec![]),
            )
            .await?;
        return Ok(());
    };
    interaction
        .create_response(ctx, serenity::CreateInteractionResponse::Acknowledge)
        .await?;
    let message = if interaction.data.custom_id.ends_with(":cancel") {
        "Sale cancelled. Your fish are safe.".into()
    } else {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The trader is busy")?;
        match ctx
            .data()
            .game
            .sale_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction.id.get(),
                None,
                Some(quote.nonce),
            )
            .await
        {
            Ok(committed) if committed.consumed => format!(
                "✓ Sold {} fish for {} coins. Your discoveries, records and badges remain.",
                committed.catch_ids.len(),
                committed.quoted_coins
            ),
            Ok(_) => "Sale could not be confirmed. Check /inventory before trying again.".into(),
            Err(error) => {
                tracing::warn!(%error, "Sale rejected");
                rejection(&error)
            }
        }
    };
    handle
        .edit(
            ctx,
            poise::CreateReply::default()
                .content(message)
                .components(vec![])
                .allowed_mentions(serenity::CreateAllowedMentions::new()),
        )
        .await?;
    Ok(())
}

/// See your owned fish and manage favorites or sales on the website.
#[poise::command(slash_command)]
async fn inventory(ctx: Context<'_>) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
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
                "#{} {}{} · {} rank · {} · {} coins",
                fish.catch_id,
                if fish.favorite { "★ " } else { "" },
                name,
                fish.rarity,
                format_length(u64::from(fish.length_mm)),
                fish.sale_value_coins
            )
        })
        .collect();
    reply(
        ctx,
        format!(
            "**Inventory · {} fish kept**\n{}\n[Manage catches]({})",
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
    acknowledge(ctx, true).await?;
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

/// List biomes or travel to a licensed biome using its ID.
#[poise::command(slash_command)]
async fn biome(
    ctx: Context<'_>,
    #[description = "Biome ID from the list"] biome_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
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
                "{} {} · ID {} · level {} · {}",
                if b.biome_id == result.player.selected_biome_id {
                    "→"
                } else {
                    "·"
                },
                b.name,
                b.biome_id,
                b.minimum_level,
                if b.biome_id == 1
                    || result
                        .licences
                        .iter()
                        .any(|licence| licence.biome_id == b.biome_id)
                {
                    "licensed"
                } else {
                    "licence needed"
                }
            )
        })
        .collect();
    reply(ctx, format!("**Biomes**\n{}\nBuy sequential biome licences at /shop. Any owned rod can fish licensed waters at your level. Travel keeps your cast cooldown.", rows.join("\n"))).await
}

/// Show off your equipped rod, or any owned rod by ID, in this channel.
#[poise::command(slash_command)]
async fn rod(
    ctx: Context<'_>,
    #[description = "Owned rod ID; omit to show your equipped rod"] rod_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, false).await?;
    let result = snapshot(ctx, false).await?;
    let id = rod_id.unwrap_or(result.player.equipped_rod_id);
    let Some(owned) = result.owned_rods.iter().find(|row| row.rod_id == id) else {
        return reply(
            ctx,
            "You can only show off rods you own. Use /gear to see your rods and their IDs.",
        )
        .await;
    };
    let definition = result
        .rods
        .iter()
        .find(|row| row.rod_id == id)
        .ok_or("Missing rod")?;
    let (quality, power, luck, xp) = rod_stats(&result, id);
    let description = format!(
        "**{} {}**\n{} · shown by **{}**\n\n**{} power** · **{:.1}%** bonus-pull chance\n**+{:.1}% luck** · **+{:.1}% catch XP**",
        quality,
        definition.name,
        if id == result.player.equipped_rod_id {
            "Equipped"
        } else {
            "Owned"
        },
        plain_name(&ctx.author().name),
        power,
        power as f64 / 2.0,
        luck as f64 / 100.0,
        xp as f64 / 100.0,
    );
    let color = ROD_QUALITY_COLORS
        .get(usize::from(owned.upgrade_level))
        .copied()
        .unwrap_or(ROD_QUALITY_COLORS[0]);
    let embed = serenity::CreateEmbed::new()
        .title("From the tackle box")
        .description(&description)
        .color(color);
    let art_key = result
        .rod_bonuses
        .iter()
        .find(|row| row.rod_id == id)
        .and_then(|row| {
            row.sprite_asset
                .strip_prefix("/rods/")?
                .strip_suffix(".png")
        });
    let mut response =
        poise::CreateReply::default().allowed_mentions(serenity::CreateAllowedMentions::new());
    if let Some(key) = art_key {
        match ctx
            .data()
            .catch_art
            .render_rod(key, owned.upgrade_level)
            .await
        {
            Ok(png) => {
                let filename = format!("rod-{id}-quality-{}.png", owned.upgrade_level);
                response = response
                    .embed(embed.image(format!("attachment://{filename}")))
                    .attachment(serenity::CreateAttachment::bytes(png, filename));
            }
            Err(error) => {
                tracing::warn!(%error, rod_id = id, "Rod artwork unavailable");
                response = response.embed(embed);
            }
        }
    } else {
        response = response.embed(embed);
    }
    match send_reply(ctx, response).await {
        Ok(_) => Ok(()),
        Err(error) => {
            tracing::warn!(%error, "Rod card delivery failed");
            reply(ctx, description).await
        }
    }
}

/// List rods or equip a purchased rod using its ID.
#[poise::command(slash_command)]
async fn gear(
    ctx: Context<'_>,
    #[description = "Rod ID from the list"] rod_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
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
    let rows: Vec<_> = result.rods.iter().map(|rod| {
        let (quality, power, luck, xp) = rod_stats(&result, rod.rod_id);
        let owned = result.owned_rods.iter().any(|row| row.rod_id == rod.rod_id);
        format!("{} **{} {}** · ID {} · level {}\n{} power · {:.1}% bonus pull · +{}% luck · +{}% XP · {}", if rod.rod_id == result.player.equipped_rod_id { "→" } else { "·" }, quality, rod.name, rod.rod_id, rod.minimum_level, power, power as f64 / 2.0, luck / 100, xp / 100, if owned { "owned" } else { "shop purchase needed" })
    }).collect();
    reply(ctx, format!("**Your fishing rods**\n{}\n/shop buys rods · /gear equips · /upgrade crafts quality · /bait equips bait. Power can add one independent fish, junk or treasure pull. One cooldown and one bait use per cast.", rows.join("\n"))).await
}

fn rod_stats(result: &Snapshot, rod_id: u32) -> (&str, u32, u32, u32) {
    let rod = result
        .rods
        .iter()
        .find(|row| row.rod_id == rod_id)
        .expect("valid rod");
    let level = result
        .owned_rods
        .iter()
        .find(|row| row.rod_id == rod_id)
        .map_or(0, |row| row.upgrade_level);
    let quality = result
        .qualities
        .iter()
        .find(|row| row.quality_level == level);
    let bonus = result.rod_bonuses.iter().find(|row| row.rod_id == rod_id);
    (
        quality.map_or("Common", |row| row.name.as_str()),
        rod.power + quality.map_or(0, |row| row.power_bonus),
        bonus.map_or(0, |row| row.luck_bp) + quality.map_or(0, |row| row.luck_bp),
        bonus.map_or(0, |row| row.xp_bonus_bp) + quality.map_or(0, |row| row.xp_bonus_bp),
    )
}

/// List your bait or equip a type. ID zero removes equipped bait.
#[poise::command(slash_command)]
async fn bait(
    ctx: Context<'_>,
    #[description = "Bait ID; zero to remove bait"] bait_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let result = if let Some(id) = bait_id {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The game is busy")?;
        ctx.data()
            .game
            .equip_bait(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                id,
            )
            .await?
    } else {
        snapshot(ctx, false).await?
    };
    let equipped = result.bait_loadout.as_ref().map_or(0, |row| row.bait_id);
    let rows: Vec<_> = result
        .baits
        .iter()
        .map(|row| {
            let stock = result
                .owned_baits
                .iter()
                .find(|stack| stack.bait_id == row.bait_id)
                .map_or(0, |stack| stack.uses_left);
            let effect = if row.xp_bonus_bp > 0 {
                format!("+{}% fishing XP", row.xp_bonus_bp / 100)
            } else if row.resource_item.is_empty() {
                format!("+{}% luck", row.luck_bp / 100)
            } else {
                format!("+1 {} per cast", row.resource_item.replace('_', " "))
            };
            format!(
                "{} **{}** · ID {} · {} uses · {}",
                if equipped == row.bait_id { "→" } else { "·" },
                row.name,
                row.bait_id,
                stock,
                effect
            )
        })
        .collect();
    reply(ctx, format!("**Bait pouch**\n{}\n{}\nBuy 10-use packs at /shop (item IDs 101–108). One use per accepted cast; depletion removes bait automatically. /bait bait_id:0 removes bait.", rows.join("\n"), if equipped == 0 { "No bait equipped." } else { "→ Equipped bait" })).await
}

/// Permanently craft the next quality for an owned rod. Defaults to equipped rod.
#[poise::command(slash_command)]
async fn upgrade(
    ctx: Context<'_>,
    #[description = "Owned rod ID; defaults to equipped rod"] rod_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let result = snapshot(ctx, false).await?;
    let id = rod_id.unwrap_or(result.player.equipped_rod_id);
    let quote = {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The workshop is busy")?;
        ctx.data()
            .game
            .upgrade_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                Some(id),
                None,
            )
            .await?
    };
    let rod = result
        .rods
        .iter()
        .find(|row| row.rod_id == id)
        .ok_or("Missing rod")?;
    let next = result
        .qualities
        .iter()
        .find(|row| row.quality_level == quote.from_quality + 1)
        .ok_or("Missing quality")?;
    let previous = result
        .qualities
        .iter()
        .find(|row| row.quality_level == quote.from_quality)
        .ok_or("Missing quality")?;
    let confirm_id = format!("upgrade:{}:confirm", interaction_id(ctx)?);
    let cancel_id = format!("upgrade:{}:cancel", interaction_id(ctx)?);
    let handle = send_reply(ctx, poise::CreateReply::default().content(format!("**Craft {} {}?**\n{} rusted tin + {} scrap · guaranteed success\n+{} power · +{}% luck · +{}% XP over current quality. Each rod keeps its own permanent quality.\nOffer expires <t:{}:R>.", next.name, rod.name, quote.tin_cost, quote.scrap_cost, next.power_bonus - previous.power_bonus, (next.luck_bp - previous.luck_bp) / 100, (next.xp_bonus_bp - previous.xp_bonus_bp) / 100, quote.expires_at.to_micros_since_unix_epoch() / 1_000_000))
        .allowed_mentions(serenity::CreateAllowedMentions::new())
        .components(vec![serenity::CreateActionRow::Buttons(vec![serenity::CreateButton::new(&confirm_id).label("Craft quality").style(serenity::ButtonStyle::Success), serenity::CreateButton::new(&cancel_id).label("Cancel").style(serenity::ButtonStyle::Secondary)])])).await?;
    let interaction = handle
        .message()
        .await?
        .await_component_interaction(ctx)
        .author_id(ctx.author().id)
        .timeout(std::time::Duration::from_secs(110))
        .filter(move |event| {
            event.data.custom_id == confirm_id || event.data.custom_id == cancel_id
        })
        .await;
    let Some(interaction) = interaction else {
        handle
            .edit(
                ctx,
                poise::CreateReply::default()
                    .content("Offer closed. Use /upgrade for a fresh recipe.")
                    .components(vec![]),
            )
            .await?;
        return Ok(());
    };
    interaction
        .create_response(ctx, serenity::CreateInteractionResponse::Acknowledge)
        .await?;
    let message = if interaction.data.custom_id.ends_with(":cancel") {
        "Crafting cancelled. Your materials are safe.".into()
    } else {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The workshop is busy")?;
        match ctx
            .data()
            .game
            .upgrade_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction.id.get(),
                None,
                Some(quote.nonce),
            )
            .await
        {
            Ok(committed) if committed.consumed => format!(
                "✓ **{} {} crafted!** Spent {} rusted tin and {} scrap. This rod keeps its quality when you switch gear.",
                next.name, rod.name, committed.tin_cost, committed.scrap_cost
            ),
            Ok(_) => "Crafting could not be confirmed. Check /gear before trying again.".into(),
            Err(error) => {
                tracing::warn!(%error, "Upgrade rejected");
                rejection(&error)
            }
        }
    };
    handle
        .edit(
            ctx,
            poise::CreateReply::default()
                .content(message)
                .components(vec![])
                .allowed_mentions(serenity::CreateAllowedMentions::new()),
        )
        .await?;
    Ok(())
}

/// Browse the camp trader or preview a licence, rod or bait purchase.
#[poise::command(slash_command)]
async fn shop(
    ctx: Context<'_>,
    #[description = "Item ID from the trader list"] item_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    if item_id.is_none() {
        let result = snapshot(ctx, false).await?;
        let mut listings = result.listings;
        listings.sort_by_key(|row| row.listing_id);
        let rows: Vec<_> = listings
            .iter()
            .map(|row| {
                let owned = if row.kind == "rod" {
                    result
                        .owned_rods
                        .iter()
                        .any(|rod| rod.rod_id == row.target_id)
                } else if row.kind == "licence" {
                    result
                        .licences
                        .iter()
                        .any(|licence| licence.biome_id == row.target_id)
                } else {
                    false
                };
                format!(
                    "**{}** · ID {} · {} coins · level {}{}",
                    row.name,
                    row.listing_id,
                    row.price_coins,
                    row.minimum_level,
                    if owned { " · owned" } else { "" }
                )
            })
            .collect();
        return reply(ctx, format!("**The Camp Trader** · {} coins in your pouch\n{}\nUse /shop item_id to review an offer before buying. Licences are permanent; each requires the previous licence. Bait packs contain 10 uses. Equip with /gear or /bait; travel with /biome.\n[Visit the stall]({}/#trader)", result.player.coins, rows.join("\n"), ctx.data().website.trim_end_matches('/'))).await;
    }
    let quote = {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The trader is busy")?;
        ctx.data()
            .game
            .shop_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction_id(ctx)?,
                item_id,
                None,
            )
            .await?
    };
    let result = snapshot(ctx, false).await?;
    let listing = result
        .listings
        .iter()
        .find(|row| row.listing_id == quote.listing_id)
        .ok_or("Missing trader offer")?;
    let details = if listing.kind == "rod" {
        let (_, power, luck, xp) = rod_stats(&result, listing.target_id);
        let (_, old_power, old_luck, old_xp) = rod_stats(&result, result.player.equipped_rod_id);
        format!(
            "Power {} · {:.1}% bonus pull · +{}% luck · +{}% XP\nVs equipped: {:+} power · {:+}% luck · {:+}% XP.",
            power,
            power as f64 / 2.0,
            luck / 100,
            xp / 100,
            i64::from(power) - i64::from(old_power),
            (i64::from(luck) - i64::from(old_luck)) / 100,
            (i64::from(xp) - i64::from(old_xp)) / 100
        )
    } else if listing.kind == "bait" {
        let bait = result
            .baits
            .iter()
            .find(|row| row.bait_id == listing.target_id)
            .ok_or("Missing bait")?;
        if bait.xp_bonus_bp > 0 {
            format!(
                "10 uses · +{}% fishing XP. Equip with /bait.",
                bait.xp_bonus_bp / 100
            )
        } else if bait.resource_item.is_empty() {
            format!("10 uses · +{}% luck. Equip with /bait.", bait.luck_bp / 100)
        } else {
            format!(
                "10 uses · +1 {} per cast. Equip with /bait.",
                bait.resource_item.replace('_', " ")
            )
        }
    } else {
        "A permanent licence; travel separately with /biome.".into()
    };
    let confirm_id = format!("shop:{}:confirm", interaction_id(ctx)?);
    let cancel_id = format!("shop:{}:cancel", interaction_id(ctx)?);
    let handle = send_reply(
        ctx,
        poise::CreateReply::default()
            .content(format!(
                "**Buy {} for {} coins?**\nYour pouch: {} coins. {} Offer expires <t:{}:R>.",
                listing.name,
                quote.quoted_coins,
                result.player.coins,
                details,
                quote.expires_at.to_micros_since_unix_epoch() / 1_000_000
            ))
            .allowed_mentions(serenity::CreateAllowedMentions::new())
            .components(vec![serenity::CreateActionRow::Buttons(vec![
                serenity::CreateButton::new(&confirm_id)
                    .label(format!("Buy · {} coins", quote.quoted_coins))
                    .style(serenity::ButtonStyle::Success),
                serenity::CreateButton::new(&cancel_id)
                    .label("Cancel")
                    .style(serenity::ButtonStyle::Secondary),
            ])]),
    )
    .await?;
    let interaction = handle
        .message()
        .await?
        .await_component_interaction(ctx)
        .author_id(ctx.author().id)
        .timeout(std::time::Duration::from_secs(110))
        .filter(move |event| {
            event.data.custom_id == confirm_id || event.data.custom_id == cancel_id
        })
        .await;
    let Some(interaction) = interaction else {
        handle
            .edit(
                ctx,
                poise::CreateReply::default()
                    .content("Offer closed. Use /shop for a fresh offer.")
                    .components(vec![]),
            )
            .await?;
        return Ok(());
    };
    interaction
        .create_response(ctx, serenity::CreateInteractionResponse::Acknowledge)
        .await?;
    let message = if interaction.data.custom_id.ends_with(":cancel") {
        "Purchase cancelled. Your coins are safe.".into()
    } else {
        let _permit = ctx
            .data()
            .pending
            .try_acquire()
            .map_err(|_| "The trader is busy")?;
        match ctx
            .data()
            .game
            .shop_action(
                ctx.author().id.get(),
                ctx.author().name.clone(),
                interaction.id.get(),
                None,
                Some(quote.nonce),
            )
            .await
        {
            Ok(committed) if committed.consumed => format!(
                "✓ **{} is yours!** Paid {} coins. Use /gear for rods, /bait for bait and /biome to travel.",
                listing.name, committed.quoted_coins
            ),
            Ok(_) => "The purchase could not be confirmed. Check /shop before trying again.".into(),
            Err(error) => {
                tracing::warn!(%error, "Trader purchase rejected");
                rejection(&error)
            }
        }
    };
    handle
        .edit(
            ctx,
            poise::CreateReply::default()
                .content(message)
                .components(vec![])
                .allowed_mentions(serenity::CreateAllowedMentions::new()),
        )
        .await?;
    Ok(())
}

/// Learn the commands and link your companion website.
#[poise::command(slash_command)]
async fn help(ctx: Context<'_>) -> Result<(), Error> {
    acknowledge(ctx, true).await?;
    let mut result = snapshot(ctx, false).await?;
    result.rods.sort_by_key(|rod| rod.rod_id);
    result.baits.sort_by_key(|bait| bait.bait_id);
    result.listings.sort_by_key(|listing| listing.listing_id);
    let rods = result
        .rods
        .iter()
        .map(|rod| format!("`{}` — {}", rod.rod_id, rod.name))
        .collect::<Vec<_>>()
        .join("\n");
    let baits = result
        .baits
        .iter()
        .map(|bait| format!("`{}` — {}", bait.bait_id, bait.name))
        .collect::<Vec<_>>()
        .join("\n");
    let listings = result
        .listings
        .iter()
        .map(|listing| format!("`{}` — {}", listing.listing_id, listing.name))
        .collect::<Vec<_>>()
        .join("\n");
    let commands = format!(
        "/fish — cast every 60 seconds\n/daily — Dockside Delivery and stamp bonus\n/profile [player_id] — your progression or a public angler profile\n/inventory — catches and IDs\n/sell [catch_ids] — preview and confirm fish sales\n/leaderboard [category] [page] [species_id] — standings or species records\n/collection — discoveries by biome\n/biome [biome_id] — destinations and travel\n/gear [rod_id] — equip owned rods\n/rod [rod_id] — show off an owned rod in the channel\n/shop [item_id] — licences, rods and bait packs\n/bait [bait_id] — equip bait; 0 removes bait\n/upgrade [rod_id] — craft permanent rod quality\n/help — this guide\n\n251 species across seven biomes. Link Discord on [the website]({}) to see your catches live, travel, equip rods, favorite catches, confirm sales, and choose earned titles in Achievements. Selling keeps discoveries and records. Ordinary ranks F through UUR come from species-relative length and weight; both must meet the minimum. Fihs is UUR-only; the Sock F-only. Both are bonus discoveries.",
        ctx.data().website
    );
    send_reply(ctx,
        poise::CreateReply::default()
            .embed(
                serenity::CreateEmbed::new()
                    .title("Fishbound command guide")
                    .description(commands)
                    .field(
                        "/gear, /rod and /upgrade · rod_id",
                        format!("{rods}\nUse an owned rod's ID. /upgrade without an ID upgrades your equipped rod."),
                        false,
                    )
                    .field("/bait · bait_id", format!("`0` — Remove bait\n{baits}"), false)
                    .field("/shop · item_id", listings, false),
            )
            .allowed_mentions(serenity::CreateAllowedMentions::new()),
    )
    .await?;
    Ok(())
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
    let mut guilds: Vec<u64> = std::env::var("DISCORD_GUILD_IDS")
        .unwrap_or_default()
        .split(',')
        .map(str::trim)
        .filter(|id| !id.is_empty())
        .map(str::parse)
        .collect::<Result<_, _>>()?;
    if let Some(guild) = std::env::var("DISCORD_GUILD_ID")
        .ok()
        .filter(|id| !id.trim().is_empty())
        .map(|id| id.trim().parse::<u64>())
        .transpose()?
    {
        guilds.push(guild);
    }
    guilds.sort_unstable();
    guilds.dedup();
    let framework = poise::Framework::builder()
        .options(poise::FrameworkOptions {
            commands: vec![
                fish(),
                daily(),
                shop(),
                profile(),
                inventory(),
                sell(),
                leaderboard(),
                collection(),
                biome(),
                gear(),
                rod(),
                bait(),
                upgrade(),
                help(),
            ],
            pre_command: |ctx| Box::pin(async move {
                ctx.set_invocation_data(Instant::now()).await;
                ctx.data().achievement_notifications.record_channel(ctx.author().id.get(), ctx.channel_id().get(), ctx.data().game.known_player_id(ctx.author().id.get()));
                tracing::info!(command = %ctx.command().name, interaction_id = interaction_id(ctx).ok(), "Discord command received");
            }),
            post_command: |ctx| Box::pin(async move {
                ctx.data().achievement_notifications.remember_player(ctx.author().id.get(), ctx.data().game.known_player_id(ctx.author().id.get())).await;
                let started = ctx.invocation_data::<Instant>().await.map(|started| *started);
                if let Some(started) = started {
                    log_timing(ctx, "complete", started, true).await;
                }
            }),
            on_error: |error| {
                Box::pin(async move {
                    if let poise::FrameworkError::Command { ctx, error, .. } = error {
                        let started = ctx.invocation_data::<Instant>().await.map(|started| *started);
                        if let Some(started) = started {
                            log_timing(ctx, "failed", started, false).await;
                        }
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
                    poise::builtins::register_globally(ctx, &framework.options().commands).await?;
                    let global_commands = serenity::Command::get_global_commands(ctx).await?;
                    for guild in guilds {
                        let guild_id = serenity::GuildId::new(guild);
                        for command in guild_id.get_commands(ctx).await? {
                            if global_commands.iter().any(|global| global.name == command.name && global.kind == command.kind) {
                                guild_id.delete_command(ctx, command.id).await?;
                                tracing::info!(guild, command = %command.name, "Removed duplicate server command");
                            }
                        }
                    }
                }
                tracing::info!("Discord adapter ready");
                let achievement_notifications = Arc::new(AchievementNotifications::load(required("SPACETIMEDB_DATABASE")?, ".local/achievement-notification-routes.json".into()));
                achievement_notifications.start(game.clone(), ctx.http.clone(), website.clone());
                Ok(Data {
                    game,
                    website,
                    pending: tokio::sync::Semaphore::new(32),
                    catch_art: CatchArt::new(assets),
                    achievement_notifications,
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
