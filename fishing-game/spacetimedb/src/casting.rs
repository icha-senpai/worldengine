use crate::{
    accounts::{config, ensure_player, require_service},
    tables::*,
};
use game_rules::{
    measurements::{SpeciesMeasurements, classify_rarity, generate_in_size_band},
    progression::{check_interaction_freshness, level_for_xp, record_wins},
    sampling::sample,
};
use spacetimedb::{ReducerContext, Table, TimeDuration, rand::Rng};

#[spacetimedb::reducer]
pub fn fish_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    guild_id: Option<u64>,
    channel_id: u64,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    if channel_id == 0 {
        return Err("INVALID_CHANNEL".into());
    }
    if ctx
        .db
        .daily_receipt()
        .interaction_id()
        .find(interaction_id)
        .is_some()
    {
        return Err("REQUEST_CONFLICT".into());
    }
    if let Some(receipt) = ctx
        .db
        .command_receipt()
        .interaction_id()
        .find(interaction_id)
    {
        if receipt.discord_user_id != discord_user_id
            || receipt.guild_id != guild_id
            || receipt.channel_id != channel_id
            || receipt.command != "fish"
        {
            return Err("REQUEST_CONFLICT".into());
        }
        return Ok(());
    }
    check_interaction_freshness(interaction_id, ctx.timestamp.to_micros_since_unix_epoch())?;
    let mut player = ensure_player(ctx, discord_user_id, "Angler")?;
    let rules = config(ctx);
    if ctx.timestamp < player.next_cast_at {
        return Err(format!(
            "COOLDOWN_ACTIVE:{}",
            player.next_cast_at.to_micros_since_unix_epoch()
        ));
    }
    let biome = ctx
        .db
        .biome_definition()
        .biome_id()
        .find(player.selected_biome_id)
        .ok_or("BIOME_UNAVAILABLE")?;
    if !crate::shop::owns_licence(ctx, player.player_id, biome.biome_id) {
        return Err("BIOME_LICENCE_REQUIRED".into());
    }
    let rod_key = u128::from(player.player_id) << 32 | u128::from(player.equipped_rod_id);
    let owned = ctx
        .db
        .owned_rod()
        .key()
        .find(rod_key)
        .ok_or("ROD_NOT_OWNED")?;
    let rod = ctx
        .db
        .rod_definition()
        .rod_id()
        .find(player.equipped_rod_id)
        .ok_or("ROD_UNAVAILABLE")?;
    let bonuses = ctx.db.rod_bonuses().rod_id().find(rod.rod_id);
    let quality = ctx
        .db
        .quality_definition()
        .quality_level()
        .find(owned.upgrade_level)
        .ok_or("QUALITY_UNAVAILABLE")?;
    if level_for_xp(player.total_xp, rules.level_cap) < biome.minimum_level {
        return Err("BIOME_LOCKED".into());
    }
    if level_for_xp(player.total_xp, rules.level_cap) < rod.minimum_level {
        return Err("ROD_LOCKED".into());
    }
    let species: Vec<_> = ctx
        .db
        .species_definition()
        .biome_id()
        .filter(player.selected_biome_id)
        .collect();
    if species.is_empty() {
        return Err("CONTENT_UNAVAILABLE".into());
    }
    let bait = crate::crafting::consume_bait(ctx, player.player_id)?;
    let power = rod
        .power
        .checked_add(quality.power_bonus)
        .ok_or("INVALID_ROD_POWER")?;
    let luck_bp = bonuses
        .as_ref()
        .map_or(0, |row| row.luck_bp)
        .checked_add(quality.luck_bp)
        .and_then(|v| v.checked_add(bait.luck_bp))
        .ok_or("INVALID_ROD_LUCK")?;
    let xp_bonus_bp = bonuses
        .as_ref()
        .map_or(0, |row| row.xp_bonus_bp)
        .checked_add(quality.xp_bonus_bp)
        .and_then(|v| v.checked_add(bait.xp_bonus_bp))
        .ok_or("INVALID_ROD_XP")?;
    let mut rng = ctx.rng();
    let pull_count = if rng.gen_range(0..10000u32) < game_rules::rods::bonus_pull_bp(power)? {
        2
    } else {
        1
    };
    let mut changed_record_holders = Vec::new();
    let mut total_base_xp = 0u64;
    let mut total_coins = 0u64;
    for pull in 0..pull_count {
        let category = sample(
            &[biome.fish_weight, biome.junk_weight, biome.treasure_weight],
            &mut rng,
        )?;
        let mut receipt = CommandReceipt {
            interaction_id,
            player_id: player.player_id,
            discord_user_id,
            guild_id,
            channel_id,
            command: "fish".into(),
            caught_at: ctx.timestamp,
            outcome: String::new(),
            catch_id: None,
            species_id: None,
            rarity: String::new(),
            length_mm: 0,
            weight_g: 0,
            size_grade: 0,
            xp_granted: 0,
            coins_granted: 0,
            sale_value_coins: 0,
            rules_version: rules.version,
            content_version: crate::content::CONTENT_VERSION,
            item_granted: String::new(),
            item_quantity: 0,
            biome_id: biome.biome_id,
            rod_id: rod.rod_id,
        };
        match category {
            0 => {
                let bands: Vec<_> = ctx
                    .db
                    .species_rank_definition()
                    .biome_id()
                    .filter(biome.biome_id)
                    .collect();
                let tickets: Vec<_> = bands
                    .iter()
                    .map(|band| {
                        let definition = species
                            .iter()
                            .find(|row| row.species_id == band.species_id)
                            .ok_or("CONTENT_UNAVAILABLE")?;
                        Ok((
                            band.encounter_weight,
                            if definition.key == "nidalees-lost-sock" {
                                9
                            } else {
                                band.ordinal
                            },
                        ))
                    })
                    .collect::<Result<_, String>>()?;
                let weights = game_rules::rods::luck_weights(&tickets, luck_bp)?;
                let band = &bands[sample(&weights, &mut rng)?];
                let definition = species
                    .iter()
                    .find(|row| row.species_id == band.species_id)
                    .ok_or("CONTENT_UNAVAILABLE")?;
                let model = SpeciesMeasurements {
                    typical_length_mm: definition.typical_length_mm,
                    min_length_mm: definition.min_length_mm,
                    max_length_mm: definition.max_length_mm,
                    typical_weight_g: definition.typical_weight_g,
                    min_weight_g: definition.min_weight_g,
                    max_weight_g: definition.max_weight_g,
                };
                let measurements = generate_in_size_band(model, band.ordinal, &mut rng)?;
                let ordinal = classify_rarity(model, measurements)?;
                let rank = ctx
                    .db
                    .species_rank_definition()
                    .key()
                    .find(u64::from(definition.species_id) * 10 + u64::from(ordinal))
                    .ok_or("CONTENT_UNAVAILABLE")?;
                if !definition.allowed_rarities.contains(&rank.rarity) {
                    return Err("CONTENT_UNAVAILABLE".into());
                }
                let ratio = (u64::from(measurements.length_mm) * 1_000_000
                    / u64::from(definition.typical_length_mm))
                .clamp(600_000, 2_000_000);
                let sale_value =
                    rank.base_value.checked_mul(ratio).ok_or("VALUE_OVERFLOW")? / 1_000_000;
                let bonus_bp = [0u64, 0, 0, 1000, 2000, 2500][measurements.size_grade as usize];
                let mut xp = rank
                    .base_xp
                    .checked_mul(10_000 + bonus_bp)
                    .ok_or("XP_OVERFLOW")?
                    / 10_000;
                let specimen = ctx.db.owned_specimen().insert(OwnedSpecimen {
                    catch_id: 0,
                    player_id: player.player_id,
                    species_id: definition.species_id,
                    rarity: rank.rarity.clone(),
                    length_mm: measurements.length_mm,
                    weight_g: measurements.weight_g,
                    size_grade: measurements.size_grade,
                    caught_at: ctx.timestamp,
                    sale_value_coins: sale_value,
                    favorite: false,
                    rules_version: rules.version,
                    content_version: crate::content::CONTENT_VERSION,
                    biome_id: biome.biome_id,
                    source_guild_id: guild_id,
                });
                for (metric_id, metric, measurement) in [
                    (0u64, "length", u64::from(measurements.length_mm)),
                    (1, "weight", measurements.weight_g),
                ] {
                    let key = u64::from(definition.species_id) * 2 + metric_id;
                    let previous = ctx.db.species_record().key().find(key);
                    if previous.as_ref().is_none_or(|old| {
                        record_wins(
                            measurement,
                            ctx.timestamp.to_micros_since_unix_epoch(),
                            specimen.catch_id,
                            (
                                old.measurement,
                                old.caught_at.to_micros_since_unix_epoch(),
                                old.catch_id,
                            ),
                        )
                    }) {
                        let record = SpeciesRecord {
                            key,
                            species_id: definition.species_id,
                            rarity: rank.rarity.clone(),
                            metric: metric.into(),
                            measurement,
                            player_id: player.player_id,
                            display_name: player.display_name.clone(),
                            catch_id: specimen.catch_id,
                            caught_at: ctx.timestamp,
                            rules_version: rules.version,
                            content_version: crate::content::CONTENT_VERSION,
                        };
                        if let Some(previous) = previous {
                            changed_record_holders.push(previous.player_id);
                            ctx.db.species_record().key().update(record);
                        } else {
                            ctx.db.species_record().insert(record);
                        }
                    }
                }
                let key = (u128::from(player.player_id) << 32) | u128::from(definition.species_id);
                if let Some(mut progress) = ctx.db.player_species_progress().key().find(key) {
                    progress.count = progress.count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                    let rank_count = progress
                        .rank_counts
                        .get_mut(usize::from(rank.ordinal))
                        .ok_or("CONTENT_UNAVAILABLE")?;
                    *rank_count = rank_count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                    progress.best_length_mm = progress.best_length_mm.max(measurements.length_mm);
                    progress.best_weight_g = progress.best_weight_g.max(measurements.weight_g);
                    ctx.db.player_species_progress().key().update(progress);
                } else {
                    xp = xp
                        .checked_add(definition.discovery_xp)
                        .ok_or("XP_OVERFLOW")?;
                    let mut rank_counts = vec![0; game_rules::RARITY_TIERS.len()];
                    rank_counts[usize::from(rank.ordinal)] = 1;
                    ctx.db
                        .player_species_progress()
                        .insert(PlayerSpeciesProgress {
                            key,
                            player_id: player.player_id,
                            species_id: definition.species_id,
                            count: 1,
                            rank_counts,
                            first_caught_at: ctx.timestamp,
                            best_length_mm: measurements.length_mm,
                            best_weight_g: measurements.weight_g,
                        });
                    let mut profile = ctx
                        .db
                        .public_profile()
                        .player_id()
                        .find(player.player_id)
                        .ok_or("PROFILE_MISSING")?;
                    if definition.counts_for_ordinary_collection_completion {
                        profile.discoveries =
                            profile.discoveries.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                    }
                    ctx.db.public_profile().player_id().update(profile);
                }
                player.kept_count = player.kept_count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                player.fish_count = player.fish_count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                receipt.outcome = "fish".into();
                receipt.catch_id = Some(specimen.catch_id);
                receipt.species_id = Some(definition.species_id);
                receipt.rarity = rank.rarity.clone();
                receipt.length_mm = measurements.length_mm;
                receipt.weight_g = measurements.weight_g;
                receipt.size_grade = measurements.size_grade;
                receipt.sale_value_coins = sale_value;
                receipt.xp_granted = xp;
            }
            1 => {
                player.junk_count = player.junk_count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                receipt.outcome = "junk".into();
                receipt.xp_granted = 2;
                receipt.item_granted = "rusted_tin".into();
                receipt.item_quantity = 1;
                crate::inventory::grant_item(
                    ctx,
                    player.player_id,
                    2,
                    "rusted_tin",
                    1,
                    format!("discord:{interaction_id}"),
                )?;
            }
            _ => {
                player.treasure_count = player
                    .treasure_count
                    .checked_add(1)
                    .ok_or("COUNT_OVERFLOW")?;
                receipt.outcome = "treasure".into();
                receipt.xp_granted = 8;
                receipt.coins_granted = rng.gen_range(25..=60);
                receipt.item_granted = "scrap".into();
                receipt.item_quantity = rng.gen_range(1..=3);
                crate::inventory::grant_item(
                    ctx,
                    player.player_id,
                    1,
                    "scrap",
                    receipt.item_quantity,
                    format!("discord:{interaction_id}"),
                )?;
            }
        }
        total_base_xp = total_base_xp
            .checked_add(receipt.xp_granted)
            .ok_or("XP_OVERFLOW")?;
        total_coins = total_coins
            .checked_add(receipt.coins_granted)
            .ok_or("COIN_OVERFLOW")?;
        ctx.db.cast_pull().insert(CastPull {
            key: u128::from(interaction_id) << 8 | u128::from(pull as u8),
            interaction_id,
            caught_at: ctx.timestamp,
            player_id: player.player_id,
            base_xp: receipt.xp_granted,
            receipt,
        });
    }
    let total_xp = game_rules::rods::fishing_xp(total_base_xp, xp_bonus_bp)?;
    let mut allocated_xp = 0u64;
    let mut pulls: Vec<_> = ctx
        .db
        .cast_pull()
        .interaction_id()
        .filter(interaction_id)
        .collect();
    pulls.sort_by_key(|row| row.key);
    for (index, mut pull) in pulls.into_iter().enumerate() {
        pull.receipt.xp_granted = if index + 1 == pull_count {
            total_xp - allocated_xp
        } else {
            game_rules::rods::fishing_xp(pull.base_xp, xp_bonus_bp)?
        };
        allocated_xp += pull.receipt.xp_granted;
        crate::journal::save_pull(ctx, &pull);
        ctx.db.recent_catch().insert(RecentCatch {
            recent_id: 0,
            player_id: player.player_id,
            caught_at: ctx.timestamp,
            outcome: pull.receipt.outcome.clone(),
            species_id: pull.receipt.species_id,
            rarity: pull.receipt.rarity.clone(),
            length_mm: pull.receipt.length_mm,
            weight_g: pull.receipt.weight_g,
            size_grade: pull.receipt.size_grade,
            xp_granted: pull.receipt.xp_granted,
        });
        ctx.db.cast_pull().key().update(pull);
    }
    if !bait.resource_item.is_empty() {
        let slot = if bait.resource_item == "rusted_tin" {
            2
        } else {
            1
        };
        crate::inventory::grant_item(
            ctx,
            player.player_id,
            slot,
            &bait.resource_item,
            1,
            format!("discord:{interaction_id}:bait"),
        )?;
    }
    ctx.db
        .cast_equipment_receipt()
        .insert(CastEquipmentReceipt {
            interaction_id,
            caught_at: ctx.timestamp,
            player_id: player.player_id,
            quality_level: quality.quality_level,
            power,
            luck_bp,
            xp_bonus_bp,
            bait_id: bait.bait_id,
            bait_uses_left: bait.uses_left,
            bait_item: bait.resource_item,
        });
    let mut receipt = ctx
        .db
        .cast_pull()
        .key()
        .find(u128::from(interaction_id) << 8)
        .ok_or("RECEIPT_MISSING")?
        .receipt;
    receipt.xp_granted = total_xp;
    receipt.coins_granted = total_coins;
    player.coins = player
        .coins
        .checked_add(receipt.coins_granted)
        .ok_or("COIN_OVERFLOW")?;
    if receipt.coins_granted > 0 {
        crate::inventory::ledger(
            ctx,
            player.player_id,
            format!("discord:{interaction_id}"),
            "coins",
            i64::try_from(receipt.coins_granted).map_err(|_| "COIN_OVERFLOW")?,
            "treasure",
        );
    }
    player.total_xp = player
        .total_xp
        .checked_add(receipt.xp_granted)
        .ok_or("XP_OVERFLOW")?;
    player.completed_casts = player
        .completed_casts
        .checked_add(1)
        .ok_or("COUNT_OVERFLOW")?;
    player.next_cast_at = ctx.timestamp
        + TimeDuration::from_micros(i64::from(rules.cast_cooldown_seconds) * 1_000_000);
    let mut profile = ctx
        .db
        .public_profile()
        .player_id()
        .find(player.player_id)
        .ok_or("PROFILE_MISSING")?;
    profile.level = level_for_xp(player.total_xp, rules.level_cap);
    profile.fish_count = player.fish_count;
    ctx.db.public_profile().player_id().update(profile);
    let mut history: Vec<_> = ctx
        .db
        .recent_catch()
        .player_id()
        .filter(player.player_id)
        .collect();
    history.sort_by_key(|row| row.recent_id);
    let overflow = history.len().saturating_sub(rules.recent_limit as usize);
    for old in history.into_iter().take(overflow) {
        ctx.db.recent_catch().recent_id().delete(old.recent_id);
    }
    changed_record_holders.push(player.player_id);
    ctx.db.player().player_id().update(player);
    changed_record_holders.sort_unstable();
    changed_record_holders.dedup();
    for player_id in changed_record_holders {
        crate::records::refresh_player(ctx, player_id)?;
    }
    ctx.db.command_receipt().insert(receipt);
    Ok(())
}
