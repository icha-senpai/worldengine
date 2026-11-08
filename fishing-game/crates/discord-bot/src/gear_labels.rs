use game_client::{Client, Snapshot, module_bindings::{CommandReceipt, GearActivitySnapshot, GearInstance, HaulTrip}};

pub fn plain(value: &str) -> String {
    let mut result = String::new();
    let mut chars = value.chars().filter(|ch| !ch.is_control()).peekable();
    while let Some(ch) = chars.next() {
        result.push(ch);
        if ch == '@' && chars.peek() != Some(&'\u{200b}') {
            result.push('\u{200b}');
        }
    }
    result
}

pub fn markdown(value: &str) -> String {
    let mut result = String::new();
    for ch in plain(value).chars() {
        if matches!(ch, '\\' | '*' | '_' | '`' | '~' | '|' | '[' | ']' | '<' | '>') {
            result.push('\\');
        }
        result.push(ch);
    }
    result
}

pub fn label(snapshot: &Snapshot, name: &str, quality: Option<u8>, reinforcement: Option<u8>) -> String {
    let mut parts = vec![plain(name)];
    if let Some(quality) = quality.and_then(|level| snapshot.qualities.iter().find(|row| row.quality_level == level)) {
        parts.push(plain(&quality.name));
    }
    match reinforcement {
        Some(0) => parts.push("Unreinforced".into()),
        Some(level) => {
            if let Some(grade) = snapshot.raid_grades.iter().find(|row| row.grade == level - 1) {
                parts.push(plain(&grade.name));
            }
        }
        None => {}
    }
    parts.join(" - ")
}

fn booked<'a>(snapshot: &'a Snapshot, player_id: u64, activity: &str, id: u64, kind: &str, definition: u32) -> Option<&'a GearActivitySnapshot> {
    if snapshot.player.player_id != player_id {
        return None;
    }
    snapshot.gear_activity_snapshots.iter().find(|row| {
        row.player_id == player_id && row.activity_kind == activity && row.activity_id == u128::from(id)
            && row.snapshot.kind == kind && row.snapshot.definition_id == definition
    })
}

fn instance_name<'a>(snapshot: &'a Snapshot, player_id: u64, instance_id: u128, kind: &str, definition: u32) -> Option<&'a str> {
    snapshot.gear_instances.iter().find(|row| row.player_id == player_id && row.instance_id == instance_id
        && row.kind == kind && row.definition_id == definition)?;
    snapshot.gear_instance_names.iter().find(|row| row.player_id == player_id && row.instance_id == instance_id)
        .map(|row| row.name.as_str()).filter(|name| !plain(name).trim().is_empty())
}

pub fn selected_rod(snapshot: &Snapshot, definition: u32) -> Option<&GearInstance> {
    let owner = snapshot.player.player_id;
    let equipped = snapshot.gear_loadout.as_ref().filter(|row| row.player_id == owner)
        .and_then(|row| snapshot.gear_instances.iter().find(|item| item.player_id == owner
            && item.kind == "rod" && item.definition_id == definition && item.instance_id == row.rod_instance_id));
    equipped.or_else(|| snapshot.gear_instances.iter().find(|item| item.player_id == owner
        && item.kind == "rod" && item.definition_id == definition && item.protected_original))
}

pub fn rod_label(snapshot: &Snapshot, model: &str, instance: Option<&GearInstance>, quality: u8, reinforcement: u8) -> String {
    let name = instance.and_then(|item| instance_name(snapshot, snapshot.player.player_id, item.instance_id, "rod", item.definition_id))
        .unwrap_or(model);
    label(snapshot, name, Some(quality), Some(reinforcement))
}

pub fn cast_rod_label(snapshot: &Snapshot, receipt: &CommandReceipt) -> String {
    let model = snapshot.rods.iter().find(|row| row.rod_id == receipt.rod_id).map_or("Rod", |row| row.name.as_str());
    if snapshot.player.player_id != receipt.player_id {
        return plain(model);
    }
    let booking = booked(snapshot, receipt.player_id, "cast", receipt.interaction_id, "rod", receipt.rod_id);
    let name = booking.and_then(|row| instance_name(snapshot, receipt.player_id, row.snapshot.instance_id, "rod", receipt.rod_id))
        .unwrap_or(model);
    let equipment = snapshot.cast_equipment.as_ref().filter(|row| row.player_id == receipt.player_id && row.interaction_id == receipt.interaction_id);
    let quality = equipment.map(|row| row.quality_level).or_else(|| booking.map(|row| row.snapshot.quality_level));
    let reinforcement = equipment.map(|row| row.reinforcement_level).or_else(|| booking.map(|row| row.snapshot.reinforcement_level));
    label(snapshot, name, quality, reinforcement)
}

pub fn cast_rod_summary(snapshot: &Snapshot, receipt: &CommandReceipt) -> String {
    format!("**{}**", markdown(&cast_rod_label(snapshot, receipt)))
}

pub fn rod_heading(snapshot: &Snapshot, model: &str, instance: Option<&GearInstance>, quality: u8, reinforcement: u8) -> String {
    format!("**{}**", markdown(&rod_label(snapshot, model, instance, quality, reinforcement)))
}

pub fn haul_gear_label(snapshot: Option<&Snapshot>, trip: &HaulTrip) -> String {
    let Some(snapshot) = snapshot.filter(|row| row.player.player_id == trip.player_id) else {
        return plain(&trip.equipment_name);
    };
    let booking = booked(snapshot, trip.player_id, "haul", trip.interaction_id, &trip.kind, trip.equipment_id);
    let name = booking.and_then(|row| instance_name(snapshot, trip.player_id, row.snapshot.instance_id, &trip.kind, trip.equipment_id))
        .unwrap_or(&trip.equipment_name);
    label(snapshot, name, Some(trip.quality_level), booking.map(|row| row.snapshot.reinforcement_level))
}

pub async fn haul_label(client: &Client, trip: &HaulTrip) -> String {
    match client.player(trip.discord_user_id, String::new(), trip.interaction_id, trip.guild_id, trip.channel_id, false).await {
        Ok(snapshot) if snapshot.player.player_id == trip.player_id => haul_gear_label(Some(&snapshot), trip),
        _ => {
            tracing::warn!("Haul gear metadata unavailable; using the booked model name");
            haul_gear_label(None, trip)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use game_client::module_bindings::{
        BiomeDefinition, CastEquipmentReceipt, GameConfig, GearInstanceName, GearLoadout,
        GearSnapshot, Player, QualityDefinition, RaidGradeDefinition, RodDefinition,
    };
    use spacetimedb_sdk::{Identity, Timestamp};

    fn instance(id: u128, owner: u64, kind: &str, definition: u32, original: bool, quality: u8, rank: u8) -> GearInstance {
        GearInstance {
            instance_id: id, player_id: owner, kind: kind.into(), definition_id: definition,
            family_id: definition, primary_biome_id: 1, quality_level: quality,
            reinforcement_level: rank, protected_original: original, favorite: false,
            revision: 1, source: "test".into(), created_at: Timestamp::UNIX_EPOCH,
        }
    }

    fn booking(owner: u64, activity: &str, id: u64, gear: &GearInstance) -> GearActivitySnapshot {
        GearActivitySnapshot {
            key: format!("{activity}:{id}"), player_id: owner, activity_kind: activity.into(),
            activity_id: u128::from(id), booked_at: Timestamp::UNIX_EPOCH,
            recorded_at: Timestamp::UNIX_EPOCH, legacy_booking: false,
            snapshot: GearSnapshot {
                instance_id: gear.instance_id, gear_revision: Some(gear.revision), kind: gear.kind.clone(),
                definition_id: gear.definition_id, family_id: gear.family_id, primary_biome_id: Some(1),
                quality_level: gear.quality_level, reinforcement_level: gear.reinforcement_level,
                effects_version: 1, power: None, luck_bp: None, xp_bonus_bp: None, rules_version: 1,
                content_version: Some(1), catalog_version: Some(1),
            },
        }
    }

    fn snapshot() -> Snapshot {
        let original = instance(100, 1, "rod", 2, true, 6, 10);
        let copy = instance(101, 1, "rod", 2, false, 0, 0);
        let boat = instance(200, 1, "boat", 102, false, 6, 10);
        let second_boat = instance(201, 1, "boat", 102, false, 0, 0);
        let bookings = vec![booking(1, "cast", 20, &original), booking(1, "haul", 30, &boat)];
        let quality = |level, name: &str| QualityDefinition {
            quality_level: level, version: 1, name: name.into(), power_bonus: 0, luck_bp: 0,
            xp_bonus_bp: 0, tin_cost: 0, scrap_cost: 0,
        };
        Snapshot {
            quests: vec![], quest_definitions: vec![], public_profiles: vec![], portraits: vec![],
            standings: vec![], statistics: vec![], records: vec![], achievements: vec![],
            earned_achievements: vec![], titles: vec![], perfect_species: vec![], rank_maxima: vec![],
            baits: vec![], qualities: vec![quality(0, "Common"), quality(6, "Prismatic")],
            owned_baits: vec![], focus_preferences: vec![], bait_loadout: None, items: vec![], cast_pulls: vec![],
            cast_equipment: Some(CastEquipmentReceipt {
                interaction_id: 20, caught_at: Timestamp::UNIX_EPOCH, player_id: 1,
                quality_level: 6, power: 0, luck_bp: 0, xp_bonus_bp: 0, bait_id: 0,
                bait_uses_left: 0, bait_item: String::new(), bait_quantity: 0, bait_size_rolls: 0,
                collection_focus_enabled: false, collection_focus_rerolls: 0, secondary_bait_id: 0,
                secondary_bait_uses_left: 0, secondary_bait_item: String::new(), secondary_bait_quantity: 0,
                reinforcement_level: 10,
            }),
            gear_instances: vec![original, copy, boat, second_boat],
            gear_instance_names: vec![
                GearInstanceName { instance_id: 100, player_id: 1, name: "Old Faithful".into() },
                GearInstanceName { instance_id: 101, player_id: 1, name: "New Rod".into() },
                GearInstanceName { instance_id: 200, player_id: 1, name: "Old Faithful".into() },
                GearInstanceName { instance_id: 201, player_id: 1, name: "New Boat".into() },
            ],
            gear_loadout: Some(GearLoadout { player_id: 1, rod_instance_id: 101, boat_instance_id: 200, net_instance_id: 0, revision: 1 }),
            gear_activity_snapshots: bookings,
            player: Player {
                player_id: 1, discord_user_id: 99, display_name: "Angler".into(), created_at: Timestamp::UNIX_EPOCH,
                total_xp: 0, coins: 0, completed_casts: 0, fish_count: 0, junk_count: 0,
                treasure_count: 0, kept_count: 0, next_cast_at: Timestamp::UNIX_EPOCH,
                selected_biome_id: 1, equipped_rod_id: 2,
            },
            inventory: vec![], collection: vec![], species: vec![], biomes: vec![],
            rods: vec![RodDefinition { rod_id: 2, name: "River Rod".into(), power: 10, minimum_level: 1 }],
            rod_bonuses: vec![], owned_rods: vec![], licences: vec![], listings: vec![],
            config: GameConfig { version: 1, cast_cooldown_seconds: 60, inventory_capacity: 0, recent_limit: 50, level_cap: 500 },
            receipt: None, raid_attempts: vec![], raid_actions: vec![],
            raid_grades: vec![RaidGradeDefinition {
                grade: 9, catalog_version: 1, name: "UUR".into(), duration_seconds: 0,
                supply_units: 0, fillets: 0, scales: 0, reinforce_coins: 0,
                focus_chance_bp: 0, perfect_bonus_rolls: 0,
            }],
            community_raids: vec![], raid_badges: vec![], rod_reinforcements: vec![], raid_statistics: vec![],
        }
    }

    fn receipt() -> CommandReceipt {
        CommandReceipt {
            interaction_id: 20, player_id: 1, discord_user_id: 99, guild_id: None, channel_id: 42,
            command: "fish".into(), caught_at: Timestamp::UNIX_EPOCH, outcome: "junk".into(),
            catch_id: None, species_id: None, rarity: String::new(), length_mm: 0, weight_g: 0,
            size_grade: 0, xp_granted: 0, coins_granted: 0, sale_value_coins: 0, rules_version: 1,
            content_version: 1, item_granted: String::new(), item_quantity: 0, biome_id: 1, rod_id: 2,
        }
    }

    fn trip() -> HaulTrip {
        HaulTrip {
            interaction_id: 30, player_id: 1, discord_user_id: 99, guild_id: None, channel_id: 42,
            kind: "boat".into(), equipment_id: 102, equipment_name: "River Boat".into(), quality_level: 6,
            luck_bp: 0, xp_bonus_bp: 0, biome: BiomeDefinition {
                biome_id: 1, name: "Pond".into(), description: String::new(), minimum_level: 1,
                required_power: 0, fish_weight: 80, junk_weight: 10, treasure_weight: 10,
            },
            rules: GameConfig { version: 1, cast_cooldown_seconds: 60, inventory_capacity: 0, recent_limit: 50, level_cap: 500 },
            collection_focus_enabled: false, started_at: Timestamp::UNIX_EPOCH, due_at: Timestamp::UNIX_EPOCH,
            completed_at: None, status: "running".into(), results: vec![], total_xp: 0, total_coins: 0,
            available_at: Timestamp::UNIX_EPOCH, lease_owner: Identity::ZERO, lease_nonce: 0, attempts: 0,
        }
    }

    #[test]
    fn current_rod_uses_selected_owned_instance_and_safe_defaults() {
        let mut s = snapshot();
        let selected = selected_rod(&s, 2).unwrap();
        assert_eq!(selected.instance_id, 101);
        assert_eq!(rod_label(&s, "River Rod", Some(selected), selected.quality_level, selected.reinforcement_level),
            "New Rod - Common - Unreinforced");
        s.gear_loadout.as_mut().unwrap().rod_instance_id = 999;
        let original = selected_rod(&s, 2).unwrap().clone();
        assert_eq!(rod_label(&s, "River Rod", Some(&original), original.quality_level, original.reinforcement_level),
            "Old Faithful - Prismatic - UUR");
        s.gear_instance_names.clear();
        assert_eq!(rod_label(&s, "River Rod", Some(&original), 6, 10), "River Rod - Prismatic - UUR");
        s.gear_instance_names.push(GearInstanceName { instance_id: 100, player_id: 1, name: "\0\n".into() });
        assert_eq!(rod_label(&s, "River Rod", Some(&original), 6, 10), "River Rod - Prismatic - UUR");
        assert_eq!(label(&s, "River Rod", Some(99), Some(99)), "River Rod");
    }

    #[test]
    fn booked_cast_and_haul_ignore_loadout_changes_and_foreign_names() {
        let mut s = snapshot();
        let r = receipt();
        let t = trip();
        let mut foreign_booking = s.gear_activity_snapshots[0].clone();
        foreign_booking.player_id = 2;
        foreign_booking.snapshot.instance_id = 101;
        s.gear_activity_snapshots.insert(0, foreign_booking);
        s.gear_instance_names.insert(0, GearInstanceName { instance_id: 100, player_id: 2, name: "Foreign".into() });
        assert_eq!(cast_rod_label(&s, &r), "Old Faithful - Prismatic - UUR");
        assert_eq!(haul_gear_label(Some(&s), &t), "Old Faithful - Prismatic - UUR");
        s.gear_instances[0].quality_level = 0;
        s.gear_instances[0].reinforcement_level = 0;
        s.gear_instances[2].quality_level = 0;
        s.gear_instances[2].reinforcement_level = 0;
        s.gear_loadout.as_mut().unwrap().boat_instance_id = 201;
        assert_eq!(cast_rod_label(&s, &r), "Old Faithful - Prismatic - UUR");
        assert_eq!(haul_gear_label(Some(&s), &t), "Old Faithful - Prismatic - UUR");
        let equipment = s.cast_equipment.as_mut().unwrap();
        equipment.player_id = 2;
        equipment.quality_level = 0;
        equipment.reinforcement_level = 0;
        assert_eq!(cast_rod_label(&s, &r), "Old Faithful - Prismatic - UUR");
        s.gear_instance_names.iter_mut().find(|row| row.player_id == 1 && row.instance_id == 100).unwrap().name = "Renamed".into();
        assert_eq!(cast_rod_label(&s, &r), "Renamed - Prismatic - UUR");
        s.gear_instance_names.iter_mut().find(|row| row.player_id == 1 && row.instance_id == 200).unwrap().name = "Boat Rename".into();
        assert_eq!(haul_gear_label(Some(&s), &t), "Boat Rename - Prismatic - UUR");
        s.gear_instance_names.retain(|row| row.instance_id != 100 && row.instance_id != 200);
        assert_eq!(cast_rod_label(&s, &r), "River Rod - Prismatic - UUR");
        assert_eq!(haul_gear_label(Some(&s), &t), "River Boat - Prismatic - UUR");
        s.gear_activity_snapshots.clear();
        s.cast_equipment = None;
        assert_eq!(cast_rod_label(&s, &r), "River Rod");
        assert_eq!(haul_gear_label(Some(&s), &t), "River Boat - Prismatic");
        assert_eq!(haul_gear_label(None, &t), "River Boat");
        s.gear_activity_snapshots.push(booking(1, "haul", t.interaction_id, &s.gear_instances[1]));
        assert_eq!(haul_gear_label(Some(&s), &t), "River Boat - Prismatic");
        s.gear_activity_snapshots.push(booking(1, "haul", r.interaction_id, &s.gear_instances[1]));
        assert_eq!(cast_rod_label(&s, &r), "River Rod");
        s.gear_activity_snapshots.push(booking(1, "cast", r.interaction_id + 1, &s.gear_instances[1]));
        assert_eq!(cast_rod_label(&s, &r), "River Rod");
        let mut foreign = r.clone();
        foreign.player_id = 2;
        assert_eq!(cast_rod_label(&s, &foreign), "River Rod");
    }

    #[test]
    fn serialized_catch_rod_and_haul_labels_are_literal_and_mention_safe() {
        let mut s = snapshot();
        let malicious = "**/@everyone/<@123>/雪\n";
        s.gear_instance_names.iter_mut().find(|row| row.instance_id == 100).unwrap().name = malicious.into();
        s.gear_instance_names.iter_mut().find(|row| row.instance_id == 200).unwrap().name = malicious.into();
        let expected = "**/@\u{200b}everyone/<@\u{200b}123>/雪 - Prismatic - UUR";
        assert_eq!(cast_rod_label(&s, &receipt()), expected);
        let catch = cast_rod_summary(&s, &receipt());
        assert!(catch.contains("\\*\\*/@\u{200b}everyone/\\<@\u{200b}123\\>/雪"));
        let catch_message = serde_json::to_value(poise::serenity_prelude::CreateMessage::new()
            .content(&catch).allowed_mentions(poise::serenity_prelude::CreateAllowedMentions::new())).unwrap();
        assert_eq!(catch_message["content"], catch);
        assert_eq!(catch_message["allowed_mentions"]["parse"], serde_json::json!([]));
        let rod = rod_heading(&s, "River Rod", Some(&s.gear_instances[0]), 6, 10);
        let serialized = serde_json::to_value(poise::serenity_prelude::CreateEmbed::new().description(&rod)).unwrap();
        assert_eq!(serialized["description"], catch);
        let t = trip();
        let label = haul_gear_label(Some(&s), &t);
        let status = crate::haul_again::status(&t, &label).unwrap();
        assert!(status.contains("\\*\\*/@\u{200b}everyone/\\<@\u{200b}123\\>/雪"));
        let start = serde_json::to_value(poise::serenity_prelude::CreateMessage::new()
            .content(&status).allowed_mentions(poise::serenity_prelude::CreateAllowedMentions::new())).unwrap();
        assert_eq!(start["content"], status);
        assert_eq!(start["allowed_mentions"]["parse"], serde_json::json!([]));
        let message = serde_json::to_value(crate::haul_notifications::haul_message(&t, &label, &[], "https://fishbound.test")).unwrap();
        assert_eq!(message["embeds"][0]["title"], format!("{expected} · 0 catches"));
        assert_eq!(message["allowed_mentions"]["parse"], serde_json::json!([]));
        assert_eq!(message["enforce_nonce"], true);
        assert_eq!(message["nonce"], "h1e");
        assert_eq!(message["components"][0]["components"][0]["custom_id"], crate::haul_again::BOAT_ID);
    }
}
