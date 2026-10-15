// Owner-only controls for isolated quest proofs. Never included in the game module.
#[spacetimedb::reducer]
pub fn proof_quest_history(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Quest proof")?;
    let player_id = player.player_id;
    let mut species: Vec<_> = ctx.db.species_definition().iter().filter(|s| s.counts_for_ordinary_collection_completion).collect();
    species.sort_by_key(|s| (s.biome_id, std::cmp::Reverse(s.encounter_weight), s.species_id));
    let mut ids = vec![];
    for biome in 1..=7 {
        for s in species.iter().filter(|s| s.biome_id == biome).take(if biome == 1 {3} else {10}) {
            let key = u128::from(player_id) << 32 | u128::from(s.species_id);
            let mut ranks = vec![0; 10]; for value in ranks.iter_mut().take(8) { *value = 1; }
            let row = PlayerSpeciesProgress {key, player_id, species_id: s.species_id, count: 8, rank_counts: ranks, first_caught_at: ctx.timestamp, best_length_mm: s.typical_length_mm + 1, best_weight_g: s.typical_weight_g};
            if ctx.db.player_species_progress().key().find(key).is_some() {ctx.db.player_species_progress().key().update(row);} else {ctx.db.player_species_progress().insert(row);}
            ids.push(s.species_id);
        }
    }
    for biome in 1..=7 {
        let s = species.iter().find(|s| s.biome_id == biome).ok_or("SPECIES_MISSING")?;
        ctx.db.owned_specimen().insert(OwnedSpecimen {catch_id: 0, player_id, species_id: s.species_id, rarity: "F".into(), length_mm: s.min_length_mm, weight_g: s.min_weight_g, size_grade: 0, caught_at: ctx.timestamp, sale_value_coins: 5, favorite: false, rules_version: 16, content_version: 5, biome_id: biome, source_guild_id: None});
    }
    player.kept_count = ctx.db.owned_specimen().player_id().filter(player_id).count() as u32;
    player.fish_count = player.fish_count.max(ids.len() as u64);
    player.junk_count = player.junk_count.max(15);
    player.completed_casts = player.fish_count + player.junk_count + player.treasure_count;
    ctx.db.player().player_id().update(player);
    let key = u128::from(player_id) << 32 | 1;
    let mut rod = ctx.db.owned_rod().key().find(key).ok_or("ROD_MISSING")?;
    rod.upgrade_level = 3; ctx.db.owned_rod().key().update(rod);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_quest_week(ctx: &ReducerContext, player_id: u64, previous: bool) -> Result<(), String> {
    proof_owner(ctx)?;
    quests::refresh_calendar(ctx);
    let week = ctx.db.quest_calendar().singleton().find(1).ok_or("CALENDAR_MISSING")?.week;
    let ids: Vec<_> = ctx.db.species_definition().iter().filter(|s| s.counts_for_ordinary_collection_completion).take(5).map(|s| s.species_id).collect();
    let row = WeeklyQuestProgress {player_id, week: week - i64::from(previous), fish_count: 15, junk_count: 3, species_ids: ids, claimed_quest_id: if previous {301} else {0}, bait_id: 0};
    if ctx.db.weekly_quest_progress().player_id().find(player_id).is_some() {ctx.db.weekly_quest_progress().player_id().update(row);} else {ctx.db.weekly_quest_progress().insert(row);}
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_quest_link(ctx: &ReducerContext, identity: spacetimedb::Identity, player_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    ctx.db.player_identity().insert(PlayerIdentity {identity, player_id, linked_at: ctx.timestamp});
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_quest_pull(ctx: &ReducerContext, player_id: u64, outcome: String, species_id: u32, times: u32) -> Result<(), String> {
    proof_owner(ctx)?;
    if times > 100 { return Err("PROOF_LIMIT".into()); }
    let mut receipt = ctx.db.command_receipt().player_id().filter(player_id).next().or_else(||ctx.db.command_receipt().iter().next()).ok_or("RECEIPT_MISSING")?;
    receipt.player_id = player_id;
    receipt.outcome = outcome;
    receipt.species_id = Some(species_id);
    for _ in 0..times { quests::observe_pull(ctx, &receipt)?; }
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_quest_maximum(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let s = ctx.db.species_definition().iter().find(|s| s.counts_for_ordinary_collection_completion).ok_or("SPECIES_MISSING")?;
    ctx.db.rank_maximum().insert(RankMaximum {key: (u128::from(player_id)<<64) | (u128::from(s.species_id)<<8) | 9, player_id, species_id:s.species_id,rarity:"UUR".into(),first_catch_id:1,achieved_at:ctx.timestamp,length_mm:s.max_length_mm,weight_g:s.max_weight_g});
    Ok(())
}
