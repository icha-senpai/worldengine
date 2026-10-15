//! Permanent stories, weekly contracts and unlimited fresh-progress jobs. Rewards are server-owned.
use crate::{accounts::current_player, tables::*};
use game_rules::daily::{DAY_MICROS, utc_day};
use game_rules::quests::utc_week as week;
use spacetimedb::{ReducerContext, Table, Timestamp, ViewContext};

fn key(player_id: u64, quest_id: u32) -> u128 {
    (u128::from(player_id) << 32) | u128::from(quest_id)
}

pub fn refresh_calendar(ctx: &ReducerContext) {
    let day = utc_day(ctx.timestamp.to_micros_since_unix_epoch());
    if ctx
        .db
        .quest_calendar()
        .singleton()
        .find(1)
        .is_some_and(|row| row.day == day)
    {
        return;
    }
    let row = QuestCalendar {
        singleton: 1,
        day,
        week: week(day),
        next_week_at: Timestamp::from_micros_since_unix_epoch(
            ((week(day) + 1) * 7 - 3) * DAY_MICROS,
        ),
    };
    if ctx.db.quest_calendar().singleton().find(1).is_some() {
        ctx.db.quest_calendar().singleton().update(row);
    } else {
        ctx.db.quest_calendar().insert(row);
    }
}

pub fn seed(ctx: &ReducerContext) {
    refresh_calendar(ctx);
    let definitions: Vec<QuestDefinition> =
        serde_json::from_str(include_str!("../../content/quests.json")).expect("validated quests");
    for row in definitions {
        if ctx
            .db
            .quest_definition()
            .quest_id()
            .find(row.quest_id)
            .is_some()
        {
            ctx.db.quest_definition().quest_id().update(row);
        } else {
            ctx.db.quest_definition().insert(row);
        }
    }
    #[derive(serde::Deserialize)]
    struct Award {
        achievement_id: u32,
        title: String,
    }
    let awards: Vec<Award> = serde_json::from_str(include_str!("../../content/quest-awards.json"))
        .expect("validated quest awards");
    for award in awards {
        let quest = ctx
            .db
            .quest_definition()
            .iter()
            .find(|q| q.achievement_id == award.achievement_id)
            .expect("award finale");
        let id = award.achievement_id;
        let name = quest.name;
        let title = award.title;
        let description = format!("Complete the {} quest chain.", quest.chain);
        let row = AchievementDefinition {
            achievement_id: id,
            name: name.into(),
            description: description.into(),
            title: title.into(),
            target: 1,
            bonus: false,
        };
        if ctx
            .db
            .achievement_definition()
            .achievement_id()
            .find(id)
            .is_some()
        {
            ctx.db.achievement_definition().achievement_id().update(row);
        } else {
            ctx.db.achievement_definition().insert(row);
        }
    }
}

#[spacetimedb::reducer]
pub fn activate_quests(ctx: &ReducerContext) -> Result<(), String> {
    if !ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .is_some_and(|row| row.identity == ctx.sender())
    {
        return Err("OWNER_REQUIRED".into());
    }
    seed(ctx);
    Ok(())
}

fn empty_week(player_id: u64, week: i64) -> WeeklyQuestProgress {
    WeeklyQuestProgress {
        player_id,
        week,
        fish_count: 0,
        junk_count: 0,
        species_ids: vec![],
        claimed_quest_id: 0,
        bait_id: 0,
    }
}

pub fn observe_pull(ctx: &ReducerContext, receipt: &CommandReceipt) -> Result<(), String> {
    refresh_calendar(ctx);
    for definition in ctx
        .db
        .quest_definition()
        .iter()
        .filter(|q| q.goal.starts_with("repeat_"))
    {
        let row_key = key(receipt.player_id, definition.quest_id);
        let previous = ctx.db.repeat_quest_progress().key().find(row_key);
        let exists = previous.is_some();
        let mut row = previous.unwrap_or(RepeatQuestProgress {
            key: row_key,
            player_id: receipt.player_id,
            quest_id: definition.quest_id,
            cycle: 0,
            current: 0,
            species_ids: vec![],
        });
        if row.current >= definition.target {
            continue;
        }
        let increment = match definition.goal.as_str() {
            "repeat_fish" => receipt.outcome == "fish",
            "repeat_junk" => receipt.outcome == "junk",
            "repeat_variety" => {
                receipt.outcome == "fish"
                    && receipt.species_id.is_some_and(|id| {
                        if row.species_ids.contains(&id)
                            || !ctx
                                .db
                                .species_definition()
                                .species_id()
                                .find(id)
                                .is_some_and(|s| s.counts_for_ordinary_collection_completion)
                        {
                            return false;
                        }
                        row.species_ids.push(id);
                        true
                    })
            }
            _ => false,
        };
        if increment {
            row.current += 1;
        }
        if exists {
            ctx.db.repeat_quest_progress().key().update(row);
        } else {
            ctx.db.repeat_quest_progress().insert(row);
        }
    }
    let current_week = week(utc_day(ctx.timestamp.to_micros_since_unix_epoch()));
    let previous = ctx
        .db
        .weekly_quest_progress()
        .player_id()
        .find(receipt.player_id);
    let existed = previous.is_some();
    let mut row = previous
        .filter(|row| row.week == current_week)
        .unwrap_or_else(|| empty_week(receipt.player_id, current_week));
    if receipt.outcome == "fish" {
        row.fish_count = row.fish_count.checked_add(1).ok_or("QUEST_OVERFLOW")?;
        if let Some(id) = receipt.species_id {
            if ctx
                .db
                .species_definition()
                .species_id()
                .find(id)
                .is_some_and(|s| s.counts_for_ordinary_collection_completion)
                && !row.species_ids.contains(&id)
            {
                row.species_ids.push(id);
            }
        }
    } else if receipt.outcome == "junk" {
        row.junk_count = row.junk_count.checked_add(1).ok_or("QUEST_OVERFLOW")?;
    }
    if existed {
        ctx.db.weekly_quest_progress().player_id().update(row);
    } else {
        ctx.db.weekly_quest_progress().insert(row);
    }
    Ok(())
}

fn delivery_species(definition: &QuestDefinition, species: &[SpeciesDefinition]) -> Vec<u32> {
    let mut choices: Vec<_> = species
        .iter()
        .filter(|s| {
            s.biome_id == definition.biome_id && s.counts_for_ordinary_collection_completion
        })
        .collect();
    choices.sort_by_key(|s| (std::cmp::Reverse(s.encounter_weight), s.species_id));
    choices.into_iter().take(3).map(|s| s.species_id).collect()
}

fn statuses(
    definitions: Vec<QuestDefinition>,
    claims: Vec<QuestClaim>,
    player: Player,
    progress: Vec<PlayerSpeciesProgress>,
    rods: Vec<OwnedRod>,
    species: Vec<SpeciesDefinition>,
    weekly: Option<WeeklyQuestProgress>,
    current_week: i64,
    repeats: Vec<RepeatQuestProgress>,
    maxima: Vec<RankMaximum>,
) -> Vec<QuestStatus> {
    let weekly = weekly.filter(|row| row.week == current_week);
    definitions
        .into_iter()
        .map(|definition| {
            let related: Vec<_> = progress
                .iter()
                .filter(|p| {
                    p.count > 0
                        && species.iter().any(|s| {
                            s.species_id == p.species_id
                                && s.counts_for_ordinary_collection_completion
                                && (definition.biome_id == 0 || s.biome_id == definition.biome_id)
                        })
                })
                .collect();
            let repeat = repeats.iter().find(|r| r.quest_id == definition.quest_id);
            let claimed = if definition.goal.starts_with("repeat_") {
                false
            } else if definition.weekly {
                weekly.as_ref().is_some_and(|w| w.claimed_quest_id != 0)
            } else {
                claims.iter().any(|c| c.quest_id == definition.quest_id)
            };
            let current = match definition.goal.as_str() {
                "fish" => player.fish_count,
                "discoveries" => related.len() as u64,
                "junk" => player.junk_count,
                "upgrade" => rods
                    .iter()
                    .map(|r| u64::from(r.upgrade_level))
                    .max()
                    .unwrap_or(0),
                "ranks" => (0..10)
                    .filter(|i| {
                        related
                            .iter()
                            .any(|p| p.rank_counts.get(*i).is_some_and(|n| *n > 0))
                    })
                    .count() as u64,
                "rank_maxima" => u64::from(maxima.iter().any(|m| {
                    species.iter().any(|s| {
                        s.species_id == m.species_id && s.counts_for_ordinary_collection_completion
                    })
                })),
                "repeat_fish" | "repeat_junk" | "repeat_variety" => repeat.map_or(0, |r| r.current),
                "large" => u64::from(related.iter().any(|p| {
                    species.iter().any(|s| {
                        s.species_id == p.species_id && p.best_length_mm > s.typical_length_mm
                    })
                })),
                "rank" => u64::from(
                    related
                        .iter()
                        .any(|p| p.rank_counts.iter().skip(5).any(|n| *n > 0)),
                ),
                "delivery" => u64::from(claimed),
                "weekly_fish" => weekly.as_ref().map_or(0, |w| w.fish_count),
                "weekly_junk" => weekly.as_ref().map_or(0, |w| w.junk_count),
                "weekly_variety" => weekly.as_ref().map_or(0, |w| w.species_ids.len() as u64),
                _ => 0,
            }
            .min(definition.target);
            QuestStatus {
                quest_id: definition.quest_id,
                cycle: repeat.map_or(0, |r| r.cycle),
                current,
                unlocked: definition.previous_quest_id == 0
                    || claims
                        .iter()
                        .any(|c| c.quest_id == definition.previous_quest_id),
                claimed,
                delivery_species_ids: if definition.goal == "delivery" {
                    delivery_species(&definition, &species)
                } else {
                    vec![]
                },
                week: current_week,
            }
        })
        .collect()
}

fn reducer_statuses(ctx: &ReducerContext, player_id: u64) -> Result<Vec<QuestStatus>, String> {
    Ok(statuses(
        ctx.db.quest_definition().iter().collect(),
        ctx.db.quest_claim().player_id().filter(player_id).collect(),
        ctx.db
            .player()
            .player_id()
            .find(player_id)
            .ok_or("PLAYER_MISSING")?,
        ctx.db
            .player_species_progress()
            .player_id()
            .filter(player_id)
            .collect(),
        ctx.db.owned_rod().player_id().filter(player_id).collect(),
        ctx.db.species_definition().iter().collect(),
        ctx.db.weekly_quest_progress().player_id().find(player_id),
        week(utc_day(ctx.timestamp.to_micros_since_unix_epoch())),
        ctx.db
            .repeat_quest_progress()
            .player_id()
            .filter(player_id)
            .collect(),
        ctx.db
            .rank_maximum()
            .player_id()
            .filter(player_id)
            .collect(),
    ))
}

fn view_statuses(ctx: &ViewContext, player_id: u64) -> Vec<QuestStatus> {
    let Some(player) = ctx.db.player().player_id().find(player_id) else {
        return vec![];
    };
    statuses(
        ctx.db
            .quest_definition()
            .weekly()
            .filter(false)
            .chain(ctx.db.quest_definition().weekly().filter(true))
            .collect(),
        ctx.db.quest_claim().player_id().filter(player_id).collect(),
        player,
        ctx.db
            .player_species_progress()
            .player_id()
            .filter(player_id)
            .collect(),
        ctx.db.owned_rod().player_id().filter(player_id).collect(),
        ctx.db
            .species_definition()
            .biome_id()
            .filter(0u32..)
            .collect(),
        ctx.db.weekly_quest_progress().player_id().find(player_id),
        ctx.db
            .quest_calendar()
            .singleton()
            .find(1)
            .map_or(0, |c| c.week),
        ctx.db
            .repeat_quest_progress()
            .player_id()
            .filter(player_id)
            .collect(),
        ctx.db
            .rank_maximum()
            .player_id()
            .filter(player_id)
            .collect(),
    )
}

fn reward(
    ctx: &ReducerContext,
    player_id: u64,
    definition: &QuestDefinition,
    bait_id: u32,
    operation: &str,
) -> Result<(), String> {
    if (definition.bait_choices.is_empty() && bait_id != 0)
        || (!definition.bait_choices.is_empty() && !definition.bait_choices.contains(&bait_id))
    {
        return Err("QUEST_REWARD_CHOICE_REQUIRED".into());
    }
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    if definition.coins > 0 {
        player.coins = player
            .coins
            .checked_add(definition.coins)
            .ok_or("COIN_OVERFLOW")?;
        ctx.db.player().player_id().update(player);
        crate::inventory::ledger(
            ctx,
            player_id,
            operation.into(),
            "coins",
            i64::try_from(definition.coins).map_err(|_| "COIN_OVERFLOW")?,
            "quest_reward",
        );
    }
    for (slot, item, amount) in [
        (2, "rusted_tin", definition.tin),
        (1, "scrap", definition.scrap),
    ] {
        if amount > 0 {
            crate::inventory::grant_item_with_reason(
                ctx,
                player_id,
                slot,
                item,
                amount,
                operation.into(),
                "quest_reward",
            )?;
        }
    }
    if bait_id != 0 {
        crate::crafting::grant_bait(ctx, player_id, bait_id, definition.bait_packs)?;
    }
    if definition.achievement_id != 0 {
        let achievement_key = key(player_id, definition.achievement_id);
        if ctx
            .db
            .earned_achievement()
            .key()
            .find(achievement_key)
            .is_none()
        {
            ctx.db.earned_achievement().insert(EarnedAchievement {
                key: achievement_key,
                player_id,
                achievement_id: definition.achievement_id,
                completed_at: ctx.timestamp,
            });
            crate::notifications::enqueue(ctx, achievement_key);
        }
        let progress = AchievementProgress {
            key: achievement_key,
            player_id,
            achievement_id: definition.achievement_id,
            current: 1,
        };
        if ctx
            .db
            .achievement_progress()
            .key()
            .find(achievement_key)
            .is_some()
        {
            ctx.db.achievement_progress().key().update(progress);
        } else {
            ctx.db.achievement_progress().insert(progress);
        }
    }
    Ok(())
}

fn claim(
    ctx: &ReducerContext,
    player_id: u64,
    quest_id: u32,
    bait_id: u32,
    catch_id: Option<u64>,
    expected_week: i64,
    expected_cycle: u64,
) -> Result<(), String> {
    refresh_calendar(ctx);
    let definition = ctx
        .db
        .quest_definition()
        .quest_id()
        .find(quest_id)
        .ok_or("QUEST_UNAVAILABLE")?;
    let status = reducer_statuses(ctx, player_id)?
        .into_iter()
        .find(|s| s.quest_id == quest_id)
        .ok_or("QUEST_UNAVAILABLE")?;
    if definition.weekly && expected_week != status.week {
        return Err("QUEST_WEEK_CHANGED".into());
    }
    let repeating = definition.goal.starts_with("repeat_");
    if repeating && expected_cycle != status.cycle {
        return Err("QUEST_RUN_CHANGED".into());
    }
    // Durable claim keys make replay safe even after the delivered fish is gone.
    if status.claimed {
        if definition.weekly
            && ctx
                .db
                .weekly_quest_progress()
                .player_id()
                .find(player_id)
                .is_some_and(|w| w.claimed_quest_id != quest_id)
        {
            return Err("QUEST_WEEK_ALREADY_CLAIMED".into());
        }
        return Ok(());
    }
    if !status.unlocked {
        return Err("QUEST_LOCKED".into());
    }
    if definition.goal == "delivery" {
        let id = catch_id.ok_or("QUEST_DELIVERY_REQUIRED")?;
        let fish = ctx
            .db
            .owned_specimen()
            .catch_id()
            .find(id)
            .ok_or("CATCH_UNAVAILABLE")?;
        if fish.player_id != player_id {
            return Err("CATCH_NOT_OWNED".into());
        }
        if fish.favorite {
            return Err("FAVORITE_PROTECTED".into());
        }
        if !status.delivery_species_ids.contains(&fish.species_id)
            || !matches!(fish.rarity.as_str(), "F" | "D" | "C")
        {
            return Err("QUEST_WRONG_FISH".into());
        }
        let mut player = ctx
            .db
            .player()
            .player_id()
            .find(player_id)
            .ok_or("PLAYER_MISSING")?;
        player.kept_count = player.kept_count.checked_sub(1).ok_or("COUNT_UNDERFLOW")?;
        ctx.db.player().player_id().update(player);
        ctx.db.owned_specimen().catch_id().delete(id);
        crate::inventory::ledger(
            ctx,
            player_id,
            format!("quest:{quest_id}"),
            "delivered_fish",
            -1,
            "quest_delivery",
        );
    } else {
        if catch_id.is_some() {
            return Err("QUEST_DELIVERY_NOT_REQUIRED".into());
        }
        if status.current < definition.target {
            return Err("QUEST_NOT_READY".into());
        }
    }
    let operation = format!(
        "quest:{quest_id}:{}:{}",
        if definition.weekly { status.week } else { 0 },
        status.cycle
    );
    reward(ctx, player_id, &definition, bait_id, &operation)?;
    if repeating {
        let mut row = ctx
            .db
            .repeat_quest_progress()
            .key()
            .find(key(player_id, quest_id))
            .ok_or("QUEST_NOT_READY")?;
        row.cycle = row.cycle.checked_add(1).ok_or("QUEST_OVERFLOW")?;
        row.current = 0;
        row.species_ids.clear();
        ctx.db.repeat_quest_progress().key().update(row);
    } else if definition.weekly {
        let mut row = ctx
            .db
            .weekly_quest_progress()
            .player_id()
            .find(player_id)
            .filter(|w| w.week == status.week)
            .ok_or("QUEST_NOT_READY")?;
        row.claimed_quest_id = quest_id;
        row.bait_id = bait_id;
        ctx.db.weekly_quest_progress().player_id().update(row);
    } else {
        ctx.db.quest_claim().insert(QuestClaim {
            key: key(player_id, quest_id),
            player_id,
            quest_id,
            bait_id,
            delivered_catch_id: catch_id,
            claimed_at: ctx.timestamp,
        });
    }
    crate::statistics::refresh(ctx, player_id)?;
    Ok(())
}

#[spacetimedb::reducer]
pub fn claim_quest(
    ctx: &ReducerContext,
    quest_id: u32,
    bait_id: u32,
    catch_id: Option<u64>,
    expected_week: i64,
    expected_cycle: u64,
) -> Result<(), String> {
    claim(
        ctx,
        crate::shop::browser_player(ctx)?,
        quest_id,
        bait_id,
        catch_id,
        expected_week,
        expected_cycle,
    )
}

#[spacetimedb::reducer]
pub fn claim_quest_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    quest_id: u32,
    bait_id: u32,
    catch_id: Option<u64>,
    expected_week: i64,
    expected_cycle: u64,
) -> Result<(), String> {
    claim(
        ctx,
        crate::shop::adapter_player_id(ctx, discord_user_id, interaction_id)?,
        quest_id,
        bait_id,
        catch_id,
        expected_week,
        expected_cycle,
    )
}

#[spacetimedb::view(accessor = my_quests, public)]
pub fn my_quests(ctx: &ViewContext) -> Vec<QuestStatus> {
    current_player(ctx)
        .map(|id| view_statuses(ctx, id))
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_quests, public)]
pub fn adapter_quests(ctx: &ViewContext) -> Vec<QuestStatus> {
    crate::shop::selected(ctx)
        .map(|id| view_statuses(ctx, id))
        .unwrap_or_default()
}
