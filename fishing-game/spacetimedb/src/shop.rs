use crate::{
    accounts::{config, current_player, require_service, service_can_read},
    inventory::ledger,
    tables::*,
};
use game_rules::progression::{check_interaction_freshness, level_for_xp};
use serde::Deserialize;
use spacetimedb::{ReducerContext, Table, TimeDuration, ViewContext, rand::Rng};

#[derive(Deserialize)]
struct Catalog {
    version: u32,
    listings: Vec<Listing>,
}
#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Listing {
    listing_id: u32,
    kind: String,
    target_id: u32,
    name: String,
    price_coins: u64,
    minimum_level: u32,
    previous_biome_id: u32,
    biome_id: u32,
}

pub fn seed(ctx: &ReducerContext) {
    let catalog: Catalog = serde_json::from_str(include_str!("../../content/trader.json"))
        .expect("validated trader catalog");
    for row in catalog.listings {
        let listing = ShopListing {
            listing_id: row.listing_id,
            kind: row.kind,
            target_id: row.target_id,
            name: row.name,
            price_coins: row.price_coins,
            minimum_level: row.minimum_level,
            previous_biome_id: row.previous_biome_id,
            biome_id: row.biome_id,
            catalog_version: catalog.version,
        };
        if ctx
            .db
            .shop_listing()
            .listing_id()
            .find(listing.listing_id)
            .is_some()
        {
            ctx.db.shop_listing().listing_id().update(listing);
        } else {
            ctx.db.shop_listing().insert(listing);
        }
    }
}

pub fn owns_licence(ctx: &ReducerContext, player_id: u64, biome_id: u32) -> bool {
    biome_id == 1
        || ctx
            .db
            .owned_biome_licence()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(biome_id))
            .is_some()
}

fn owned(ctx: &ReducerContext, player_id: u64, listing: &ShopListing) -> bool {
    if listing.kind == "bait" {
        return false;
    }
    if listing.kind == "licence" {
        owns_licence(ctx, player_id, listing.target_id)
    } else {
        ctx.db
            .owned_rod()
            .key()
            .find((u128::from(player_id) << 32) | u128::from(listing.target_id))
            .is_some()
    }
}

fn eligible(ctx: &ReducerContext, player: &Player, listing: &ShopListing) -> Result<(), String> {
    if level_for_xp(player.total_xp, config(ctx).level_cap) < listing.minimum_level {
        return Err("SHOP_LEVEL_REQUIRED".into());
    }
    if !owns_licence(ctx, player.player_id, listing.previous_biome_id) {
        return Err("PREVIOUS_LICENCE_REQUIRED".into());
    }
    if owned(ctx, player.player_id, listing) {
        return Err("ALREADY_OWNED".into());
    }
    if player.coins < listing.price_coins {
        return Err("INSUFFICIENT_COINS".into());
    }
    Ok(())
}

fn quote_key(ctx: &ReducerContext, player_id: u64) -> String {
    format!("{}:{player_id}", ctx.sender())
}

fn prepare(ctx: &ReducerContext, player_id: u64, listing_id: u32) -> Result<(), String> {
    let player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    let listing = ctx
        .db
        .shop_listing()
        .listing_id()
        .find(listing_id)
        .ok_or("SHOP_ITEM_UNAVAILABLE")?;
    eligible(ctx, &player, &listing)?;
    let key = quote_key(ctx, player_id);
    if let Some(old) = ctx.db.shop_quote().key().find(&key) {
        if ctx.timestamp < old.created_at + TimeDuration::from_micros(1_000_000) {
            if !old.consumed
                && old.listing_id == listing_id
                && old.quoted_coins == listing.price_coins
                && old.catalog_version == listing.catalog_version
            {
                return Ok(());
            }
            return Err("ACTION_RATE_LIMITED".into());
        }
    }
    let quote = ShopQuote {
        key: key.clone(),
        identity: ctx.sender(),
        player_id,
        nonce: ctx.rng().r#gen(),
        listing_id,
        quoted_coins: listing.price_coins,
        catalog_version: listing.catalog_version,
        created_at: ctx.timestamp,
        expires_at: ctx.timestamp + TimeDuration::from_micros(120_000_000),
        consumed: false,
    };
    if ctx.db.shop_quote().key().find(&key).is_some() {
        ctx.db.shop_quote().key().update(quote);
    } else {
        ctx.db.shop_quote().insert(quote);
    }
    Ok(())
}

fn commit(ctx: &ReducerContext, player_id: u64, nonce: u128) -> Result<(), String> {
    let key = quote_key(ctx, player_id);
    let mut quote = ctx
        .db
        .shop_quote()
        .key()
        .find(&key)
        .ok_or("ACTION_NOT_FOUND")?;
    if quote.nonce != nonce || quote.player_id != player_id || quote.identity != ctx.sender() {
        return Err("ACTION_CONFLICT".into());
    }
    if quote.consumed {
        return Ok(());
    }
    if ctx.timestamp >= quote.expires_at {
        return Err("ACTION_EXPIRED".into());
    }
    let listing = ctx
        .db
        .shop_listing()
        .listing_id()
        .find(quote.listing_id)
        .ok_or("SHOP_ITEM_UNAVAILABLE")?;
    if listing.catalog_version != quote.catalog_version || listing.price_coins != quote.quoted_coins
    {
        return Err("QUOTE_CHANGED".into());
    }
    let mut player = ctx
        .db
        .player()
        .player_id()
        .find(player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    eligible(ctx, &player, &listing)?;
    player.coins = player
        .coins
        .checked_sub(listing.price_coins)
        .ok_or("INSUFFICIENT_COINS")?;
    ctx.db.player().player_id().update(player);
    let ownership_key = (u128::from(player_id) << 32) | u128::from(listing.target_id);
    if listing.kind == "licence" {
        ctx.db.owned_biome_licence().insert(OwnedBiomeLicence {
            key: ownership_key,
            player_id,
            biome_id: listing.target_id,
        });
    } else if listing.kind == "bait" {
        crate::crafting::grant_bait(ctx, player_id, listing.target_id)?;
    } else {
        ctx.db.owned_rod().insert(OwnedRod {
            key: ownership_key,
            player_id,
            rod_id: listing.target_id,
            upgrade_level: 0,
        });
    }
    let operation = format!("shop:{nonce}");
    ledger(
        ctx,
        player_id,
        operation.clone(),
        "coins",
        -(listing.price_coins as i64),
        "trader_purchase",
    );
    ledger(
        ctx,
        player_id,
        operation,
        &format!("{}_{}", listing.kind, listing.target_id),
        1,
        "trader_purchase",
    );
    quote.consumed = true;
    ctx.db.shop_quote().key().update(quote);
    crate::achievements::refresh_player(ctx, player_id)
}

pub(crate) fn browser_player(ctx: &ReducerContext) -> Result<u64, String> {
    ctx.db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .map(|row| row.player_id)
        .ok_or("ACCOUNT_NOT_LINKED".into())
}
pub(crate) fn adapter_player_id(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
) -> Result<u64, String> {
    require_service(ctx, ServiceRole::DiscordAdapter)?;
    check_interaction_freshness(interaction_id, ctx.timestamp.to_micros_since_unix_epoch())?;
    let selection = ctx
        .db
        .service_selection()
        .identity()
        .find(ctx.sender())
        .ok_or("PLAYER_UNAVAILABLE")?;
    let player = ctx
        .db
        .player()
        .player_id()
        .find(selection.player_id)
        .ok_or("PLAYER_UNAVAILABLE")?;
    if player.discord_user_id != discord_user_id || selection.interaction_id != interaction_id {
        return Err("REQUEST_CONFLICT".into());
    }
    Ok(player.player_id)
}
#[spacetimedb::reducer]
pub fn prepare_shop_purchase(ctx: &ReducerContext, listing_id: u32) -> Result<(), String> {
    prepare(ctx, browser_player(ctx)?, listing_id)
}
#[spacetimedb::reducer]
pub fn commit_shop_purchase(ctx: &ReducerContext, nonce: u128) -> Result<(), String> {
    commit(ctx, browser_player(ctx)?, nonce)
}
#[spacetimedb::reducer]
pub fn prepare_shop_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    listing_id: u32,
) -> Result<(), String> {
    prepare(
        ctx,
        adapter_player_id(ctx, discord_user_id, interaction_id)?,
        listing_id,
    )
}
#[spacetimedb::reducer]
pub fn commit_shop_from_discord(
    ctx: &ReducerContext,
    discord_user_id: u64,
    interaction_id: u64,
    nonce: u128,
) -> Result<(), String> {
    commit(
        ctx,
        adapter_player_id(ctx, discord_user_id, interaction_id)?,
        nonce,
    )
}

#[spacetimedb::view(accessor = my_rods, public)]
pub fn my_rods(ctx: &ViewContext) -> Vec<OwnedRod> {
    current_player(ctx)
        .map(|id| ctx.db.owned_rod().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = my_licences, public)]
pub fn my_licences(ctx: &ViewContext) -> Vec<OwnedBiomeLicence> {
    current_player(ctx)
        .map(|id| {
            ctx.db
                .owned_biome_licence()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = my_shop_quote, public)]
pub fn my_shop_quote(ctx: &ViewContext) -> Option<ShopQuote> {
    let id = current_player(ctx)?;
    ctx.db
        .shop_quote()
        .key()
        .find(format!("{}:{id}", ctx.sender()))
}
pub(crate) fn selected(ctx: &ViewContext) -> Option<u64> {
    if !service_can_read(ctx, ServiceRole::DiscordAdapter) {
        return None;
    }
    Some(
        ctx.db
            .service_selection()
            .identity()
            .find(ctx.sender())?
            .player_id,
    )
}
#[spacetimedb::view(accessor = adapter_rods, public)]
pub fn adapter_rods(ctx: &ViewContext) -> Vec<OwnedRod> {
    selected(ctx)
        .map(|id| ctx.db.owned_rod().player_id().filter(id).collect())
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = adapter_licences, public)]
pub fn adapter_licences(ctx: &ViewContext) -> Vec<OwnedBiomeLicence> {
    selected(ctx)
        .map(|id| {
            ctx.db
                .owned_biome_licence()
                .player_id()
                .filter(id)
                .collect()
        })
        .unwrap_or_default()
}
#[spacetimedb::view(accessor = adapter_shop_quote, public)]
pub fn adapter_shop_quote(ctx: &ViewContext) -> Option<ShopQuote> {
    let id = selected(ctx)?;
    ctx.db
        .shop_quote()
        .key()
        .find(format!("{}:{id}", ctx.sender()))
}

/// One-time transition retains existing equipment and optionally visited waters.
#[spacetimedb::reducer]
pub fn activate_trader(ctx: &ReducerContext, grandfather_licences: bool) -> Result<(), String> {
    if ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .ok_or("OWNER_UNAVAILABLE")?
        .identity
        != ctx.sender()
    {
        return Err("OWNER_REQUIRED".into());
    }
    seed(ctx);
    if ctx.db.trader_migration().singleton().find(1).is_some() {
        return Ok(());
    }
    for mut player in ctx.db.player().iter() {
        if grandfather_licences {
            let furthest = ctx
                .db
                .player_species_progress()
                .player_id()
                .filter(player.player_id)
                .filter_map(|row| {
                    ctx.db
                        .species_definition()
                        .species_id()
                        .find(row.species_id)
                        .map(|species| species.biome_id)
                })
                .max()
                .unwrap_or(1)
                .max(player.selected_biome_id);
            for biome_id in 2..=furthest {
                let key = (u128::from(player.player_id) << 32) | u128::from(biome_id);
                if ctx.db.owned_biome_licence().key().find(key).is_none() {
                    ctx.db.owned_biome_licence().insert(OwnedBiomeLicence {
                        key,
                        player_id: player.player_id,
                        biome_id,
                    });
                }
            }
        } else if player.selected_biome_id != 1
            && !owns_licence(ctx, player.player_id, player.selected_biome_id)
        {
            player.selected_biome_id = 1;
            ctx.db.player().player_id().update(player);
        }
    }
    ctx.db.trader_migration().insert(TraderMigration {
        singleton: 1,
        grandfathered: grandfather_licences,
    });
    Ok(())
}
