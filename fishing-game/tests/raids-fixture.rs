// Appended only to the isolated proof's copy of raids.rs, never the live module.
fn require_raid_proof(ctx: &ReducerContext) -> Result<(), String> {
    let database = spacetimedb::Identity::from_hex("__RAID_PROOF_DATABASE__")
        .map_err(|_| "PROOF_DATABASE_UNSET")?;
    let owner = ctx.db.deployment_owner().singleton().find(1).ok_or("OWNER_REQUIRED")?;
    if ctx.database_identity() != database || ctx.sender() != owner.identity {
        return Err("ISOLATED_RAID_PROOF_REQUIRED".into());
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_raid_setup(
    ctx: &ReducerContext,
    discord_user_id: u64,
    browser_identity: spacetimedb::Identity,
) -> Result<(), String> {
    require_raid_proof(ctx)?;
    if !matches!(discord_user_id, 99002 | 99003) {
        return Err("PROOF_ACTOR_REQUIRED".into());
    }
    if ctx.db.player().discord_user_id().find(discord_user_id).is_some()
        || ctx.db.player_identity().identity().find(browser_identity).is_some()
    {
        return Err("PROOF_ACTOR_ALREADY_EXISTS".into());
    }
    let name = if discord_user_id == 99002 { "Raid Test Angler" } else { "Other Raid Test Angler" };
    let mut player = crate::accounts::ensure_player(ctx, discord_user_id, name)?;
    player.total_xp = 1_000_000;
    player.coins = 500_000;
    let mut fish_count = 0;
    let mut discoveries = 0;
    for species in ctx.db.species_definition().iter().filter(|species| {
        (species.biome_id == 1 && species.counts_for_ordinary_collection_completion)
            || matches!(species.species_id, SPECIAL_FIHS | SPECIAL_SOCK)
    }) {
        let mut rank_counts = vec![0; 10];
        rank_counts[if species.species_id == SPECIAL_FIHS { 9 } else { 0 }] = 1;
        ctx.db.player_species_progress().insert(PlayerSpeciesProgress {
            key: (u128::from(player.player_id) << 32) | u128::from(species.species_id),
            player_id: player.player_id,
            species_id: species.species_id,
            count: 1,
            rank_counts,
            first_caught_at: ctx.timestamp,
            best_length_mm: species.typical_length_mm,
            best_weight_g: species.typical_weight_g,
        });
        fish_count += 1;
        if species.counts_for_ordinary_collection_completion {
            discoveries += 1;
        }
    }
    player.fish_count = fish_count;
    player.completed_casts = fish_count;
    let player_id = player.player_id;
    ctx.db.player().player_id().update(player);
    ctx.db.player_identity().insert(PlayerIdentity {
        identity: browser_identity,
        player_id,
        linked_at: ctx.timestamp,
    });
    let rod_key = (u128::from(player_id) << 32) | 1;
    let mut twig = ctx.db.owned_rod().key().find(rod_key).ok_or("PROOF_TWIG_REQUIRED")?;
    twig.upgrade_level = ctx.db.quality_definition().iter()
        .map(|quality| quality.quality_level).max().ok_or("QUALITY_UNAVAILABLE")?;
    ctx.db.owned_rod().key().update(twig);
    ctx.db.owned_rod().insert(OwnedRod {
        key: (u128::from(player_id) << 32) | 2,
        player_id,
        rod_id: 2,
        upgrade_level: 0,
    });
    let catalog = rules::catalog();
    crate::inventory::grant_item_with_reason(
        ctx, player_id, catalog.supply.slot, &catalog.supply.item, 1000,
        format!("raid-proof-setup:{player_id}"), "isolated_proof",
    )?;
    for grade in 0..10 {
        for material in 0..2 {
            let (slot, item) = rules::material(grade, material).map_err(|_| "PROOF_MATERIAL_INVALID")?;
            crate::inventory::grant_item_with_reason(
                ctx, player_id, slot, &item, 2000,
                format!("raid-proof-setup:{player_id}"), "isolated_proof",
            )?;
        }
    }
    ctx.db.collection_focus_preference().insert(CollectionFocusPreference {
        key: (u128::from(player_id) << 32) | 1,
        player_id,
        biome_id: 1,
        enabled: true,
    });
    if let Some(mut profile) = ctx.db.public_profile().player_id().find(player_id) {
        profile.fish_count = fish_count;
        profile.discoveries = discoveries;
        let config = config(ctx);
        profile.level = game_rules::progression::level_for_xp_for_version(
            1_000_000, config.level_cap, config.version,
        );
        ctx.db.public_profile().player_id().update(profile);
    }
    crate::records::refresh_player(ctx, player_id)?;
    crate::statistics::backfill_player_statistics(ctx, player_id)?;
    Ok(())
}

fn proof_attempt(ctx: &ReducerContext, attempt_id: u128) -> Result<RaidAttempt, String> {
    require_raid_proof(ctx)?;
    let attempt = ctx.db.raid_attempt().id().find(attempt_id).ok_or("RAID_ATTEMPT_UNAVAILABLE")?;
    let player = ctx.db.player().player_id().find(attempt.player_id).ok_or("PLAYER_MISSING")?;
    if !matches!(player.discord_user_id, 99002 | 99003) {
        return Err("PROOF_ACTOR_REQUIRED".into());
    }
    Ok(attempt)
}

#[spacetimedb::reducer]
pub fn proof_raid_finish(ctx: &ReducerContext, attempt_id: u128) -> Result<(), String> {
    let mut attempt = proof_attempt(ctx, attempt_id)?;
    if !matches!(attempt.status.as_str(), "deployed" | "running") {
        return Err("PROOF_DEPLOYMENT_REQUIRED".into());
    }
    // Accelerate deadlines only after a real, supply-consuming deployment.
    // The production settlement still owns every reward, catch, seal and receipt.
    attempt.starts_at = ctx.timestamp - TimeDuration::from_micros(
        i64::from(attempt.duration_seconds) * rules::MICROS_PER_SECOND,
    );
    attempt.started_at = Some(attempt.starts_at);
    attempt.ends_at = ctx.timestamp;
    attempt.status = "running".into();
    complete_attempt(ctx, &mut attempt)
}

#[spacetimedb::reducer]
pub fn proof_raid_make_due(ctx: &ReducerContext, attempt_id: u128) -> Result<(), String> {
    let mut attempt = proof_attempt(ctx, attempt_id)?;
    if !matches!(attempt.status.as_str(), "deployed" | "running") {
        return Err("PROOF_DEPLOYMENT_REQUIRED".into());
    }
    attempt.ends_at = ctx.timestamp - TimeDuration::from_micros(1);
    attempt.starts_at = attempt.ends_at - TimeDuration::from_micros(
        i64::from(attempt.duration_seconds) * rules::MICROS_PER_SECOND,
    );
    attempt.started_at = Some(attempt.starts_at);
    attempt.status = "running".into();
    ctx.db.raid_attempt().id().update(attempt);
    remove_jobs(ctx, attempt_id);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_raid_expire_window(ctx: &ReducerContext, attempt_id: u128) -> Result<(), String> {
    let mut attempt = proof_attempt(ctx, attempt_id)?;
    if attempt.status != "prepared" {
        return Err("PROOF_PREPARATION_REQUIRED".into());
    }
    attempt.starts_at = ctx.timestamp - TimeDuration::from_micros(1);
    attempt.deployment_deadline = attempt.starts_at;
    attempt.ends_at = attempt.starts_at + TimeDuration::from_micros(
        i64::from(attempt.duration_seconds) * rules::MICROS_PER_SECOND,
    );
    ctx.db.raid_attempt().id().update(attempt);
    remove_jobs(ctx, attempt_id);
    schedule_job(ctx, attempt_id, "start", ctx.timestamp + TimeDuration::from_micros(2_000_000));
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_raid_open_event(ctx: &ReducerContext, event_id: u64) -> Result<(), String> {
    require_raid_proof(ctx)?;
    if !matches!(event_id, 90990101 | 90990102) {
        return Err("PROOF_EVENT_REQUIRED".into());
    }
    if ctx.db.raid_attempt().iter().any(|attempt| attempt.event_id == Some(event_id)) {
        return Err("PROOF_EVENT_ALREADY_USED".into());
    }
    let starts_at = ctx.timestamp + TimeDuration::from_micros(900_000_000);
    let event = CommunityRaidEvent {
        event_id,
        name: "Isolated Proof Convergence".into(),
        signup_at: ctx.timestamp - TimeDuration::from_micros(60_000_000),
        starts_at,
        ends_at: starts_at + TimeDuration::from_micros(
            i64::from(rules::catalog().community.duration_seconds) * rules::MICROS_PER_SECOND,
        ),
    };
    if ctx.db.community_raid_event().event_id().find(event_id).is_some() {
        ctx.db.community_raid_event().event_id().update(event);
    } else {
        ctx.db.community_raid_event().insert(event);
    }
    Ok(())
}
