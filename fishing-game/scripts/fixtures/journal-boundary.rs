// Only compiled into the isolated journal proof module.
include!("crafting-boundary.rs");

#[spacetimedb::reducer]
pub fn proof_legacy_journal(ctx: &ReducerContext, discord_user_id: u64) -> Result<(), String> {
    proof_owner(ctx)?;
    let p = accounts::ensure_player(ctx, discord_user_id, "Journal proof")?;
    let player_id = p.player_id;
    let receipt = |id: u64, species: Option<u32>, rarity: &str, age: i64, xp: u64, catch_id: Option<u64>| CommandReceipt {
        interaction_id: id, player_id, discord_user_id, guild_id: Some(5), channel_id: 42,
        command: "fish".into(), caught_at: ctx.timestamp - spacetimedb::TimeDuration::from_micros(age),
        outcome: if species.is_some() { "fish" } else { "junk" }.into(), catch_id, species_id: species,
        rarity: rarity.into(), length_mm: if species.is_some() { id as u32 } else { 0 },
        weight_g: if species.is_some() { id } else { 0 }, size_grade: 2, xp_granted: xp,
        coins_granted: 0, sale_value_coins: 5, rules_version: 7, content_version: 5,
        item_granted: String::new(), item_quantity: 1, biome_id: 1, rod_id: 1,
    };
    let a = receipt(100, Some(1), "F", 10 * 86_400_000_000, 0, Some(50001));
    let b = receipt(200, Some(2), "C", 9 * 86_400_000_000, 111, Some(50002));
    let c = receipt(300, Some(3), "A", 8 * 86_400_000_000, 222, Some(50003));
    let d = receipt(400, None, "", 86_400_000_000, 10, None);
    let e = receipt(500, Some(4), "S", 86_400_000_000, 30, Some(50004));
    let e2 = receipt(500, None, "", 86_400_000_000, 10, None);
    let f = receipt(600, Some(5), "UUR", 86_400_000_000, 99, Some(50005));
    for r in [&a, &b] {
        ctx.db.owned_specimen().insert(OwnedSpecimen { catch_id: r.catch_id.unwrap(), player_id: p.player_id,
            species_id: r.species_id.unwrap(), rarity: r.rarity.clone(), length_mm: r.length_mm, weight_g: r.weight_g,
            size_grade: 2, caught_at: r.caught_at, sale_value_coins: 5, favorite: false,
            rules_version: 7, content_version: 5, biome_id: 1, source_guild_id: Some(5) });
    }
    let mut p = p; p.kept_count = 2; ctx.db.player().player_id().update(p);
    for r in [&b, &c, &d, &d, &e, &e2] {
        ctx.db.recent_catch().insert(RecentCatch { recent_id: 0, player_id: r.player_id, caught_at: r.caught_at,
            outcome: r.outcome.clone(), species_id: r.species_id, rarity: r.rarity.clone(),
            length_mm: r.length_mm, weight_g: r.weight_g, size_grade: 2, xp_granted: r.xp_granted });
    }
    for index in 0..2u128 {
        ctx.db.cast_pull().insert(CastPull { key: 400 << 8 | index, interaction_id: 400,
            caught_at: d.caught_at, player_id: d.player_id, base_xp: 10,
            receipt: receipt(400, None, "", 86_400_000_000, 10, None) });
    }
    let mut aggregate = e; aggregate.xp_granted = 40;
    ctx.db.command_receipt().insert(aggregate);
    ctx.db.command_receipt().insert(f);
    Ok(())
}

#[spacetimedb::reducer]
pub fn proof_age_receipts(ctx: &ReducerContext) -> Result<(), String> {
    proof_owner(ctx)?;
    let old = ctx.timestamp - spacetimedb::TimeDuration::from_micros(8 * 86_400_000_000);
    for mut r in ctx.db.cast_pull().iter().collect::<Vec<_>>() { r.caught_at = old; ctx.db.cast_pull().key().update(r); }
    for mut r in ctx.db.command_receipt().iter().collect::<Vec<_>>() { r.caught_at = old; ctx.db.command_receipt().interaction_id().update(r); }
    for mut r in ctx.db.cast_equipment_receipt().iter().collect::<Vec<_>>() { r.caught_at = old; ctx.db.cast_equipment_receipt().interaction_id().update(r); }
    // The normal production scheduler will prune these on its next minute tick.
    Ok(())
}
