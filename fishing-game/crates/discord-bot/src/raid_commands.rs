use game_client::{RaidAction, Snapshot};
use poise::serenity_prelude as serenity;
use super::{Context, Error};

type RaidContext<'a> = Context<'a>;

pub(super) fn raid() -> poise::Command<super::Data, Error> {
    raid_command()
}

#[poise::command(
    slash_command,
    rename = "raid",
    subcommands(
        "list",
        "start",
        "community",
        "deploy",
        "status",
        "collect",
        "withdraw",
        "refine",
        "reinforce"
    )
)]
async fn raid_command(_ctx: RaidContext<'_>) -> Result<(), Error> {
    Ok(())
}

fn parse_grade(value: &str) -> Result<u8, &'static str> {
    match value.trim().to_ascii_uppercase().as_str() {
        "F" => Ok(0),
        "D" => Ok(1),
        "C" => Ok(2),
        "B" => Ok(3),
        "A" => Ok(4),
        "S" => Ok(5),
        "SS" => Ok(6),
        "SSS" => Ok(7),
        "UR" => Ok(8),
        "UUR" => Ok(9),
        _ => Err("Choose a raid grade from F, D, C, B, A, S, SS, SSS, UR, or UUR."),
    }
}

fn parse_species_id(value: &str, species: &[(u32, &str)]) -> Result<u32, &'static str> {
    let value = value.trim();
    if value.is_empty() {
        return Err("Enter a species name or numeric species ID.");
    }
    let matches: Vec<_> = species
        .iter()
        .filter(|(_, name)| name.eq_ignore_ascii_case(value))
        .collect();
    if matches.len() == 1 {
        return Ok(matches[0].0);
    }
    if matches.len() > 1 {
        return Err("That name is ambiguous; use the numeric species ID.");
    }
    if !value.bytes().all(|byte| byte.is_ascii_digit()) {
        return Err("Use an exact fish name or a numeric species ID.");
    }
    let id = value
        .parse::<u32>()
        .map_err(|_| "That species ID is invalid.")?;
    species
        .iter()
        .find(|(species_id, _)| *species_id == id)
        .map(|(species_id, _)| *species_id)
        .ok_or("That species ID is not in the catalog.")
}

fn parse_id(value: &str) -> Result<u128, &'static str> {
    let value = value.trim();
    if value.is_empty() || !value.bytes().all(|byte| byte.is_ascii_digit()) {
        return Err("Enter a numeric ID.");
    }
    value.parse().map_err(|_| "That numeric ID is out of range.")
}

fn parse_material(value: &str) -> Result<u8, &'static str> {
    match value.trim().to_ascii_lowercase().as_str() {
        "fillets" => Ok(0),
        "scales" => Ok(1),
        _ => Err("Choose fillets or scales."),
    }
}

fn grade_name(snapshot: &Snapshot, grade: u8) -> String {
    snapshot
        .raid_grades
        .iter()
        .find(|row| row.grade == grade)
        .map_or_else(|| format!("grade {grade}"), |row| row.name.clone())
}

fn discord_time(micros: i64, style: &str) -> String {
    format!("<t:{}:{style}>", micros / 1_000_000)
}

fn attempt_line(snapshot: &Snapshot, attempt_id: u128) -> Option<String> {
    let attempt = snapshot.raid_attempts.iter().find(|row| row.id == attempt_id)?;
    let target = attempt
        .species_id
        .and_then(|id| snapshot.species.iter().find(|row| row.species_id == id))
        .map_or_else(
            || {
                attempt
                    .event_id
                    .and_then(|id| snapshot.community_raids.iter().find(|row| row.event_id == id))
                    .map_or_else(|| "Community expedition".into(), |row| row.name.clone())
            },
            |row| row.name.clone(),
        );
    let grade = attempt
        .grade
        .map(|value| format!(" · {} grade", grade_name(snapshot, value)))
        .unwrap_or_default();
    Some(format!(
        "`{}` · **{}{}** · {} · boat **{}** · starts {} · ends {}",
        attempt.id,
        target,
        grade,
        attempt.status,
        attempt.boat_name,
        discord_time(attempt.starts_at.to_micros_since_unix_epoch(), "R"),
        discord_time(attempt.ends_at.to_micros_since_unix_epoch(), "R"),
    ))
}

#[derive(Clone, Debug)]
struct StatusLine {
    active: bool,
    timestamp_micros: i64,
    text: String,
}

fn visible_status_lines(entries: &[StatusLine]) -> Vec<String> {
    let mut active: Vec<_> = entries.iter().filter(|row| row.active).collect();
    let mut terminal: Vec<_> = entries.iter().filter(|row| !row.active).collect();
    active.sort_by_key(|row| std::cmp::Reverse(row.timestamp_micros));
    terminal.sort_by_key(|row| std::cmp::Reverse(row.timestamp_micros));
    active
        .into_iter()
        .map(|row| row.text.clone())
        .chain(terminal.into_iter().take(3).map(|row| row.text.clone()))
        .collect()
}

#[derive(Clone, Debug, PartialEq, Eq)]
struct RaidTargetLine {
    species_id: u32,
    name: String,
    special: bool,
    highest: Option<String>,
    next: Option<String>,
}

fn target_page<T>(rows: &[T], page: usize, page_size: usize) -> Option<&[T]> {
    if page == 0 || page_size == 0 {
        return None;
    }
    let start = (page - 1).checked_mul(page_size)?;
    (start < rows.len()).then(|| &rows[start..rows.len().min(start + page_size)])
}

fn target_line(target: &RaidTargetLine) -> String {
    if target.special {
        format!(
            "ID `{}` · **{}** · SPECIAL encounter",
            target.species_id, target.name
        )
    } else {
        format!(
            "ID `{}` · **{}** · highest clear: {} · next: {}",
            target.species_id,
            target.name,
            target.highest.as_deref().unwrap_or("none"),
            target.next.as_deref().unwrap_or("complete")
        )
    }
}

fn discovered_targets(snapshot: &Snapshot) -> Vec<RaidTargetLine> {
    let discovered: std::collections::HashSet<_> = snapshot
        .collection
        .iter()
        .filter(|row| row.count > 0)
        .map(|row| row.species_id)
        .collect();
    let mut targets: Vec<_> = snapshot
        .species
        .iter()
        .filter(|species| discovered.contains(&species.species_id))
        .map(|species| {
            let special = !species.counts_for_ordinary_collection_completion;
            let cleared = snapshot
                .raid_badges
                .iter()
                .find(|row| row.species_id == species.species_id)
                .map_or(0, |row| row.cleared_grades);
            let highest = (cleared > 0).then(|| grade_name(snapshot, cleared - 1));
            let next = (!special).then(|| grade_name(snapshot, cleared.min(9)));
            RaidTargetLine {
                species_id: species.species_id,
                name: species.name.clone(),
                special,
                highest,
                next,
            }
        })
        .collect();
    targets.sort_by_key(|row| row.species_id);
    targets
}

fn community_event_line(event_id: u64, name: &str, signup: i64, starts: i64, ends: i64) -> String {
    format!(
        "Event ID `{event_id}` · **{name}** · signup {} · starts {} · ends {}",
        discord_time(signup, "R"),
        discord_time(starts, "R"),
        discord_time(ends, "R"),
    )
}

fn split_reply_lines(lines: &[String], limit: usize) -> Result<Vec<String>, &'static str> {
    if limit == 0 {
        return Err("MESSAGE_LIMIT_INVALID");
    }
    let mut chunks = Vec::new();
    let mut current = String::new();
    let mut current_units = 0usize;
    for line in lines {
        let line_units = line.encode_utf16().count();
        if line_units > limit {
            return Err("MESSAGE_LINE_TOO_LONG");
        }
        let separator_units = if current.is_empty() { 0 } else { 1 };
        let extra = line_units + separator_units;
        if current_units + extra > limit {
            chunks.push(std::mem::take(&mut current));
            current_units = 0;
        }
        if !current.is_empty() {
            current.push('\n');
            current_units += 1;
        }
        current.push_str(line);
        current_units += line_units;
    }
    if !current.is_empty() {
        chunks.push(current);
    }
    Ok(chunks)
}

fn snapshot_status(snapshot: &Snapshot) -> String {
    let mut lines = vec![format!(
        "**Raid status** · {} community expeditions completed",
        snapshot
            .raid_statistics
            .first()
            .map_or(0, |row| row.community_completions)
    )];
    if snapshot.raid_attempts.is_empty() {
        lines.push("No raid attempts are recorded.".into());
    } else {
        let entries: Vec<_> = snapshot.raid_attempts.iter().filter_map(|attempt| {
            Some(StatusLine {
                active: matches!(attempt.status.as_str(), "prepared" | "deployed" | "running"),
                timestamp_micros: attempt
                    .settled_at
                    .as_ref()
                    .map(|timestamp| timestamp.to_micros_since_unix_epoch())
                    .unwrap_or_else(|| attempt.prepared_at.to_micros_since_unix_epoch()),
                text: attempt_line(snapshot, attempt.id)?,
            })
        }).collect();
        lines.extend(visible_status_lines(&entries));
    }
    lines.join("\n")
}

async fn acknowledge(ctx: RaidContext<'_>) -> Result<(), Error> {
    ctx.defer_ephemeral().await.map_err(Into::into)
}

async fn respond(ctx: RaidContext<'_>, message: impl Into<String>) -> Result<(), Error> {
    let message = message.into();
    let lines: Vec<_> = message.lines().map(str::to_owned).collect();
    let chunks = split_reply_lines(&lines, 1900)
        .unwrap_or_else(|_| vec!["The raid response was too long to display safely. Narrow the page and retry.".into()]);
    for chunk in chunks {
        ctx.send(
            poise::CreateReply::default()
                .content(chunk)
                .ephemeral(true)
                .allowed_mentions(serenity::CreateAllowedMentions::new()),
        )
        .await?;
    }
    Ok(())
}

fn rejection(error: &Error) -> String {
    let message = error.to_string();
    if message.contains("RAID_DISCOVERY_REQUIRED") {
        "Discover that fish with a normal catch before starting its raid.".into()
    } else if message.contains("RAID_ALREADY_ACTIVE") {
        "Settle or withdraw your active raid before starting another.".into()
    } else if message.contains("RAID_NOT_READY") || message.contains("RAID_NOT_DEPLOYED") {
        "That expedition is not ready for this action yet.".into()
    } else if message.contains("COMMUNITY_SIGNUP_CLOSED") {
        "Signup for that community expedition has closed.".into()
    } else if message.contains("COMMUNITY_RAID_ALREADY_JOINED") {
        "You already joined that community expedition.".into()
    } else if message.contains("RAID_SUPPLY_REQUIRED") {
        "Buy Expedition Chum from the website Trader before deploying.".into()
    } else if message.contains("BOAT_BUSY") {
        "Your boat is committed to an active haul or raid.".into()
    } else if message.contains("RAID_BOAT_REQUIRED") || message.contains("EQUIPMENT_NOT_OWNED") {
        "Equip an owned boat in the website Tackle Box before preparing.".into()
    } else if message.contains("RAID_GRADE_LOCKED") || message.contains("RAID_GRADE_NOT_CLEARED") {
        "Clear the required raid grade before preparing, refining, or reinforcing.".into()
    } else if message.contains("INSUFFICIENT_MATERIALS") {
        "You do not have enough graded raid materials.".into()
    } else if message.contains("INSUFFICIENT_COINS") {
        "You do not have enough coins for that action.".into()
    } else if message.contains("ROD_NOT_OWNED") {
        "You can reinforce only a rod you own.".into()
    } else if message.contains("REINFORCEMENT_LEVEL_CHANGED") {
        "That rod's reinforcement changed. Check /rod and try again.".into()
    } else {
        format!("Raid action could not be confirmed: {message}")
    }
}

async fn player_snapshot(ctx: RaidContext<'_>) -> Result<Snapshot, Error> {
    super::snapshot(ctx, false).await
}

async fn act(ctx: RaidContext<'_>, action: RaidAction) -> Result<Snapshot, Error> {
    let _permit = ctx
        .data()
        .pending
        .try_acquire()
        .map_err(|_| "The pond is busy; try again shortly")?;
    ctx.data()
        .game
        .raid_action(
            ctx.author().id.get(),
            ctx.author().name.clone(),
            super::interaction_id(ctx)?,
            action,
        )
        .await
}

fn committed_result(snapshot: &Snapshot, interaction_id: u64) -> String {
    let mut lines = snapshot
        .raid_actions
        .iter()
        .find(|row| row.request_id == interaction_id)
        .map(|row| row.result.clone())
        .into_iter()
        .collect::<Vec<_>>();
    lines.push(snapshot_status(snapshot));
    lines.join("\n")
}

#[poise::command(slash_command)]
async fn list(
    ctx: RaidContext<'_>,
    #[description = "Discovered target page, ten per page"] page: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    match player_snapshot(ctx).await {
        Ok(snapshot) => {
            let mut lines = vec![snapshot_status(&snapshot)];
            if !snapshot.community_raids.is_empty() {
                lines.push("**Community calendar**".into());
                lines.extend(snapshot.community_raids.iter().map(|event| community_event_line(
                    event.event_id,
                    &event.name,
                    event.signup_at.to_micros_since_unix_epoch(),
                    event.starts_at.to_micros_since_unix_epoch(),
                    event.ends_at.to_micros_since_unix_epoch(),
                )));
            }
            let targets = discovered_targets(&snapshot);
            if targets.is_empty() {
                lines.push("No raid targets are discovered yet. Catch fish normally first; specials require their own natural discovery.".into());
            } else {
                let total_pages = targets.len().div_ceil(10);
                let selected_page = page.unwrap_or(1) as usize;
                if selected_page == 0 || selected_page > total_pages {
                    lines.push(format!("Choose a discovered-target page from 1 to {total_pages}."));
                } else if let Some(rows) = target_page(&targets, selected_page, 10) {
                    lines.push(format!("**Discovered targets · page {selected_page}/{total_pages}**"));
                    lines.extend(rows.iter().map(target_line));
                }
            }
            respond(ctx, lines.join("\n")).await
        }
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn start(
    ctx: RaidContext<'_>,
    #[description = "Exact fish name or species ID"] species: String,
    #[description = "Raid grade, default F"] grade: Option<String>,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        let before = player_snapshot(ctx).await?;
        let catalog: Vec<_> = before
            .species
            .iter()
            .map(|row| (row.species_id, row.name.as_str()))
            .collect();
        let species_id = parse_species_id(&species, &catalog)?;
        let grade = parse_grade(grade.as_deref().unwrap_or("F"))?;
        act(ctx, RaidAction::Prepare { species_id, grade }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn community(
    ctx: RaidContext<'_>,
    #[description = "Community event ID"] event_id: String,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        let event_id = parse_id(&event_id)?
            .try_into()
            .map_err(|_| "That community event ID is out of range")?;
        act(ctx, RaidAction::PrepareCommunity { event_id }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn deploy(
    ctx: RaidContext<'_>,
    #[description = "Attempt ID; defaults to your latest prepared raid"] attempt_id: Option<String>,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        let snapshot = player_snapshot(ctx).await?;
        let id = attempt_id
            .as_deref()
            .map(parse_id)
            .transpose()?
            .or_else(|| {
                snapshot
                    .raid_attempts
                    .iter()
                    .filter(|row| row.status == "prepared")
                    .max_by_key(|row| row.prepared_at.to_micros_since_unix_epoch())
                    .map(|row| row.id)
            })
            .ok_or("No prepared raid was found.")?;
        act(ctx, RaidAction::Deploy { attempt_id: id }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn status(ctx: RaidContext<'_>) -> Result<(), Error> {
    acknowledge(ctx).await?;
    match player_snapshot(ctx).await {
        Ok(snapshot) => respond(ctx, snapshot_status(&snapshot)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn collect(
    ctx: RaidContext<'_>,
    #[description = "Attempt ID; defaults to the latest due raid"] attempt_id: Option<String>,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        let snapshot = player_snapshot(ctx).await?;
        let now_micros = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_micros() as i64;
        let id = attempt_id
            .as_deref()
            .map(parse_id)
            .transpose()?
            .or_else(|| {
                snapshot
                    .raid_attempts
                    .iter()
                    .filter(|row| row.ends_at.to_micros_since_unix_epoch() <= now_micros && row.status != "cancelled")
                    .max_by_key(|row| row.prepared_at.to_micros_since_unix_epoch())
                    .map(|row| row.id)
            })
            .ok_or("No due raid was found.")?;
        act(ctx, RaidAction::Claim { attempt_id: id }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn withdraw(
    ctx: RaidContext<'_>,
    #[description = "Attempt ID; defaults to your active raid"] attempt_id: Option<String>,
    #[description = "Set true to confirm withdrawal"] confirm: bool,
) -> Result<(), Error> {
    if !confirm {
        return respond(ctx, "Withdrawal spends any deployed supplies. A running personal raid returns only floored partial materials and grants no seal; special and community raids return no rewards. Set `confirm` to true to continue.").await;
    }
    acknowledge(ctx).await?;
    let result = async {
        let snapshot = player_snapshot(ctx).await?;
        let id = attempt_id
            .as_deref()
            .map(parse_id)
            .transpose()?
            .or_else(|| {
                snapshot
                    .raid_attempts
                    .iter()
                    .filter(|row| matches!(row.status.as_str(), "prepared" | "deployed" | "running"))
                    .max_by_key(|row| row.prepared_at.to_micros_since_unix_epoch())
                    .map(|row| row.id)
            })
            .ok_or("No active raid was found.")?;
        act(ctx, RaidAction::Cancel { attempt_id: id }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn refine(
    ctx: RaidContext<'_>,
    #[description = "fillets or scales"] material: String,
    #[description = "Source grade F through UR"] from_grade: String,
    #[description = "Number of batches, 1 to 100"] batches: u32,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        if !(1..=100).contains(&batches) {
            return Err("Choose between 1 and 100 batches.".into());
        }
        let material_id = parse_material(&material)?;
        let from_grade = parse_grade(&from_grade)?;
        act(ctx, RaidAction::Refine { material_id, from_grade, batches }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[poise::command(slash_command)]
async fn reinforce(
    ctx: RaidContext<'_>,
    #[description = "Owned rod ID; defaults to your equipped rod"] rod_id: Option<u32>,
) -> Result<(), Error> {
    acknowledge(ctx).await?;
    let result = async {
        let snapshot = player_snapshot(ctx).await?;
        let rod_id = rod_id.unwrap_or(snapshot.player.equipped_rod_id);
        if !snapshot.owned_rods.iter().any(|row| row.rod_id == rod_id) {
            return Err("ROD_NOT_OWNED".into());
        }
        let expected_level = snapshot
            .rod_reinforcements
            .iter()
            .find(|row| row.rod_id == rod_id)
            .map_or(0, |row| row.level);
        act(ctx, RaidAction::Reinforce { rod_id, expected_level }).await
    }
    .await;
    match result {
        Ok(snapshot) => respond(ctx, committed_result(&snapshot, super::interaction_id(ctx)?)).await,
        Err(error) => respond(ctx, rejection(&error)).await,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parser_rejects_unknown_grades_materials_and_malformed_ids() {
        assert_eq!(parse_grade("f"), Ok(0));
        assert_eq!(parse_grade("UUR"), Ok(9));
        assert!(parse_grade("f+").is_err());
        assert_eq!(parse_material("scales"), Ok(1));
        assert!(parse_material("bait").is_err());
        assert_eq!(parse_id("1234"), Ok(1234));
        assert!(parse_id("12x").is_err());
    }

    #[test]
    fn species_parser_requires_exact_names_or_known_numeric_ids() {
        let species = [(74, "Fihs"), (165, "Nidalees Lost Sock")];
        assert_eq!(parse_species_id("fIhS", &species), Ok(74));
        assert_eq!(parse_species_id("74", &species), Ok(74));
        assert!(parse_species_id("74fish", &species).is_err());
        assert!(parse_species_id("Not a fish", &species).is_err());
    }

    #[test]
    fn raid_group_registers_all_nine_subcommands() {
        let command = raid();
        let names: Vec<&str> = command.subcommands.iter().map(|row| row.name.as_ref()).collect();
        assert_eq!(
            names,
            ["list", "start", "community", "deploy", "status", "collect", "withdraw", "refine", "reinforce"]
        );
    }

    #[test]
    fn status_caps_one_hundred_history_rows_after_all_active_attempts() {
        let entries: Vec<_> = (0..100)
            .map(|id| StatusLine {
                active: id < 2,
                timestamp_micros: i64::from(id),
                text: format!("Attempt ID `{id}` · state"),
            })
            .collect();
        let shown = visible_status_lines(&entries);
        assert_eq!(shown.len(), 5);
        assert!(shown[0].contains("`1`"));
        assert!(shown[1].contains("`0`"));
        assert!(shown[2].contains("`99`"));
        assert!(shown[4].contains("`97`"));
    }

    #[test]
    fn target_list_paginates_all_251_species_and_keeps_specials_explicit() {
        let targets: Vec<_> = (1..=251)
            .map(|species_id| RaidTargetLine {
                species_id,
                name: match species_id {
                    74 => "Fihs".into(),
                    165 => "Nidalees Lost Sock".into(),
                    _ => format!("Species {species_id}"),
                },
                special: species_id == 74 || species_id == 165,
                highest: (species_id % 10 > 0).then(|| "C".into()),
                next: Some("B".into()),
            })
            .collect();
        assert_eq!(targets.len().div_ceil(10), 26);
        assert_eq!(target_page(&targets, 1, 10).unwrap().len(), 10);
        assert_eq!(target_page(&targets, 26, 10).unwrap().len(), 1);
        let special = target_line(&targets[73]);
        assert!(special.contains("ID `74`"));
        assert!(special.contains("Fihs"));
        assert!(special.contains("SPECIAL"));
    }

    #[test]
    fn community_list_lines_show_the_event_id() {
        let line = community_event_line(1234567890, "Deepwater Convergence", 1, 2, 3);
        assert!(line.contains("Event ID `1234567890`"));
        assert!(line.contains("Deepwater Convergence"));
    }

    #[test]
    fn response_splitting_keeps_complete_lines_ids_and_mentions_under_limit() {
        let lines: Vec<_> = (0..100)
            .map(|id| format!("Attempt ID `{id}` · <@123456789012345678> · {}", "🎣".repeat(20)))
            .collect();
        let chunks = split_reply_lines(&lines, 1900).unwrap();
        assert!(chunks.len() > 1);
        assert!(chunks.iter().all(|chunk| chunk.encode_utf16().count() <= 1900));
        assert_eq!(chunks.join("\n").split('\n').collect::<Vec<_>>(), lines.iter().map(String::as_str).collect::<Vec<_>>());
        assert!(chunks.iter().all(|chunk| chunk.contains("<@123456789012345678>")));
        assert!(split_reply_lines(&["x".repeat(1901)], 1900).is_err());
    }
}
