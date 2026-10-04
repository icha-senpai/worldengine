use crate::tables::*;
use spacetimedb::{Identity, ReducerContext, Table, TimeDuration, ViewContext, rand::Rng};

pub fn require_service(ctx: &ReducerContext, role: ServiceRole) -> Result<(), String> {
    match ctx.db.service_principal().identity().find(ctx.sender()) {
        Some(service) if service.active && service.role == role => Ok(()),
        _ => Err("SERVICE_UNAUTHORIZED".into()),
    }
}
pub fn service_can_read(ctx: &ViewContext, role: ServiceRole) -> bool {
    ctx.db
        .service_principal()
        .identity()
        .find(ctx.sender())
        .is_some_and(|service| service.active && service.role == role)
}
pub fn current_player(ctx: &ViewContext) -> Option<u64> {
    ctx.db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .map(|row| row.player_id)
}
pub fn config(ctx: &ReducerContext) -> GameConfig {
    ctx.db
        .game_config()
        .iter()
        .max_by_key(|rules| rules.version)
        .expect("initialized game config")
}
pub fn ensure_player(
    ctx: &ReducerContext,
    discord_user_id: u64,
    display_name: &str,
) -> Result<Player, String> {
    if discord_user_id == 0 {
        return Err("INVALID_DISCORD_USER".into());
    }
    if let Some(player) = ctx.db.player().discord_user_id().find(discord_user_id) {
        return Ok(player);
    }
    let name: String = display_name
        .chars()
        .filter(|c| !c.is_control() && *c != '@')
        .take(40)
        .collect();
    let name = if name.trim().is_empty() {
        "Angler".into()
    } else {
        name.trim().to_owned()
    };
    let player = ctx.db.player().insert(Player {
        player_id: 0,
        discord_user_id,
        display_name: name.clone(),
        created_at: ctx.timestamp,
        total_xp: 0,
        coins: 0,
        completed_casts: 0,
        fish_count: 0,
        junk_count: 0,
        treasure_count: 0,
        kept_count: 0,
        next_cast_at: ctx.timestamp,
        selected_biome_id: 1,
        equipped_rod_id: 1,
    });
    ctx.db.owned_rod().insert(OwnedRod {
        key: u128::from(player.player_id) << 32 | 1,
        player_id: player.player_id,
        rod_id: 1,
        upgrade_level: 0,
    });
    ctx.db.public_profile().insert(PublicProfile {
        player_id: player.player_id,
        display_name: name,
        level: 1,
        fish_count: 0,
        discoveries: 0,
    });
    Ok(player)
}

#[spacetimedb::reducer]
pub fn configure_service(
    ctx: &ReducerContext,
    identity: Identity,
    role: ServiceRole,
    active: bool,
) -> Result<(), String> {
    let owner = ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_UNAVAILABLE")?;
    if ctx.sender() != owner.identity {
        return Err("OWNER_REQUIRED".into());
    }
    let row = ServicePrincipal {
        identity,
        role,
        active,
    };
    if ctx
        .db
        .service_principal()
        .identity()
        .find(identity)
        .is_some()
    {
        ctx.db.service_principal().identity().update(row);
    } else {
        ctx.db.service_principal().insert(row);
    }
    ctx.db.admin_audit().insert(AdminAudit {
        audit_id: 0,
        actor: ctx.sender(),
        target: identity,
        action: format!("service_role:{role:?}:active={active}"),
        created_at: ctx.timestamp,
    });
    Ok(())
}

#[spacetimedb::reducer]
pub fn select_discord_player(
    ctx: &ReducerContext,
    discord_user_id: u64,
    display_name: String,
    interaction_id: u64,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    let player = ensure_player(ctx, discord_user_id, &display_name)?;
    let selection = ServiceSelection {
        identity: ctx.sender(),
        player_id: player.player_id,
        interaction_id,
    };
    if ctx
        .db
        .service_selection()
        .identity()
        .find(ctx.sender())
        .is_some()
    {
        ctx.db.service_selection().identity().update(selection);
    } else {
        ctx.db.service_selection().insert(selection);
    }
    Ok(())
}

#[spacetimedb::reducer]
pub fn begin_link_challenge(ctx: &ReducerContext) -> Result<(), String> {
    if let Some(old) = ctx
        .db
        .link_challenge()
        .browser_identity()
        .find(ctx.sender())
    {
        if ctx.timestamp < old.created_at + TimeDuration::from_micros(30_000_000) {
            return Err("LINK_RATE_LIMITED".into());
        }
        ctx.db
            .link_challenge()
            .challenge_id()
            .delete(old.challenge_id);
    }
    ctx.db.link_challenge().insert(LinkChallenge {
        challenge_id: 0,
        browser_identity: ctx.sender(),
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(600_000_000),
        consumed: false,
        proof: ctx.rng().r#gen(),
    });
    Ok(())
}

#[spacetimedb::reducer]
pub fn complete_account_link(
    ctx: &ReducerContext,
    challenge_id: u64,
    verified_discord_user_id: u64,
    proof: u128,
) -> Result<(), String> {
    require_service(ctx, ServiceRole::AccountLinker)?;
    let mut challenge = ctx
        .db
        .link_challenge()
        .challenge_id()
        .find(challenge_id)
        .ok_or("LINK_NOT_FOUND")?;
    if challenge.proof != proof {
        return Err("LINK_PROOF_INVALID".into());
    }
    if challenge.consumed {
        let player_id = ctx
            .db
            .player()
            .discord_user_id()
            .find(verified_discord_user_id)
            .map(|player| player.player_id);
        if ctx
            .db
            .player_identity()
            .identity()
            .find(challenge.browser_identity)
            .is_some_and(|mapping| Some(mapping.player_id) == player_id)
        {
            return Ok(());
        }
        return Err("LINK_USED".into());
    }
    if ctx.timestamp >= challenge.expires_at {
        return Err("LINK_EXPIRED".into());
    }
    let player = ensure_player(ctx, verified_discord_user_id, "Angler")?;
    if let Some(existing) = ctx
        .db
        .player_identity()
        .identity()
        .find(challenge.browser_identity)
    {
        if existing.player_id != player.player_id {
            return Err("LINK_CONFLICT".into());
        }
        ctx.db
            .player_identity()
            .identity()
            .delete(existing.identity);
    }
    if let Some(old) = ctx.db.player_identity().player_id().find(player.player_id) {
        ctx.db.player_identity().identity().delete(old.identity);
        ctx.db.action_nonce().identity().delete(old.identity);
    }
    ctx.db.player_identity().insert(PlayerIdentity {
        identity: challenge.browser_identity,
        player_id: player.player_id,
        linked_at: ctx.timestamp,
    });
    challenge.consumed = true;
    ctx.db.link_challenge().challenge_id().update(challenge);
    Ok(())
}

#[spacetimedb::view(accessor = my_profile, public)]
pub fn my_profile(ctx: &ViewContext) -> Option<PublicProfile> {
    ctx.db
        .public_profile()
        .player_id()
        .find(current_player(ctx)?)
}
#[spacetimedb::view(accessor = my_player, public)]
pub fn my_player(ctx: &ViewContext) -> Option<Player> {
    ctx.db.player().player_id().find(current_player(ctx)?)
}
#[spacetimedb::view(accessor = my_inventory, public)]
pub fn my_inventory(ctx: &ViewContext) -> Vec<OwnedSpecimen> {
    current_player(ctx)
        .map(|id| ctx.db.owned_specimen().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = my_collection, public)]
pub fn my_collection(ctx: &ViewContext) -> Vec<PlayerSpeciesProgress> {
    current_player(ctx)
        .map(|id| {
            ctx.db
                .player_species_progress()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = my_recent_catches, public)]
pub fn my_recent_catches(ctx: &ViewContext) -> Vec<RecentCatch> {
    current_player(ctx)
        .map(|id| ctx.db.recent_catch().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = my_link_challenge, public)]
pub fn my_link_challenge(ctx: &ViewContext) -> Option<LinkChallenge> {
    ctx.db
        .link_challenge()
        .browser_identity()
        .find(ctx.sender())
}

#[spacetimedb::view(accessor = my_service, public)]
pub fn my_service(ctx: &ViewContext) -> Option<ServicePrincipal> {
    ctx.db.service_principal().identity().find(ctx.sender())
}

#[spacetimedb::view(accessor = my_action, public)]
pub fn my_action(ctx: &ViewContext) -> Option<ActionNonce> {
    // A revoked browser must not recover its old action quote.
    let player_id = current_player(ctx)?;
    ctx.db
        .action_nonce()
        .identity()
        .find(ctx.sender())
        .filter(|action| action.player_id == player_id)
}

#[spacetimedb::reducer]
pub fn unlink_browser(ctx: &ReducerContext) {
    ctx.db.player_identity().identity().delete(ctx.sender());
    ctx.db.action_nonce().identity().delete(ctx.sender());
    if let Some(challenge) = ctx
        .db
        .link_challenge()
        .browser_identity()
        .find(ctx.sender())
    {
        ctx.db
            .link_challenge()
            .challenge_id()
            .delete(challenge.challenge_id);
    }
}

#[spacetimedb::view(accessor = my_items, public)]
pub fn my_items(ctx: &ViewContext) -> Vec<ItemStack> {
    current_player(ctx)
        .map(|id| ctx.db.item_stack().player_id().filter(id).collect())
        .unwrap_or_default()
}

#[spacetimedb::view(accessor = my_ledger, public)]
pub fn my_ledger(ctx: &ViewContext) -> Vec<EconomyLedger> {
    let mut rows: Vec<_> = current_player(ctx)
        .map(|id| ctx.db.economy_ledger().player_id().filter(id).collect())
        .unwrap_or_default();
    rows.sort_by_key(|row| std::cmp::Reverse(row.ledger_id));
    rows.truncate(100);
    rows
}
#[spacetimedb::view(accessor = adapter_player, public)]
pub fn adapter_player(ctx: &ViewContext) -> Option<Player> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return None;
    }
    let selection = ctx.db.service_selection().identity().find(ctx.sender())?;
    ctx.db.player().player_id().find(selection.player_id)
}
#[spacetimedb::view(accessor = adapter_inventory, public)]
pub fn adapter_inventory(ctx: &ViewContext) -> Vec<OwnedSpecimen> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return vec![];
    }
    ctx.db
        .service_selection()
        .identity()
        .find(ctx.sender())
        .map(|selection| {
            ctx.db
                .owned_specimen()
                .player_id()
                .filter(selection.player_id)
                .collect()
        })
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = adapter_collection, public)]
pub fn adapter_collection(ctx: &ViewContext) -> Vec<PlayerSpeciesProgress> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return vec![];
    }
    ctx.db
        .service_selection()
        .identity()
        .find(ctx.sender())
        .map(|selection| {
            ctx.db
                .player_species_progress()
                .player_id()
                .filter(selection.player_id)
                .collect()
        })
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = adapter_receipt, public)]
pub fn adapter_receipt(ctx: &ViewContext) -> Option<CommandReceipt> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return None;
    }
    let selection = ctx.db.service_selection().identity().find(ctx.sender())?;
    let receipt = ctx
        .db
        .command_receipt()
        .interaction_id()
        .find(selection.interaction_id)?;
    (receipt.player_id == selection.player_id).then_some(receipt)
}
