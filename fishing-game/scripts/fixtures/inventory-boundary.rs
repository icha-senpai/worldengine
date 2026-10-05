// Compiled only into the isolated inventory proof module, never the game module.
#[spacetimedb::reducer]
pub fn prepare_hoarder(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_UNAVAILABLE")?;
    if owner.identity != ctx.sender() {
        return Err("OWNER_REQUIRED".into());
    }
    let mut player = accounts::ensure_player(ctx, discord_user_id, "Inventory boundary proof")?;
    if player.kept_count == 0 {
        let species = ctx
            .db
            .species_definition()
            .biome_id()
            .filter(1u32)
            .find(|fish| fish.counts_for_ordinary_collection_completion)
            .ok_or("CONTENT_UNAVAILABLE")?;
        for _ in 0..100 {
            ctx.db.owned_specimen().insert(OwnedSpecimen {
                catch_id: 0,
                player_id: player.player_id,
                species_id: species.species_id,
                rarity: "F".into(),
                length_mm: species.min_length_mm,
                weight_g: species.min_weight_g,
                size_grade: 0,
                caught_at: ctx.timestamp,
                sale_value_coins: 10,
                favorite: false,
                rules_version: 1,
                content_version: content::CONTENT_VERSION,
                biome_id: 1,
                source_guild_id: None,
            });
        }
        let mut rank_counts = vec![0; 10];
        rank_counts[0] = 100;
        ctx.db
            .player_species_progress()
            .insert(PlayerSpeciesProgress {
                key: (u128::from(player.player_id) << 32) | u128::from(species.species_id),
                player_id: player.player_id,
                species_id: species.species_id,
                count: 100,
                rank_counts,
                first_caught_at: ctx.timestamp,
                best_length_mm: species.min_length_mm,
                best_weight_g: species.min_weight_g,
            });
        player.kept_count = 100;
        player.fish_count = 100;
        player.completed_casts = 100;
        let mut profile = ctx
            .db
            .public_profile()
            .player_id()
            .find(player.player_id)
            .ok_or("PROFILE_MISSING")?;
        profile.fish_count = 100;
        profile.discoveries = 1;
        ctx.db.public_profile().player_id().update(profile);
    }
    // Fixture advancement avoids waiting minutes. Each test still checks the actual cooldown first.
    player.next_cast_at = ctx.timestamp;
    let player_id = player.player_id;
    ctx.db.player().player_id().update(player);
    records::refresh_player(ctx, player_id)
}
