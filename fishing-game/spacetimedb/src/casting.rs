use crate::hauls::haul_request;
use crate::raids::rod_reinforcement;
use crate::tideforge::{TideforgeSpecimenModifiers, tideforge_specimen_modifiers};
use crate::{
    accounts::{config, ensure_player, require_service},
    tables::*,
};
use game_rules::{
    measurements::{SpeciesMeasurements, classify_rarity},
    progression::{check_interaction_freshness, level_for_xp_for_version, record_wins},
    sampling::{sample, sample_wide},
};
use spacetimedb::{ReducerContext, Table, TimeDuration, rand::Rng};

fn grant_packet(
    ctx: &ReducerContext,
    player_id: u64,
    operation: &str,
    fillets: u32,
    scales: u32,
) -> Result<(), String> {
    for (slot, item, quantity) in [
        (game_rules::tideforge::NORMAL_FILLETS_SLOT, "normal_fillets", fillets),
        (game_rules::tideforge::NORMAL_SCALES_SLOT, "normal_scales", scales),
    ] {
        if quantity != 0 {
            crate::inventory::grant_item(ctx, player_id, slot, item, u64::from(quantity), operation.into())?;
        }
    }
    Ok(())
}

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
        .haul_request()
        .interaction_id()
        .find(interaction_id)
        .is_some()
    {
        return Err("REQUEST_CONFLICT".into());
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
    let player = ensure_player(ctx, discord_user_id, "Angler")?;
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
    let selected = crate::gear_instances::selected_instance(ctx, player.player_id, "rod")?;
    let (quality_level, owned_reinforcement) = if let Some(instance) = selected {
        (instance.quality_level, instance.reinforcement_level)
    } else {
        let rod_key = u128::from(player.player_id) << 32 | u128::from(player.equipped_rod_id);
        let owned = ctx.db.owned_rod().key().find(rod_key).ok_or("ROD_NOT_OWNED")?;
        let reinforcement = ctx.db.rod_reinforcement().key().find(owned.key).map_or(0, |row| row.level);
        (owned.upgrade_level, reinforcement)
    };
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
        .find(quality_level)
        .ok_or("QUALITY_UNAVAILABLE")?;
    let reinforcement_level =
        if rules.version >= game_rules::raids::catalog().rules_version {
        owned_reinforcement
    } else {
        0
    };
    if reinforcement_level > game_rules::raids::MAX_REINFORCEMENT_LEVEL {
        return Err("INVALID_REINFORCEMENT".into());
    }
    if level_for_xp_for_version(player.total_xp, rules.level_cap, rules.version)
        < biome.minimum_level
    {
        return Err("BIOME_LOCKED".into());
    }
    if level_for_xp_for_version(player.total_xp, rules.level_cap, rules.version) < rod.minimum_level
    {
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
    let collection_focus_enabled =
        crate::collection_focus::active(ctx, player.player_id, biome.biome_id, &species);
    let snapshot = crate::tideforge::book(
        ctx, player.player_id, "rod", "cast", u128::from(interaction_id), biome.biome_id,
    )?;
    let effects = snapshot.as_ref().map(crate::tideforge::decode).transpose()?;
    let bait = crate::crafting::consume_bait_with_preservation(
        ctx, player.player_id, effects.as_ref().map_or(0, |fx| fx.stat("baitPreserveBp")),
    )?;
    let power = rod
        .power
        .checked_add(quality.power_bonus)
        .ok_or("INVALID_ROD_POWER")?;
    let luck_bp = bonuses
        .as_ref()
        .map_or(0, |row| row.luck_bp)
        .checked_add(quality.luck_bp)
        .and_then(|v| v.checked_add(bait.luck_bp))
        .and_then(|v| v.checked_add(effects.as_ref().map_or(0, |fx| fx.stat("luckBp"))))
        .ok_or("INVALID_ROD_LUCK")?;
    let xp_bonus_bp = bonuses
        .as_ref()
        .map_or(0, |row| row.xp_bonus_bp)
        .checked_add(quality.xp_bonus_bp)
        .and_then(|v| v.checked_add(bait.xp_bonus_bp))
        .ok_or("INVALID_ROD_XP")?;
    crate::gear_instances::snapshot_selected(
        ctx, player.player_id, "rod", "cast", u128::from(interaction_id),
        Some(power), Some(luck_bp), Some(xp_bonus_bp), rules.version,
        Some(crate::content::CONTENT_VERSION), None,
    )?;
    let mut rng = ctx.rng();
    let pull_count = if let Some(fx) = &effects {
        let extra_power = fx.stat("powerCenti");
        let maximum_power_pulls = if extra_power == 0 && power <= 200 {
            1 + usize::from(power != 0)
        } else {
            let centi = u64::from(power).checked_mul(100)
                .and_then(|value| value.checked_add(u64::from(extra_power)))
                .ok_or("INVALID_ROD_POWER")?;
            1usize.checked_add(usize::try_from(centi.div_ceil(20_000)).map_err(|_| "INVALID_PULL_COUNT")?)
                .ok_or("INVALID_PULL_COUNT")?
        };
        if fx.extra_pull_bp.len() > 2
            || maximum_power_pulls.checked_add(fx.extra_pull_bp.len()).is_none_or(|count| count > 5)
        {
            return Err("INVALID_PULL_COUNT".into());
        }
        game_rules::tideforge::rod_pulls(power, fx, &mut rng)?
    } else if rng.gen_range(0..10000u32) < game_rules::rods::bonus_pull_bp(power)? {
        2
    } else {
        1
    };
    resolve(
        ctx,
        CastPlan {
            player,
            biome,
            rules,
            interaction_id,
            guild_id,
            channel_id,
            command: "fish".into(),
            equipment_id: rod.rod_id,
            quality_level: quality.quality_level,
            power,
            luck_bp,
            xp_bonus_bp,
            bait,
            pull_count,
            collection_focus_enabled,
            reinforcement_level,
            special_raid_species_id: None,
            special_raid_species_definition: None,
            special_raid_rank_definition: None,
            special_raid_band: None,
            special_raid_maximum_roll_one_in: 0,
            content_version: crate::content::CONTENT_VERSION,
        },
    )
}

/// Shared reward pipeline: hauls get the same specimens, discoveries, records,
/// quests and journal entries as rod fishing, without consuming rod bait/cooldown.
pub(crate) struct CastPlan {
    pub player: Player,
    pub biome: BiomeDefinition,
    pub rules: GameConfig,
    pub interaction_id: u64,
    pub guild_id: Option<u64>,
    pub channel_id: u64,
    pub command: String,
    pub equipment_id: u32,
    pub quality_level: u8,
    pub power: u32,
    pub luck_bp: u32,
    pub xp_bonus_bp: u32,
    pub bait: crate::crafting::UsedBaits,
    pub pull_count: usize,
    pub collection_focus_enabled: bool,
    pub reinforcement_level: u8,
    pub special_raid_species_id: Option<u32>,
    pub special_raid_species_definition: Option<SpeciesDefinition>,
    pub special_raid_rank_definition: Option<SpeciesRankDefinition>,
    pub special_raid_band: Option<u8>,
    pub special_raid_maximum_roll_one_in: u32,
    pub content_version: u32,
}

pub(crate) fn resolve(ctx: &ReducerContext, plan: CastPlan) -> Result<(), String> {
    let CastPlan {
        mut player,
        biome,
        rules,
        interaction_id,
        guild_id,
        channel_id,
        command,
        equipment_id,
        quality_level,
        power,
        luck_bp,
        xp_bonus_bp,
        bait,
        pull_count,
        collection_focus_enabled,
        reinforcement_level,
        special_raid_species_id,
        special_raid_species_definition,
        special_raid_rank_definition,
        special_raid_band,
        special_raid_maximum_roll_one_in,
        content_version,
    } = plan;
    let normal_cast = command == "fish";
    let special_raid = command == "fishraid";
    if !matches!(command.as_str(), "fish" | "fishboat" | "fishnet" | "fishraid")
        || special_raid != special_raid_species_id.is_some()
        || special_raid != special_raid_species_definition.is_some()
        || special_raid != special_raid_rank_definition.is_some()
        || special_raid != special_raid_band.is_some()
        || (special_raid
            && (pull_count != 1
                || reinforcement_level != 0
                || rules.version < game_rules::raids::catalog().rules_version
                || xp_bonus_bp != 0
                || bait.primary.bait_id != 0
                || bait.secondary.bait_id != 0
                || !bait.primary.resource_item.is_empty()
                || !bait.secondary.resource_item.is_empty()
                || special_raid_maximum_roll_one_in == 0))
        || ((!normal_cast || rules.version < game_rules::raids::catalog().rules_version)
            && reinforcement_level != 0)
        || pull_count == 0
    {
        return Err("INVALID_CAST_PLAN".into());
    }
    let expected_kind = match command.as_str() {
        "fish" => Some(("cast", "rod")),
        "fishboat" => Some(("haul", "boat")),
        "fishnet" => Some(("haul", "net")),
        _ => None,
    };
    let snapshot = if let Some((activity_kind, kind)) = expected_kind {
        let row = crate::tideforge::lookup(ctx, player.player_id, activity_kind, u128::from(interaction_id))?;
        if row.as_ref().is_some_and(|row| row.gear_kind != kind || row.biome_id != biome.biome_id) {
            return Err("TIDEFORGE_SNAPSHOT_CONFLICT".into());
        }
        row
    } else {
        None
    };
    let effects = snapshot.as_ref().map(crate::tideforge::decode).transpose()?;
    let species: Vec<_> = ctx
        .db
        .species_definition()
        .biome_id()
        .filter(biome.biome_id)
        .collect();
    if species.is_empty() && !special_raid {
        return Err("CONTENT_UNAVAILABLE".into());
    }
    if special_raid
        && special_raid_species_definition
            .as_ref()
            .is_none_or(|row| row.species_id != special_raid_species_id.unwrap_or_default())
    {
        return Err("INVALID_SPECIAL_RAID".into());
    }
    if let (Some(species_id), Some(species), Some(rank), Some(band)) = (
        special_raid_species_id,
        special_raid_species_definition.as_ref(),
        special_raid_rank_definition.as_ref(),
        special_raid_band,
    ) {
        let legal_band = match (species.key.as_str(), species_id) {
            ("fihs", 74) => 9,
            ("nidalees-lost-sock", 165) => 0,
            _ => return Err("INVALID_SPECIAL_RAID".into()),
        };
        if band != legal_band
            || rank.species_id != species_id
            || rank.ordinal != legal_band
            || !species
                .allowed_rarities
                .iter()
                .any(|rarity| rarity == &rank.rarity)
            || rank.rarity != game_rules::RARITY_TIERS[usize::from(legal_band)]
        {
            return Err("INVALID_SPECIAL_RAID".into());
        }
    }
    let mut rng = ctx.rng();
    let mut pull_count = pull_count;
    let max_base_pulls = match command.as_str() {
        "fish" => 5usize,
        "fishboat" => 12,
        "fishnet" => 6,
        _ => 1,
    };
    if let Some(fx) = &effects {
        if fx.extra_pull_bp.len() > 2
            || (!normal_cast && pull_count.checked_add(fx.extra_pull_bp.len()).is_none_or(|limit| limit > max_base_pulls))
        {
            return Err("INVALID_PULL_COUNT".into());
        }
        if !normal_cast {
            pull_count = pull_count
                .checked_add(game_rules::tideforge::haul_extra_pulls(fx, &mut rng)?)
                .ok_or("INVALID_PULL_COUNT")?;
        }
        if pull_count > max_base_pulls {
            return Err("INVALID_PULL_COUNT".into());
        }
    }
    let mut collection_focus_rerolls = 0;
    let xp_reward = |base| {
        if normal_cast {
            game_rules::rods::fishing_xp(base, xp_bonus_bp)
        } else {
            game_rules::hauls::fishing_xp(base, xp_bonus_bp)
        }
    };
    let mut changed_record_holders = Vec::new();
    let mut total_base_xp = 0u64;
    let mut total_coins = 0u64;
    let mut ocean_bonuses = Vec::new();
    let mut ordinary_seen = false;
    let mut junk_seen = false;
    let mut treasure_seen = false;
    let mut rift_pull = None;
    let mut duplicate_quota = game_rules::tideforge::DuplicateQuota::default();
    crate::statistics::observe_cast(ctx, player.player_id, biome.biome_id)?;
    let mut pull = 0usize;
    while pull < pull_count {
        let mut sets_record = false;
        let mut ocean_bonus = 0u32;
        let category = if special_raid || rift_pull == Some(pull) {
            0
        } else {
            sample(
                &[biome.fish_weight, biome.junk_weight, biome.treasure_weight],
                &mut rng,
            )?
        };
        let mut receipt = CommandReceipt {
            interaction_id,
            player_id: player.player_id,
            discord_user_id: player.discord_user_id,
            guild_id,
            channel_id,
            command: command.clone(),
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
            content_version,
            item_granted: String::new(),
            item_quantity: 0,
            biome_id: biome.biome_id,
            rod_id: equipment_id,
        };
        match category {
            0 => {
                let bands: Vec<_> = if special_raid {
                    vec![special_raid_rank_definition
                        .clone()
                        .ok_or("INVALID_SPECIAL_RAID")?]
                } else {
                    ctx.db
                        .species_rank_definition()
                        .biome_id()
                        .filter(biome.biome_id)
                        .collect()
                };
                if bands.is_empty() {
                    return Err("CONTENT_UNAVAILABLE".into());
                }
                let mut weights = Vec::new();
                let mut band_index = if special_raid {
                    let band = special_raid_band.ok_or("INVALID_SPECIAL_RAID")?;
                    if bands[0].species_id != special_raid_species_id.unwrap_or_default()
                        || bands[0].ordinal != band
                    {
                        return Err("INVALID_SPECIAL_RAID".into());
                    }
                    0
                } else {
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
                    weights = if normal_cast {
                        game_rules::rods::luck_weights(&tickets, luck_bp)?
                    } else {
                        game_rules::hauls::luck_weights(&tickets, luck_bp)?
                    };
                    if rift_pull == Some(pull) {
                        let ordinary: Vec<bool> = bands.iter().map(|band| {
                            species.iter().find(|row| row.species_id == band.species_id)
                                .is_some_and(|row| row.counts_for_ordinary_collection_completion)
                        }).collect();
                        game_rules::tideforge::sample_ordinary(&weights, &ordinary, &mut rng)?
                    } else {
                        sample_wide(&weights, &mut rng)?
                    }
                };
                if !normal_cast && !special_raid {
                    if let Some(fx) = &effects {
                        let candidate = &bands[band_index];
                        let known_ordinary = species.iter()
                            .find(|row| row.species_id == candidate.species_id)
                            .is_some_and(|row| row.counts_for_ordinary_collection_completion)
                            && ctx.db.player_species_progress().key()
                                .find((u128::from(player.player_id) << 32) | u128::from(candidate.species_id))
                                .is_some_and(|row| row.count > 0);
                        if duplicate_quota.reroll(known_ordinary, fx, &mut rng)? {
                            let ordinary: Vec<bool> = bands.iter().map(|band| {
                                species.iter().find(|row| row.species_id == band.species_id)
                                    .is_some_and(|row| row.counts_for_ordinary_collection_completion)
                            }).collect();
                            band_index = game_rules::tideforge::sample_ordinary(&weights, &ordinary, &mut rng)?;
                        }
                    }
                }
                let mut band = &bands[band_index];
                let definition = if special_raid {
                    special_raid_species_definition
                        .as_ref()
                        .ok_or("INVALID_SPECIAL_RAID")?
                } else {
                    species
                        .iter()
                        .find(|row| row.species_id == band.species_id)
                        .ok_or("CONTENT_UNAVAILABLE")?
                };
                let first_ordinary = !ordinary_seen && definition.counts_for_ordinary_collection_completion;
                let mut all_ranks_known = false;
                if !special_raid
                    && collection_focus_enabled
                    && definition.counts_for_ordinary_collection_completion
                {
                    let progress_key =
                        (u128::from(player.player_id) << 32) | u128::from(definition.species_id);
                    let progress = ctx.db.player_species_progress().key().find(progress_key);
                    let known: Vec<_> = (0..10)
                        .map(|rank| {
                            progress.as_ref().is_some_and(|row| {
                                row.rank_counts.get(rank).is_some_and(|count| *count > 0)
                            })
                        })
                        .collect();
                    all_ranks_known =
                        known.len() == 10 && known.iter().all(|rank_is_known| *rank_is_known);
                    let ranks: Vec<_> = bands
                        .iter()
                        .zip(&weights)
                        .filter(|(row, _)| row.species_id == definition.species_id)
                        .map(|(row, weight)| (row.ordinal, *weight))
                        .collect();
                    let precision = effects.as_ref().is_some_and(|fx| {
                        fx.goal != "ranks"
                            || fx.stat("rankFocusBp") != 0
                            || fx.stat("maximaFocusBp") != 0
                            || fx.stat("perfectFocusBp") != 0
                    });
                    let ordinal = if normal_cast && precision {
                        let mut rank_weights = [0u128; 10];
                        for &(ordinal, weight) in &ranks {
                            let entry = rank_weights.get_mut(usize::from(ordinal)).ok_or("CONTENT_UNAVAILABLE")?;
                            *entry = weight;
                        }
                        let known_mask = known.iter().enumerate().fold(
                            0u16, |mask, (index, present)| if *present { mask | (1u16 << index) } else { mask },
                        );
                        let mut maxima_mask = 0u16;
                        for ordinal in 0..10u8 {
                            let key = (u128::from(player.player_id) << 64)
                                | (u128::from(definition.species_id) << 8)
                                | u128::from(ordinal);
                            if ctx.db.rank_maximum().key().find(key).is_some() {
                                maxima_mask |= 1u16 << ordinal;
                            }
                        }
                        let perfect_known = ctx.db.perfect_species().key().find(progress_key).is_some();
                        let base_focus = if rules.version >= game_rules::raids::catalog().rules_version
                            && reinforcement_level > 0
                        {
                            game_rules::raids::focus_chance_bp(reinforcement_level)?
                        } else {
                            game_rules::collection_focus::CHANCE_BP
                        };
                        game_rules::tideforge::steer_rank(
                            usize::from(band.ordinal),
                            &rank_weights,
                            known_mask,
                            maxima_mask,
                            perfect_known,
                            progress.as_ref().is_some_and(|row| row.count > 0),
                            base_focus,
                            effects.as_ref().ok_or("TIDEFORGE_EFFECTS_INVALID")?,
                            &mut rng,
                        )? as u8
                    } else if normal_cast
                        && rules.version >= game_rules::raids::catalog().rules_version
                        && reinforcement_level > 0
                    {
                        game_rules::collection_focus::reroll_with_chance(
                            band.ordinal,
                            &ranks,
                            &known,
                            game_rules::raids::focus_chance_bp(reinforcement_level)?,
                            &mut rng,
                        )?
                    } else {
                        game_rules::collection_focus::reroll(
                            band.ordinal,
                            &ranks,
                            &known,
                            &mut rng,
                        )?
                    };
                    if ordinal != band.ordinal {
                        collection_focus_rerolls += 1;
                        band = bands
                            .iter()
                            .find(|row| {
                                row.species_id == definition.species_id && row.ordinal == ordinal
                            })
                            .ok_or("CONTENT_UNAVAILABLE")?;
                    }
                }
                let model = SpeciesMeasurements {
                    typical_length_mm: definition.typical_length_mm,
                    min_length_mm: definition.min_length_mm,
                    max_length_mm: definition.max_length_mm,
                    typical_weight_g: definition.typical_weight_g,
                    min_weight_g: definition.min_weight_g,
                    max_weight_g: definition.max_weight_g,
                };
                let measurements = if special_raid {
                    game_rules::raids::special_measurements_with_probability(
                        model,
                        special_raid_band.ok_or("INVALID_SPECIAL_RAID")?,
                        special_raid_maximum_roll_one_in,
                        &mut rng,
                    )?
                } else if let Some(fx) = &effects {
                    let bait_rolls = [&bait.primary, &bait.secondary].into_iter()
                        .filter(|used| used.bait_id != 0 && used.bonuses.luck_bp > 0 && used.bonuses.size_rolls > 1)
                        .try_fold(0u8, |sum, used| sum.checked_add(used.bonuses.size_rolls).ok_or("INVALID_SIZE_ROLLS"))?;
                    game_rules::tideforge::generate_measurements(
                        game_rules::tideforge::MeasurementPlan {
                            species: model,
                            band: band.ordinal,
                            base_rolls: bait.size_rolls,
                            bait_rolls,
                            ordinary: definition.counts_for_ordinary_collection_completion,
                            first_ordinary,
                            focus_enabled: collection_focus_enabled,
                            all_ranks_known,
                            reinforcement_level,
                            rules_version: rules.version,
                        },
                        fx,
                        &mut rng,
                    )?
                } else if normal_cast
                    && rules.version >= game_rules::raids::catalog().rules_version
                    && reinforcement_level > 0
                    && collection_focus_enabled
                    && definition.counts_for_ordinary_collection_completion
                    && all_ranks_known
                    && band.ordinal == 9
                {
                    game_rules::raids::reinforced_measurements(
                        model,
                        band.ordinal,
                        bait.size_rolls,
                        rules.version,
                        game_rules::raids::perfect_bonus_rolls(reinforcement_level)?,
                        &mut rng,
                    )?
                } else {
                    game_rules::trophy::generate(
                        model,
                        band.ordinal,
                        bait.size_rolls,
                        rules.version,
                        &mut rng,
                    )?
                };
                let ordinal = classify_rarity(model, measurements)?;
                if special_raid && ordinal != band.ordinal {
                    return Err("INVALID_SPECIAL_RAID".into());
                }
                let rank = if special_raid {
                    special_raid_rank_definition
                        .clone()
                        .ok_or("INVALID_SPECIAL_RAID")?
                } else {
                    ctx.db
                        .species_rank_definition()
                        .key()
                        .find(u64::from(definition.species_id) * 10 + u64::from(ordinal))
                        .ok_or("CONTENT_UNAVAILABLE")?
                };
                if !definition.allowed_rarities.contains(&rank.rarity) {
                    return Err("CONTENT_UNAVAILABLE".into());
                }
                let ratio = (u64::from(measurements.length_mm) * 1_000_000
                    / u64::from(definition.typical_length_mm))
                .clamp(600_000, 2_000_000);
                let mut sale_value =
                    rank.base_value.checked_mul(ratio).ok_or("VALUE_OVERFLOW")? / 1_000_000;
                let (sale_bp, fillet_bp, scale_bp) = if let Some(fx) = &effects {
                    let sale_bp = fx.stat("saleBp");
                    let ordinary = definition.counts_for_ordinary_collection_completion;
                    let fillet_bp = if ordinary { fx.stat("filletBp") } else { 0 };
                    let scale_bp = if ordinary { fx.stat("scaleBp") } else { 0 };
                    let rate = 10_000u32.checked_add(sale_bp).ok_or("VALUE_OVERFLOW")?;
                    sale_value = game_rules::tideforge::scale_credit(sale_value, rate, 0)?.0;
                    (sale_bp, fillet_bp, scale_bp)
                } else {
                    (0, 0, 0)
                };
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
                    content_version,
                    biome_id: biome.biome_id,
                    source_guild_id: guild_id,
                });
                if sale_bp != 0 || fillet_bp != 0 || scale_bp != 0 {
                    if ctx.db.tideforge_specimen_modifiers().catch_id().find(specimen.catch_id).is_some() {
                        return Err("TIDEFORGE_SPECIMEN_INVALID".into());
                    }
                    ctx.db.tideforge_specimen_modifiers().insert(TideforgeSpecimenModifiers {
                        catch_id: specimen.catch_id,
                        player_id: player.player_id,
                        sale_bp,
                        fillet_bp,
                        scale_bp,
                        effects_version: game_rules::tideforge::EFFECTS_VERSION,
                        activity_key: snapshot.as_ref().ok_or("TIDEFORGE_SNAPSHOT_UNAVAILABLE")?.key.clone(),
                    });
                }
                crate::perfection::observe(
                    ctx,
                    player.player_id,
                    definition.species_id,
                    specimen.catch_id,
                    ctx.timestamp,
                    measurements.length_mm,
                    measurements.weight_g,
                )?;
                crate::perfection::observe_rank_maximum(
                    ctx,
                    player.player_id,
                    definition.species_id,
                    specimen.catch_id,
                    ctx.timestamp,
                    &rank.rarity,
                    measurements.length_mm,
                    measurements.weight_g,
                )?;
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
                        sets_record = true;
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
                            content_version,
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
                if definition.counts_for_ordinary_collection_completion {
                    if let (Some(fx), Some(snapshot)) = (&effects, &snapshot) {
                        let new_rank = ctx.db.player_species_progress().key().find(key)
                            .and_then(|row| row.rank_counts.get(usize::from(rank.ordinal)).copied())
                            .unwrap_or(0) == 0;
                        if new_rank && fx.has("oceans_memory")
                            && crate::tideforge::take_daily(ctx, snapshot, "oceans_memory")?
                        {
                            ocean_bonus = fx.parameter("oceans_memory", "dailyNewRankPlayerXpBp");
                        }
                    }
                }
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
                if definition.counts_for_ordinary_collection_completion {
                    ordinary_seen = true;
                    if first_ordinary {
                        if let (Some(fx), Some(snapshot)) = (&effects, &snapshot) {
                            if fx.has("forgotten_cache") {
                                grant_packet(
                                    ctx, player.player_id, &format!("discord:{interaction_id}:{pull}:cache"),
                                    fx.parameter("forgotten_cache", "firstFishFillets"),
                                    fx.parameter("forgotten_cache", "firstFishScales"),
                                )?;
                            }
                            if fx.has("rift_current") && crate::tideforge::take_daily(ctx, snapshot, "rift_current")? {
                                rift_pull = Some(pull_count);
                                pull_count = pull_count.checked_add(1).ok_or("INVALID_PULL_COUNT")?;
                                if pull_count > max_base_pulls + 1 {
                                    return Err("INVALID_PULL_COUNT".into());
                                }
                            }
                        }
                    }
                }
            }
            1 => {
                player.junk_count = player.junk_count.checked_add(1).ok_or("COUNT_OVERFLOW")?;
                receipt.outcome = "junk".into();
                receipt.xp_granted = 2;
                receipt.item_granted = "rusted_tin".into();
                receipt.item_quantity = if let Some(fx) = &effects {
                    let rate = 10_000u32.checked_add(fx.stat("salvageBp")).ok_or("ITEM_OVERFLOW")?;
                    crate::tideforge::credit(ctx, player.player_id, "rusted_tin", 1, rate)?
                } else {
                    1
                };
                crate::inventory::grant_item(
                    ctx,
                    player.player_id,
                    2,
                    "rusted_tin",
                    receipt.item_quantity,
                    format!("discord:{interaction_id}"),
                )?;
                if !junk_seen {
                    if let Some(fx) = &effects {
                        for (signature, parameter, slot, item) in [
                            ("salvage_sermon", "firstJunkScrap", 1u32, "scrap"),
                            ("rusted_crown", "firstJunkTin", 2u32, "rusted_tin"),
                        ] {
                            let quantity = fx.parameter(signature, parameter);
                            if fx.has(signature) && quantity != 0 {
                                crate::inventory::grant_item(
                                    ctx, player.player_id, slot, item, u64::from(quantity),
                                    format!("discord:{interaction_id}:{pull}:{signature}"),
                                )?;
                            }
                        }
                        if fx.has("fishermans_folly") {
                            grant_packet(
                                ctx, player.player_id, &format!("discord:{interaction_id}:{pull}:folly"),
                                fx.parameter("fishermans_folly", "firstJunkFillets"),
                                fx.parameter("fishermans_folly", "firstJunkScales"),
                            )?;
                        }
                    }
                }
                junk_seen = true;
            }
            _ => {
                player.treasure_count = player
                    .treasure_count
                    .checked_add(1)
                    .ok_or("COUNT_OVERFLOW")?;
                receipt.outcome = "treasure".into();
                receipt.xp_granted = 8;
                receipt.coins_granted = rng.gen_range(25..=60);
                if let Some(fx) = &effects {
                    let rate = 10_000u32.checked_add(fx.stat("treasureBp")).ok_or("COIN_OVERFLOW")?;
                    receipt.coins_granted =
                        game_rules::tideforge::scale_credit(receipt.coins_granted, rate, 0)?.0;
                    if !treasure_seen {
                        let signature = match fx.kind.as_str() {
                            "boat" => Some("gilded_bilge"),
                            "net" => Some("pearlbound"),
                            _ => None,
                        };
                        if let Some(signature) = signature {
                            if fx.has(signature) && game_rules::tideforge::chance(
                                fx.parameter(signature, "firstTreasureDoubleBp"), 10_000, &mut rng,
                            )? {
                                receipt.coins_granted = receipt.coins_granted.checked_mul(2).ok_or("COIN_OVERFLOW")?;
                            }
                        }
                    }
                }
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
                treasure_seen = true;
            }
        }
        crate::statistics::observe_pull(ctx, &receipt, normal_cast && pull > 0, sets_record)?;
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
        ocean_bonuses.push(ocean_bonus);
        pull += 1;
    }
    let legacy_total_xp = xp_reward(total_base_xp)?;
    let mut allocated_xp = 0u64;
    let mut total_xp = 0u64;
    let mut pulls: Vec<_> = ctx
        .db
        .cast_pull()
        .interaction_id()
        .filter(interaction_id)
        .collect();
    pulls.sort_by_key(|row| row.key);
    for (index, mut pull) in pulls.into_iter().enumerate() {
        let legacy_xp = if index + 1 == pull_count {
            legacy_total_xp - allocated_xp
        } else {
            xp_reward(pull.base_xp)?
        };
        allocated_xp += legacy_xp;
        let bonus_xp = if let Some(fx) = &effects {
            let bonus_bp = fx.stat("playerXpBp")
                .checked_add(*ocean_bonuses.get(index).ok_or("RECEIPT_MISSING")?)
                .ok_or("XP_OVERFLOW")?;
            if bonus_bp == 0 {
                0
            } else {
                crate::tideforge::credit(ctx, player.player_id, "player_xp", legacy_xp, bonus_bp)?
            }
        } else {
            0
        };
        pull.receipt.xp_granted = legacy_xp.checked_add(bonus_xp).ok_or("XP_OVERFLOW")?;
        total_xp = total_xp.checked_add(pull.receipt.xp_granted).ok_or("XP_OVERFLOW")?;
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
    for (index, used) in [&bait.primary, &bait.secondary].into_iter().enumerate() {
        if !used.resource_item.is_empty() {
            let slot = if used.resource_item == "rusted_tin" {
                2
            } else {
                1
            };
            crate::inventory::grant_item(
                ctx,
                player.player_id,
                slot,
                &used.resource_item,
                used.resource_quantity,
                format!("discord:{interaction_id}:bait:{}", index + 1),
            )?;
        }
    }
    ctx.db
        .cast_equipment_receipt()
        .insert(CastEquipmentReceipt {
            interaction_id,
            caught_at: ctx.timestamp,
            player_id: player.player_id,
            quality_level,
            power,
            luck_bp,
            xp_bonus_bp,
            bait_id: bait.primary.bait_id,
            bait_uses_left: bait.primary.uses_left,
            bait_item: bait.primary.resource_item,
            bait_quantity: bait.primary.resource_quantity,
            bait_size_rolls: bait.size_rolls,
            collection_focus_enabled,
            collection_focus_rerolls,
            reinforcement_level,
            secondary_bait_id: bait.secondary.bait_id,
            secondary_bait_uses_left: bait.secondary.uses_left,
            secondary_bait_item: bait.secondary.resource_item,
            secondary_bait_quantity: bait.secondary.resource_quantity,
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
    if normal_cast {
        player.next_cast_at = ctx.timestamp
            + TimeDuration::from_micros(i64::from(rules.cast_cooldown_seconds) * 1_000_000);
    }
    let mut profile = ctx
        .db
        .public_profile()
        .player_id()
        .find(player.player_id)
        .ok_or("PROFILE_MISSING")?;
    profile.level = level_for_xp_for_version(player.total_xp, rules.level_cap, rules.version);
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
    if !normal_cast {
        crate::statistics::observe_haul_catches(ctx, player.player_id, pull_count)?;
    }
    if let Some(snapshot) = &snapshot {
        crate::tideforge::award_mastery(ctx, snapshot, ordinary_seen)?;
    }
    ctx.db.player().player_id().update(player);
    changed_record_holders.sort_unstable();
    changed_record_holders.dedup();
    for player_id in changed_record_holders {
        crate::records::refresh_player(ctx, player_id)?;
    }
    ctx.db.command_receipt().insert(receipt);
    Ok(())
}
