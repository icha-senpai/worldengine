//! Permanent, cosmetic milestones. No balance, XP, or equipment mutations.
use crate::tables::*;
use spacetimedb::{ReducerContext, Table, ViewContext};

pub fn seed(ctx: &ReducerContext) {
    let catalog = [
        (
            1,
            "First ripple",
            "Complete your first cast.",
            "New Angler",
            1,
            false,
        ),
        (
            2,
            "First fish",
            "Catch your first fish.",
            "Hooked",
            1,
            false,
        ),
        (
            3,
            "A hundred ripples",
            "Complete 100 casts.",
            "Patient Angler",
            100,
            false,
        ),
        (
            4,
            "A thousand ripples",
            "Complete 1,000 casts.",
            "Ripplekeeper",
            1_000,
            false,
        ),
        (
            5,
            "A full net",
            "Catch 100 fish over your lifetime.",
            "Steady Hand",
            100,
            false,
        ),
        (
            6,
            "Old hand",
            "Catch 1,000 fish over your lifetime.",
            "Veteran Angler",
            1_000,
            false,
        ),
        (
            7,
            "Curious angler",
            "Discover 10 ordinary species.",
            "Curious Angler",
            10,
            false,
        ),
        (
            8,
            "Naturalist",
            "Discover 50 ordinary species.",
            "Naturalist",
            50,
            false,
        ),
        (
            9,
            "Fish scholar",
            "Discover 100 ordinary species.",
            "Fish Scholar",
            100,
            false,
        ),
        (
            10,
            "Pond collection",
            "Discover every ordinary Pond species.",
            "Pond Naturalist",
            28,
            false,
        ),
        (
            11,
            "River wanderer",
            "Own a River fishing licence.",
            "River Wanderer",
            1,
            false,
        ),
        (
            12,
            "Deepwater explorer",
            "Own an Abyssal fishing licence.",
            "Deepwater Explorer",
            1,
            false,
        ),
        (
            13,
            "Rare company",
            "Catch an A-rank fish or better.",
            "Trophy Hunter",
            1,
            false,
        ),
        (
            14,
            "Beyond typical",
            "Catch a fish at least 1.35 times its typical length.",
            "Tall Tale Teller",
            1,
            false,
        ),
        (
            15,
            "Mythic maker",
            "Upgrade any rod to Mythic or better.",
            "Mythic Crafter",
            1,
            false,
        ),
        (
            16,
            "Prismatic masterpiece",
            "Upgrade any rod to Prismatic.",
            "Prismatic Artisan",
            1,
            false,
        ),
        (
            17,
            "A fish called Fihs",
            "Discover Fihs. A bonus outside ordinary completion.",
            "Myth Hunter",
            1,
            true,
        ),
        (
            18,
            "The lost sock",
            "Discover Nidalees Lost Sock. A bonus outside ordinary completion.",
            "Sock Detective",
            1,
            true,
        ),
    ];
    for (achievement_id, name, description, title, target, bonus) in catalog {
        let row = AchievementDefinition {
            achievement_id,
            name: name.into(),
            description: description.into(),
            title: title.into(),
            target,
            bonus,
        };
        if ctx
            .db
            .achievement_definition()
            .achievement_id()
            .find(achievement_id)
            .is_some()
        {
            ctx.db.achievement_definition().achievement_id().update(row);
        } else {
            ctx.db.achievement_definition().insert(row);
        }
    }
}

pub fn refresh_player(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_MISSING")?;
    let mut values = [0u64; 18];
    values[0] = player.completed_casts;
    values[1] = player.fish_count;
    values[2] = player.completed_casts;
    values[3] = player.completed_casts;
    values[4] = player.fish_count;
    values[5] = player.fish_count;
    let mut discoveries = 0;
    for progress in ctx
        .db
        .player_species_progress()
        .player_id()
        .filter(player_id)
    {
        if progress.count == 0 {
            continue;
        }
        let species = ctx
            .db
            .species_definition()
            .species_id()
            .find(progress.species_id)
            .ok_or("CONTENT_UNAVAILABLE")?;
        if species.counts_for_ordinary_collection_completion {
            discoveries += 1;
            if species.biome_id == 1 {
                values[9] += 1;
            }
        } else if species.name == "Fihs" {
            values[16] = 1;
        } else if species.name == "Nidalees Lost Sock" {
            values[17] = 1;
        }
        if progress.rank_counts.iter().skip(4).any(|count| *count > 0) {
            values[12] = 1;
        }
        if u64::from(progress.best_length_mm) * 100 >= u64::from(species.typical_length_mm) * 135 {
            values[13] = 1;
        }
    }
    values[6] = discoveries;
    values[7] = discoveries;
    values[8] = discoveries;
    for licence in ctx.db.owned_biome_licence().player_id().filter(player_id) {
        if licence.biome_id == 2 {
            values[10] = 1;
        }
        if licence.biome_id == 7 {
            values[11] = 1;
        }
    }
    for rod in ctx.db.owned_rod().player_id().filter(player_id) {
        if rod.upgrade_level >= 5 {
            values[14] = 1;
        }
        if rod.upgrade_level >= 6 {
            values[15] = 1;
        }
    }
    for definition in ctx.db.achievement_definition().iter() {
        let id = definition.achievement_id;
        let key = u128::from(player_id) << 32 | u128::from(id);
        let current = values[(id - 1) as usize].min(definition.target);
        let row = AchievementProgress {
            key,
            player_id,
            achievement_id: id,
            current,
        };
        if ctx.db.achievement_progress().key().find(key).is_some() {
            ctx.db.achievement_progress().key().update(row);
        } else {
            ctx.db.achievement_progress().insert(row);
        }
        if current >= definition.target && ctx.db.earned_achievement().key().find(key).is_none() {
            ctx.db.earned_achievement().insert(EarnedAchievement {
                key,
                player_id,
                achievement_id: id,
                completed_at: ctx.timestamp,
            });
        }
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn activate_achievements(ctx: &ReducerContext) -> Result<(), String> {
    require_owner(ctx)?;
    seed(ctx);
    Ok(())
}

#[spacetimedb::reducer]
pub fn backfill_player_achievements(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    require_owner(ctx)?;
    refresh_player(ctx, player_id)
}

fn require_owner(ctx: &ReducerContext) -> Result<(), String> {
    if ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .is_some_and(|owner| owner.identity == ctx.sender())
    {
        Ok(())
    } else {
        Err("OWNER_REQUIRED".into())
    }
}

#[spacetimedb::reducer]
pub fn equip_title(ctx: &ReducerContext, achievement_id: u32) -> Result<(), String> {
    let player_id = ctx
        .db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .ok_or("ACCOUNT_NOT_LINKED")?
        .player_id;
    if achievement_id != 0
        && ctx
            .db
            .earned_achievement()
            .key()
            .find(u128::from(player_id) << 32 | u128::from(achievement_id))
            .is_none()
    {
        return Err("TITLE_NOT_EARNED".into());
    }
    let row = AnglerTitle {
        player_id,
        achievement_id,
    };
    if ctx.db.angler_title().player_id().find(player_id).is_some() {
        ctx.db.angler_title().player_id().update(row);
    } else {
        ctx.db.angler_title().insert(row);
    }
    Ok(())
}

#[spacetimedb::view(accessor = my_achievement_progress, public)]
pub fn my_achievement_progress(ctx: &ViewContext) -> Vec<AchievementProgress> {
    crate::accounts::current_player(ctx)
        .map(|id| {
            ctx.db
                .achievement_progress()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = adapter_achievement_progress, public)]
pub fn adapter_achievement_progress(ctx: &ViewContext) -> Vec<AchievementProgress> {
    crate::shop::selected(ctx)
        .map(|id| {
            ctx.db
                .achievement_progress()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}
