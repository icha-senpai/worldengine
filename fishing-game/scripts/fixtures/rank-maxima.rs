// Test-only paired evidence, compiled exclusively into isolated proof modules.
#[spacetimedb::reducer]
pub fn proof_rank_evidence(ctx: &ReducerContext, discord_user_id: u64, species_id: u32, ordinal: u8, length_delta: u32, weight_delta: u64, source: u8) -> Result<(), String> {
    proof_owner(ctx)?;
    let player = accounts::ensure_player(ctx, discord_user_id, "Rank maxima proof")?;
    let species = ctx.db.species_definition().species_id().find(species_id).ok_or("SPECIES_UNAVAILABLE")?;
    let maximum = game_rules::measurements::maximum_in_size_band(game_rules::measurements::SpeciesMeasurements {
        typical_length_mm: species.typical_length_mm, min_length_mm: species.min_length_mm, max_length_mm: species.max_length_mm,
        typical_weight_g: species.typical_weight_g, min_weight_g: species.min_weight_g, max_weight_g: species.max_weight_g,
    }, ordinal)?;
    let rarity = game_rules::RARITY_TIERS[ordinal as usize].to_owned();
    let row = ctx.db.owned_specimen().insert(OwnedSpecimen {
        catch_id: 0, player_id: player.player_id, species_id, rarity: rarity.clone(),
        length_mm: maximum.length_mm - length_delta, weight_g: maximum.weight_g - weight_delta,
        size_grade: maximum.size_grade, caught_at: ctx.timestamp, sale_value_coins: 1, favorite: false,
        rules_version: 14, content_version: crate::content::CONTENT_VERSION, biome_id: species.biome_id, source_guild_id: None,
    });
    if source == 1 {
        ctx.db.journal_entry().insert(JournalEntry {key: format!("proof-{}", row.catch_id), player_id: player.player_id,
            catch_id: Some(row.catch_id), caught_at: row.caught_at, outcome: "fish".into(), species_id: Some(species_id),
            name: species.name, rarity, length_mm: row.length_mm, weight_g: row.weight_g, xp_granted: None, biome_id: Some(species.biome_id)});
        ctx.db.owned_specimen().catch_id().delete(row.catch_id);
    } else if source == 3 || source == 4 {
        let receipt = CommandReceipt {interaction_id: 800000 + row.catch_id, player_id: player.player_id,
            discord_user_id, guild_id: None, channel_id: 1, command: "fish".into(), caught_at: row.caught_at,
            outcome: "fish".into(), catch_id: Some(row.catch_id), species_id: Some(species_id), rarity,
            length_mm: row.length_mm, weight_g: row.weight_g, size_grade: row.size_grade,
            xp_granted: 0, coins_granted: 0, sale_value_coins: 1, rules_version: 14, content_version: crate::content::CONTENT_VERSION,
            item_granted: String::new(), item_quantity: 0, biome_id: species.biome_id, rod_id: 1};
        if source == 3 {ctx.db.command_receipt().insert(receipt);} else {
            ctx.db.cast_pull().insert(CastPull {key: u128::from(receipt.interaction_id) << 8, interaction_id: receipt.interaction_id,
                caught_at: receipt.caught_at, player_id: player.player_id, base_xp: 0, receipt});
        }
        ctx.db.owned_specimen().catch_id().delete(row.catch_id);
    } else if source == 2 {
        let mut invalid = row; invalid.rarity = "invalid".into(); ctx.db.owned_specimen().catch_id().update(invalid);
    }
    // Independent bests intentionally look perfect even for two near-miss specimens.
    let key = u128::from(player.player_id) << 32 | u128::from(species_id);
    let mut progress = ctx.db.player_species_progress().key().find(key).unwrap_or(PlayerSpeciesProgress {
        key, player_id: player.player_id, species_id, count: 0, rank_counts: vec![0;10], first_caught_at: ctx.timestamp,
        best_length_mm: 0, best_weight_g: 0,
    });
    progress.count += 1; progress.rank_counts[ordinal as usize] += 1;
    progress.best_length_mm = progress.best_length_mm.max(maximum.length_mm - length_delta);
    progress.best_weight_g = progress.best_weight_g.max(maximum.weight_g - weight_delta);
    if ctx.db.player_species_progress().key().find(key).is_some() {ctx.db.player_species_progress().key().update(progress);} else {ctx.db.player_species_progress().insert(progress);}
    Ok(())
}
