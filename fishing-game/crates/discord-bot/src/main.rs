use discord_bot::achievement_notifications::AchievementNotifications;
use discord_bot::catch_art::{CatchArt, Material, ROD_QUALITY_COLORS, rank_color};
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
    if result.is_ok() {
        let user = ctx.author();
        if let Err(error) = ctx
            .data()
            .game
            .sync_portrait(
                user.id.get(),
                user.avatar.map(|hash| hash.to_string()),
                user.discriminator.map_or(0, |value| value.get()),
            )
            .await
        {
            tracing::warn!(%error, "Discord portrait refresh unavailable");
        }
    }
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

fn log_fish_again_timing(interaction_id: u64, stage: &str, started: Instant, success: bool) {
    tracing::info!(
        interaction_id,
        stage,
        elapsed_ms = started.elapsed().as_millis() as u64,
        success,
        "Fish again stage timing"
    );
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
    if message.contains("INVALID_BAIT_SLOT") {
        return "Choose bait slot 1 or 2.".into();
    }
    if message.contains("MULTI_BAIT_UNAVAILABLE") {
        return "The second bait slot is awaiting the game update.".into();
    }
    if message.contains("COLLECTION_FOCUS_LOCKED") {
        return "Discover every ordinary species in that biome to unlock Collection Focus. Fihs and the Sock are bonus discoveries.".into();
    }
    if message.contains("COLLECTION_FOCUS_UNAVAILABLE") {
        return "Collection Focus is awaiting the game update.".into();
    }
    if message.contains("BIOME_LICENCE_REQUIRED") || message.contains("PREVIOUS_LICENCE_REQUIRED") {
        return "Visit the website Trader for biome licences. Buy each water's licence before moving on.".into();
    }
    if message.contains("ROD_NOT_OWNED") {
        return "Buy and equip rods on the website.".into();
    }
    if message.contains("INSUFFICIENT_COINS") {
        return "You need more coins for this offer. Sell catches on the website or collect /daily.".into();
    }
    if message.contains("SHOP_ITEM_UNAVAILABLE") {
        return "That item ID is unavailable. Open the website Trader to see available offers."
            .into();
    }
    if message.contains("ALREADY_OWNED") {
        return "You already own this item.".into();
    }
    if message.contains("SHOP_LEVEL_REQUIRED") {
        return "Reach the item's required angler level before buying.".into();
    }
    if message.contains("ACTION_EXPIRED") || message.contains("QUOTE_CHANGED") {
        return "That offer expired or changed. Review a fresh offer on the website.".into();
    }
    if message.contains("BIOME_LEVEL_REQUIRED")
        || message.contains("BIOME_LOCKED")
        || message.contains("ROD_LOCKED")
    {
        return "That biome or rod needs a higher angler level. See unlock levels on the website World map and Tackle Box.".into();
    }
    if message.contains("INSUFFICIENT_MATERIALS") {
        return "You need more rusted tin and scrap. Resource bait from the website Trader helps; your next recipe is in the website Tackle Box.".into();
    }
    if message.contains("MAX_QUALITY") {
        return "That rod is already Prismatic.".into();
    }
    if message.contains("BAIT_NOT_OWNED") {
        return "This slot needs stocked bait from the website Trader. Matching bait in both slots requires two shared uses.".into();
    }
    if message.contains("BIOME_UNAVAILABLE") || message.contains("ROD_UNAVAILABLE") {
        return "That ID is unavailable. Choose your water and equipment on the website.".into();
    }
    if message.contains("FAVORITE_PROTECTED") {
        return "Favorites are protected. Unfavorite that catch on the website before selling."
            .into();
    }
    if message.contains("CATCH_UNAVAILABLE") || message.contains("CATCH_NOT_OWNED") {
        return "A selected catch is unavailable or belongs to another angler. Review your catches and prepare a new sale on the website.".into();
    }
    if message.contains("DUPLICATE_CATCH") || message.contains("BATCH_INVALID") {
        return "Choose your catches on the website.".into();
    }
    if message.contains("ACTION_CONFLICT") || message.contains("ACTION_NOT_FOUND") {
        return "That preview was replaced or is unavailable. Open the website for a fresh preview.".into();
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
    let response = catch_reply(ctx.data(), &result, None).await?;
    match send_reply(ctx, response.rich).await {
        Ok(_) => Ok(()),
        Err(error) => {
            tracing::warn!(%error, "Catch card delivery failed");
            send_reply(ctx, response.fallback).await?;
            Ok(())
        }
    }
}

/// Send your boat on a ten-minute trip for ten catches. Fish normally meanwhile.
#[poise::command(slash_command)]
async fn fishboat(ctx: Context<'_>) -> Result<(), Error> {
    start_haul(ctx, "boat").await
}

/// Cast a net for four minutes and four catches. No bait required.
#[poise::command(slash_command)]
async fn fishnet(ctx: Context<'_>) -> Result<(), Error> {
    start_haul(ctx, "net").await
}

async fn start_haul(ctx: Context<'_>, kind: &str) -> Result<(), Error> {
    acknowledge(ctx, false).await?;
    let _permit = match ctx.data().pending.try_acquire() {
        Ok(permit) => permit,
        Err(_) => return reply(ctx, "The pond is busy; try again shortly.").await,
    };
    match ctx
        .data()
        .game
        .start_haul(
            ctx.author().id.get(),
            ctx.author().name.clone(),
            interaction_id(ctx)?,
            ctx.guild_id().map(|id| id.get()),
            ctx.channel_id().get(),
            kind.into(),
        )
        .await
    {
        Ok(trip) => reply(ctx, discord_bot::haul_again::status(&trip)?).await,
        Err(error) => {
            tracing::warn!(%error, kind, "Haul request rejected");
            reply(ctx, rejection(&error)).await
        }
    }
}

struct CatchReply {
    rich: poise::CreateReply,
    fallback: poise::CreateReply,
}

async fn catch_reply(
    data: &Data,
    result: &Snapshot,
    clicking_user_id: Option<u64>,
) -> Result<CatchReply, Error> {
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
                let perfect =
                    discord_bot::perfect_catch::card(species, pull, &result.player.display_name);
                let is_maximum = perfect.is_some();
                let is_perfect =
                    is_maximum && pull.rarity == "UUR" && species.key != "nidalees-lost-sock";
                lines.push(format!(
                    "{} **{}** · {}",
                    if is_perfect {
                        "✦ PERFECT CATCH ·"
                    } else if is_maximum {
                        "✦ RANK MAXIMUM ·"
                    } else {
                        "🎣"
                    },
                    species.name,
                    description
                ));
                let embed = perfect.unwrap_or_else(|| {
                    serenity::CreateEmbed::new()
                        .title(&species.name)
                        .description(description)
                        .color(rank_color(&pull.rarity))
                });
                let art = if is_perfect {
                    data.catch_art
                        .render_perfect(&species.key, &pull.rarity)
                        .await
                } else if is_maximum {
                    data.catch_art
                        .render_rank_maximum(&species.key, &pull.rarity)
                        .await
                } else {
                    data.catch_art.render(&species.key, &pull.rarity).await
                };
                match art {
                    Ok(png) => {
                        let filename =
                            format!("catch-{}-{}-{}.png", index + 1, species.key, pull.rarity);
                        response = response
                            .embed(embed.image(format!("attachment://{filename}")))
                            .attachment(serenity::CreateAttachment::bytes(png, filename));
                    }
                    Err(error) => {
                        tracing::warn!(%error, "Catch artwork unavailable");
                        response = response.embed(embed);
                    }
                }
            }
            "junk" => {
                lines.push(format!("Rusted tin · +{} XP", pull.xp_granted));
                response = material_card(
                    data,
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
                    data,
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
        for (slot, id, uses, item, quantity) in [
            (
                1,
                equipment.bait_id,
                equipment.bait_uses_left,
                &equipment.bait_item,
                equipment.bait_quantity,
            ),
            (
                2,
                equipment.secondary_bait_id,
                equipment.secondary_bait_uses_left,
                &equipment.secondary_bait_item,
                equipment.secondary_bait_quantity,
            ),
        ] {
            if id == 0 {
                continue;
            }
            let name = result
                .baits
                .iter()
                .find(|bait| bait.bait_id == id)
                .map_or("Bait", |bait| bait.name.as_str());
            summary.push_str(&format!("\nSlot {slot}: {name} · {uses} shared uses left"));
            if !item.is_empty() {
                summary.push_str(&format!(
                    " · +{} {}",
                    quantity.max(1),
                    item.replace('_', " ")
                ));
            }
        }
    }
    let level = game_rules::progression::level_for_xp_for_version(
        result.player.total_xp,
        result.config.level_cap,
        result.config.version,
    );
    if level >= result.config.level_cap {
        summary.push_str(&format!("\nAngler level {} · Max level reached", level));
    } else {
        let level_start_xp: u64 = (1..level)
            .map(|level| {
                game_rules::progression::xp_to_next_for_version(level, result.config.version)
            })
            .sum();
        let remaining_xp =
            game_rules::progression::xp_to_next_for_version(level, result.config.version)
                - (result.player.total_xp - level_start_xp);
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
        data.website
    ));
    let components = discord_bot::fish_again::components();
    let fallback_content = format!("{}\n{}", lines.join("\n"), summary);
    let (summary, fallback_content) = match clicking_user_id {
        Some(user_id) => (
            discord_bot::fish_again::attributed_content(user_id, &summary),
            discord_bot::fish_again::attributed_content(user_id, &fallback_content),
        ),
        None => (summary, fallback_content),
    };
    Ok(CatchReply {
        rich: response.content(&summary).components(components.clone()),
        fallback: poise::CreateReply::default()
            .content(fallback_content)
            .components(components)
            .allowed_mentions(serenity::CreateAllowedMentions::new()),
    })
}

async fn material_card(
    data: &Data,
    response: poise::CreateReply,
    embed: serenity::CreateEmbed,
    material: Material,
    filename: String,
) -> poise::CreateReply {
    match data.catch_art.render_material(material).await {
        Ok(png) => response
            .embed(embed.image(format!("attachment://{filename}")))
            .attachment(serenity::CreateAttachment::bytes(png, filename)),
        Err(error) => {
            tracing::warn!(%error, material = material.key(), "Material artwork unavailable");
            response.embed(embed)
        }
    }
}

async fn handle_event(
    framework: poise::FrameworkContext<'_, Data, Error>,
    event: &serenity::FullEvent,
) -> Result<(), Error> {
    let serenity::FullEvent::InteractionCreate {
        interaction: serenity::Interaction::Component(interaction),
    } = event
    else {
        return Ok(());
    };
    if interaction.message.author.id != framework.bot_id() {
        return Ok(());
    }
    let custom_id = &interaction.data.custom_id;
    let is_haul = custom_id.starts_with(discord_bot::haul_again::PREFIX);
    if !is_haul && !custom_id.starts_with(discord_bot::fish_again::PREFIX) {
        return Ok(());
    }
    let result = if is_haul {
        haul_again(framework.serenity_context, interaction, framework.user_data).await
    } else {
        fish_again(framework.serenity_context, interaction, framework.user_data).await
    };
    if let Err(error) = result {
        tracing::warn!(%error, interaction_id=interaction.id.get(), "Fishing repeat button unavailable");
        let reply_started = Instant::now();
        let reply = interaction
            .create_followup(
                framework.serenity_context,
                serenity::CreateInteractionResponseFollowup::new()
                    .content(rejection(&error))
                    .ephemeral(true)
                    .allowed_mentions(serenity::CreateAllowedMentions::new()),
            )
            .await;
        log_fish_again_timing(
            interaction.id.get(),
            "send_error",
            reply_started,
            reply.is_ok(),
        );
    }
    Ok(())
}

async fn haul_again(
    ctx: &serenity::Context,
    interaction: &serenity::ComponentInteraction,
    data: &Data,
) -> Result<(), Error> {
    let (user_id, kind) = match discord_bot::haul_again::request(
        &interaction.data.custom_id,
        interaction.user.id.get(),
    ) {
        Ok(request) => request,
        Err(message) => {
            interaction
                .create_response(
                    ctx,
                    serenity::CreateInteractionResponse::Message(
                        serenity::CreateInteractionResponseMessage::new()
                            .content(message)
                            .ephemeral(true)
                            .allowed_mentions(serenity::CreateAllowedMentions::new()),
                    ),
                )
                .await?;
            return Ok(());
        }
    };
    // Keep the completed haul and its reusable button intact. Each click is a
    // new interaction; the module deduplicates retries and reports active trips.
    interaction
        .create_response(ctx, serenity::CreateInteractionResponse::Acknowledge)
        .await?;
    let trip = {
        let _permit = data
            .pending
            .try_acquire()
            .map_err(|_| "The pond is busy; try again shortly")?;
        data.achievement_notifications.record_channel(
            user_id,
            interaction.channel_id.get(),
            data.game.known_player_id(user_id),
        );
        data.game
            .start_haul(
                user_id,
                interaction.user.name.clone(),
                interaction.id.get(),
                interaction.guild_id.map(|id| id.get()),
                interaction.channel_id.get(),
                kind.into(),
            )
            .await?
    };
    data.achievement_notifications
        .remember_player(user_id, Some(trip.player_id))
        .await;
    interaction
        .create_followup(
            ctx,
            serenity::CreateInteractionResponseFollowup::new()
                .content(discord_bot::haul_again::status(&trip)?)
                .ephemeral(true)
                .allowed_mentions(serenity::CreateAllowedMentions::new()),
        )
        .await?;
    Ok(())
}

async fn fish_again(
    ctx: &serenity::Context,
    interaction: &serenity::ComponentInteraction,
    data: &Data,
) -> Result<(), Error> {
    let total_started = Instant::now();
    let user_id = match discord_bot::fish_again::caster(
        &interaction.data.custom_id,
        interaction.user.id.get(),
    ) {
        Ok(user_id) => user_id,
        Err(message) => {
            let ack_started = Instant::now();
            let ack = interaction
                .create_response(
                    ctx,
                    serenity::CreateInteractionResponse::Message(
                        serenity::CreateInteractionResponseMessage::new()
                            .content(message)
                            .ephemeral(true)
                            .allowed_mentions(serenity::CreateAllowedMentions::new()),
                    ),
                )
                .await;
            log_fish_again_timing(
                interaction.id.get(),
                "acknowledge_rejected",
                ack_started,
                ack.is_ok(),
            );
            ack?;
            return Ok(());
        }
    };
    // Acknowledge the click without editing the existing catch or its button.
    let ack_started = Instant::now();
    let ack = interaction
        .create_response(ctx, serenity::CreateInteractionResponse::Acknowledge)
        .await;
    log_fish_again_timing(
        interaction.id.get(),
        "acknowledge",
        ack_started,
        ack.is_ok(),
    );
    ack?;
    let started = Instant::now();
    data.achievement_notifications.record_channel(
        user_id,
        interaction.channel_id.get(),
        data.game.known_player_id(user_id),
    );
    let game_started = Instant::now();
    let result = {
        let _permit = data
            .pending
            .try_acquire()
            .map_err(|_| "The pond is busy; try again shortly")?;
        data.game
            .player(
                user_id,
                interaction.user.name.clone(),
                interaction.id.get(),
                interaction.guild_id.map(|id| id.get()),
                interaction.channel_id.get(),
                true,
            )
            .await
    };
    log_fish_again_timing(interaction.id.get(), "game", game_started, result.is_ok());
    let result = match result {
        Ok(result) => result,
        Err(error) => {
            tracing::warn!(%error, interaction_id=interaction.id.get(), "Fish again rejected or unavailable");
            let reply_started = Instant::now();
            let reply = interaction
                .create_followup(
                    ctx,
                    serenity::CreateInteractionResponseFollowup::new()
                        .content(rejection(&error))
                        .ephemeral(true)
                        .allowed_mentions(serenity::CreateAllowedMentions::new()),
                )
                .await;
            log_fish_again_timing(
                interaction.id.get(),
                "send_rejection",
                reply_started,
                reply.is_ok(),
            );
            reply?;
            return Ok(());
        }
    };
    data.achievement_notifications
        .remember_player(user_id, Some(result.player.player_id))
        .await;
    let portrait_started = Instant::now();
    let portrait = data
        .game
        .sync_portrait(
            user_id,
            interaction.user.avatar.map(|hash| hash.to_string()),
            interaction
                .user
                .discriminator
                .map_or(0, |value| value.get()),
        )
        .await;
    log_fish_again_timing(
        interaction.id.get(),
        "portrait",
        portrait_started,
        portrait.is_ok(),
    );
    if let Err(error) = portrait {
        tracing::warn!(%error, "Discord portrait refresh unavailable");
    }
    let render_started = Instant::now();
    let response = catch_reply(data, &result, Some(user_id)).await;
    log_fish_again_timing(
        interaction.id.get(),
        "render",
        render_started,
        response.is_ok(),
    );
    let response = response?;
    let source = serenity::MessageReference::from(interaction.message.as_ref());
    let send_started = Instant::now();
    let delivery = interaction
        .channel_id
        .send_message(
            ctx,
            discord_bot::fish_again::public_message(
                response.rich,
                source.clone(),
                interaction.id.get(),
            ),
        )
        .await;
    log_fish_again_timing(
        interaction.id.get(),
        "send_card",
        send_started,
        delivery.is_ok(),
    );
    if let Err(error) = delivery {
        tracing::warn!(%error, "Fish again catch card delivery failed");
        let text_started = Instant::now();
        let text_delivery = interaction
            .channel_id
            .send_message(
                ctx,
                discord_bot::fish_again::public_message(
                    response.fallback,
                    source,
                    interaction.id.get(),
                ),
            )
            .await;
        log_fish_again_timing(
            interaction.id.get(),
            "send_text",
            text_started,
            text_delivery.is_ok(),
        );
        if let Err(error) = text_delivery {
            tracing::warn!(%error, "Fish again text delivery failed");
            let notice_started = Instant::now();
            let notice = interaction.create_followup(
                ctx,
                serenity::CreateInteractionResponseFollowup::new()
                    .content("Your cast was saved, but I couldn't post the catch in this channel. You can see it on the website.")
                    .ephemeral(true)
                    .allowed_mentions(serenity::CreateAllowedMentions::new()),
            ).await;
            log_fish_again_timing(
                interaction.id.get(),
                "send_notice",
                notice_started,
                notice.is_ok(),
            );
            notice?;
        }
    }
    tracing::info!(
        interaction_id = interaction.id.get(),
        elapsed_ms = started.elapsed().as_millis() as u64,
        total_ms = total_started.elapsed().as_millis() as u64,
        "Fish again complete"
    );
    Ok(())
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
    reply(ctx, format!("{reward}\n{progress}\nNext delivery <t:{}:R> · resets at midnight UTC.\nMissed days keep your stamps. Visit the website for quests and weekly contracts.",
        receipt.next_delivery_at.to_micros_since_unix_epoch() / 1_000_000)).await
}

/// Share an angler's Fishbound profile with this channel.
#[poise::command(slash_command)]
async fn profile(
    ctx: Context<'_>,
    #[description = "Public angler ID from the website; omit for your own profile"]
    player_id: Option<u64>,
) -> Result<(), Error> {
    acknowledge(ctx, false).await?;
    let result = snapshot(ctx, false).await?;
    let target = player_id.unwrap_or(result.player.player_id);
    let portrait = if target == result.player.player_id {
        Some(ctx.author().face())
    } else {
        result
            .portraits
            .iter()
            .find(|row| row.player_id == target)
            .map(|row| row.avatar_url.clone())
    };
    let Some(embed) = discord_bot::profile_card::profile_embed(
        &result,
        target,
        &ctx.data().website,
        portrait.as_deref(),
    ) else {
        return reply(
            ctx,
            "That angler is not in the public book. Find anglers on the website.",
        )
        .await;
    };
    send_reply(
        ctx,
        poise::CreateReply::default()
            .embed(embed)
            .allowed_mentions(serenity::CreateAllowedMentions::new()),
    )
    .await?;
    Ok(())
}

fn plain_name(name: &str) -> String {
    name.chars()
        .filter(|c| {
            !matches!(c, '*' | '_' | '`' | '~' | '[' | ']' | '<' | '>' | '\\') && !c.is_control()
        })
        .collect()
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
            "You can only show off rods you own. Find your rods and their IDs in the website Tackle Box.",
        )
        .await;
    };
    let definition = result
        .rods
        .iter()
        .find(|row| row.rod_id == id)
        .ok_or("Missing rod")?;
    let (quality, power, luck, xp) = rod_stats(&result, id);
    let reinforcement_level = result
        .rod_reinforcements
        .iter()
        .find(|row| row.rod_id == id)
        .map_or(0, |row| row.level);
    let reinforcement = reinforcement_level
        .checked_sub(1)
        .and_then(|grade| result.raid_grades.iter().find(|row| row.grade == grade))
        .map_or_else(|| "Unreinforced".into(), |grade| format!("{}-reinforced", grade.name));
    let focus = reinforcement_level
        .checked_sub(1)
        .and_then(|grade| result.raid_grades.iter().find(|row| row.grade == grade))
        .map_or_else(
            || "Unreinforced rods keep the base missing-rank Collection Focus behavior and have no reinforcement endpoint bonus rolls.".into(),
            |grade| format!(
                "This rod provides {}% missing-rank Focus chance when Focus is enabled for a mastered biome. Its {} authored UUR endpoint bonus rolls apply only to an ordinary fish currently at UUR after all ten ranks of that species are recorded. Special species are excluded.",
                grade.focus_chance_bp / 100,
                grade.perfect_bonus_rolls,
            ),
        );
    let description = format!(
        "**{} {} {}**\n{} · shown by **{}**\n\n**{} power** · **{:.1}%** bonus-pull chance\n**+{:.1}% luck** · **+{:.1}% catch XP**\n\n**Collection Focus** · {}",
        quality,
        reinforcement,
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
        focus,
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

fn commands() -> Vec<poise::Command<Data, Error>> {
    vec![fish(), fishboat(), fishnet(), daily(), rod(), profile()]
}

fn is_fishbound_command(name: &str) -> bool {
    matches!(
        name,
        "fish"
            | "fishboat"
            | "fishnet"
            | "daily"
            | "rod"
            | "profile"
            | "raid"
            | "quests"
            | "shop"
            | "inventory"
            | "sell"
            | "leaderboard"
            | "collection"
            | "biome"
            | "gear"
            | "bait"
            | "focus"
            | "upgrade"
            | "help"
    )
}

fn required(name: &str) -> Result<String, Error> {
    std::env::var(name)
        .ok()
        .filter(|v| !v.trim().is_empty())
        .ok_or_else(|| format!("Set {name} in the service environment").into())
}

#[cfg(test)]
mod command_tests {
    use super::*;

    #[test]
    fn fishing_commands_include_independent_timed_hauls() {
        let commands = commands();
        let names: Vec<&str> = commands
            .iter()
            .map(|command| command.name.as_ref())
            .collect();
        assert_eq!(names, ["fish", "fishboat", "fishnet", "daily", "rod", "profile"]);
        assert!(commands.iter()
            .all(|command| command.slash_action.is_some() && command.subcommands.is_empty()));
    }

    #[test]
    fn retired_commands_are_cleaned_from_legacy_guilds() {
        for name in [
            "quests",
            "upgrade",
            "shop",
            "help",
            "focus",
            "gear",
            "sell",
            "bait",
            "collection",
            "biome",
            "inventory",
            "leaderboard",
            "raid",
        ] {
            assert!(is_fishbound_command(name));
        }
        assert!(!is_fishbound_command("another-app-command"));
    }
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
            event_handler: |framework, event| Box::pin(handle_event(framework, event)),
            commands: commands(),
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
                            if is_fishbound_command(&command.name) || global_commands.iter().any(|global| global.name == command.name && global.kind == command.kind) {
                                guild_id.delete_command(ctx, command.id).await?;
                                tracing::info!(guild, command = %command.name, "Removed legacy Fishbound server command");
                            }
                        }
                    }
                }
                tracing::info!("Discord adapter ready");
                let achievement_notifications = Arc::new(AchievementNotifications::load(required("SPACETIMEDB_DATABASE")?, ".local/achievement-notification-routes.json".into()));
                achievement_notifications.start(game.clone(), ctx.http.clone(), website.clone());
                discord_bot::haul_notifications::start(game.clone(), ctx.http.clone(), website.clone());
                discord_bot::raid_notifications::start(game.clone(), ctx.http.clone(), website.clone(), achievement_notifications.clone());
                Ok(Data {
                    game,
                    website,
                    pending: tokio::sync::Semaphore::new(discord_bot::MAX_PENDING_GAME_REQUESTS),
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
