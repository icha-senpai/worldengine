use crate::{accounts::current_player, tables::*};
use spacetimedb::{ReducerContext, Table, ViewContext};
use std::{cmp::Ordering, collections::HashSet};

fn name(ctx: &ReducerContext, species: Option<u32>, outcome: &str) -> String {
    species
        .and_then(|id| ctx.db.species_definition().species_id().find(id))
        .map(|row| row.name)
        .unwrap_or_else(|| {
            match outcome {
                "junk" => "Rusted tin",
                "treasure" => "Treasure cache",
                _ => "Fish",
            }
            .into()
        })
}

fn from_receipt(
    ctx: &ReducerContext,
    key: String,
    r: &CommandReceipt,
    xp: Option<u64>,
) -> JournalEntry {
    JournalEntry {
        key,
        player_id: r.player_id,
        catch_id: r.catch_id,
        caught_at: r.caught_at,
        outcome: r.outcome.clone(),
        species_id: r.species_id,
        name: name(ctx, r.species_id, &r.outcome),
        rarity: r.rarity.clone(),
        length_mm: r.length_mm,
        weight_g: r.weight_g,
        xp_granted: xp,
        biome_id: Some(r.biome_id),
    }
}

pub fn save_pull(ctx: &ReducerContext, pull: &CastPull) {
    let key = format!("pull:{}", pull.key);
    if ctx.db.journal_entry().key().find(&key).is_none() {
        ctx.db.journal_entry().insert(from_receipt(
            ctx,
            key,
            &pull.receipt,
            Some(pull.receipt.xp_granted),
        ));
    }
}

/// Owner-only, once per player. Reconcile overlaps as a multiset: two identical
/// bonus pulls are still two catches. No player balances or inventory are edited.
#[spacetimedb::reducer]
pub fn backfill_journal(ctx: &ReducerContext, player_id: u64) -> Result<(), String> {
    if !ctx
        .db
        .deployment_owner()
        .singleton()
        .find(1)
        .is_some_and(|r| r.identity == ctx.sender())
    {
        return Err("OWNER_REQUIRED".into());
    }
    if ctx.db.player().player_id().find(player_id).is_none() {
        return Err("PLAYER_MISSING".into());
    }
    if ctx
        .db
        .journal_import()
        .player_id()
        .find(player_id)
        .is_some()
    {
        return Ok(());
    }
    let pulls: Vec<_> = ctx
        .db
        .cast_pull()
        .iter()
        .filter(|r| r.player_id == player_id)
        .collect();
    let interactions: HashSet<_> = pulls.iter().map(|r| r.interaction_id).collect();
    for pull in pulls {
        save_pull(ctx, &pull);
    }
    // A command receipt can aggregate multiple pulls. Its XP is not per-fish XP.
    for r in ctx.db.command_receipt().player_id().filter(player_id) {
        if r.command != "fish"
            || !matches!(r.outcome.as_str(), "fish" | "junk" | "treasure")
            || interactions.contains(&r.interaction_id)
        {
            continue;
        }
        let key = format!("pull:{}", u128::from(r.interaction_id) << 8);
        if ctx.db.journal_entry().key().find(&key).is_none() {
            ctx.db
                .journal_entry()
                .insert(from_receipt(ctx, key, &r, None));
        }
    }
    let mut entries: Vec<_> = ctx
        .db
        .journal_entry()
        .player_id()
        .filter(player_id)
        .collect();
    let mut used = HashSet::new();
    let mut recent: Vec<_> = ctx
        .db
        .recent_catch()
        .player_id()
        .filter(player_id)
        .collect();
    recent.sort_by_key(|r| r.recent_id);
    for r in recent {
        let matches = |e: &&mut JournalEntry| {
            !used.contains(&e.key)
                && e.caught_at == r.caught_at
                && e.outcome == r.outcome
                && e.species_id == r.species_id
                && e.rarity == r.rarity
                && e.length_mm == r.length_mm
                && e.weight_g == r.weight_g
                && (e.xp_granted.is_none() || e.xp_granted == Some(r.xp_granted))
        };
        if let Some(e) = entries.iter_mut().find(matches) {
            used.insert(e.key.clone());
            if e.xp_granted.is_none() {
                e.xp_granted = Some(r.xp_granted);
            }
        } else {
            let e = JournalEntry {
                key: format!("recent:{}", r.recent_id),
                player_id,
                catch_id: None,
                caught_at: r.caught_at,
                outcome: r.outcome.clone(),
                species_id: r.species_id,
                name: name(ctx, r.species_id, &r.outcome),
                rarity: r.rarity,
                length_mm: r.length_mm,
                weight_g: r.weight_g,
                xp_granted: Some(r.xp_granted),
                biome_id: None,
            };
            used.insert(e.key.clone());
            entries.push(e);
        }
    }
    for fish in ctx.db.owned_specimen().player_id().filter(player_id) {
        if entries.iter().any(|e| e.catch_id == Some(fish.catch_id)) {
            continue;
        }
        if let Some(e) = entries.iter_mut().find(|e| {
            e.catch_id.is_none()
                && e.outcome == "fish"
                && e.caught_at == fish.caught_at
                && e.species_id == Some(fish.species_id)
                && e.rarity == fish.rarity
                && e.length_mm == fish.length_mm
                && e.weight_g == fish.weight_g
        }) {
            e.catch_id = Some(fish.catch_id);
            e.biome_id = Some(fish.biome_id);
        } else {
            entries.push(JournalEntry {
                key: format!("catch:{}", fish.catch_id),
                player_id,
                catch_id: Some(fish.catch_id),
                caught_at: fish.caught_at,
                outcome: "fish".into(),
                species_id: Some(fish.species_id),
                name: name(ctx, Some(fish.species_id), "fish"),
                rarity: fish.rarity,
                length_mm: fish.length_mm,
                weight_g: fish.weight_g,
                xp_granted: None,
                biome_id: Some(fish.biome_id),
            });
        }
    }
    for e in entries {
        if ctx.db.journal_entry().key().find(&e.key).is_some() {
            ctx.db.journal_entry().key().update(e);
        } else {
            ctx.db.journal_entry().insert(e);
        }
    }
    ctx.db.journal_import().insert(JournalImport {
        player_id,
        completed_at: ctx.timestamp,
    });
    Ok(())
}

#[spacetimedb::reducer]
pub fn select_journal(
    ctx: &ReducerContext,
    page: u32,
    page_size: u32,
    sort: String,
    descending: bool,
    search: String,
    biome_id: u32,
    rarity: String,
    outcome: String,
) -> Result<(), String> {
    let player_id = ctx
        .db
        .player_identity()
        .identity()
        .find(ctx.sender())
        .ok_or("LINK_REQUIRED")?
        .player_id;
    if page == 0
        || !(1..=50).contains(&page_size)
        || search.chars().count() > 80
        || !matches!(sort.as_str(), "date" | "rank" | "weight" | "length" | "xp")
        || (!rarity.is_empty() && rank(&rarity).is_none())
        || !matches!(outcome.as_str(), "" | "fish" | "junk" | "treasure")
        || (biome_id != 0
            && ctx
                .db
                .biome_definition()
                .biome_id()
                .find(biome_id)
                .is_none())
    {
        return Err("JOURNAL_QUERY_INVALID".into());
    }
    let row = JournalSelection {
        identity: ctx.sender(),
        player_id,
        page,
        page_size,
        sort,
        descending,
        search: search.trim().to_lowercase(),
        biome_id,
        rarity,
        outcome,
    };
    if ctx
        .db
        .journal_selection()
        .identity()
        .find(ctx.sender())
        .is_some()
    {
        ctx.db.journal_selection().identity().update(row);
    } else {
        ctx.db.journal_selection().insert(row);
    }
    Ok(())
}

fn rank(value: &str) -> Option<usize> {
    ["F", "D", "C", "B", "A", "S", "SS", "SSS", "UR", "UUR"]
        .iter()
        .position(|&r| r == value)
}

// Unknown measurements/rewards go last in either direction. Ties always use
// newest timestamp then a stable key, so page boundaries never shuffle.
fn compare<T: Ord>(a: Option<T>, b: Option<T>, descending: bool) -> Ordering {
    match (a, b) {
        (Some(a), Some(b)) => {
            if descending {
                b.cmp(&a)
            } else {
                a.cmp(&b)
            }
        }
        (None, Some(_)) => Ordering::Greater,
        (Some(_), None) => Ordering::Less,
        (None, None) => Ordering::Equal,
    }
}

#[spacetimedb::view(accessor = my_journal, public)]
pub fn my_journal(ctx: &ViewContext) -> Option<JournalPage> {
    let player_id = current_player(ctx)?;
    let q = ctx
        .db
        .journal_selection()
        .identity()
        .find(ctx.sender())
        .filter(|q| q.player_id == player_id)
        .unwrap_or(JournalSelection {
            identity: ctx.sender(),
            player_id,
            page: 1,
            page_size: 12,
            sort: "date".into(),
            descending: true,
            search: String::new(),
            biome_id: 0,
            rarity: String::new(),
            outcome: String::new(),
        });
    let mut rows: Vec<_> = ctx
        .db
        .journal_entry()
        .player_id()
        .filter(player_id)
        .collect();
    let total_entries = rows.len() as u64;
    rows.retain(|e| {
        (q.search.is_empty() || e.name.to_lowercase().contains(&q.search))
            && (q.biome_id == 0 || e.biome_id == Some(q.biome_id))
            && (q.rarity.is_empty() || e.rarity == q.rarity)
            && (q.outcome.is_empty() || e.outcome == q.outcome)
    });
    rows.sort_by(|a, b| {
        let order = match q.sort.as_str() {
            "rank" => compare(rank(&a.rarity), rank(&b.rarity), q.descending),
            "length" => compare(
                (a.outcome == "fish").then_some(a.length_mm),
                (b.outcome == "fish").then_some(b.length_mm),
                q.descending,
            ),
            "weight" => compare(
                (a.outcome == "fish").then_some(a.weight_g),
                (b.outcome == "fish").then_some(b.weight_g),
                q.descending,
            ),
            "xp" => compare(a.xp_granted, b.xp_granted, q.descending),
            _ => compare(Some(a.caught_at), Some(b.caught_at), q.descending),
        };
        order
            .then_with(|| b.caught_at.cmp(&a.caught_at))
            .then_with(|| b.key.cmp(&a.key))
    });
    let matching_entries = rows.len() as u64;
    let pages = matching_entries.div_ceil(u64::from(q.page_size)).max(1);
    let page = u64::from(q.page).min(pages) as u32;
    let entries = rows
        .into_iter()
        .skip((u64::from(page - 1) * u64::from(q.page_size)) as usize)
        .take(q.page_size as usize)
        .collect();
    Some(JournalPage {
        entries,
        total_entries,
        matching_entries,
        page,
        page_size: q.page_size,
    })
}
